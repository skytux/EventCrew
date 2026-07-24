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

    /**
     * The reputation score as a whole percentage, e.g. 82. Only meaningful once
     * rated; on an unrated person it is 0 and should not be shown (see
     * ratedSummary(), which hides it).
     */
    public function scorePercent(): int
    {
        return (int) round($this->score * 100);
    }

    /**
     * The level label with the score appended once there is enough history to
     * rate it - "Good standing · 82%" - or just "New" while unrated, so a bare
     * "0%" is never shown to someone who simply hasn't worked enough yet.
     */
    public function ratedSummary(): string
    {
        if (! $this->isRated()) {
            return $this->levelLabel();
        }

        return sprintf(
            /* translators: 1: standing level, 2: reputation score percentage */
            __('%1$s · %2$d%%', 'eventcrew'),
            $this->levelLabel(),
            $this->scorePercent()
        );
    }
}
