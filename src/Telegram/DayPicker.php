<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Support\Dates;

/**
 * The row of day buttons under a list that shows one day at a time: the
 * group board and its private copy (BoardRenderer), and the list of tasks for
 * the person's roles (RoleTaskBot).
 *
 * The same days in the same places every time, in date order, whichever day
 * is shown; the shown day is marked "▸". There is no separate "back" button:
 * the next day is simply the first button. With only one day there is
 * nothing to pick, so there is no row at all.
 */
final class DayPicker
{
    /** How many days are offered, and how many buttons per row. */
    public const DATES = 6;
    private const PER_ROW = 3;

    /** The mark in front of the day that is shown. */
    public const MARK = '▸ ';

    /**
     * @param array<int, string> $dates  Every day there is, Y-m-d, ascending.
     * @param string             $shown  The day shown now, Y-m-d.
     * @param string             $prefix Callback data before the date, e.g. "bd:".
     * @param string             $spacer Callback data of the heading row (does nothing).
     * @return array<int, array<int, array<string, string>>>
     */
    public static function rows(array $dates, string $shown, string $prefix, string $spacer): array
    {
        $dates = array_values(array_unique($dates));
        sort($dates);

        if (count($dates) < 2) {
            return [];
        }

        $offered = array_slice($dates, 0, self::DATES);

        // A day picked from further on (an old message) stays reachable and marked.
        if (in_array($shown, $dates, true) && ! in_array($shown, $offered, true)) {
            $offered[self::DATES - 1] = $shown;
        }

        $buttons = array_map(
            static fn (string $d): array => [
                'text' => ($d === $shown ? self::MARK : '') . Dates::dayLabel($d),
                'callback_data' => $prefix . $d,
            ],
            $offered
        );

        return [
            [['text' => __('🗓 Dates', 'eventcrew'), 'callback_data' => $spacer]],
            ...array_chunk($buttons, self::PER_ROW),
        ];
    }
}
