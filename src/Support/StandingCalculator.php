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
    /**
     * Threshold below which a rated person counts as at-risk. The canonical name
     * lives on ReputationSettings now; kept here so existing references (Settings,
     * tests) resolve to the same option.
     */
    public const THRESHOLD_OPTION = ReputationSettings::THRESHOLD_OPTION;

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

        $settings = new ReputationSettings();

        $completed = Reputation::completedCount($history);
        $score = Reputation::score($history, $now, $settings->weights());
        // Rated on every finished task, not just completions, so a no-show record
        // is judged; credits below still come from completions alone.
        $level = Reputation::level(Reputation::scoredCount($history), $score, $settings->threshold());

        $balance = Credits::balance(
            $completed,
            $this->redemptions->countFor($personId),
            $this->grants->sumFor($personId)
        );

        return new Standing($level, $score, $completed, $balance);
    }
}
