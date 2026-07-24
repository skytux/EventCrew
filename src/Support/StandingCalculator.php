<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\RedemptionRepository;

/**
 * The one place a person's Standing is assembled from storage: their history
 * (for the reputation weighting), their redemptions and any hand-granted bonus
 * (for the credit balance). Every surface - the People list, the door list, the
 * bot's join gate and /me - reads through here, so they can never disagree about
 * where someone stands.
 */
final class StandingCalculator
{
    /** Threshold below which a rated person counts as at-risk. */
    public const THRESHOLD_OPTION = 'eventcrew_reputation_threshold';

    public function __construct(
        private readonly AssignmentRepository $assignments,
        private readonly RedemptionRepository $redemptions,
        private readonly CreditGrantRepository $grants
    ) {
    }

    public function for(int $personId): Standing
    {
        $history = $this->assignments->historyFor($personId);
        $now = (int) strtotime((string) current_time('mysql'));

        $completed = Reputation::completedCount($history);
        $score = Reputation::score($history, $now);
        $level = Reputation::level($completed, $score, $this->threshold());

        $balance = Credits::balance(
            $completed,
            $this->redemptions->countFor($personId),
            $this->grants->sumFor($personId)
        );

        return new Standing($level, $score, $completed, $balance);
    }

    private function threshold(): float
    {
        $value = (float) get_option(self::THRESHOLD_OPTION, Reputation::DEFAULT_THRESHOLD);

        // A misconfigured 0 or a value outside [0,1] would make the gate either
        // never or always fire; fall back to the default rather than trust it.
        if ($value <= 0.0 || $value > 1.0) {
            return Reputation::DEFAULT_THRESHOLD;
        }

        return $value;
    }
}
