<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\ReplacementService;

final class ReplacementServiceTest extends TelegramTestCase
{
    /** @var array<string, mixed> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->transients = [];

        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->transients[$key] = $value;

            return true;
        });
        Functions\when('get_transient')->alias(fn (string $key): mixed => $this->transients[$key] ?? false);
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);

            return true;
        });
        Functions\when('get_the_title')->justReturn('');
    }

    private function service(): ReplacementService
    {
        return new ReplacementService(
            new AssignmentRepository(),
            new TaskRepository(),
            new PersonRepository(),
            new BoardService(
                new TaskRepository(),
                new AssignmentRepository(),
                new PersonRepository(),
                $this->client(),
                new Logger(),
                new Mailer(new Logger())
            ),
            $this->client()
        );
    }

    private function verifiedPersonRow(int $telegramId = 555): array
    {
        return [
            'id' => 7,
            'email' => 'sam@example.com',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => $telegramId,
        ];
    }

    private function taskRow(int $id): array
    {
        return [
            'id' => $id,
            'task_date' => '2026-08-01',
            'role_slug' => 'decorate',
            'capacity' => 2,
            'event_post_id' => null,
            'event_label' => 'Ecstatic Dance',
            'starts_at' => '2026-08-01 20:00:00',
            'ends_at' => null,
        ];
    }

    public function testStartOffersThePersonsUpcomingSlots(): void
    {
        $this->wpdb->nextRows[] = $this->verifiedPersonRow();          // findByTelegramUserId
        $this->wpdb->nextResults[] = [                                 // forPerson
            ['id' => 3, 'task_id' => 5, 'person_id' => 7, 'status' => AssignmentStatus::SIGNED_UP],
        ];
        $this->wpdb->nextRows[] = $this->taskRow(5);                   // tasks->find

        $this->service()->start(555, 555);

        $body = $this->lastCallTo('sendMessage');
        self::assertSame('rep:5', $body['reply_markup']['inline_keyboard'][0][0]['callback_data']);
    }

    public function testStartTellsThePersonWhenThereIsNothingToHandOver(): void
    {
        $this->wpdb->nextRows[] = $this->verifiedPersonRow();
        $this->wpdb->nextResults[] = []; // forPerson: no assignments

        $this->service()->start(555, 555);

        self::assertStringContainsString('no upcoming tasks', $this->lastCallTo('sendMessage')['text']);
        self::assertNull($this->lastCallTo('sendMessage')['reply_markup'] ?? null);
    }

    public function testSelectRemembersTheTaskAndAsksForAName(): void
    {
        $this->wpdb->nextRows[] = $this->verifiedPersonRow();          // findByTelegramUserId
        $this->wpdb->nextRows[] = [                                    // findFor
            'id' => 3, 'task_id' => 5, 'person_id' => 7, 'status' => AssignmentStatus::SIGNED_UP,
        ];

        $this->service()->onSelect([
            'id' => 'cbq', 'from' => ['id' => 555],
            'message' => ['chat' => ['id' => 555]], 'data' => 'rep:5',
        ]);

        self::assertSame(5, $this->transients['eventcrew_tg_await_replacement_555']);
        self::assertContains('sendMessage', $this->calledMethods());
    }

    public function testSelectRefusesATaskThePersonIsNotOn(): void
    {
        $this->wpdb->nextRows[] = $this->verifiedPersonRow();
        // findFor -> null (no queued row), so they hold no such slot.

        $this->service()->onSelect([
            'id' => 'cbq', 'from' => ['id' => 555],
            'message' => ['chat' => ['id' => 555]], 'data' => 'rep:5',
        ]);

        self::assertArrayNotHasKey('eventcrew_tg_await_replacement_555', $this->transients);
        self::assertTrue((bool) $this->lastCallTo('answerCallbackQuery')['show_alert']);
    }

    public function testCaptureNameFreesTheSlotAndAnnouncesToTheGroup(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => -100, 'message_id' => 1];
        $this->transients['eventcrew_tg_await_replacement_555'] = 5;

        $this->wpdb->nextRows[] = $this->verifiedPersonRow();          // findByTelegramUserId
        $this->wpdb->nextRows[] = $this->taskRow(5);                   // tasks->find
        $this->wpdb->nextRows[] = [                                    // findFor
            'id' => 3, 'task_id' => 5, 'person_id' => 7, 'status' => AssignmentStatus::SIGNED_UP,
        ];

        $this->service()->captureName(555, 555, 'Alex Cover');

        // Slot freed as a neutral cancel (kept, not deleted).
        self::assertSame(AssignmentStatus::CANCELLED, $this->wpdb->updates[0]['data']['status']);
        self::assertSame([], $this->wpdb->deletes);
        // Announced to the group chat, naming the replacement.
        $announce = $this->telegramCalls[0]['body'];
        self::assertSame(-100, $announce['chat_id']);
        self::assertStringContainsString('Alex Cover', $announce['text']);
        // Await state cleared.
        self::assertArrayNotHasKey('eventcrew_tg_await_replacement_555', $this->transients);
    }
}
