<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

/**
 * Splitting a long bot list into pages that each fit in one Telegram message,
 * shown one at a time in the same message with « Previous / Next » buttons.
 *
 * A page button sends <prefix>:<page> (from 0), and page one sends plain
 * <prefix>, so the button that first opened the list still opens page one.
 * Used by /roster, the door list and the guest list.
 */
final class MessagePages
{
    /** Telegram's limit for the text of one message, in UTF-16 code units. */
    public const MAX_TEXT = 4096;

    /**
     * The length of these lines as Telegram counts it - in UTF-16 code units,
     * so an emoji counts two - with a newline after each.
     *
     * @param array<int, string> $lines
     */
    public static function textLength(array $lines): int
    {
        $length = 0;

        foreach ($lines as $line) {
            $length += intdiv(strlen((string) mb_convert_encoding($line, 'UTF-16LE', 'UTF-8')), 2) + 1;
        }

        return $length;
    }

    /** " · page 2 of 3", added to the first line of a paged list. */
    public static function note(int $page, int $count): string
    {
        /* translators: 1: page number, 2: number of pages */
        return ' · ' . sprintf(__('page %1$d of %2$d', 'eventcrew'), $page + 1, $count);
    }

    /**
     * The previous/next row of a paged list. Page one is plain $prefix.
     *
     * @return array<int, array{text: string, callback_data: string}>
     */
    public static function buttons(string $prefix, int $page, int $count): array
    {
        $row = [];

        if ($page > 0) {
            $row[] = [
                'text' => __('« Previous', 'eventcrew'),
                'callback_data' => $prefix . (1 === $page ? '' : ':' . ($page - 1)),
            ];
        }

        if ($page < $count - 1) {
            $row[] = [
                'text' => __('Next »', 'eventcrew'),
                'callback_data' => $prefix . ':' . ($page + 1),
            ];
        }

        return $row;
    }

    /**
     * Fills pages with the lines of the blocks in order, starting a new page
     * when the next line would take this one past $maxText. A block's
     * heading comes before its first line; a block cut by a page break starts
     * the next page with its 'repeat' lines (its heading when not given).
     * Always at least one page.
     *
     * @param array<int, array{heading: array<int, string>, lines: array<int, string>,
     *     repeat?: array<int, string>}> $blocks
     * @return array<int, array<int, string>>
     */
    public static function split(array $blocks, int $maxText): array
    {
        $pages = [];
        $page = [];
        $length = 0;

        foreach ($blocks as $block) {
            foreach ($block['lines'] as $index => $line) {
                $lines = 0 === $index ? array_merge($block['heading'], [$line]) : [$line];

                if ([] !== $page && $length + self::textLength($lines) > $maxText) {
                    $pages[] = $page;
                    $page = [];
                    $length = 0;
                    $lines = array_merge($block['repeat'] ?? $block['heading'], [$line]);
                }

                $page = array_merge($page, $lines);
                $length += self::textLength($lines);
            }
        }

        $pages[] = $page;

        return $pages;
    }
}
