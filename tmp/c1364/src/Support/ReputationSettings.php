<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The tunable reputation values an organizer sets in Settings: the good-standing
 * threshold and the weight each outcome carries. Reputation itself stays a pure
 * function of a history and these numbers; this is the one place they are read
 * from - and sanitised out of - the options table, so scoring and the "how your
 * score works" explanation always agree on the live figures.
 *
 * Dependency-free on purpose: it only reads options, exactly as StandingCalculator
 * already did for the threshold, so it can be newed wherever it is needed without
 * threading another constructor argument through the container.
 */
final class ReputationSettings
{
    /** Score at or above which a rated member is in good standing. */
    public const THRESHOLD_OPTION = 'eventcrew_reputation_threshold';

    /** Per-outcome weights, stored as whole percentages keyed by status slug. */
    public const WEIGHTS_OPTION = 'eventcrew_reputation_weights';

    /** Completed tasks per earned free-entry credit. */
    public const TASKS_PER_CREDIT_OPTION = 'eventcrew_tasks_per_credit';

    /** Finished tasks before a person is rated rather than "New". */
    public const MIN_RATED_TASKS_OPTION = 'eventcrew_min_rated_tasks';

    /** Days for an outcome to count half as much toward the score. */
    public const HALF_LIFE_OPTION = 'eventcrew_reputation_half_life_days';

    /**
     * The good-standing threshold as a fraction in (0, 1]. A stored value outside
     * that range would make the join gate never or always fire, so fall back to
     * the shipped default rather than trust it.
     */
    public function threshold(): float
    {
        $value = (float) get_option(self::THRESHOLD_OPTION, Reputation::DEFAULT_THRESHOLD);

        if ($value <= 0.0 || $value > 1.0) {
            return Reputation::DEFAULT_THRESHOLD;
        }

        return $value;
    }

    /**
     * Outcome weights for scoring, status => fraction in [0, 1]. Each outcome
     * takes its stored percentage when that is a sane 0..100, otherwise the
     * shipped default for that outcome, so a missing or half-filled option can
     * never break the score.
     *
     * @return array<string, float>
     */
    public function weights(): array
    {
        $stored = get_option(self::WEIGHTS_OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $weights = [];

        foreach (Reputation::defaultWeights() as $status => $default) {
            $percent = $stored[$status] ?? null;

            $weights[$status] = (is_numeric($percent) && $percent >= 0 && $percent <= 100)
                ? (float) $percent / 100.0
                : $default;
        }

        return $weights;
    }

    /** Completed tasks per earned free-entry credit (never below 1). */
    public function tasksPerCredit(): int
    {
        return $this->positiveInt(self::TASKS_PER_CREDIT_OPTION, Credits::TASKS_PER_CREDIT);
    }

    /** Finished tasks before a person is rated (never below 1). */
    public function minRatedTasks(): int
    {
        return $this->positiveInt(self::MIN_RATED_TASKS_OPTION, Reputation::MIN_RATED_TASKS);
    }

    /** Recency half-life in days (never below 1). */
    public function halfLifeDays(): int
    {
        return $this->positiveInt(self::HALF_LIFE_OPTION, Reputation::HALF_LIFE_DAYS);
    }

    /**
     * A stored positive integer option, or the default when it is missing or not
     * a sane value >= 1. Defaulting (rather than clamping to 1) keeps a stray
     * non-integer - a test's blanket get_option stub, say - reading as the
     * shipped value, not as 1.
     */
    private function positiveInt(string $option, int $default): int
    {
        $value = get_option($option, $default);

        if (! is_numeric($value)) {
            return $default;
        }

        $int = (int) $value;

        return $int >= 1 ? $int : $default;
    }

    /**
     * The same weights as whole percentages keyed by status - what the Settings
     * form shows and edits.
     *
     * @return array<string, int>
     */
    public function weightPercents(): array
    {
        $percents = [];

        foreach ($this->weights() as $status => $fraction) {
            $percents[$status] = (int) round($fraction * 100);
        }

        return $percents;
    }

    /**
     * Human label => percent, best to worst - the rows of the "how your score
     * works" table, built from the live weights so the explanation tracks
     * whatever the organizer has configured.
     *
     * @return array<string, int>
     */
    public function outcomeRows(): array
    {
        $percents = $this->weightPercents();
        $rows = [];

        foreach (Reputation::outcomeLabels() as $status => $label) {
            $rows[$label] = $percents[$status] ?? 0;
        }

        return $rows;
    }
}
