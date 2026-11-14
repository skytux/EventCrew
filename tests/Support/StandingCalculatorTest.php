<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CreditGrantRepository;
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
            new RedemptionRepository(),
            new CreditGrantRepository()
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

    /**
     * The helper's what-ifs: one more late cancel or one more completed task
     * today, next to the standing now, from one read of the history.
     */
    public function testWhatIfAddsOneTaskOfEachOutcomeToday(): void
    {
        $this->queueHistory(array_fill(0, 3, AssignmentStatus::COMPLETED));

        $whatIf = $this->calculator()->whatIf(9, [AssignmentStatus::LATE_CANCEL, AssignmentStatus::COMPLETED]);

        self::assertSame(1.0, $whatIf['now']->score);
        self::assertSame(3, $whatIf['now']->scoredCount);
        // Three completions (1.0) and a late cancel (0.4), all today: 3.4 / 4.
        self::assertEqualsWithDelta(0.85, $whatIf[AssignmentStatus::LATE_CANCEL]->score, 0.0001);
        self::assertSame(4, $whatIf[AssignmentStatus::LATE_CANCEL]->scoredCount);
        self::assertSame(3, $whatIf[AssignmentStatus::LATE_CANCEL]->completedCount);
        self::assertSame(1.0, $whatIf[AssignmentStatus::COMPLETED]->score);
        self::assertSame(4, $whatIf[AssignmentStatus::COMPLETED]->completedCount);
    }

    /**
     * A task someone was put on with "Count toward free-entry credit?" answered
     * No is left out of everything: completions, credit and rating.
     */
    public function testATaskThatDoesNotCountForCreditIsLeftOut(): void
    {
        $rows = [];

        foreach ([1, 1, 0, 0] as $i => $counts) {
            $rows[] = [
                'id' => $i + 1,
                'task_id' => $i + 1,
                'person_id' => 9,
                'status' => AssignmentStatus::COMPLETED,
                'task_date' => '2026-07-20',
                'counts_credit' => $counts,
            ];
        }

        $this->wpdb->nextResults[] = $rows;
        $this->wpdb->nextVars[] = 0; // countFor: nothing redeemed

        $standing = $this->calculator()->for(9);

        self::assertSame(2, $standing->completedCount);
        self::assertSame(1, $standing->creditBalance); // floor(2/2), not floor(4/2)
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

    public function testANoShowOnlyRecordIsRatedAtRisk(): void
    {
        // No completions at all, but three no-shows are three finished tasks -
        // enough to rate. A zero score then puts them at risk instead of letting
        // them hide behind "New" for never completing anything.
        $this->queueHistory(array_fill(0, 3, AssignmentStatus::NO_SHOW));
        $this->wpdb->nextVars[] = 0; // countFor: nothing redeemed

        $standing = $this->calculator()->for(9);

        self::assertSame(Standing::AT_RISK, $standing->level);
        self::assertSame(0, $standing->completedCount);
        self::assertSame(0, $standing->creditBalance);
    }

    public function testTooLittleHistoryIsUnrated(): void
    {
        $this->queueHistory([AssignmentStatus::COMPLETED, AssignmentStatus::COMPLETED]);
        $this->wpdb->nextVars[] = 0;

        self::assertSame(Standing::UNRATED, $this->calculator()->for(9)->level);
    }

    /**
     * forMany() reads every listed person's history, redemptions and grants
     * in three queries, and gives the same standing for() would.
     */
    public function testForManyGivesEachPersonTheirStandingInThreeQueries(): void
    {
        $completed = static fn (int $id, int $person): array => [
            'id' => $id, 'task_id' => $id, 'person_id' => $person,
            'status' => AssignmentStatus::COMPLETED, 'task_date' => '2026-07-20',
        ];
        $this->wpdb->nextResults[] = [$completed(1, 9), $completed(2, 9), $completed(3, 9), $completed(4, 9), $completed(5, 11)];
        $this->wpdb->nextResults[] = [['person_id' => 9, 'n' => 1]];  // redemption counts
        $this->wpdb->nextResults[] = [['person_id' => 11, 'n' => 2]]; // grant sums

        $standings = $this->calculator()->forMany([9, 11, 12]);

        self::assertSame([9, 11, 12], array_keys($standings));
        self::assertSame(1, $standings[9]->creditBalance);   // floor(4/2) - 1
        self::assertSame(4, $standings[9]->completedCount);
        self::assertSame(2, $standings[11]->creditBalance);  // floor(1/2) + 2
        self::assertSame(0, $standings[12]->creditBalance);
        self::assertCount(3, $this->wpdb->queries);
        self::assertStringContainsString('IN (9,11,12)', (string) $this->wpdb->queries[0]);
    }

    public function testForManyOfNobodyRunsNoQuery(): void
    {
        self::assertSame([], $this->calculator()->forMany([]));
        self::assertCount(0, $this->wpdb->queries);
    }
}
