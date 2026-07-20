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
 *     end_offset: int|null
 * }
 */
final class Roles
{
    public const OPTION_NAME = 'eventcrew_roles';

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
                'anchor' => self::ANCHOR_START,
                'start_offset' => -120,
                'end_offset' => 0,
            ],
            // Welcome straddles the doors opening: a little before, an hour in.
            [
                'slug' => 'welcome',
                'label' => __('Welcome', 'eventcrew'),
                'emoji' => '🙋',
                'capacity' => 3,
                'archived' => false,
                'anchor' => self::ANCHOR_START,
                'start_offset' => -30,
                'end_offset' => 60,
            ],
            // Cleaning starts when the event ends, which is the whole reason
            // an end anchor exists - it is the task that crosses midnight.
            [
                'slug' => 'clean',
                'label' => __('Clean', 'eventcrew'),
                'emoji' => '🧹',
                'capacity' => 3,
                'archived' => false,
                'anchor' => self::ANCHOR_END,
                'start_offset' => 0,
                'end_offset' => 60,
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

            if ('' === $slug || isset($seen[$slug])) {
                continue;
            }

            $seen[$slug] = true;

            $clean[] = self::normalize($role, $slug);
        }

        update_option(self::OPTION_NAME, [] === $clean ? self::defaults() : $clean);
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
        $roles = self::all();

        foreach ($roles as $index => $role) {
            if ($role['slug'] === $slug) {
                $roles[$index]['archived'] = $archived;
            }
        }

        update_option(self::OPTION_NAME, $roles);
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
        return self::find($slug)['label'] ?? $slug;
    }

    /**
     * Label with its emoji, for the Telegram board and the roster heading.
     */
    public static function display(string $slug): string
    {
        $role = self::find($slug);

        if (null === $role) {
            return $slug;
        }

        return '' === $role['emoji']
            ? $role['label']
            : $role['emoji'] . ' ' . $role['label'];
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
        return null !== self::find($slug);
    }

    public static function isArchived(string $slug): bool
    {
        return self::find($slug)['archived'] ?? false;
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
            'capacity' => max(1, (int) ($role['capacity'] ?? 1)),
            'archived' => ! empty($role['archived']),
            'anchor' => self::ANCHOR_END === $anchor ? self::ANCHOR_END : self::ANCHOR_START,
            'start_offset' => self::nullableOffset($role['start_offset'] ?? null),
            'end_offset' => self::nullableOffset($role['end_offset'] ?? null),
        ];
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
