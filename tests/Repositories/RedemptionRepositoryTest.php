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
        $this->wpdb->nextQueryResults[] = 1; // one row written

        $id = $this->repository()->record(9, '2026-08-01', 55, 'Ecstatic Dance', 'at the door');

        $sql = $this->wpdb->lastQuery();

        self::assertGreaterThan(0, $id);
        self::assertStringContainsString("'2026-08-01'", $sql);
        self::assertStringContainsString('55', $sql);
        self::assertStringContainsString('Ecstatic Dance', $sql);
    }

    /**
     * The guard is in the statement, not in front of it.
     *
     * Every caller checks "have they already redeemed this date?" before
     * calling, and two requests can both be past that check at once - a
     * double-tap, or the bot and the web page a moment apart. Two rows then
     * spend two credits against a balance of one, and since the balance is
     * derived from these rows it just goes negative and clamps at zero. The
     * insert refuses the second one itself, and says so by writing nothing.
     */
    public function testRecordRefusesASecondRedemptionForTheSameDate(): void
    {
        $this->wpdb->nextQueryResults[] = 0; // the WHERE NOT EXISTS matched

        $id = $this->repository()->record(9, '2026-08-01', 55, 'Ecstatic Dance');

        self::assertSame(0, $id);
        self::assertStringContainsString('NOT EXISTS', $this->wpdb->lastQuery());
    }

    /** A redemption with no linked event stores a real NULL, never a 0. */
    public function testRecordKeepsTheEventColumnNullWhenThereIsNoEvent(): void
    {
        $this->wpdb->nextQueryResults[] = 1;

        $this->repository()->record(9, '2026-08-01');

        self::assertStringContainsString('NULL', $this->wpdb->lastQuery());
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
