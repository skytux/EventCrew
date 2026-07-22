<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Telegram\ProfileService;

final class ProfileServiceTest extends TelegramTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_the_title')->justReturn('');
    }

    private function service(): ProfileService
    {
        return new ProfileService(
            new PersonRepository(),
            new AssignmentRepository(),
            new TaskRepository(),
            $this->standing(),
            $this->client()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(int $id): array
    {
        return [
            'id' => $id,
            'task_date' => '2026-07-20',
            'role_slug' => 'decorate',
            'capacity' => 2,
            'event_post_id' => null,
            'event_label' => 'Dance',
            'starts_at' => null,
            'ends_at' => null,
            'notes' => '',
            'created_at' => '2026-07-01 00:00:00',
        ];
    }

    public function testMeReportsStandingCreditsAndRecentTasks(): void
    {
        // findByTelegramUserId -> a verified person
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => 555,
        ];
        // standing->for(7): historyFor (four completions -> rated, two credits)
        $this->wpdb->nextResults[] = [
            ['id' => 1, 'task_id' => 1, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-07-20'],
            ['id' => 2, 'task_id' => 2, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-07-19'],
            ['id' => 3, 'task_id' => 3, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-07-18'],
            ['id' => 4, 'task_id' => 4, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-07-17'],
        ];
        $this->wpdb->nextVars[] = 0; // countFor redemptions
        // recentLines: historyFor again, then tasks->find for the first three
        $this->wpdb->nextResults[] = [
            ['id' => 1, 'task_id' => 1, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-07-20'],
            ['id' => 2, 'task_id' => 2, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-07-19'],
            ['id' => 3, 'task_id' => 3, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-07-18'],
        ];
        $this->wpdb->nextRows[] = $this->taskRow(1);
        $this->wpdb->nextRows[] = $this->taskRow(2);
        $this->wpdb->nextRows[] = $this->taskRow(3);

        $this->service()->onMe(555, 555);

        $body = (string) $this->lastCallTo('sendMessage')['text'];
        self::assertStringContainsString('Sam', $body);
        self::assertStringContainsString('Good standing', $body);
        self::assertStringContainsString('4', $body); // completed count
        self::assertStringContainsString('2', $body); // credit balance
        self::assertStringContainsString('2026-07-20', $body); // a recent task line
    }

    public function testMeAsksAnUnknownUserToSetUpFirst(): void
    {
        // No person row queued -> findByTelegramUserId returns null.
        $this->service()->onMe(999, 999);

        self::assertStringContainsString('/start', (string) $this->lastCallTo('sendMessage')['text']);
    }
}
