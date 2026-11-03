<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\BuyerImport;
use EventCrew\Support\DoorAccess;
use EventCrew\Tests\TestCase;

/**
 * "Today" is 2026-07-20 (the stubbed current_time).
 */
final class BuyerImportTest extends TestCase
{
    private function import(): BuyerImport
    {
        return new BuyerImport(
            new TicketBuyerRepository(),
            new DoorAccess(new TaskRepository(), new AssignmentRepository())
        );
    }

    public function testNextDateIsTheNearestUpcomingNight(): void
    {
        $this->wpdb->nextCols[] = ['2026-08-08', '2026-08-01', '2026-07-10'];

        self::assertSame('2026-08-01', $this->import()->nextDate());
    }

    public function testNextDateAcceptsToday(): void
    {
        $this->wpdb->nextCols[] = ['2026-07-20', '2026-07-10'];

        self::assertSame('2026-07-20', $this->import()->nextDate());
    }

    public function testNextDateRefusesWhenOnlyPastNightsExist(): void
    {
        // DoorAccess falls back to the last past night; an import must not.
        $this->wpdb->nextCols[] = ['2026-07-19', '2026-07-10'];

        self::assertSame('', $this->import()->nextDate());
    }

    public function testNextDateRefusesWhenNoNightExists(): void
    {
        $this->wpdb->nextCols[] = [];

        self::assertSame('', $this->import()->nextDate());
    }

    public function testImportCountsAddedAndSkipped(): void
    {
        Functions\when('get_option')->justReturn(false); // no ticket send waiting
        $this->wpdb->nextVars[] = '0'; // hasBatch: not seen before
        $this->wpdb->nextResults[] = [['id' => 1, 'event_date' => '2026-08-01', 'name' => 'Anna']];

        $result = $this->import()->import('2026-08-01', "Anna\nBo\nCecilia", 'email', 'b1');

        self::assertSame(['added' => 2, 'skipped' => 1, 'duplicate' => false], $result);
        self::assertSame('email', $this->wpdb->inserts[0]['data']['source']);
        self::assertSame('b1', $this->wpdb->inserts[0]['data']['batch']);
    }

    public function testTheSameBatchTwiceAddsNothing(): void
    {
        $this->wpdb->nextVars[] = '3'; // hasBatch: already imported

        $result = $this->import()->import('2026-08-01', "Anna\nBo", 'email', 'b1');

        self::assertSame(['added' => 0, 'skipped' => 2, 'duplicate' => true], $result);
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testBatchForHashesTheMessageIdOrElseTheText(): void
    {
        self::assertSame(substr(hash('sha256', '<a@b>'), 0, 16), BuyerImport::batchFor(' <a@b> ', 'x'));
        self::assertSame(substr(hash('sha256', "Anna\n"), 0, 16), BuyerImport::batchFor('', "Anna\n"));
    }
}
