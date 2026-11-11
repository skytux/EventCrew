<?php

declare(strict_types=1);

namespace EventCrew\Tests\Repositories;

use EventCrew\Repositories\MonthlyReportRepository;
use EventCrew\Tests\TestCase;

/**
 * The reads behind the monthly report: one query each over the range, and
 * counts or ids back, never an address (names only for "Done for others").
 */
final class MonthlyReportRepositoryTest extends TestCase
{
    public function testBurstIsReadOnlyWhenItsTableIsThere(): void
    {
        $repository = new MonthlyReportRepository();

        $this->wpdb->nextVars[] = null;
        self::assertNull($repository->burstMonth(100, 200));
        self::assertCount(1, $this->wpdb->queries);

        $this->wpdb->nextVars[] = 'wp_burst_statistics';
        $this->wpdb->nextRows[] = ['views' => '12', 'visitors' => '5'];
        self::assertSame(['views' => 12, 'visitors' => 5], $repository->burstMonth(100, 200));
        self::assertStringContainsString('FROM wp_burst_statistics', $this->wpdb->lastQuery());
        self::assertStringContainsString('time >= 100 AND time < 200', $this->wpdb->lastQuery());
        self::assertStringContainsString('COUNT(DISTINCT NULLIF(uid_id, 0))', $this->wpdb->lastQuery());
    }

    public function testPersonIdsAreDistinctPositiveInts(): void
    {
        $this->wpdb->nextCols[] = ['3', '0', '7'];
        $this->wpdb->nextCols[] = null;

        $repository = new MonthlyReportRepository();

        self::assertSame([3, 7], $repository->botPeople('2026-09-30 21:00:00', '2026-10-31 22:00:00'));
        self::assertStringContainsString('SELECT DISTINCT person_id', $this->wpdb->lastQuery());
        self::assertStringContainsString('person_id IS NOT NULL', $this->wpdb->lastQuery());

        self::assertSame([], $repository->actingPeople('web', '2026-10-01 00:00:00', '2026-11-01 00:00:00'));
        self::assertStringContainsString(
            "channel = 'web' AND created_at >= '2026-10-01 00:00:00' AND created_at < '2026-11-01 00:00:00'",
            $this->wpdb->lastQuery()
        );
    }

    public function testHistoryIsCountedPerChannel(): void
    {
        $this->wpdb->nextResults[] = [['channel' => 'web', 'n' => '5'], ['channel' => 'bot', 'n' => '2']];

        $repository = new MonthlyReportRepository();

        self::assertSame(
            ['web' => 5, 'bot' => 2],
            $repository->historyByChannel(['cancelled', 'late_cancel'], '2026-10-01 00:00:00', '2026-11-01 00:00:00')
        );
        self::assertStringContainsString("kind IN ('cancelled', 'late_cancel')", $this->wpdb->lastQuery());
        self::assertStringContainsString('GROUP BY channel', $this->wpdb->lastQuery());

        $queries = count($this->wpdb->queries);
        self::assertSame([], $repository->historyByChannel([], 'a', 'b'));
        self::assertCount($queries, $this->wpdb->queries);
    }

    public function testAdminActionsAreWhatOrganizersAndWpUsersDidForOthers(): void
    {
        $this->wpdb->nextResults[] = [['id' => '1', 'kind' => 'assigned', 'actor_kind' => 'organizer']];

        $rows = (new MonthlyReportRepository())->adminActions('2026-10-01 00:00:00', '2026-11-01 00:00:00');
        $sql = $this->wpdb->lastQuery();

        self::assertSame([['id' => '1', 'kind' => 'assigned', 'actor_kind' => 'organizer']], $rows);
        // Organizers and wp-admin only: not own actions ('person'), not the system.
        self::assertStringContainsString("h.actor_kind IN ('organizer', 'wp_user')", $sql);
        // Not the rows made from older data.
        self::assertStringContainsString("h.source <> 'backfill'", $sql);
        self::assertStringContainsString(
            "h.created_at >= '2026-10-01 00:00:00' AND h.created_at < '2026-11-01 00:00:00'",
            $sql
        );
        self::assertStringContainsString('ORDER BY h.created_at ASC, h.id ASC', $sql);
        // Names for the lines, never an address.
        self::assertStringContainsString('a.display_name AS actor_name', $sql);
        self::assertStringNotContainsString('email', $sql);
    }

    public function testTaskPeopleLeaveOutDeletedTasks(): void
    {
        $this->wpdb->nextResults[] = [['person_id' => '4', 'n_all' => '3', 'n_matched' => '1']];

        self::assertSame(
            [4 => ['all' => 3, 'matched' => 1]],
            (new MonthlyReportRepository())->taskPeople(['completed', 'arrived_late'], '2026-10-01', '2026-11-01')
        );

        $sql = $this->wpdb->lastQuery();

        self::assertStringContainsString("SUM(a.status IN ('completed', 'arrived_late'))", $sql);
        self::assertStringContainsString('t.deleted_at IS NULL', $sql);
        self::assertStringContainsString("t.task_date >= '2026-10-01' AND t.task_date < '2026-11-01'", $sql);
    }

    public function testCountsAndBuyersPerNight(): void
    {
        array_push($this->wpdb->nextVars, '6', '2', '9');
        $this->wpdb->nextResults[] = [['event_date' => '2026-10-10', 'n' => '40']];

        $repository = new MonthlyReportRepository();

        self::assertSame(6, $repository->newPeople('2026-10-01 00:00:00', '2026-11-01 00:00:00'));
        self::assertSame(2, $repository->assignmentCount(['no_show'], '2026-10-01', '2026-11-01'));
        self::assertStringContainsString("a.status IN ('no_show')", $this->wpdb->lastQuery());
        self::assertSame(9, $repository->checkinCount(['credit', 'credit_x'], '2026-10-01', '2026-11-01'));
        self::assertStringContainsString(
            "event_date >= '2026-10-01' AND event_date < '2026-11-01'",
            $this->wpdb->lastQuery()
        );
        self::assertSame(['2026-10-10' => 40], $repository->buyersPerNight('2026-10-01', '2026-11-01'));
        self::assertStringContainsString('GROUP BY event_date ORDER BY event_date ASC', $this->wpdb->lastQuery());

        self::assertSame(0, $repository->assignmentCount([], 'a', 'b'));
        self::assertSame(0, $repository->checkinCount([], 'a', 'b'));
    }
}
