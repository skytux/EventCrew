<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Models\Assignment;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\Reputation;
use EventCrew\Support\Standing;
use EventCrew\Tests\TestCase;

final class ReputationTest extends TestCase
{
    private const NOW = 1_753_000_000; // a fixed reference "now"

    /**
     * @param array<int, array{0: string, 1: string}> $rows status, task_date
     * @return array<int, array{assignment: Assignment, task_date: string}>
     */
    private function history(array $rows): array
    {
        $history = [];

        foreach ($rows as $i => [$status, $date]) {
            $history[] = [
                'assignment' => new Assignment($i + 1, 1, 1, $status),
                'task_date' => $date,
            ];
        }

        return $history;
    }

    private function today(): string
    {
        return gmdate('Y-m-d', self::NOW);
    }

    private function daysAgo(int $days): string
    {
        return gmdate('Y-m-d', self::NOW - $days * DAY_IN_SECONDS);
    }

    public function testAllCompletionsScoreOne(): void
    {
        $history = $this->history([
            [AssignmentStatus::COMPLETED, $this->today()],
            [AssignmentStatus::COMPLETED, $this->daysAgo(10)],
        ]);

        self::assertSame(1.0, Reputation::score($history, self::NOW));
    }

    public function testPendingStatusesAreIgnored(): void
    {
        // Only the one no-show scores; signed_up and arrived are in progress.
        $history = $this->history([
            [AssignmentStatus::SIGNED_UP, $this->today()],
            [AssignmentStatus::ARRIVED, $this->today()],
            [AssignmentStatus::NO_SHOW, $this->today()],
        ]);

        self::assertSame(0.0, Reputation::score($history, self::NOW));
    }

    public function testRecentOutcomesOutweighOldOnes(): void
    {
        // An old no-show against a fresh completion: the completion dominates,
        // so the score sits well above the flat average of 0.5.
        $history = $this->history([
            [AssignmentStatus::NO_SHOW, $this->daysAgo(720)],
            [AssignmentStatus::COMPLETED, $this->today()],
        ]);

        self::assertGreaterThan(0.9, Reputation::score($history, self::NOW));

        // Flip the ages and the fresh no-show drags it down instead.
        $flipped = $this->history([
            [AssignmentStatus::COMPLETED, $this->daysAgo(720)],
            [AssignmentStatus::NO_SHOW, $this->today()],
        ]);

        self::assertLessThan(0.1, Reputation::score($flipped, self::NOW));
    }

    public function testCustomWeightsChangeTheScore(): void
    {
        // A no-show is worthless by default, but an install that weights it at
        // 100% scores it exactly like a completion.
        $history = $this->history([[AssignmentStatus::NO_SHOW, $this->today()]]);

        self::assertSame(0.0, Reputation::score($history, self::NOW));
        self::assertSame(
            1.0,
            Reputation::score($history, self::NOW, [AssignmentStatus::NO_SHOW => 1.0])
        );
    }

    public function testTooFewFinishedTasksIsUnrated(): void
    {
        // Two finished tasks is below the minimum, so the level is unrated even
        // though the score is a perfect 1.0.
        $level = Reputation::level(2, 1.0, Reputation::DEFAULT_THRESHOLD);

        self::assertSame(Standing::UNRATED, $level);
    }

    public function testScoredCountCountsEveryTerminalOutcome(): void
    {
        // Every finished outcome counts toward being rated - not just the
        // completion - while the two in-progress statuses are left out.
        $history = $this->history([
            [AssignmentStatus::COMPLETED, $this->today()],
            [AssignmentStatus::NO_SHOW, $this->today()],
            [AssignmentStatus::LATE_CANCEL, $this->today()],
            [AssignmentStatus::REPLACED, $this->today()],
            [AssignmentStatus::SIGNED_UP, $this->today()],
            [AssignmentStatus::ARRIVED, $this->today()],
        ]);

        self::assertSame(4, Reputation::scoredCount($history));
        self::assertSame(1, Reputation::completedCount($history));
    }

    public function testThresholdSplitsGoodFromAtRisk(): void
    {
        $threshold = Reputation::DEFAULT_THRESHOLD;

        self::assertSame(Standing::GOOD, Reputation::level(3, $threshold, $threshold));
        self::assertSame(Standing::AT_RISK, Reputation::level(3, $threshold - 0.01, $threshold));
    }

    public function testEmptyHistoryScoresZeroAndIsUnrated(): void
    {
        self::assertSame(0.0, Reputation::score([], self::NOW));
        self::assertSame(0, Reputation::completedCount([]));
        self::assertSame(Standing::UNRATED, Reputation::level(0, 0.0, Reputation::DEFAULT_THRESHOLD));
    }
}
