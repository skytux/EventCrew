<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Assignment;
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
        return $this->fromHistory($personId, $this->history($personId));
    }

    /**
     * The standing without assignment $assignmentId and with it: the "before"
     * and "after" of a mark an organizer just set (StandingNotice), from one
     * read of the history.
     *
     * @return array{0: Standing, 1: Standing}
     */
    public function beforeAndAfter(int $personId, int $assignmentId): array
    {
        $history = $this->history($personId);
        $without = array_values(array_filter(
            $history,
            static fn (array $entry): bool => $entry['assignment']->id !== $assignmentId
        ));

        return [$this->fromHistory($personId, $without), $this->fromHistory($personId, $history)];
    }

    /**
     * What-ifs for the helper's facts: the standing now, and with one more
     * finished task of each $statuses dated today, from one read of the
     * history. Keyed 'now' and by status.
     *
     * @param list<string> $statuses AssignmentStatus values
     * @return array<string, Standing>
     */
    public function whatIf(int $personId, array $statuses): array
    {
        $history = $this->history($personId);
        $today = (string) current_time('Y-m-d');
        $result = ['now' => $this->fromHistory($personId, $history)];

        foreach ($statuses as $status) {
            $extra = ['assignment' => new Assignment(0, 0, $personId, $status), 'task_date' => $today];
            $result[$status] = $this->fromHistory($personId, [...$history, $extra]);
        }

        return $result;
    }

    /**
     * @return array<int, array{assignment: \EventCrew\Models\Assignment, task_date: string}>
     */
    private function history(int $personId): array
    {
        // A task someone was put on with "does not count toward free-entry
        // credit" counts for nothing here: not credit, not score, not rating.
        return array_values(array_filter(
            $this->assignments->historyFor($personId),
            static fn (array $entry): bool => $entry['assignment']->countsCredit
        ));
    }

    /**
     * @param array<int, array{assignment: \EventCrew\Models\Assignment, task_date: string}> $history
     */
    private function fromHistory(int $personId, array $history): Standing
    {
        $now = (int) strtotime((string) current_time('mysql'));

        $settings = new ReputationSettings();

        $completed = Reputation::completedCount($history);
        $score = Reputation::score($history, $now, $settings->weights(), $settings->halfLifeDays());
        $scored = Reputation::scoredCount($history);
        // Rated on every finished task, not just completions, so a no-show record
        // is judged; credits below still come from completions alone.
        $level = Reputation::level($scored, $score, $settings->threshold(), $settings->minRatedTasks());

        $balance = Credits::balance(
            $completed,
            $this->redemptions->countFor($personId),
            $this->grants->sumFor($personId),
            $settings->tasksPerCredit()
        );

        return new Standing($level, $score, $completed, $balance, $scored);
    }
}
