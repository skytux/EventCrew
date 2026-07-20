<?php

declare(strict_types=1);

namespace EventCrew\Tests\Repositories;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Tests\TestCase;

/**
 * Covers the branch logic of joining a task.
 *
 * The concurrency guarantee itself lives in the database - one statement that
 * counts and inserts together - so what can be verified without MySQL is that
 * the statement is shaped correctly and that each outcome maps to the right
 * answer for the caller.
 */
final class AssignmentRepositoryTest extends TestCase
{
    public function testReportsAnUnknownTaskWithoutAttemptingAWrite(): void
    {
        $this->wpdb->nextVars = [null];

        $result = (new AssignmentRepository())->join(999, 1);

        self::assertSame(AssignmentRepository::JOIN_UNKNOWN_TASK, $result);
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testReportsADuplicateWhenThePersonAlreadyHoldsASlot(): void
    {
        $this->wpdb->nextVars = [2];
        $this->wpdb->nextRows = [
            ['id' => 5, 'task_id' => 3, 'person_id' => 1, 'status' => AssignmentStatus::SIGNED_UP],
        ];

        $result = (new AssignmentRepository())->join(3, 1);

        self::assertSame(AssignmentRepository::JOIN_DUPLICATE, $result);
    }

    public function testReportsSuccessWhenTheConditionalInsertWroteARow(): void
    {
        $this->wpdb->nextVars = [2];
        $this->wpdb->nextRows = [null];
        $this->wpdb->nextQueryResults = [1];

        $result = (new AssignmentRepository())->join(3, 1);

        self::assertSame(AssignmentRepository::JOIN_OK, $result);
    }

    /**
     * Zero affected rows means the capacity condition evaluated false at write
     * time - somebody else took the last slot in between. The person must
     * be told the task is full, not that something went wrong.
     */
    public function testReportsFullWhenTheConditionalInsertWroteNothing(): void
    {
        $this->wpdb->nextVars = [2];
        $this->wpdb->nextRows = [null];
        $this->wpdb->nextQueryResults = [0];

        $result = (new AssignmentRepository())->join(3, 1);

        self::assertSame(AssignmentRepository::JOIN_FULL, $result);
    }

    /**
     * A failed write on this statement is realistically the unique key firing,
     * which means the same person's second tap arrived while the first was
     * still in flight. Re-reading the row distinguishes that from a full task.
     */
    public function testTreatsAFailedInsertWithAnExistingRowAsADuplicate(): void
    {
        $this->wpdb->nextVars = [2];
        $this->wpdb->nextRows = [
            null,
            ['id' => 9, 'task_id' => 3, 'person_id' => 1, 'status' => AssignmentStatus::SIGNED_UP],
        ];
        $this->wpdb->nextQueryResults = [false];

        $result = (new AssignmentRepository())->join(3, 1);

        self::assertSame(AssignmentRepository::JOIN_DUPLICATE, $result);
    }

    /**
     * The whole point of the single statement: the capacity comparison has to
     * be inside the INSERT, not read beforehand in PHP. If this ever becomes a
     * plain INSERT, two simultaneous joins can overbook a task.
     */
    public function testCountsCapacityInsideTheInsertStatement(): void
    {
        $this->wpdb->nextVars = [2];
        $this->wpdb->nextRows = [null];
        $this->wpdb->nextQueryResults = [1];

        (new AssignmentRepository())->join(3, 1);

        $sql = $this->wpdb->lastQuery();

        self::assertStringContainsString('INSERT INTO', $sql);
        self::assertStringContainsString('SELECT COUNT(*)', $sql);
        self::assertStringContainsString('capacity', $sql);
    }

    /**
     * Statuses that have freed their slot must not be counted, or a task
     * someone dropped out of stays permanently full.
     */
    public function testCountsOnlyOccupyingStatusesTowardCapacity(): void
    {
        $this->wpdb->nextVars = [2];
        $this->wpdb->nextRows = [null];
        $this->wpdb->nextQueryResults = [1];

        (new AssignmentRepository())->join(3, 1);

        $sql = $this->wpdb->lastQuery();

        self::assertStringContainsString(AssignmentStatus::COMPLETED, $sql);
        self::assertStringNotContainsString(AssignmentStatus::NO_SHOW, $sql);
        self::assertStringNotContainsString(AssignmentStatus::LATE_CANCEL, $sql);
    }

    public function testLeavingDeletesThePeopleRowForThatTask(): void
    {
        (new AssignmentRepository())->leave(3, 1);

        self::assertCount(1, $this->wpdb->deletes);
        self::assertSame(
            ['task_id' => 3, 'person_id' => 1],
            $this->wpdb->deletes[0]['where']
        );
    }

    public function testRecordsWhoChangedAStatusAndWhen(): void
    {
        (new AssignmentRepository())->setStatus(5, AssignmentStatus::NO_SHOW, 42);

        $update = $this->wpdb->updates[0];

        self::assertSame(AssignmentStatus::NO_SHOW, $update['data']['status']);
        self::assertSame(42, $update['data']['changed_by']);
        self::assertNotEmpty($update['data']['status_changed_at']);
        self::assertSame(['id' => 5], $update['where']);
    }
}
