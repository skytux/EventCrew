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
 */
final class Roles
{
    public const OPTION_NAME = 'eventcrew_roles';

    /**
     * @return array<int, array{slug: string, label: string, emoji: string, capacity: int}>
     */
    public static function defaults(): array
    {
        return [
            [
                'slug' => 'decorate',
                'label' => __('Decorate', 'eventcrew'),
                'emoji' => '🎈',
                'capacity' => 2,
            ],
            [
                'slug' => 'welcome',
                'label' => __('Welcome', 'eventcrew'),
                'emoji' => '🙋',
                'capacity' => 3,
            ],
            [
                'slug' => 'clean',
                'label' => __('Clean', 'eventcrew'),
                'emoji' => '🧹',
                'capacity' => 3,
            ],
        ];
    }

    /**
     * @return array<int, array{slug: string, label: string, emoji: string, capacity: int}>
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

            $capacity = (int) ($role['capacity'] ?? 1);

            $roles[] = [
                'slug' => $slug,
                'label' => (string) ($role['label'] ?? $slug),
                'emoji' => (string) ($role['emoji'] ?? ''),
                'capacity' => max(1, $capacity),
            ];
        }

        return [] === $roles ? self::defaults() : $roles;
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

            $clean[] = [
                'slug' => $slug,
                'label' => '' === $label ? $slug : $label,
                'emoji' => trim((string) ($role['emoji'] ?? '')),
                'capacity' => max(1, (int) ($role['capacity'] ?? 1)),
            ];
        }

        update_option(self::OPTION_NAME, [] === $clean ? self::defaults() : $clean);
    }

    /**
     * @return array{slug: string, label: string, emoji: string, capacity: int}|null
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

    public static function exists(string $slug): bool
    {
        return null !== self::find($slug);
    }
}
