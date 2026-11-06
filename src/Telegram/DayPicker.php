<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Support\Dates;

/**
 * The date selector under every list that shows one day at a time: the group
 * board and its private copy (BoardRenderer), the list of tasks for the
 * person's roles (RoleTaskBot), the roster (RosterService), the ticket and
 * guest lists of the sales desk (DoorListService, GuestListService), and the
 * date bar of the crew page's Admin tab (CrewAdminRoster).
 *
 * One row of three: [◀ previous day] [📅 shown day ✓] [next day ▶]. The
 * arrows step to the nearest day before and after the shown one that the
 * person may see, and an arrow is left out at either end. Past days are
 * reached only by stepping back. The middle button opens the shown day again,
 * so it refreshes the list. With no other day there is no row. A list opens on the next day with something on
 * it, today included (defaultDate()).
 */
final class DayPicker
{
    /** The mark after the day that is shown. */
    public const MARK = ' ✓';

    /**
     * The selector row for $shown. No row when no day is shown, or when
     * there is no other day to step to: the list's heading already names
     * its only day.
     *
     * @param array<int, string>          $dates   Every day there is, Y-m-d, any order.
     * @param string                      $shown   The day shown now, Y-m-d.
     * @param string                      $prefix  Callback data before the date, e.g. "bd:".
     * @param (callable(string): bool)|null $canView Which days the person may open; null = all.
     * @return array<int, array<int, array{text: string, callback_data: string}>>
     */
    public static function rows(array $dates, string $shown, string $prefix, ?callable $canView = null): array
    {
        if ('' === $shown) {
            return [];
        }

        $steps = self::steps($dates, $shown, $canView);

        if ('' === $steps['prev'] && '' === $steps['next']) {
            return [];
        }

        $row = [];

        if ('' !== $steps['prev']) {
            $row[] = ['text' => '◀ ' . Dates::dayLabel($steps['prev']), 'callback_data' => $prefix . $steps['prev']];
        }

        $row[] = ['text' => self::label($shown), 'callback_data' => $prefix . $shown];

        if ('' !== $steps['next']) {
            $row[] = ['text' => Dates::dayLabel($steps['next']) . ' ▶', 'callback_data' => $prefix . $steps['next']];
        }

        return [$row];
    }

    /**
     * The nearest day before and after $shown that the person may see, ''
     * at either end. Days are checked outward from $shown and the search stops
     * at the first one allowed, so $canView runs only as often as it must.
     *
     * @param array<int, string>          $dates Y-m-d, any order
     * @param (callable(string): bool)|null $canView null = every day
     * @return array{prev: string, next: string}
     */
    public static function steps(array $dates, string $shown, ?callable $canView = null): array
    {
        $dates = array_values(array_unique($dates));
        sort($dates);
        $allowed = $canView ?? static fn (string $d): bool => true;
        $prev = '';
        $next = '';

        foreach (array_reverse(array_filter($dates, static fn (string $d): bool => $d < $shown)) as $date) {
            if ($allowed($date)) {
                $prev = $date;
                break;
            }
        }

        foreach (array_filter($dates, static fn (string $d): bool => $d > $shown) as $date) {
            if ($allowed($date)) {
                $next = $date;
                break;
            }
        }

        return ['prev' => $prev, 'next' => $next];
    }

    /** The middle button: the shown day as the messages write it, marked: "📅 Sat 8 Nov ✓". */
    public static function label(string $ymd): string
    {
        return '📅 ' . Dates::dayLabel($ymd) . self::MARK;
    }

    /**
     * The day a list opens on: today when it has something, otherwise the
     * next day that does, or the latest past day when none is ahead. '' when
     * there are no days.
     *
     * @param array<int, string> $dates Y-m-d, any order
     */
    public static function defaultDate(array $dates, string $today): string
    {
        $upcoming = array_filter($dates, static fn (string $d): bool => $d >= $today);

        if ([] !== $upcoming) {
            return (string) min($upcoming);
        }

        return [] === $dates ? '' : (string) max($dates);
    }
}
