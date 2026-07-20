<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The job groups a shift can belong to.
 *
 * Ships opinionated - Decorate, Welcome, Clean, with Decorate capped at two
 * people - because that is the actual shape of a dance event, and a fresh
 * install should be usable without visiting Settings first. The list is
 * editable, but nothing else in the plugin branches on which types exist.
 */
final class TaskTypes
{
    public const OPTION_NAME = 'eventcrew_task_types';

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

        $types = [];

        foreach ($stored as $type) {
            if (! is_array($type)) {
                continue;
            }

            $slug = sanitize_key((string) ($type['slug'] ?? ''));

            if ('' === $slug) {
                continue;
            }

            $capacity = (int) ($type['capacity'] ?? 1);

            $types[] = [
                'slug' => $slug,
                'label' => (string) ($type['label'] ?? $slug),
                'emoji' => (string) ($type['emoji'] ?? ''),
                'capacity' => max(1, $capacity),
            ];
        }

        return [] === $types ? self::defaults() : $types;
    }

    /**
     * @param array<int, array<string, mixed>> $types
     */
    public static function save(array $types): void
    {
        $clean = [];
        $seen = [];

        foreach ($types as $type) {
            $slug = sanitize_key((string) ($type['slug'] ?? ''));
            $label = trim((string) ($type['label'] ?? ''));

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
                'emoji' => trim((string) ($type['emoji'] ?? '')),
                'capacity' => max(1, (int) ($type['capacity'] ?? 1)),
            ];
        }

        update_option(self::OPTION_NAME, [] === $clean ? self::defaults() : $clean);
    }

    /**
     * @return array{slug: string, label: string, emoji: string, capacity: int}|null
     */
    public static function find(string $slug): ?array
    {
        foreach (self::all() as $type) {
            if ($type['slug'] === $slug) {
                return $type;
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
        $type = self::find($slug);

        if (null === $type) {
            return $slug;
        }

        return '' === $type['emoji']
            ? $type['label']
            : $type['emoji'] . ' ' . $type['label'];
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
