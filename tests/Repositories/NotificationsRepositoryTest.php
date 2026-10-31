<?php

declare(strict_types=1);

namespace EventCrew\Tests\Repositories;

use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Tests\TestCase;

final class NotificationsRepositoryTest extends TestCase
{
    public function testPersonIdsSentAnswersWithAnIdSet(): void
    {
        $this->wpdb->nextCols[] = ['3', '9'];

        $ids = (new NotificationsRepository())->personIdsSent('welcome_email', '2000-01-01');

        self::assertSame([3 => true, 9 => true], $ids);
        self::assertStringContainsString("kind = 'welcome_email'", $this->wpdb->lastQuery());
        self::assertStringContainsString("task_date = '2000-01-01'", $this->wpdb->lastQuery());
    }
}
