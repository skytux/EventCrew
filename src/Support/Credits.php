<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * Free entry earned by working: one credit per two completed tasks, less what
 * has already been spent at the door.
 *
 * A pure function of two counts - no database, no clock - so the rule lives in
 * one testable place and every surface computes the same balance.
 */
final class Credits
{
    /** Completed tasks needed to earn one credit. */
    public const TASKS_PER_CREDIT = 2;

    /**
     * Earned less redeemed, never negative. Redemption is guarded against
     * overspending at the point it is recorded, so a negative here would only
     * come from hand-edited data - clamp it rather than surface nonsense.
     */
    public static function balance(int $completed, int $redeemed): int
    {
        return max(0, intdiv($completed, self::TASKS_PER_CREDIT) - $redeemed);
    }
}
