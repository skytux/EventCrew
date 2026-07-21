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
}
