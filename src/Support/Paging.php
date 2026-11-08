<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * One page of a person's own list - Tasks or Past tasks - in the bot and on
 * the web alike, PER_PAGE to a page (Owner 2026-11-08: "pagination of 25 per
 * page in both Telegram and web").
 *
 * Pages count from 1. A page below 1 reads as the first and one past the end
 * as the last, so an old Next link or button after the list shrank still
 * shows something rather than an empty page.
 */
final class Paging
{
    public const PER_PAGE = 25;

    /**
     * @template T
     * @param array<int, T> $items
     * @return array{items: list<T>, page: int, pages: int}
     */
    public static function slice(array $items, int $page, int $perPage = self::PER_PAGE): array
    {
        $perPage = max(1, $perPage);
        $pages = max(1, (int) ceil(count($items) / $perPage));
        $page = min(max(1, $page), $pages);

        return [
            'items' => array_values(array_slice(array_values($items), ($page - 1) * $perPage, $perPage)),
            'page' => $page,
            'pages' => $pages,
        ];
    }

    /** A page number from a query argument or a button: digits only, else 1. */
    public static function parse(mixed $raw): int
    {
        $raw = is_scalar($raw) ? (string) $raw : '';

        return 1 === preg_match('/^\d{1,5}$/', $raw) ? max(1, (int) $raw) : 1;
    }
}
