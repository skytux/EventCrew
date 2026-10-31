<?php

declare(strict_types=1);

namespace EventCrew\Tests\Repositories;

use EventCrew\Repositories\TaskRepository;
use EventCrew\Tests\TestCase;

final class TaskRepositoryTest extends TestCase
{
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

        self::assertStringContainsString("role_slug <> 'organize'", $this->wpdb->lastQuery());
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
