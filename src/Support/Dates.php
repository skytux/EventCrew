<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The one place dates and times are turned into human strings, so the timezone
 * handling is decided once rather than re-reasoned at every call.
 *
 * The rule that matters: a stored value is either a bare calendar day or an
 * already-local wall-clock instant, and wp_date does not know which - it moves
 * whatever timestamp it is handed into the site's zone. So neither helper lets
 * it move the source: dayLabel() formats a day at noon UTC (the label can never
 * roll to a neighbouring day), and wallClock() reformats a stored datetime's own
 * digits without any conversion (see the v1.3.1 ticket-issue-time fix).
 */
final class Dates
{
    /**
     * A bare Y-m-d as a short, localized day label like "Sat 1 Aug". Formatted
     * at noon UTC so a far-east or far-west site zone can never nudge it onto the
     * day before or after.
     */
    public static function dayLabel(string $ymd): string
    {
        $timestamp = strtotime($ymd . ' 12:00:00 UTC');

        if (false === $timestamp) {
            return $ymd;
        }

        return function_exists('wp_date')
            ? (string) wp_date('D j M', $timestamp, new \DateTimeZone('UTC'))
            : gmdate('D j M', $timestamp);
    }

    /**
     * A bare Y-m-d written out in full, like "Saturday 1 August 2026", in the
     * current language. Noon UTC for the same reason as dayLabel().
     */
    public static function longDayLabel(string $ymd): string
    {
        $timestamp = strtotime($ymd . ' 12:00:00 UTC');

        if (false === $timestamp) {
            return $ymd;
        }

        return function_exists('wp_date')
            ? (string) wp_date('l j F Y', $timestamp, new \DateTimeZone('UTC'))
            : gmdate('l j F Y', $timestamp);
    }

    /**
     * A date typed in a chat - "2026-08-01", "1.8.2026" or "1.8." / "1.8" - as
     * Y-m-d, or '' when it is not a real date. A day and month without a year
     * take the year that puts it nearest ahead: the current year, unless that
     * lands more than six months back, which means next year's.
     */
    public static function parseUserDate(string $input, string $today): string
    {
        $input = trim($input);

        if (1 === preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})$#', $input, $m)) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (1 === preg_match('#^(\d{1,2})\.(\d{1,2})\.?(\d{4})?$#', $input, $m)) {
            [$day, $month] = [(int) $m[1], (int) $m[2]];
            $year = isset($m[3]) && '' !== $m[3] ? (int) $m[3] : (int) substr($today, 0, 4);

            if (! isset($m[3]) || '' === $m[3]) {
                $sixMonthsBack = gmdate('Y-m-d', (int) strtotime($today . ' 12:00:00 UTC -6 months'));

                if (checkdate($month, $day, $year) && sprintf('%04d-%02d-%02d', $year, $month, $day) < $sixMonthsBack) {
                    ++$year;
                }
            }
        } else {
            return '';
        }

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : '';
    }

    /**
     * A stored wall-clock datetime (as written by current_time('mysql'))
     * reformatted as-is. The digits are already local, so they are never run
     * back through a timezone - doing that moves them by the site's offset.
     */
    public static function wallClock(string $mysqlDatetime, string $format): string
    {
        $when = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysqlDatetime);

        return false === $when ? $mysqlDatetime : $when->format($format);
    }

    /**
     * When someone signed up, like "2 Nov 14:05", or '' when it is not known.
     * The stored value is site-local, so it is read as UTC and written in UTC:
     * the digits stay as stored, and the month name is translated.
     */
    public static function signedUpLabel(string $signedUpAt): string
    {
        $timestamp = '' === $signedUpAt ? false : strtotime($signedUpAt . ' UTC');

        if (false === $timestamp) {
            return '';
        }

        return function_exists('wp_date')
            ? (string) wp_date('j M H:i', $timestamp, new \DateTimeZone('UTC'))
            : gmdate('j M H:i', $timestamp);
    }
}
