<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Task;

/**
 * How long a holder has to answer a takeover request.
 *
 * The answer time depends on how far away the task is when the request is
 * made, and the deadline is never later than a set time before the task
 * starts. Every number is a setting on the EventCrew settings page.
 *
 * Times here are site-local wall clock (like current_time('mysql')) turned
 * into seconds as if they were UTC. Only differences and comparisons are
 * used, so this keeps the arithmetic consistent and the digits unchanged.
 */
final class TakeoverDeadline
{
    /** Below this many hours before the start, the "near" answer time applies. */
    public const NEAR_HOURS_OPTION = 'eventcrew_takeover_near_hours';

    /** Answer time, in hours, for a request made inside the "near" window. */
    public const NEAR_ANSWER_OPTION = 'eventcrew_takeover_near_answer_hours';

    /** Below this many days before the start, the "week" answer time applies. */
    public const WEEK_DAYS_OPTION = 'eventcrew_takeover_week_days';

    /** Answer time, in hours, for a request made inside the "week" window. */
    public const WEEK_ANSWER_OPTION = 'eventcrew_takeover_week_answer_hours';

    /** Answer time, in hours, for a request made further ahead. */
    public const FAR_ANSWER_OPTION = 'eventcrew_takeover_far_answer_hours';

    /** The deadline is never later than this many hours before the start. */
    public const CUTOFF_HOURS_OPTION = 'eventcrew_takeover_cutoff_hours';

    public const DEFAULT_NEAR_HOURS = 48;
    public const DEFAULT_NEAR_ANSWER = 24;
    public const DEFAULT_WEEK_DAYS = 7;
    public const DEFAULT_WEEK_ANSWER = 48;
    public const DEFAULT_FAR_ANSWER = 72;
    public const DEFAULT_CUTOFF_HOURS = 1;

    /**
     * The answer deadline as a timestamp, or null when the latest allowed
     * deadline (the cutoff before the start) has already passed.
     */
    public static function deadline(int $now, int $start): ?int
    {
        $cap = $start - self::cutoffHours() * HOUR_IN_SECONDS;

        if ($cap <= $now) {
            return null;
        }

        $left = $start - $now;

        if ($left < self::nearHours() * HOUR_IN_SECONDS) {
            $answer = self::nearAnswerHours();
        } elseif ($left < self::weekDays() * DAY_IN_SECONDS) {
            $answer = self::weekAnswerHours();
        } else {
            $answer = self::farAnswerHours();
        }

        return min($now + $answer * HOUR_IN_SECONDS, $cap);
    }

    /**
     * When the task starts. A task with no start time counts from the start
     * of its day, the same as the late-cancel window does.
     */
    public static function startOf(Task $task): int
    {
        $start = null === $task->startsAt || '' === $task->startsAt
            ? $task->taskDate . ' 00:00:00'
            : $task->startsAt;

        return self::toTimestamp($start);
    }

    /** The current site-local time as a timestamp. */
    public static function now(): int
    {
        return self::toTimestamp((string) current_time('mysql'));
    }

    public static function toTimestamp(string $mysql): int
    {
        $when = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $mysql, new \DateTimeZone('UTC'));

        if (false === $when) {
            $when = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($mysql, 0, 10), new \DateTimeZone('UTC'));
        }

        return false === $when ? 0 : $when->getTimestamp();
    }

    public static function toMysql(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * A stored deadline for people to read, like "Sat 1 Aug 18:00". The
     * digits stay as stored; the day and month names are translated.
     */
    public static function label(string $mysql): string
    {
        return Dates::dayTimeLabel(self::toTimestamp($mysql));
    }

    public static function nearHours(): int
    {
        return self::option(self::NEAR_HOURS_OPTION, self::DEFAULT_NEAR_HOURS, 0);
    }

    public static function nearAnswerHours(): int
    {
        return self::option(self::NEAR_ANSWER_OPTION, self::DEFAULT_NEAR_ANSWER, 1);
    }

    public static function weekDays(): int
    {
        return self::option(self::WEEK_DAYS_OPTION, self::DEFAULT_WEEK_DAYS, 0);
    }

    public static function weekAnswerHours(): int
    {
        return self::option(self::WEEK_ANSWER_OPTION, self::DEFAULT_WEEK_ANSWER, 1);
    }

    public static function farAnswerHours(): int
    {
        return self::option(self::FAR_ANSWER_OPTION, self::DEFAULT_FAR_ANSWER, 1);
    }

    public static function cutoffHours(): int
    {
        return self::option(self::CUTOFF_HOURS_OPTION, self::DEFAULT_CUTOFF_HOURS, 0);
    }

    private static function option(string $name, int $default, int $minimum): int
    {
        $value = get_option($name, $default);

        return max($minimum, is_numeric($value) ? (int) $value : $default);
    }
}
