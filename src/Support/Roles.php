<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The kinds of job a task can be - the role someone signs up to fill.
 *
 * Ships opinionated - Decorate, Welcome, Clean, with Decorate capped at two
 * people - because that is the actual shape of a dance event, and a fresh
 * install should be usable without visiting Settings first. The list is
 * editable, but nothing else in the plugin branches on which roles exist.
 *
 * A role also carries an optional schedule: an anchor (the event's start or
 * its end) and two offsets in minutes from it. That is what lets a whole
 * evening's tasks be created from one event with their times already filled
 * in - decorating runs up to the doors opening, cleaning runs after the last
 * guest leaves. Roles whose timing genuinely varies leave the offsets unset
 * and produce tasks with no times, which is the same thing as before.
 *
 * A role can also carry a short description of what the job involves, shown
 * wherever someone decides whether to take it or is reminded of it, next to
 * one shared link to the crew's full guide.
 *
 * The shape is declared once here and imported by everything that handles a
 * role, so adding a key is a one-line change rather than a hunt through
 * eight identical docblocks.
 *
 * @phpstan-type Role array{
 *     slug: string,
 *     label: string,
 *     emoji: string,
 *     capacity: int,
 *     archived: bool,
 *     anchor: string,
 *     start_offset: int|null,
 *     end_offset: int|null,
 *     description: string,
 *     restricted: bool,
 *     every_night: bool
 * }
 *
 * A role can be restricted ("Only people with this role"): DJ, VJ, ceremony
 * leader and the like. Only a person given that role may see or take its
 * tasks, there is no limit on how many people take one, and such a task is
 * never counted as a place that needs filling - the same rules the reserved
 * Organize role has always had, with "is an organizer" replaced by "has this
 * role". A restricted role with every_night on gets one task on every night.
 */
final class Roles
{
    public const OPTION_NAME = 'eventcrew_roles';

    /**
     * The reserved role for the crew leader. It is never in the editable role
     * list - the plugin auto-manages a single leader task per event - but tasks
     * still carry the slug, so display()/label()/exists() recognise it and
     * save() refuses to let an organizer create or archive it by hand.
     */
    public const LEADER_SLUG = 'leader';

    /**
     * The reserved role for organizing the night. Like the leader slot it is
     * not in the editable role list: the plugin adds one per event. Only
     * organizers see it or may take it, and it has no limit on how many people
     * take it, so it is never full and never counted as a place that needs
     * filling. See Task::isOrganizersOnly() and Task::hasNoLimit().
     */
    public const ORGANIZE_SLUG = 'organize';

    /** The shipped door role: its crew checks tickets, so it may see the door list. */
    public const WELCOME_SLUG = 'welcome';

    /** One link to the crew's full guide, shared by every role. */
    public const GUIDE_URL_OPTION = 'eventcrew_guide_url';

    /** Offsets are measured from when the event starts. */
    public const ANCHOR_START = 'start';

    /** Offsets are measured from when the event ends. */
    public const ANCHOR_END = 'end';

    /**
     * @return array<int, Role>
     */
    public static function defaults(): array
    {
        return [
            // Decorating finishes as the doors open, so it hangs off the start.
            [
                'slug' => 'decorate',
                'label' => __('Decorate', 'eventcrew'),
                'emoji' => '🎈',
                'capacity' => 2,
                'archived' => false,
                'restricted' => false,
                'every_night' => true,
                'anchor' => self::ANCHOR_START,
                'start_offset' => -120,
                'end_offset' => 0,
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                'description' => __('Set up before the dance: close the curtains, check the bathrooms, place the LED candles, decorate the DJ table, prepare the water and fruit, and open the movable walls.', 'eventcrew'),
            ],
            // Welcome straddles the doors opening: a little before, an hour in.
            [
                'slug' => 'welcome',
                'label' => __('Welcome', 'eventcrew'),
                'emoji' => '🙋',
                'capacity' => 3,
                'archived' => false,
                'restricted' => false,
                'every_night' => true,
                'anchor' => self::ANCHOR_START,
                'start_offset' => -30,
                'end_offset' => 60,
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                'description' => __('Welcome people at the door, check and sell tickets, and count how many people come in.', 'eventcrew'),
            ],
            // Cleaning starts when the event ends, which is the whole reason
            // an end anchor exists - it is the task that crosses midnight.
            [
                'slug' => 'clean',
                'label' => __('Clean', 'eventcrew'),
                'emoji' => '🧹',
                'capacity' => 3,
                'archived' => false,
                'restricted' => false,
                'every_night' => true,
                'anchor' => self::ANCHOR_END,
                'start_offset' => 0,
                'end_offset' => 60,
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                'description' => __('Pack up after the dance: collect the candles and decorations, clear away the water and snacks, empty full bins, close the movable walls, open the curtains, and do the final check.', 'eventcrew'),
            ],
        ];
    }

    /**
     * Every role, archived ones included.
     *
     * Archived roles have to stay readable: tasks store a role slug, so a
     * roster or a credit history from three months ago still needs to resolve
     * "decorate" to "Decorate" long after the role stopped being offered.
     *
     * @return array<int, Role>
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION_NAME, null);

        if (! is_array($stored) || [] === $stored) {
            return self::defaults();
        }

        $roles = [];

        foreach ($stored as $role) {
            if (! is_array($role)) {
                continue;
            }

            $slug = sanitize_key((string) ($role['slug'] ?? ''));

            if ('' === $slug) {
                continue;
            }

            $roles[] = self::normalize($role, $slug);
        }

        return [] === $roles ? self::defaults() : $roles;
    }

    /**
     * The roles currently on offer - what a task form or a template should
     * show. Everything that presents a choice uses this; everything that
     * resolves a stored slug uses all().
     *
     * @return array<int, Role>
     */
    public static function active(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (array $role): bool => false === $role['archived']
        ));
    }

    /**
     * @return array<int, Role>
     */
    public static function archived(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (array $role): bool => true === $role['archived']
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $roles
     */
    public static function save(array $roles): void
    {
        $clean = [];
        $seen = [];

        foreach ($roles as $role) {
            $slug = sanitize_key((string) ($role['slug'] ?? ''));
            $label = trim((string) ($role['label'] ?? ''));

            // A row with neither a slug nor a label is an empty "add another"
            // line the organizer left untouched, not an error worth reporting.
            if ('' === $slug && '' === $label) {
                continue;
            }

            if ('' === $slug) {
                $slug = sanitize_key($label);
            }

            // The leader and organize slugs are reserved and auto-managed; never
            // let a hand-typed role take one over.
            if ('' === $slug || self::LEADER_SLUG === $slug || self::ORGANIZE_SLUG === $slug || isset($seen[$slug])) {
                continue;
            }

            $seen[$slug] = true;

            $clean[] = self::normalize($role, $slug);
        }

        $before = self::everyNightSlugs();
        update_option(self::OPTION_NAME, [] === $clean ? self::defaults() : $clean);
        self::queueNewEveryNight($before);
    }

    /**
     * @return array<int, string>
     */
    private static function everyNightSlugs(): array
    {
        return array_map(static fn (array $role): string => $role['slug'], self::everyNight());
    }

    /**
     * Hands every role that has just become an every-night restricted role
     * (newly restricted, switched to every night, or restored from the archive)
     * to the backfill, which adds its task to the upcoming nights.
     *
     * @param array<int, string> $before
     */
    private static function queueNewEveryNight(array $before): void
    {
        OrganizeTaskBackfill::queue(array_values(array_diff(self::everyNightSlugs(), $before)));
    }

    /**
     * Takes a role out of circulation without destroying it.
     *
     * Deleting instead would leave every task ever created with that slug
     * pointing at nothing: label() falls back to the raw slug, so a roster
     * would quietly start reading "decorate" instead of "🎈 Decorate", and
     * the v0.5 credit history would lose the meaning of what was earned.
     * Nothing about a role that has been used is safe to throw away.
     */
    public static function archive(string $slug): void
    {
        self::setArchived($slug, true);
    }

    public static function restore(string $slug): void
    {
        self::setArchived($slug, false);
    }

    private static function setArchived(string $slug, bool $archived): void
    {
        $before = self::everyNightSlugs();
        $roles = self::all();

        foreach ($roles as $index => $role) {
            if ($role['slug'] === $slug) {
                $roles[$index]['archived'] = $archived;
            }
        }

        update_option(self::OPTION_NAME, $roles);
        self::queueNewEveryNight($before);
    }

    /**
     * @return Role|null
     */
    public static function find(string $slug): ?array
    {
        foreach (self::all() as $role) {
            if ($role['slug'] === $slug) {
                return $role;
            }
        }

        return null;
    }

    public static function label(string $slug): string
    {
        if (self::LEADER_SLUG === $slug) {
            return __('Leader', 'eventcrew');
        }

        if (self::ORGANIZE_SLUG === $slug) {
            return __('Organize', 'eventcrew');
        }

        return self::find($slug)['label'] ?? $slug;
    }

    /**
     * Label with its emoji, for the Telegram board and the roster heading.
     */
    public static function display(string $slug): string
    {
        if (self::LEADER_SLUG === $slug) {
            return __('🧭 Leader', 'eventcrew');
        }

        if (self::ORGANIZE_SLUG === $slug) {
            return __('⭐ Organize', 'eventcrew');
        }

        $role = self::find($slug);

        if (null === $role) {
            return $slug;
        }

        return '' === $role['emoji']
            ? $role['label']
            : $role['emoji'] . ' ' . $role['label'];
    }

    /**
     * What the job involves, in a sentence or two; '' when none was written.
     */
    public static function description(string $slug): string
    {
        return self::find($slug)['description'] ?? '';
    }

    /**
     * The crew's full guide, or '' when no link has been set.
     */
    public static function guideUrl(): string
    {
        return trim((string) get_option(self::GUIDE_URL_OPTION, ''));
    }

    public static function defaultCapacity(string $slug): int
    {
        return self::find($slug)['capacity'] ?? 1;
    }

    /**
     * Whether the role exists at all, archived or not.
     *
     * Editing a task that was created under a since-archived role must keep
     * working, so validation asks this rather than checking active().
     */
    public static function exists(string $slug): bool
    {
        return self::LEADER_SLUG === $slug || self::ORGANIZE_SLUG === $slug || null !== self::find($slug);
    }

    public static function isArchived(string $slug): bool
    {
        return self::find($slug)['archived'] ?? false;
    }

    /**
     * Whether only some people may see and take this role's tasks: Organize
     * (organizers) and every role set to "Only people with this role".
     * Archived restricted roles stay restricted, so their old tasks never
     * open up to everyone.
     */
    public static function isRestricted(string $slug): bool
    {
        if (self::ORGANIZE_SLUG === $slug) {
            return true;
        }

        return self::find($slug)['restricted'] ?? false;
    }

    /**
     * The slugs of every restricted role, Organize first. Never empty.
     *
     * @return array<int, string>
     */
    public static function restrictedSlugs(): array
    {
        $slugs = [self::ORGANIZE_SLUG];

        foreach (self::all() as $role) {
            if ($role['restricted']) {
                $slugs[] = $role['slug'];
            }
        }

        return $slugs;
    }

    /**
     * The roles a person can be given (restricted, editable ones; Organize is
     * not here because it follows the organizer permission). Archived ones
     * are left out: nobody new should be handed a role no longer offered.
     *
     * @return array<int, Role>
     */
    public static function assignable(): array
    {
        return array_values(array_filter(
            self::active(),
            static fn (array $role): bool => $role['restricted']
        ));
    }

    /**
     * The active roles a night's tasks are built from by the template: every
     * active role except the restricted ones, which are added on their own
     * (see everyNight()).
     *
     * @return array<int, Role>
     */
    public static function forTemplate(): array
    {
        return array_values(array_filter(
            self::active(),
            static fn (array $role): bool => ! $role['restricted']
        ));
    }

    /**
     * The active restricted roles set to "Add to every night".
     *
     * @return array<int, Role>
     */
    public static function everyNight(): array
    {
        return array_values(array_filter(
            self::active(),
            static fn (array $role): bool => $role['restricted'] && $role['every_night']
        ));
    }

    /**
     * Fills in every key with a sane value, so no consumer has to guard
     * against a role stored before these fields existed.
     *
     * @param array<string, mixed> $role
     * @return Role
     */
    private static function normalize(array $role, string $slug): array
    {
        $label = trim((string) ($role['label'] ?? ''));
        $anchor = (string) ($role['anchor'] ?? self::ANCHOR_START);

        return [
            'slug' => $slug,
            'label' => '' === $label ? $slug : $label,
            'emoji' => trim((string) ($role['emoji'] ?? '')),
            // Zero is allowed and meaningful: a role can be defined but generate
            // no active slots, so its tasks stay on the books yet drop off the
            // boards (capacity 0 is filtered out of the open-task lists), the
            // same way a hand-edited task set to 0 does. Negative is nonsense.
            'capacity' => max(0, (int) ($role['capacity'] ?? 1)),
            'archived' => ! empty($role['archived']),
            'anchor' => self::ANCHOR_END === $anchor ? self::ANCHOR_END : self::ANCHOR_START,
            'start_offset' => self::nullableOffset($role['start_offset'] ?? null),
            'end_offset' => self::nullableOffset($role['end_offset'] ?? null),
            'description' => array_key_exists('description', $role)
                ? trim((string) $role['description'])
                : self::defaultDescription($slug),
            'restricted' => ! empty($role['restricted']),
            // On unless switched off: a role stored before this key existed
            // was never restricted, so the default only matters once it is.
            'every_night' => ! array_key_exists('every_night', $role) || ! empty($role['every_night']),
        ];
    }

    /**
     * A role saved before descriptions existed has no description key at all,
     * which is not the same as one an organizer cleared: the first picks up the
     * shipped text for its slug, the second stays blank.
     */
    private static function defaultDescription(string $slug): string
    {
        foreach (self::defaults() as $role) {
            if ($role['slug'] === $slug) {
                return $role['description'];
            }
        }

        return '';
    }

    /**
     * An offset of zero is meaningful - "starts exactly when the event ends" -
     * so only null and the empty string count as "no offset set", never 0.
     */
    private static function nullableOffset(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return (int) $value;
    }
}
