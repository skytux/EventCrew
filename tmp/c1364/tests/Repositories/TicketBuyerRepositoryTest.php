<?php

declare(strict_types=1);

namespace EventCrew\Tests\Repositories;

use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Tests\TestCase;

final class TicketBuyerRepositoryTest extends TestCase
{
    public function testForDateIsAlphabetical(): void
    {
        $this->wpdb->nextResults[] = [
            ['id' => 3, 'event_date' => '2026-08-01', 'name' => 'Anna', 'source' => 'email', 'batch' => 'abc'],
        ];

        $buyers = (new TicketBuyerRepository())->forDate('2026-08-01');

        self::assertSame('Anna', $buyers[0]->name);
        self::assertSame('email', $buyers[0]->source);
        self::assertSame('abc', $buyers[0]->batch);
        self::assertStringContainsString("event_date = '2026-08-01'", $this->wpdb->lastQuery());
        self::assertStringContainsString('ORDER BY name ASC, id ASC', $this->wpdb->lastQuery());
    }

    public function testAddManySkipsNamesAlreadyOnTheDateAndEmptyNames(): void
    {
        // Already on the list: "Anna  Virtanen" in another case and spacing.
        $this->wpdb->nextResults[] = [
            ['id' => 1, 'event_date' => '2026-08-01', 'name' => 'anna virtanen'],
        ];

        $added = (new TicketBuyerRepository())->addMany(
            '2026-08-01',
            ['Anna  Virtanen', '   ', 'Bo Lind', 'BO LIND'],
            'email',
            'batch1'
        );

        self::assertSame(1, $added);
        self::assertCount(1, $this->wpdb->inserts);
        self::assertSame(
            [
                'event_date' => '2026-08-01',
                'name' => 'Bo Lind',
                'source' => 'email',
                'batch' => 'batch1',
                'created_at' => '2026-07-20 12:00:00',
            ],
            $this->wpdb->inserts[0]['data']
        );
    }

    public function testAddManyCutsALongName(): void
    {
        (new TicketBuyerRepository())->addMany('2026-08-01', [str_repeat('x', 300)], 'paste', '');

        self::assertSame(
            TicketBuyerRepository::MAX_NAME_LENGTH,
            strlen((string) $this->wpdb->inserts[0]['data']['name'])
        );
    }

    public function testHasBatchCountsRowsWithThatBatchOnThatDate(): void
    {
        $this->wpdb->nextVars[] = '2';

        self::assertTrue((new TicketBuyerRepository())->hasBatch('2026-08-01', 'abc'));
        self::assertStringContainsString("batch = 'abc'", $this->wpdb->lastQuery());
    }

    public function testAnEmptyBatchIsNeverADuplicate(): void
    {
        // A paste has no batch; a queued count must not make it a duplicate.
        $this->wpdb->nextVars[] = '5';

        self::assertFalse((new TicketBuyerRepository())->hasBatch('2026-08-01', ''));
    }

    public function testClearForDateDeletesOnlyThatDateAndKeepsDoorSales(): void
    {
        $this->wpdb->nextQueryResults[] = 3;

        $deleted = (new TicketBuyerRepository())->clearForDate('2026-08-01');

        self::assertSame(3, $deleted);
        self::assertStringContainsString("event_date = '2026-08-01' AND source <> 'door'", $this->wpdb->lastQuery());
    }

    public function testAddManyDoesNotSkipANameOnlySoldAtTheDoor(): void
    {
        $this->wpdb->nextResults[] = [
            ['id' => 1, 'event_date' => '2026-08-01', 'name' => 'Ada Lovelace', 'source' => 'door'],
        ];

        $added = (new TicketBuyerRepository())->addMany('2026-08-01', ['Ada Lovelace'], 'paste', '');

        self::assertSame(1, $added);
    }

    public function testADoorSaleMayHaveNoNameAndRepeatAName(): void
    {
        $repository = new TicketBuyerRepository();

        self::assertSame(1, $repository->addDoorSale('2026-08-01', ''));
        self::assertSame(2, $repository->addDoorSale('2026-08-01', '  Ada  Lovelace '));
        self::assertSame(3, $repository->addDoorSale('2026-08-01', 'Ada Lovelace'));
        self::assertSame('door', $this->wpdb->inserts[0]['data']['source']);
        self::assertSame('', $this->wpdb->inserts[0]['data']['name']);
        self::assertSame('Ada Lovelace', $this->wpdb->inserts[1]['data']['name']);
    }

    public function testCountForDate(): void
    {
        $this->wpdb->nextVars[] = '7';

        self::assertSame(7, (new TicketBuyerRepository())->countForDate('2026-08-01'));
        self::assertStringContainsString("event_date = '2026-08-01'", $this->wpdb->lastQuery());
    }
}
