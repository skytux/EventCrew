<?php

declare(strict_types=1);

namespace EventCrew\Tests\Repositories;

use Brain\Monkey\Functions;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Tests\TestCase;

final class TaskRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Every option at its default: the restricted-role list is read from
        // the roles option, and the default has only Organize restricted.
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => $default
        );
    }

    public function testDeletingATaskKeepsItsRowAndCancelsItsSignUps(): void
    {
        Functions\when('current_time')->justReturn('2026-08-01 12:00:00');

        (new TaskRepository())->delete(5);

        // Nothing is removed: the tickets on it must keep working, as DISABLED.
        self::assertSame([], $this->wpdb->deletes);
        self::assertCount(2, $this->wpdb->queries);
        self::assertStringContainsString("SET status = 'cancelled'", $this->wpdb->queries[0]);
        self::assertStringContainsString('WHERE task_id = 5 AND status IN', $this->wpdb->queries[0]);
        self::assertStringContainsString(
            "SET deleted_at = '2026-08-01 12:00:00' WHERE id = 5 AND deleted_at IS NULL",
            $this->wpdb->queries[1]
        );
    }

    public function testADeletedTaskIsLeftOutOfTheNight(): void
    {
        $this->wpdb->nextResults[] = [];

        (new TaskRepository())->forDate('2026-08-01');

        self::assertStringContainsString("task_date = '2026-08-01' AND deleted_at IS NULL", $this->wpdb->lastQuery());
    }

    public function testDatesWithTasksReturnsWhateverTheQueryYields(): void
    {
        $this->wpdb->nextCols[] = ['2026-08-08', '2026-08-01', '2026-07-25'];

        $dates = (new TaskRepository())->datesWithTasks();

        self::assertSame(['2026-08-08', '2026-08-01', '2026-07-25'], $dates);
    }

    public function testDatesWithTasksAsksForDistinctDatesMostRecentFirst(): void
    {
        $this->wpdb->nextCols[] = [];

        (new TaskRepository())->datesWithTasks();

        $query = $this->wpdb->lastQuery();
        self::assertStringContainsString('DISTINCT task_date', $query);
        self::assertStringContainsString('ORDER BY task_date DESC', $query);
        // No >= today filter: unlike upcomingDates(), past dates are included.
        self::assertStringNotContainsString('task_date >=', $query);
    }

    /**
     * The organize task has no limit, so an empty one never makes a date look
     * as if it still needs people.
     */
    public function testHasOpenSlotsOnIgnoresTheOrganizeTask(): void
    {
        $this->wpdb->nextResults[] = [
            ['id' => 1, 'task_date' => '2026-08-01', 'role_slug' => 'decorate', 'capacity' => 2],
            ['id' => 2, 'task_date' => '2026-08-01', 'role_slug' => 'organize', 'capacity' => 1],
        ];
        $this->wpdb->nextResults[] = [['task_id' => 1, 'taken' => 2]]; // occupancyFor: decorate full, organize empty

        self::assertFalse((new TaskRepository())->hasOpenSlotsOn('2026-08-01'));
    }

    public function testHasOpenSlotsOnStillSeesAnOpenOrdinaryTask(): void
    {
        $this->wpdb->nextResults[] = [
            ['id' => 1, 'task_date' => '2026-08-01', 'role_slug' => 'decorate', 'capacity' => 2],
            ['id' => 2, 'task_date' => '2026-08-01', 'role_slug' => 'organize', 'capacity' => 1],
        ];
        $this->wpdb->nextResults[] = [['task_id' => 1, 'taken' => 1], ['task_id' => 2, 'taken' => 5]];

        self::assertTrue((new TaskRepository())->hasOpenSlotsOn('2026-08-01'));
    }

    public function testHasTaskCreatedSinceLeavesOutTheOrganizeTask(): void
    {
        (new TaskRepository())->hasTaskCreatedSince('2026-08-01', '2026-07-01 00:00:00');

        self::assertStringContainsString("role_slug NOT IN ('organize')", $this->wpdb->lastQuery());
    }

    public function testStartingBetweenBoundsByStartTimeAndSkipsUntimedTasks(): void
    {
        $this->wpdb->nextResults[] = [];

        (new TaskRepository())->startingBetween('2026-07-20 12:00:00', '2026-07-21 12:00:00');

        $query = $this->wpdb->lastQuery();
        self::assertStringContainsString('starts_at IS NOT NULL', $query);
        self::assertStringContainsString("starts_at >= '2026-07-20 12:00:00'", $query);
        self::assertStringContainsString("starts_at <= '2026-07-21 12:00:00'", $query);
    }
}
