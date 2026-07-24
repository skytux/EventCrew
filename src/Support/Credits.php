<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * Free entry earned by working: one credit per two completed tasks, plus any
 * hand-granted bonus, less what has already been spent at the door.
 *
 * A pure function of three counts - no database, no clock - so the rule lives in
 * one testable place and every surface computes the same balance.
 */
final class Credits
{
    /** Completed tasks needed to earn one credit. */
    public const TASKS_PER_CREDIT = 2;

    /**
     * Earned plus granted less redeemed, never negative. Redemption is guarded
     * against overspending at the point it is recorded, so a negative here would
     * only come from hand-edited data - clamp it rather than surface nonsense.
     */
    public static function balance(int $completed, int $redeemed, int $granted = 0): int
    {
        return max(0, intdiv($completed, self::TASKS_PER_CREDIT) + $granted - $redeemed);
    }
}
