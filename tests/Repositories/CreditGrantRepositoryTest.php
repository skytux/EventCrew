<?php

declare(strict_types=1);

namespace EventCrew\Tests\Repositories;

use Brain\Monkey\Functions;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Tests\TestCase;

final class CreditGrantRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('current_time')->justReturn('2026-07-24 10:00:00');
    }

    private function repository(): CreditGrantRepository
    {
        return new CreditGrantRepository();
    }

    public function testSumForReturnsTheGrantedTotal(): void
    {
        $this->wpdb->nextVars[] = 2;

        self::assertSame(2, $this->repository()->sumFor(9));
        self::assertStringContainsString('SUM(credits)', $this->wpdb->lastQuery());
        self::assertStringContainsString('person_id = 9', $this->wpdb->lastQuery());
    }

    public function testRecordWritesAtLeastOneCreditWithItsNote(): void
    {
        $this->repository()->record(9, 1, 'covered a cleaning task', 3);

        $insert = $this->wpdb->inserts[0];
        self::assertSame(9, $insert['data']['person_id']);
        self::assertSame(1, $insert['data']['credits']);
        self::assertSame('covered a cleaning task', $insert['data']['note']);
        self::assertSame(3, $insert['data']['granted_by']);
    }

    public function testDeleteForPersonRemovesEveryGrant(): void
    {
        $this->repository()->deleteForPerson(9);

        self::assertSame(['person_id' => 9], $this->wpdb->deletes[0]['where']);
    }
}
