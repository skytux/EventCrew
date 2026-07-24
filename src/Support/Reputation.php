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
    /**
     * Finished tasks below which there is too little history to rate - counting
     * every terminal outcome, not just completions, so a run of no-shows or late
     * cancellations rates (and so exposes) a person just as completions do.
     */
    public const MIN_RATED_TASKS = 3;

    /** Score at or above which a rated person is in good standing. */
    public const DEFAULT_THRESHOLD = 0.6;

    /** Days after which an outcome counts for half of a same-day one. */
    public const HALF_LIFE_DAYS = 180;

    /**
     * The shipped weights: only terminal outcomes score, and signed_up and
     * arrived are still in progress so they are left out entirely - neither
     * rewarded nor penalised. These are the defaults an organizer's tuned
     * per-outcome percentages (ReputationSettings) fall back to; the set of
     * scoring statuses is these keys, whatever their configured values.
     *
     * @return array<string, float>
     */
    public static function defaultWeights(): array
    {
        return [
            AssignmentStatus::COMPLETED => 1.0,
            AssignmentStatus::REPLACED => 0.8,
            AssignmentStatus::LATE_CANCEL => 0.4,
            AssignmentStatus::NO_SHOW => 0.0,
        ];
    }

    /**
     * A human label for each scoring outcome, in best-to-worst order and keyed
     * by status - the labels the "how your score works" table pairs with the
     * live weights.
     *
     * @return array<string, string> status => label
     */
    public static function outcomeLabels(): array
    {
        return [
            AssignmentStatus::COMPLETED => __('Completed the task', 'eventcrew'),
            AssignmentStatus::REPLACED => __('Found a replacement', 'eventcrew'),
            AssignmentStatus::LATE_CANCEL => __('Cancelled late', 'eventcrew'),
            AssignmentStatus::NO_SHOW => __('No-show', 'eventcrew'),
        ];
    }

    /**
     * Recency-weighted average outcome in [0, 1]. No scored history yields 0,
     * but the <MIN_RATED_TASKS rule (see level()) means that reads as
     * "unrated", not "worst possible".
     *
     * @param array<int, array{assignment: Assignment, task_date: string}> $history
     * @param array<string, float>|null $weights status => weight in [0,1]; the
     *        shipped defaults when null, or an organizer's tuned values.
     * @param int|null $halfLifeDays Days for an outcome to count half; the
     *        shipped default when null.
     */
    public static function score(array $history, int $nowTs, ?array $weights = null, ?int $halfLifeDays = null): float
    {
        $weights ??= self::defaultWeights();
        $halfLife = null === $halfLifeDays ? self::HALF_LIFE_DAYS : max(1, $halfLifeDays);
        $weightedSum = 0.0;
        $totalWeight = 0.0;

        foreach ($history as $entry) {
            $status = $entry['assignment']->status;

            if (! array_key_exists($status, $weights)) {
                continue;
            }

            $recency = self::recencyWeight($entry['task_date'], $nowTs, $halfLife);
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
     * How many of the history's tasks reached a terminal outcome - completed,
     * replaced, late-cancelled or no-showed. This is what the rating threshold is
     * gated on, so a person is judged on everything they finished, not only the
     * ones they completed.
     *
     * @param array<int, array{assignment: Assignment, task_date: string}> $history
     */
    public static function scoredCount(array $history): int
    {
        $weights = self::defaultWeights();
        $count = 0;

        foreach ($history as $entry) {
            if (array_key_exists($entry['assignment']->status, $weights)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * The standing level: unrated until there is enough finished history, then
     * good or at-risk on either side of the threshold. $ratedCount is the number
     * of terminal outcomes (see scoredCount()), so no-shows and late cancels
     * count toward being rated exactly as completions do.
     */
    public static function level(int $ratedCount, float $score, float $threshold, ?int $minRatedTasks = null): string
    {
        $min = null === $minRatedTasks ? self::MIN_RATED_TASKS : max(1, $minRatedTasks);

        if ($ratedCount < $min) {
            return Standing::UNRATED;
        }

        return $score >= $threshold ? Standing::GOOD : Standing::AT_RISK;
    }

    /**
     * An outcome's weight by age: 1.0 on the day, halving every HALF_LIFE_DAYS.
     * A task with no readable date, or one dated in the future, counts as today.
     */
    private static function recencyWeight(string $taskDate, int $nowTs, int $halfLifeDays = self::HALF_LIFE_DAYS): float
    {
        $taskTs = strtotime($taskDate);

        if (false === $taskTs) {
            return 1.0;
        }

        $daysAgo = max(0.0, ($nowTs - $taskTs) / DAY_IN_SECONDS);

        return 2.0 ** (-$daysAgo / $halfLifeDays);
    }
}
