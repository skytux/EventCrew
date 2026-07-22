<?php

declare(strict_types=1);

namespace EventCrew\Tests\Repositories;

use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Tests\TestCase;

final class RedemptionRepositoryTest extends TestCase
{
    private function repository(): RedemptionRepository
    {
        return new RedemptionRepository();
    }

    public function testCountForReturnsTheSpentCredits(): void
    {
        $this->wpdb->nextVars[] = 3;

        self::assertSame(3, $this->repository()->countFor(9));
        self::assertStringContainsString('COUNT(*)', $this->wpdb->lastQuery());
        self::assertStringContainsString('person_id = 9', $this->wpdb->lastQuery());
    }

    public function testRecordWritesTheDateItBuysEntryTo(): void
    {
        $this->repository()->record(9, '2026-08-01', 55, 'Ecstatic Dance', 'at the door');

        $insert = $this->wpdb->inserts[0];
        self::assertSame(9, $insert['data']['person_id']);
        self::assertSame('2026-08-01', $insert['data']['redeemed_for']);
        self::assertSame(55, $insert['data']['event_post_id']);
        self::assertSame('Ecstatic Dance', $insert['data']['event_label']);
    }

    public function testForDateReturnsTheNightsRedemptions(): void
    {
        $this->wpdb->nextResults[] = [
            ['id' => 4, 'person_id' => 7, 'redeemed_for' => '2026-08-01'],
            ['id' => 5, 'person_id' => 9, 'redeemed_for' => '2026-08-01'],
        ];

        $redemptions = $this->repository()->forDate('2026-08-01');

        self::assertCount(2, $redemptions);
        self::assertSame(7, $redemptions[0]->personId);
        self::assertStringContainsString("redeemed_for = '2026-08-01'", $this->wpdb->lastQuery());
    }

    public function testForPersonHydratesRedemptions(): void
    {
        $this->wpdb->nextResults[] = [
            ['id' => 4, 'person_id' => 9, 'redeemed_for' => '2026-08-01', 'event_label' => 'Dance'],
        ];

        $redemptions = $this->repository()->forPerson(9);

        self::assertCount(1, $redemptions);
        self::assertSame('2026-08-01', $redemptions[0]->redeemedFor);
        self::assertSame('Dance', $redemptions[0]->eventLabel);
    }

    public function testDeleteForPersonRemovesEveryRow(): void
    {
        $this->repository()->deleteForPerson(9);

        self::assertSame(['person_id' => 9], $this->wpdb->deletes[0]['where']);
    }
}
