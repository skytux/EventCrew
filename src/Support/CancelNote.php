<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Task;

/**
 * The sentences that tell a person what their own cancel means for their
 * standing: why an early cancel has no effect, and how a late cancel can be
 * undone (Sign up again puts them back on the task, and the row is then no
 * longer a late cancellation). One source for the bot's Cancel question and
 * answer, the web page's Cancel question, and the cancel DM and email.
 */
final class CancelNote
{
    /** The late-cancellation notice in hours (Settings), as SignupService reads it. */
    public static function noticeHours(): int
    {
        return max(0, (int) get_option(SignupService::NOTICE_HOURS_OPTION, 48));
    }

    /**
     * Why a cancel that is not late has no effect on the standing. With a
     * task that starts at least the notice hours from now (or no task, as in
     * a preview): it is an early cancellation, more than N hours before the
     * task. A task closer than that was cancelled free inside the second
     * thoughts window: taken only minutes ago. With a notice of 0 hours, or a
     * task whose start cannot be read: only that it has no effect.
     */
    public static function early(?Task $task = null): string
    {
        $hours = self::noticeHours();
        $left = null === $task ? null : self::secondsToStart($task);

        if (0 === $hours || (null !== $task && null === $left)) {
            return __('It has no effect on your standing.', 'eventcrew');
        }

        if (null !== $task && ! self::isEarly($task)) {
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            return __('You took this task only minutes ago, so cancelling it has no effect on your standing.', 'eventcrew');
        }

        // phpcs:disable Generic.Files.LineLength.TooLong -- single gettext literals; splitting them breaks extraction.
        return sprintf(
            /* translators: %d: the late-cancellation notice in hours */
            _n(
                'This is an early cancellation: it is more than %d hour before the task, so it has no effect on your standing.',
                'This is an early cancellation: it is more than %d hours before the task, so it has no effect on your standing.',
                $hours,
                'eventcrew'
            ),
            $hours
        );
        // phpcs:enable Generic.Files.LineLength.TooLong
    }

    /**
     * Whether a cancel now is early: the notice is above 0 hours and the
     * task starts at least that many hours from now. False when its start
     * cannot be read.
     */
    public static function isEarly(Task $task): bool
    {
        $hours = self::noticeHours();
        $left = self::secondsToStart($task);

        return $hours > 0 && null !== $left && $left >= $hours * HOUR_IN_SECONDS;
    }

    /** Under a late-cancel confirmation that carries the Sign up again button. */
    public static function undo(): string
    {
        // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
        return __('Changed your mind? Tap Sign up again while the place is still free: you are back on the task and the late cancellation is removed.', 'eventcrew');
    }

    /** The short form, in the bot's answer to Yes, cancel, where there is no button. */
    public static function undoShort(): string
    {
        // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
        return __('Sign up for it again while the place is free and the late cancellation is removed.', 'eventcrew');
    }

    /**
     * Seconds from now to the task's start, the same way the cancel itself
     * counts them (AssignmentRepository): an untimed task from 00:00 of its
     * date. Null when the start cannot be read.
     */
    private static function secondsToStart(Task $task): ?int
    {
        $when = null !== $task->startsAt && '' !== $task->startsAt ? $task->startsAt : $task->taskDate . ' 00:00:00';
        $start = strtotime($when);
        $now = strtotime((string) current_time('mysql'));

        return false === $start || false === $now ? null : $start - $now;
    }
}
