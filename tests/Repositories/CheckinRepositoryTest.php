<?php

declare(strict_types=1);

namespace EventCrew\Tests\Repositories;

use EventCrew\Repositories\CheckinRepository;
use EventCrew\Tests\TestCase;

final class CheckinRepositoryTest extends TestCase
{
    public function testForDateGivesExtraPeopleAndTheCheckInTimeByKey(): void
    {
        $this->wpdb->nextResults[] = [
            ['kind' => 'guest', 'ref_id' => 4, 'plus_ones' => 2, 'checked_in_at' => '2026-08-01 21:05:00'],
        ];

        $in = (new CheckinRepository())->forDate('2026-08-01');

        self::assertSame(['guest:4' => ['plus' => 2, 'at' => '2026-08-01 21:05:00']], $in);
        self::assertStringContainsString('checked_in_at', $this->wpdb->lastQuery());
    }

    public function testPersonTimesGroupsTheCrewAndCreditCheckInsByDate(): void
    {
        $this->wpdb->nextResults[] = [
            ['event_date' => '2026-08-01', 'kind' => 'credit', 'checked_in_at' => '2026-08-01 22:40:00'],
            ['event_date' => '2026-08-08', 'kind' => 'crew', 'checked_in_at' => '2026-08-08 20:00:00'],
        ];

        $times = (new CheckinRepository())->personTimes(7);

        self::assertSame(
            [
                '2026-08-01' => ['credit' => '2026-08-01 22:40:00'],
                '2026-08-08' => ['crew' => '2026-08-08 20:00:00'],
            ],
            $times
        );
        self::assertStringContainsString("ref_id = 7 AND kind IN ('crew', 'credit')", $this->wpdb->lastQuery());
    }

    public function testInAtFromTriesTheKindsInOrderAndIsNullWhenNotIn(): void
    {
        $times = ['2026-08-01' => ['credit' => '2026-08-01 22:40:00', 'crew' => '2026-08-01 21:00:00']];

        self::assertSame(
            '2026-08-01 21:00:00',
            CheckinRepository::inAtFrom($times, '2026-08-01', CheckinRepository::TASK_TICKET_KINDS)
        );
        self::assertSame(
            '2026-08-01 22:40:00',
            CheckinRepository::inAtFrom($times, '2026-08-01', CheckinRepository::CREDIT_TICKET_KINDS)
        );
        self::assertNull(CheckinRepository::inAtFrom($times, '2026-08-08', CheckinRepository::TASK_TICKET_KINDS));
    }
}
