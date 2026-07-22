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
                new Mailer(new Logger()),
                $this->standing()
            ),
            $this->client()
        );
    }

    private function verifiedPersonRow(int $id, int $telegramId): array
    {
        return [
            'id' => $id,
            'email' => sprintf('p%d@example.com', $id),
            'display_name' => 'Person ' . $id,
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

    public function testStartAsksWhoIsBeingCovered(): void
    {
        $this->wpdb->nextRows[] = $this->verifiedPersonRow(7, 555); // the cover

        $this->service()->start(555, 555);

        self::assertArrayHasKey('eventcrew_tg_await_replace_target_555', $this->transients);
        self::assertContains('sendMessage', $this->calledMethods());
    }

    public function testCaptureTargetListsTheNamedPersonsUpcomingTasks(): void
    {
        // people->all() name search returns the target (Alice, id 9)
        $this->wpdb->nextResults[] = [$this->verifiedPersonRow(9, 999)];
        // assignments->forPerson(9)
        $this->wpdb->nextResults[] = [
            ['id' => 3, 'task_id' => 5, 'person_id' => 9, 'status' => AssignmentStatus::SIGNED_UP],
        ];
        // tasks->find(5)
        $this->wpdb->nextRows[] = $this->taskRow(5);

        $this->service()->captureTarget(555, 555, 'Person 9', []);

        $body = $this->lastCallTo('sendMessage');
        self::assertSame('rep:3', $body['reply_markup']['inline_keyboard'][0][0]['callback_data']);
    }

    public function testCaptureTargetResolvesATextMentionExactly(): void
    {
        // No name-search results queued; the text_mention resolves by user id.
        $this->wpdb->nextRows[] = $this->verifiedPersonRow(9, 999); // findByTelegramUserId(999)
        $this->wpdb->nextResults[] = [                              // forPerson(9)
            ['id' => 3, 'task_id' => 5, 'person_id' => 9, 'status' => AssignmentStatus::SIGNED_UP],
        ];
        $this->wpdb->nextRows[] = $this->taskRow(5);                // tasks->find(5)

        $this->service()->captureTarget(555, 555, 'Alice', [
            ['type' => 'text_mention', 'user' => ['id' => 999]],
        ]);

        self::assertSame('rep:3', $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'][0][0]['callback_data']);
    }

    public function testSelectSwapsTheOriginalForTheCoverAutomatically(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => -100, 'message_id' => 1];

        // find(assignment 3) -> the original's occupying slot on task 5
        $this->wpdb->nextRows[] = ['id' => 3, 'task_id' => 5, 'person_id' => 9, 'status' => AssignmentStatus::SIGNED_UP];
        // findByTelegramUserId(555) -> the cover (id 7)
        $this->wpdb->nextRows[] = $this->verifiedPersonRow(7, 555);
        // tasks->find(5)
        $this->wpdb->nextRows[] = $this->taskRow(5);
        // refusalFor: findFor(5,7) -> null (not already on)
        $this->wpdb->nextRows[] = null;
        // hasOverlapping -> 0
        $this->wpdb->nextVars[] = 0;
        // swap: setStatus(replaced) [update], then join(): taskCapacity, findFor null, insert
        $this->wpdb->nextVars[] = 2;         // taskCapacity
        $this->wpdb->nextRows[] = null;      // join findFor
        $this->wpdb->nextQueryResults[] = 1; // conditional insert -> JOIN_OK
        // success messaging: tasks->find(5), people->find(9) [original]
        $this->wpdb->nextRows[] = $this->taskRow(5);
        $this->wpdb->nextRows[] = array_merge($this->verifiedPersonRow(9, 999), ['telegram_chat_id' => 999]);

        $this->service()->onSelect([
            'id' => 'cbq', 'from' => ['id' => 555], 'data' => 'rep:3',
        ]);

        // The original was marked replaced (an update to status).
        self::assertSame(AssignmentStatus::REPLACED, $this->wpdb->updates[0]['data']['status']);
        // The cover was signed up (a conditional insert ran).
        self::assertStringContainsString('INSERT INTO', implode("\n", $this->wpdb->queries));
        // The group was told, and the original DMed.
        self::assertContains('answerCallbackQuery', $this->calledMethods());
        self::assertContains('sendMessage', $this->calledMethods());
    }

    public function testSelectRefusesCoveringYourOwnTask(): void
    {
        // The original slot belongs to person 7, and the cover is also 7.
        $this->wpdb->nextRows[] = ['id' => 3, 'task_id' => 5, 'person_id' => 7, 'status' => AssignmentStatus::SIGNED_UP];
        $this->wpdb->nextRows[] = $this->verifiedPersonRow(7, 555);
        $this->wpdb->nextRows[] = $this->taskRow(5);

        $this->service()->onSelect([
            'id' => 'cbq', 'from' => ['id' => 555], 'data' => 'rep:3',
        ]);

        self::assertSame([], $this->wpdb->updates);
        self::assertTrue((bool) $this->lastCallTo('answerCallbackQuery')['show_alert']);
    }
}
