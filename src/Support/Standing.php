<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * How one person stands: their reputation level, the score behind it, and their
 * credit balance.
 *
 * A plain readonly carrier so every surface - the People list, the door list,
 * the bot's join gate and /me - reads the same shape from StandingCalculator
 * rather than each re-deriving a level from a raw score.
 */
final class Standing
{
    /** Fewer than the minimum completed tasks: not enough history to judge. */
    public const UNRATED = 'unrated';

    /** Rated and at or above the threshold. */
    public const GOOD = 'good';

    /** Rated and below the threshold. */
    public const AT_RISK = 'at_risk';

    public function __construct(
        public readonly string $level,
        public readonly float $score,
        public readonly int $completedCount,
        public readonly int $creditBalance
    ) {
    }

    public function isAtRisk(): bool
    {
        return self::AT_RISK === $this->level;
    }

    public function isRated(): bool
    {
        return self::UNRATED !== $this->level;
    }

    public function levelLabel(): string
    {
        return match ($this->level) {
            self::GOOD => __('Good standing', 'eventcrew'),
            self::AT_RISK => __('At risk', 'eventcrew'),
            default => __('New', 'eventcrew'),
        };
    }
}
