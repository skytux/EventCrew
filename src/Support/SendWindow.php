<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The hours of the day the scheduled notifications are allowed out.
 *
 * The heartbeat runs hourly, and until this existed a message went the moment
 * its condition became true - so a task starting at 03:00 had its day-before
 * reminder delivered at 03:00, and an event crossing a lead time at midnight
 * mailed the whole crew at midnight. Nothing here is urgent to the minute; it
 * is urgent to the day.
 *
 * One setting rather than two: the window opens at the chosen hour and runs for
 * twelve, so a 9 means "somewhere between breakfast and the evening" without
 * anyone having to describe both ends of it. Notifications that answer
 * something a person just did - a signup confirmation - are not scheduled and
 * do not come through here; they go immediately, which is the whole point of
 * them. A freed slot is told to the whole crew, so it is queued and waits for
 * the window.
 *
 * The organizers' own scheduled emails (the newsletter and post-event email
 * previews, the monthly report, the role-conditions notice) wait for the
 * window only when ORGANIZER_ANY_HOUR_OPTION is off (Owner, 2026-11-11: they
 * should go out regardless of time). It is on by default.
 */
final class SendWindow
{
    /** The hour the window opens, 0-23. */
    public const OPTION = 'eventcrew_notify_hour';

    public const DEFAULT_HOUR = 9;

    /**
     * '1' or '0', on when unset: the organizer-only scheduled messages go out
     * as soon as they are due, at any hour.
     */
    public const ORGANIZER_ANY_HOUR_OPTION = 'eventcrew_organizer_mail_any_hour';

    /** How long the window stays open. */
    private const LENGTH_HOURS = 12;

    public static function hour(): int
    {
        $hour = (int) get_option(self::OPTION, self::DEFAULT_HOUR);

        return $hour >= 0 && $hour <= 23 ? $hour : self::DEFAULT_HOUR;
    }

    /** Whether the organizer-only scheduled messages ignore the window. */
    public static function organizersAnyHour(): bool
    {
        return (bool) get_option(self::ORGANIZER_ANY_HOUR_OPTION, true);
    }

    /** Saves ORGANIZER_ANY_HOUR_OPTION. */
    public static function saveOrganizersAnyHour(bool $on): void
    {
        update_option(self::ORGANIZER_ANY_HOUR_OPTION, $on ? '1' : '0');
    }

    /**
     * Whether scheduled sends may go out right now.
     *
     * Times here are the site's own, never UTC: current_time('timestamp')
     * returns a timestamp already offset into the site's timezone, so reading
     * it back with gmdate() gives the wall-clock hour an organizer would
     * recognise. That is WordPress's own idiom for this, and it keeps the whole
     * class to one time function.
     */
    public static function isOpen(?int $now = null): bool
    {
        $current = (int) gmdate('G', $now ?? self::now());

        // Distance from the opening hour, wrapping midnight: an 18:00 window is
        // open until 06:00 without needing a second case.
        return (($current - self::hour() + 24) % 24) < self::LENGTH_HOURS;
    }

    /**
     * When the window next opens, as a timestamp. Used to decide whether
     * holding a message back would mean missing it entirely.
     */
    public static function nextOpening(?int $now = null): int
    {
        $now ??= self::now();

        if (self::isOpen($now)) {
            return $now;
        }

        $hoursUntil = (self::hour() - (int) gmdate('G', $now) + 24) % 24;

        // To the top of that hour, not this many hours from now: the window
        // opens on the hour, whatever the minutes currently are.
        $atTheHour = $now - ((int) gmdate('i', $now) * MINUTE_IN_SECONDS) - (int) gmdate('s', $now);

        return $atTheHour + $hoursUntil * HOUR_IN_SECONDS;
    }

    private static function now(): int
    {
        return (int) current_time('timestamp');
    }
}
