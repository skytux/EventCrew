<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Assignment;

/**
 * How reliably a person has turned up, from their assignment history.
 *
 * A pure function of the history and a reference "now" - no database, no clock
 * of its own - so the weighting is testable without a WordPress in the way, the
 * same reason TaskTemplate is pure. The composing StandingCalculator feeds it
 * the rows and the threshold and turns the result into a Standing.
 *
 * Two ideas: outcomes are weighted (a completion is worth more than a late
 * cancellation, a no-show nothing), and recent outcomes count for more than old
 * ones, so a rocky start fades as someone becomes reliable - and a good record
 * does not excuse a fresh string of no-shows.
 */
final class Reputation
{
    /** Completed tasks below which there is too little history to rate. */
    public const MIN_RATED_COMPLETED = 3;

    /** Score at or above which a rated person is in good standing. */
    public const DEFAULT_THRESHOLD = 0.6;

    /** Days after which an outcome counts for half of a same-day one. */
    private const HALF_LIFE_DAYS = 180;

    /**
     * Only terminal outcomes score. signed_up and arrived are still in
     * progress and are left out entirely - neither rewarded nor penalised.
     *
     * @return array<string, float>
     */
    private static function weights(): array
    {
        return [
            AssignmentStatus::COMPLETED => 1.0,
            AssignmentStatus::REPLACED => 0.8,
            AssignmentStatus::LATE_CANCEL => 0.4,
            AssignmentStatus::NO_SHOW => 0.0,
        ];
    }

    /**
     * The same weights as whole percentages against a human label for each
     * outcome, in best-to-worst order - the single source of truth behind the
     * "how your score works" table shown in /me and on the web profile. Kept
     * here so the explanation can never drift from the scoring.
     *
     * @return array<string, int> label => percent
     */
    public static function outcomeWeights(): array
    {
        return [
            __('Completed the task', 'eventcrew') => 100,
            __('Found a replacement', 'eventcrew') => 80,
            __('Cancelled late', 'eventcrew') => 40,
            __('No-show', 'eventcrew') => 0,
        ];
    }

    /**
     * Recency-weighted average outcome in [0, 1]. No scored history yields 0,
     * but the <MIN_RATED_COMPLETED rule (see level()) means that reads as
     * "unrated", not "worst possible".
     *
     * @param array<int, array{assignment: Assignment, task_date: string}> $history
     */
    public static function score(array $history, int $nowTs): float
    {
        $weights = self::weights();
        $weightedSum = 0.0;
        $totalWeight = 0.0;

        foreach ($history as $entry) {
            $status = $entry['assignment']->status;

            if (! array_key_exists($status, $weights)) {
                continue;
            }

            $recency = self::recencyWeight($entry['task_date'], $nowTs);
            $weightedSum += $weights[$status] * $recency;
            $totalWeight += $recency;
        }

        return $totalWeight > 0.0 ? $weightedSum / $totalWeight : 0.0;
    }

    /**
     * How many of the history's tasks were completed - the count the rating
     * threshold is gated on.
     *
     * @param array<int, array{assignment: Assignment, task_date: string}> $history
     */
    public static function completedCount(array $history): int
    {
        $count = 0;

        foreach ($history as $entry) {
            if ($entry['assignment']->isCompleted()) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * The standing level: unrated until there is enough history, then good or
     * at-risk on either side of the threshold.
     */
    public static function level(int $completedCount, float $score, float $threshold): string
    {
        if ($completedCount < self::MIN_RATED_COMPLETED) {
            return Standing::UNRATED;
        }

        return $score >= $threshold ? Standing::GOOD : Standing::AT_RISK;
    }

    /**
     * An outcome's weight by age: 1.0 on the day, halving every HALF_LIFE_DAYS.
     * A task with no readable date, or one dated in the future, counts as today.
     */
    private static function recencyWeight(string $taskDate, int $nowTs): float
    {
        $taskTs = strtotime($taskDate);

        if (false === $taskTs) {
            return 1.0;
        }

        $daysAgo = max(0.0, ($nowTs - $taskTs) / DAY_IN_SECONDS);

        return 2.0 ** (-$daysAgo / self::HALF_LIFE_DAYS);
    }
}
