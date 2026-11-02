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

    public function testClearForDateDeletesOnlyThatDateAndKeepsDoorSalesAndDeskBuyers(): void
    {
        $this->wpdb->nextQueryResults[] = 3;

        $deleted = (new TicketBuyerRepository())->clearForDate('2026-08-01');

        self::assertSame(3, $deleted);
        self::assertStringContainsString(
            "event_date = '2026-08-01' AND source NOT IN ('door', 'desk')",
            $this->wpdb->lastQuery()
        );
    }

    public function testADeskBuyerIsStoredWithTheEmailOrNull(): void
    {
        $repository = new TicketBuyerRepository();

        self::assertSame(1, $repository->addDeskBuyer('2026-08-01', ' Ada ', 'ada@example.com'));
        self::assertSame(2, $repository->addDeskBuyer('2026-08-01', 'Bo', ''));
        self::assertSame('desk', $this->wpdb->inserts[0]['data']['source']);
        self::assertSame('Ada', $this->wpdb->inserts[0]['data']['name']);
        self::assertSame('ada@example.com', $this->wpdb->inserts[0]['data']['email']);
        self::assertNull($this->wpdb->inserts[1]['data']['email']);
    }

    public function testForDateReadsTheEmail(): void
    {
        $this->wpdb->nextResults[] = [
            ['id' => 3, 'event_date' => '2026-08-01', 'name' => 'Ada', 'email' => 'ada@example.com', 'source' => 'desk'],
            ['id' => 4, 'event_date' => '2026-08-01', 'name' => 'Bo', 'email' => null, 'source' => 'paste'],
        ];

        $buyers = (new TicketBuyerRepository())->forDate('2026-08-01');

        self::assertSame('ada@example.com', $buyers[0]->email);
        self::assertSame('', $buyers[1]->email);
        self::assertFalse($buyers[0]->isDoorSale());
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

    public function testAddTicketsKeepsOneRowPerTicketEvenWithTheSameName(): void
    {
        $this->wpdb->nextResults[] = [];

        $added = (new TicketBuyerRepository())->addTickets(
            '2026-08-01',
            [
                ['name' => 'Anna Tester', 'email' => 'anna@example.com', 'code' => 'A1'],
                ['name' => 'Anna Tester', 'email' => 'anna@example.com', 'code' => 'A1'],
                ['name' => '', 'email' => '', 'code' => ''],
                ['name' => '', 'email' => '', 'code' => 'C3'],
            ],
            'email',
            'b1'
        );

        self::assertSame(3, $added);
        self::assertSame(
            [
                'event_date' => '2026-08-01',
                'name' => 'Anna Tester',
                'email' => 'anna@example.com',
                'ticket_code' => 'A1',
                'source' => 'email',
                'batch' => 'b1',
                'created_at' => '2026-07-20 12:00:00',
            ],
            $this->wpdb->inserts[1]['data']
        );
        self::assertNull($this->wpdb->inserts[2]['data']['email']);
        self::assertSame('C3', $this->wpdb->inserts[2]['data']['ticket_code']);
    }

    public function testAddTicketsAgainAddsOnlyTheNewTickets(): void
    {
        // One of Anna's two A1 tickets is stored already; a door sale with the
        // same name does not count.
        $this->wpdb->nextResults[] = [
            ['id' => 1, 'event_date' => '2026-08-01', 'name' => 'Anna Tester', 'email' => 'anna@example.com', 'ticket_code' => 'A1', 'source' => 'email'],
            ['id' => 2, 'event_date' => '2026-08-01', 'name' => 'Bo', 'email' => null, 'ticket_code' => null, 'source' => 'door'],
        ];

        $added = (new TicketBuyerRepository())->addTickets(
            '2026-08-01',
            [
                ['name' => 'Anna Tester', 'email' => 'ANNA@example.com', 'code' => 'A1'],
                ['name' => 'Anna Tester', 'email' => 'anna@example.com', 'code' => 'A1'],
                ['name' => 'Bo', 'email' => '', 'code' => ''],
            ],
            'email',
            'b2'
        );

        self::assertSame(2, $added);
        self::assertSame(['Anna Tester', 'Bo'], array_map(
            static fn (array $insert): string => (string) $insert['data']['name'],
            $this->wpdb->inserts
        ));
    }

    public function testForDateReadsTheTicketCode(): void
    {
        $this->wpdb->nextResults[] = [
            ['id' => 1, 'event_date' => '2026-08-01', 'name' => 'Anna', 'ticket_code' => 'A1', 'source' => 'email'],
        ];

        self::assertSame('A1', (new TicketBuyerRepository())->forDate('2026-08-01')[0]->ticketCode);
    }

    public function testCountForDate(): void
    {
        $this->wpdb->nextVars[] = '7';

        self::assertSame(7, (new TicketBuyerRepository())->countForDate('2026-08-01'));
        self::assertStringContainsString("event_date = '2026-08-01'", $this->wpdb->lastQuery());
    }

    public function testFindByTicketCodeReadsEveryTicketOfThatCodeOnThatDate(): void
    {
        $this->wpdb->nextResults[] = [
            ['id' => 4, 'event_date' => '2026-08-01', 'name' => 'Anna', 'source' => 'email'],
            ['id' => 5, 'event_date' => '2026-08-01', 'name' => 'Anna', 'source' => 'email'],
        ];

        $buyers = (new TicketBuyerRepository())->findByTicketCode('2026-08-01', ' AB12 ');

        self::assertSame([4, 5], array_map(static fn ($buyer): int => $buyer->id, $buyers));
        self::assertStringContainsString(
            "WHERE event_date = '2026-08-01' AND ticket_code = 'AB12' ORDER BY id ASC",
            $this->wpdb->lastQuery()
        );
    }

    public function testFindByTicketCodeWithAnEmptyCodeAsksNothing(): void
    {
        self::assertSame([], (new TicketBuyerRepository())->findByTicketCode('2026-08-01', '  '));
        self::assertSame([], $this->wpdb->queries);
    }
}
