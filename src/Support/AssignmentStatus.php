<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The lifecycle of a person's commitment to one task.
 *
 * These strings are persisted in the assignments table and fed straight into
 * the reputation weighting, so renaming one is a data migration, not a
 * cosmetic change.
 */
final class AssignmentStatus
{
    /** Signed up, event hasn't happened yet. The starting state. */
    public const SIGNED_UP = 'signed_up';

    /** Turned up on the day, task not yet closed out. */
    public const ARRIVED = 'arrived';

    /** Did the task. The only status that earns credit toward a reward. */
    public const COMPLETED = 'completed';

    /** Couldn't make it but found someone to take the slot. Nearly as good. */
    public const REPLACED = 'replaced';

    /** Cancelled inside the notice period, leaving the slot hard to refill. */
    public const LATE_CANCEL = 'late_cancel';

    /** Simply didn't turn up. The one outcome that scores zero. */
    public const NO_SHOW = 'no_show';

    /** Cancelled with enough notice. Carries no penalty, earns no credit. */
    public const CANCELLED = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::SIGNED_UP,
            self::ARRIVED,
            self::COMPLETED,
            self::REPLACED,
            self::LATE_CANCEL,
            self::NO_SHOW,
            self::CANCELLED,
        ];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    /**
     * Statuses that still occupy a slot in the task. Everything else has freed
     * the slot in practice even though the row stays for the reputation
     * history, so capacity counting must not include them:
     *
     * - `late_cancel` / `cancelled` / `no_show` — the person is not coming.
     * - `replaced` — the person found someone to take their place, and that
     *   replacement signs up as their own assignment; counting both would
     *   double-book the slot and stop the replacement from joining.
     *
     * @return array<int, string>
     */
    public static function occupying(): array
    {
        return [
            self::SIGNED_UP,
            self::ARRIVED,
            self::COMPLETED,
        ];
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::SIGNED_UP => __('Signed up', 'eventcrew'),
            self::ARRIVED => __('Arrived', 'eventcrew'),
            self::COMPLETED => __('Completed', 'eventcrew'),
            self::REPLACED => __('Found a replacement', 'eventcrew'),
            self::LATE_CANCEL => __('Late cancellation', 'eventcrew'),
            self::NO_SHOW => __('No-show', 'eventcrew'),
            self::CANCELLED => __('Cancelled', 'eventcrew'),
            default => $status,
        };
    }
}
