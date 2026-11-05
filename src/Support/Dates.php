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
 *
 * Day and month names follow Language, not WordPress. Language switches only
 * the eventcrew text domain, so WordPress's own day and month names
 * ($wp_locale, which wp_date and date_i18n use) stay in the site's core
 * language - English on a site whose Finnish comes from Polylang. So inside a
 * Language::run() format() writes the date itself, with the names and the
 * layout from the plugin's own translations ("la 14.11." in Finnish). Nothing
 * global is changed, so nothing can leak into the next person's message.
 * Outside any run() - wp-admin - wp_date writes it, as before.
 */
final class Dates
{
    /**
     * A bare Y-m-d as a short, localized day label like "Sat 1 Aug" ("la 1.8."
     * in Finnish). Formatted at noon UTC so a far-east or far-west site zone can
     * never nudge it onto the day before or after.
     */
    public static function dayLabel(string $ymd): string
    {
        /* translators: PHP date() format of a short day label, like "Sat 1 Aug". */
        return self::formatDay($ymd, _x('D j M', 'date format', 'eventcrew'));
    }

    /**
     * A bare Y-m-d as a heading without the year, like "Saturday 1 August"
     * ("lauantai 1. elokuuta" in Finnish). Noon UTC for the same reason as
     * dayLabel().
     */
    public static function dayHeading(string $ymd): string
    {
        /* translators: PHP date() format of a day heading without the year, like "Saturday 1 August". */
        return self::formatDay($ymd, _x('l j F', 'date format', 'eventcrew'));
    }

    /**
     * A bare Y-m-d written out in full, like "Saturday 1 August 2026", in the
     * current language. Noon UTC for the same reason as dayLabel().
     */
    public static function longDayLabel(string $ymd): string
    {
        /* translators: PHP date() format of a full date, like "Saturday 1 August 2026". */
        return self::formatDay($ymd, _x('l j F Y', 'date format', 'eventcrew'));
    }

    /**
     * A day and a time, like "Sat 1 Aug 18:00" ("la 1.8. 18:00" in Finnish),
     * written in $zone (UTC when none).
     */
    public static function dayTimeLabel(int $timestamp, ?\DateTimeZone $zone = null): string
    {
        /* translators: PHP date() format of a day and a time, like "Sat 1 Aug 18:00". */
        return self::format(_x('D j M H:i', 'date format', 'eventcrew'), $timestamp, $zone);
    }

    /**
     * $timestamp in the PHP date() $format, written in $zone (UTC when none).
     *
     * Inside a Language::run() the day and month names (D, l, M, F) are the
     * plugin's own, in that language, and every other letter is PHP's. A month
     * written out in full (F) takes the form that follows a day number, which
     * in Finnish is not the plain name ("1. elokuuta", not "elokuu"). Outside
     * a run wp_date writes it with WordPress's names; without WordPress (the
     * unit tests) PHP does.
     */
    public static function format(string $format, int $timestamp, ?\DateTimeZone $zone = null): string
    {
        $zone ??= new \DateTimeZone('UTC');

        if (null === Language::current() && function_exists('wp_date')) {
            return (string) wp_date($format, $timestamp, $zone);
        }

        $when = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($zone);

        if (null === Language::current()) {
            return $when->format($format);
        }

        $out = '';
        $length = strlen($format);

        for ($i = 0; $i < $length; $i++) {
            $char = $format[$i];

            if ('\\' === $char) {
                ++$i;
                $out .= $format[$i] ?? '';
                continue;
            }

            $out .= match ($char) {
                'D' => self::weekdayShort((int) $when->format('w')),
                'l' => self::weekday((int) $when->format('w')),
                'M' => self::monthShort((int) $when->format('n')),
                'F' => self::monthInDate((int) $when->format('n')),
                default => $when->format($char),
            };
        }

        return $out;
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
     * Inside a Language::run() the names are in that language (format()).
     */
    public static function wallClock(string $mysqlDatetime, string $format): string
    {
        $when = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysqlDatetime, new \DateTimeZone('UTC'));

        if (false === $when) {
            return $mysqlDatetime;
        }

        // Read as UTC and written in UTC, so the digits stay as stored.
        return null === Language::current() ? $when->format($format) : self::format($format, $when->getTimestamp());
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

        /* translators: PHP date() format of when someone signed up, like "2 Nov 14:05". */
        return self::format(_x('j M H:i', 'date format', 'eventcrew'), $timestamp);
    }

    /**
     * A bare Y-m-d at noon UTC in $format, or the Y-m-d itself when it is not
     * a date.
     */
    private static function formatDay(string $ymd, string $format): string
    {
        $timestamp = strtotime($ymd . ' 12:00:00 UTC');

        return false === $timestamp ? $ymd : self::format($format, $timestamp);
    }

    private static function weekdayShort(int $day): string
    {
        return match ($day) {
            0 => _x('Sun', 'short weekday name', 'eventcrew'),
            1 => _x('Mon', 'short weekday name', 'eventcrew'),
            2 => _x('Tue', 'short weekday name', 'eventcrew'),
            3 => _x('Wed', 'short weekday name', 'eventcrew'),
            4 => _x('Thu', 'short weekday name', 'eventcrew'),
            5 => _x('Fri', 'short weekday name', 'eventcrew'),
            default => _x('Sat', 'short weekday name', 'eventcrew'),
        };
    }

    private static function weekday(int $day): string
    {
        return match ($day) {
            0 => _x('Sunday', 'weekday name', 'eventcrew'),
            1 => _x('Monday', 'weekday name', 'eventcrew'),
            2 => _x('Tuesday', 'weekday name', 'eventcrew'),
            3 => _x('Wednesday', 'weekday name', 'eventcrew'),
            4 => _x('Thursday', 'weekday name', 'eventcrew'),
            5 => _x('Friday', 'weekday name', 'eventcrew'),
            default => _x('Saturday', 'weekday name', 'eventcrew'),
        };
    }

    private static function monthShort(int $month): string
    {
        return match ($month) {
            1 => _x('Jan', 'short month name', 'eventcrew'),
            2 => _x('Feb', 'short month name', 'eventcrew'),
            3 => _x('Mar', 'short month name', 'eventcrew'),
            4 => _x('Apr', 'short month name', 'eventcrew'),
            5 => _x('May', 'short month name', 'eventcrew'),
            6 => _x('Jun', 'short month name', 'eventcrew'),
            7 => _x('Jul', 'short month name', 'eventcrew'),
            8 => _x('Aug', 'short month name', 'eventcrew'),
            9 => _x('Sep', 'short month name', 'eventcrew'),
            10 => _x('Oct', 'short month name', 'eventcrew'),
            11 => _x('Nov', 'short month name', 'eventcrew'),
            default => _x('Dec', 'short month name', 'eventcrew'),
        };
    }

    /** The month in full as it follows a day number: "1 August", in Finnish "1. elokuuta". */
    private static function monthInDate(int $month): string
    {
        return match ($month) {
            1 => _x('January', 'month name after a day number', 'eventcrew'),
            2 => _x('February', 'month name after a day number', 'eventcrew'),
            3 => _x('March', 'month name after a day number', 'eventcrew'),
            4 => _x('April', 'month name after a day number', 'eventcrew'),
            5 => _x('May', 'month name after a day number', 'eventcrew'),
            6 => _x('June', 'month name after a day number', 'eventcrew'),
            7 => _x('July', 'month name after a day number', 'eventcrew'),
            8 => _x('August', 'month name after a day number', 'eventcrew'),
            9 => _x('September', 'month name after a day number', 'eventcrew'),
            10 => _x('October', 'month name after a day number', 'eventcrew'),
            11 => _x('November', 'month name after a day number', 'eventcrew'),
            default => _x('December', 'month name after a day number', 'eventcrew'),
        };
    }
}
