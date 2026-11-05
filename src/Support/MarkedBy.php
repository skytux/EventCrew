<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Assignment;
use EventCrew\Repositories\PersonRepository;

/**
 * Who set an assignment's attendance status, and when, as one line for the
 * roster views: "marked by Sam at 21:42".
 *
 * An assignment's changed_by holds an id, and changed_by_kind says which table
 * the id is from: an EventCrew person (the sales desk, the bot, the crew page)
 * or a WordPress user (the wp-admin Roster page). A row with no kind - every
 * row written before schema 16 - cannot say whose id it holds, so its line
 * gives only the time.
 */
final class MarkedBy
{
    /** changed_by is an EventCrew person id. */
    public const PERSON = 'person';

    /** changed_by is a WordPress user id. */
    public const WP_USER = 'wp_user';

    /** The statuses that are an attendance mark, the only ones given a line. */
    private const MARKS = [
        AssignmentStatus::ARRIVED,
        AssignmentStatus::ARRIVED_LATE,
        AssignmentStatus::COMPLETED,
        AssignmentStatus::NO_SHOW,
    ];

    public static function isKind(?string $kind): bool
    {
        return self::PERSON === $kind || self::WP_USER === $kind;
    }

    /**
     * The line for one assignment, or '' when its status is not an attendance
     * mark or has no time recorded.
     */
    public static function line(Assignment $assignment, PersonRepository $people): string
    {
        if (null === $assignment->statusChangedAt) {
            return '';
        }

        // Signed up with someone recorded as the changer: an attendance mark
        // was taken back (the desk marked them out, or the roster reset them).
        if (AssignmentStatus::SIGNED_UP === $assignment->status && null !== $assignment->changedBy) {
            $time = self::time($assignment->statusChangedAt);
            $name = self::name($assignment, $people);

            return '' === $name
                /* translators: %s: time, or date and time, the person was signed out */
                ? sprintf(__('signed out at %s', 'eventcrew'), $time)
                /* translators: 1: name of who signed the person out, 2: time, or date and time, it happened */
                : sprintf(__('signed out by %1$s at %2$s', 'eventcrew'), $name, $time);
        }

        if (! in_array($assignment->status, self::MARKS, true)) {
            return '';
        }

        $time = self::time($assignment->statusChangedAt);
        $name = self::name($assignment, $people);

        if ('' === $name) {
            /* translators: %s: time, or date and time, the attendance was marked */
            return sprintf(__('marked at %s', 'eventcrew'), $time);
        }

        /* translators: 1: name of who marked the attendance, 2: time, or date and time, it was marked */
        return sprintf(__('marked by %1$s at %2$s', 'eventcrew'), $name, $time);
    }

    /**
     * The marker's name, or '' when the kind is unknown or the account is gone.
     */
    private static function name(Assignment $assignment, PersonRepository $people): string
    {
        if (null === $assignment->changedBy) {
            return '';
        }

        if (self::PERSON === $assignment->changedByKind) {
            $person = $people->find($assignment->changedBy);

            return null === $person ? '' : $person->name();
        }

        if (self::WP_USER === $assignment->changedByKind) {
            $user = get_userdata($assignment->changedBy);

            return false === $user ? '' : (string) $user->display_name;
        }

        return '';
    }

    /**
     * "21:42" for a mark made today, "Sat 1 Aug 21:42" for an earlier day.
     * status_changed_at is site-local wall-clock time (current_time('mysql')),
     * so it is read in the site's zone and wp_date writes it back in the same
     * zone, unchanged. Without wp_date (the unit tests) the same zone formats it.
     */
    private static function time(string $mysqlDatetime): string
    {
        try {
            $when = new \DateTimeImmutable($mysqlDatetime, wp_timezone());
        } catch (\Exception) {
            return $mysqlDatetime;
        }

        if (substr($mysqlDatetime, 0, 10) === current_time('Y-m-d')) {
            return function_exists('wp_date')
                ? (string) wp_date('H:i', $when->getTimestamp())
                : $when->format('H:i');
        }

        return Dates::dayTimeLabel($when->getTimestamp(), $when->getTimezone());
    }
}
