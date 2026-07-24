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
     * A stored wall-clock datetime (as written by current_time('mysql'))
     * reformatted as-is. The digits are already local, so they are never run
     * back through a timezone - doing that moves them by the site's offset.
     */
    public static function wallClock(string $mysqlDatetime, string $format): string
    {
        $when = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysqlDatetime);

        return false === $when ? $mysqlDatetime : $when->format($format);
    }
}
