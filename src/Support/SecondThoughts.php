<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The second thoughts window (Settings, default 5 minutes, 0 = off).
 *
 * A person who takes or cancels a task sees it at once everywhere: the board,
 * the rosters, the counts and the door. What the take or cancel would send -
 * their email and DM, the "a spot opened" call, the ticket email - waits until
 * the window has passed, and then only what is still true is sent
 * (ClaimNotifier::afterSecondThoughts()). Take then cancel inside the window,
 * or cancel then take again: nothing is sent. A cancel inside the window after
 * a take is never a late cancellation (SignupService::drop()).
 *
 * The window is one WP-Cron single event per person and task, whose arguments
 * carry whether the person held the task before it opened. It is open while
 * that event is still waiting, so it runs until WP-Cron runs the event: with
 * a pinger opening wp-cron.php every 5 minutes, a 5-minute window can last up
 * to about 10.
 */
final class SecondThoughts
{
    public const OPTION = 'eventcrew_second_thoughts_minutes';

    public const DEFAULT = 5;

    public const HOOK = 'eventcrew_second_thoughts';

    /** The window in minutes, from the setting; 0 = off. */
    public static function minutes(): int
    {
        return max(0, (int) get_option(self::OPTION, self::DEFAULT));
    }

    /**
     * Whether a window is open for this person and task, and how it started:
     * true when they held the task before it opened (the window opened on a
     * cancel), false when they did not (it opened on a take), null when no
     * window is open.
     */
    public static function opened(int $personId, int $taskId): ?bool
    {
        foreach ([1, 0] as $heldBefore) {
            if (false !== wp_next_scheduled(self::HOOK, [$personId, $taskId, $heldBefore])) {
                return 1 === $heldBefore;
            }
        }

        return null;
    }

    /**
     * Whether this person took this task less than the window ago: a window
     * opened on a take is waiting and its time has not come yet. Unlike
     * opened(), this ends on the minute, not when WP-Cron runs the event. A
     * cancel then gets through the sign-out lock (SignupService::drop()).
     */
    public static function justTaken(int $personId, int $taskId): bool
    {
        if (false !== self::opened($personId, $taskId)) {
            return false;
        }

        $at = wp_next_scheduled(self::HOOK, [$personId, $taskId, 0]);

        return false !== $at && (int) $at > time();
    }

    /**
     * Holds back the messages of a take ($heldBefore false) or a cancel
     * ($heldBefore true). True when they wait for the window - a window was
     * open already, or one is opened now - and false when they should be sent
     * now (the window is off, or WP-Cron would not store the event).
     */
    public static function hold(int $personId, int $taskId, bool $heldBefore): bool
    {
        if (null !== self::opened($personId, $taskId)) {
            return true;
        }

        $minutes = self::minutes();

        if (0 === $minutes) {
            return false;
        }

        // When the event cannot be stored, the messages go now rather than
        // never.
        return true === wp_schedule_single_event(
            time() + $minutes * MINUTE_IN_SECONDS,
            self::HOOK,
            [$personId, $taskId, $heldBefore ? 1 : 0]
        );
    }
}
