<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Database\Schema;
use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Telegram\CommandMenu;

/**
 * Who is an organizer, derived from the roles people hold.
 *
 * A person is an organizer while they hold an active role with Admin access
 * (Roles::adminSlugs(); Organize has it by default). people.is_organizer is
 * kept as a copy of that, so everything that reads it keeps working, and it
 * is written in one place only: PersonRepository writes it with every change
 * of a person's roles (organizerFor()), and rolesChanged() recomputes
 * everyone after a role's Admin access tick or archive changes.
 *
 * Making someone an organizer gives them Organize (withAdmin()); taking it
 * away takes every Admin access role they hold (withoutAdmin()). When the copy changes,
 * changed() refreshes their Telegram command menu and, for a new organizer,
 * fires RoleNotifier::madeOrganizer() (the organizers' notice and the
 * person's access email and DM) - once, whichever path made the change.
 *
 * The lockout guard: no change may leave the site without an organizer
 * (wouldLeaveNone(), personWouldLeaveNone()). WordPress administrators keep
 * wp-admin either way; this is about the bot and the Crew app.
 */
final class OrganizerSync
{
    /**
     * Whether holding these role slugs makes a person an organizer.
     *
     * @param array<int, string> $slugs
     */
    public static function holdsAdmin(array $slugs): bool
    {
        return [] !== array_intersect($slugs, Roles::adminSlugs());
    }

    /**
     * The is_organizer value for a person with these roles: 1 or 0.
     *
     * @param array<int, string> $slugs
     */
    public static function organizerFor(array $slugs): int
    {
        return self::holdsAdmin($slugs) ? 1 : 0;
    }

    /**
     * The roles after making a person an organizer: Organize added, or when
     * Organize gives no Admin access, the first role that does. Unchanged
     * when they already hold one, or when no role gives Admin access.
     *
     * @param array<int, string> $slugs
     * @return list<string>
     */
    public static function withAdmin(array $slugs): array
    {
        $slugs = array_values($slugs);

        if (self::holdsAdmin($slugs)) {
            return $slugs;
        }

        $admin = Roles::adminSlugs();

        if ([] === $admin) {
            return $slugs;
        }

        $slugs[] = in_array(Roles::ORGANIZE_SLUG, $admin, true) ? Roles::ORGANIZE_SLUG : $admin[0];

        return $slugs;
    }

    /**
     * The roles after taking organizer rights away: every Admin access role
     * removed (Organize included, even when it gives none just now).
     *
     * @param array<int, string> $slugs
     * @return list<string>
     */
    public static function withoutAdmin(array $slugs): array
    {
        return array_values(array_diff($slugs, [...Roles::adminSlugs(), Roles::ORGANIZE_SLUG]));
    }

    /**
     * A person's organizer status changed (PersonRepository found the stored
     * copy differs): the Telegram command menu follows, and a new organizer
     * is announced. $by is who made the change, '' when not known.
     */
    public static function changed(int $personId, bool $organizer, string $by = ''): void
    {
        if ($personId <= 0) {
            return;
        }

        do_action(CommandMenu::ORGANIZER_CHANGED_HOOK, $personId);

        if ($organizer) {
            RoleNotifier::madeOrganizer($personId, $by);
        }
    }

    /**
     * After the role list changed (a role's Admin access, archived, saved):
     * when the roles giving Admin access are not what they were ($before,
     * Roles::adminSlugs() read before the change), everyone's organizer
     * status is recomputed. Returns how many changed.
     *
     * @param array<int, string> $before
     */
    public static function rolesChanged(array $before): int
    {
        $after = Roles::adminSlugs();
        sort($before);
        sort($after);

        return $before === $after ? 0 : self::refreshAll();
    }

    /**
     * Recomputes is_organizer for every person from the roles they hold, and
     * writes it where it differs (through PersonRepository, so the history
     * shows it and changed() runs). Anonymised people are never organizers.
     * Returns how many were written.
     */
    public static function refreshAll(?PersonRepository $people = null): int
    {
        $people ??= new PersonRepository();
        $written = 0;

        foreach (self::rows() as $row) {
            $want = null === $row['anonymized_at'] ? self::organizerFor($row['slugs']) : 0;

            if ($want !== $row['is_organizer']) {
                $people->writeOrganizer($row['id'], 1 === $want);
                ++$written;
            }
        }

        return $written;
    }

    /**
     * Whether a role list where only $adminSlugs give Admin access would
     * leave no organizer while there is one now. A site without organizers
     * (a new install) is never blocked.
     *
     * @param array<int, string> $adminSlugs
     */
    public static function wouldLeaveNone(array $adminSlugs): bool
    {
        // Nothing taken away: whoever is an organizer now still is.
        if ([] === array_diff(Roles::adminSlugs(), $adminSlugs)) {
            return false;
        }

        $now = false;

        foreach (self::rows() as $row) {
            if (null !== $row['anonymized_at']) {
                continue;
            }

            if ([] !== array_intersect($row['slugs'], $adminSlugs)) {
                return false;
            }

            $now = $now || 1 === $row['is_organizer'];
        }

        return $now;
    }

    /**
     * Whether giving person $personId the roles $slugs would leave no
     * organizer: they are one now, would not be after, and nobody else is.
     *
     * @param array<int, string> $slugs
     */
    public static function personWouldLeaveNone(int $personId, bool $isOrganizer, array $slugs): bool
    {
        if (! $isOrganizer || self::holdsAdmin($slugs)) {
            return false;
        }

        global $wpdb;

        $table = Schema::table(Schema::PEOPLE);
        $others = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE is_organizer = 1 AND id <> %d",
                $personId
            )
        );

        return 0 === (int) $others;
    }

    /**
     * Whether giving person $personId the roles $slugs would take organizer
     * rights from the organizer making the change ($actorId): nobody takes
     * away their own, as the old "Remove as organizer" link never allowed.
     * $actorId 0 (wp-admin, the system) is never refused.
     *
     * @param array<int, string> $slugs
     */
    public static function selfWouldLose(int $actorId, int $personId, bool $isOrganizer, array $slugs): bool
    {
        return $actorId > 0 && $actorId === $personId && $isOrganizer && ! self::holdsAdmin($slugs);
    }

    /**
     * Every person's id, roles, organizer copy and anonymised mark.
     *
     * @return list<array{id: int, slugs: list<string>, is_organizer: int, anonymized_at: ?string}>
     */
    private static function rows(): array
    {
        global $wpdb;

        $table = Schema::table(Schema::PEOPLE);
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT id, role_slugs, is_organizer, anonymized_at FROM {$table}
             WHERE is_organizer = 1 OR role_slugs <> ''",
            ARRAY_A
        );
        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'slugs' => Person::decodeRoles((string) ($row['role_slugs'] ?? '')),
                'is_organizer' => empty($row['is_organizer']) ? 0 : 1,
                'anonymized_at' => empty($row['anonymized_at']) ? null : (string) $row['anonymized_at'],
            ];
        }

        return $out;
    }
}
