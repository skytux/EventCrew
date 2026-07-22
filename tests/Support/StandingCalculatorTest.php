<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\Standing;
use EventCrew\Support\StandingCalculator;
use EventCrew\Tests\TestCase;

final class StandingCalculatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The threshold option; the default is fine for these cases.
        Functions\when('get_option')->justReturn(0.6);
    }

    private function calculator(): StandingCalculator
    {
        return new StandingCalculator(
            new AssignmentRepository(),
            new RedemptionRepository()
        );
    }

    /**
     * @param array<int, string> $statuses
     */
    private function queueHistory(array $statuses, string $date = '2026-07-20'): void
    {
        $rows = [];

        foreach ($statuses as $i => $status) {
            $rows[] = [
                'id' => $i + 1,
                'task_id' => $i + 1,
                'person_id' => 9,
                'status' => $status,
                'task_date' => $date,
            ];
        }

        // AssignmentRepository::historyFor() reads via get_results.
        $this->wpdb->nextResults[] = $rows;
    }

    public function testComposesReputationAndCreditsIntoOneStanding(): void
    {
        // Four completions today: rated, perfect score, two credits earned.
        $this->queueHistory(array_fill(0, 4, AssignmentStatus::COMPLETED));
        $this->wpdb->nextVars[] = 1; // countFor: one credit already redeemed

        $standing = $this->calculator()->for(9);

        self::assertSame(Standing::GOOD, $standing->level);
        self::assertSame(4, $standing->completedCount);
        self::assertSame(1, $standing->creditBalance); // floor(4/2) - 1
        self::assertSame(1.0, $standing->score);
    }

    public function testRecentNoShowsPushARatedPersonToAtRisk(): void
    {
        // Three completions (enough to rate) but three no-shows drag the
        // weighted score to 0.5, under the 0.6 threshold.
        $this->queueHistory([
            AssignmentStatus::COMPLETED,
            AssignmentStatus::COMPLETED,
            AssignmentStatus::COMPLETED,
            AssignmentStatus::NO_SHOW,
            AssignmentStatus::NO_SHOW,
            AssignmentStatus::NO_SHOW,
        ]);
        $this->wpdb->nextVars[] = 0;

        $standing = $this->calculator()->for(9);

        self::assertSame(Standing::AT_RISK, $standing->level);
        self::assertTrue($standing->isAtRisk());
    }

    public function testTooLittleHistoryIsUnrated(): void
    {
        $this->queueHistory([AssignmentStatus::COMPLETED, AssignmentStatus::COMPLETED]);
        $this->wpdb->nextVars[] = 0;

        self::assertSame(Standing::UNRATED, $this->calculator()->for(9)->level);
    }
}
