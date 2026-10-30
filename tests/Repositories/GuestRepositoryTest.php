<?php

declare(strict_types=1);

namespace EventCrew\Tests\Repositories;

use EventCrew\Repositories\GuestRepository;
use EventCrew\Tests\TestCase;

final class GuestRepositoryTest extends TestCase
{
    public function testForDateKeepsTheOrderGuestsWereAdded(): void
    {
        $this->wpdb->nextResults[] = [
            ['id' => 1, 'event_date' => '2026-08-01', 'name' => 'Anna', 'plus_ones' => 2, 'note' => ''],
        ];

        $guests = (new GuestRepository())->forDate('2026-08-01');

        self::assertSame('Anna', $guests[0]->name);
        self::assertSame(3, $guests[0]->headcount());
        self::assertStringContainsString("event_date = '2026-08-01'", $this->wpdb->lastQuery());
        self::assertStringContainsString('ORDER BY id ASC', $this->wpdb->lastQuery());
    }

    public function testAddClampsPlusOnesAndLeavesAnUnknownAdderNull(): void
    {
        (new GuestRepository())->add('2026-08-01', 'Anna', 99, '', null);

        $row = $this->wpdb->inserts[0]['data'];
        self::assertSame(GuestRepository::MAX_PLUS_ONES, $row['plus_ones']);
        self::assertArrayNotHasKey('added_by', $row);
    }

    public function testUpdateNeverStoresNegativePlusOnes(): void
    {
        (new GuestRepository())->update(4, 'Anna', -2, 'press');

        self::assertSame(0, $this->wpdb->updates[0]['data']['plus_ones']);
        self::assertSame(['id' => 4], $this->wpdb->updates[0]['where']);
    }
}
