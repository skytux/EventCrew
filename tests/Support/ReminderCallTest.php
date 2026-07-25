<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\ReminderCall;
use EventCrew\Tests\Telegram\TelegramTestCase;

/**
 * Uses the Telegram scaffolding so the DM half of a reminder is captured on the
 * real client over a faked wp_remote_post, the same way the board tests do.
 */
final class ReminderCallTest extends TelegramTestCase
{
    /** @var array<int, array{to: string, subject: string}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mails = [];

        Functions\when('get_the_title')->justReturn('');
        Functions\when('rest_url')->alias(static fn (string $path = ''): string => 'https://example.test/wp-json/' . $path);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_mail')->alias(function (string $to, string $subject): bool {
            $this->mails[] = ['to' => $to, 'subject' => $subject];

            return true;
        });
    }

    private function call(): ReminderCall
    {
        return new ReminderCall(
            new TaskRepository(),
            new AssignmentRepository(),
            new PersonRepository(),
            $this->client(),
            new Mailer(new Logger())
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(int $id): array
    {
        return [
            'id' => $id,
            'task_date' => '2026-07-21',
            'role_slug' => 'decorate',
            'capacity' => 2,
            'event_post_id' => null,
            'event_label' => 'Dance',
            'starts_at' => '2026-07-21 08:00:00',
            'ends_at' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assignmentRow(int $id, string $status = 'signed_up', ?string $reminded = null): array
    {
        return [
            'id' => $id,
            'task_id' => 5,
            'person_id' => 7,
            'status' => $status,
            'reminded_at' => $reminded,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function personRow(?int $chatId = 999): array
    {
        return [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_chat_id' => $chatId,
        ];
    }

    public function testRemindsOnBothChannelsAndMarksReminded(): void
    {
        $this->wpdb->nextResults[] = [$this->taskRow(5)];          // startingBetween
        $this->wpdb->nextResults[] = [$this->assignmentRow(3)];    // forTask
        $this->wpdb->nextRows[] = $this->personRow();              // people->find

        $sent = $this->call()->run(25);

        self::assertSame(1, $sent);
        // DM sent, email sent.
        self::assertContains('sendMessage', $this->calledMethods());
        self::assertCount(1, $this->mails);
        // reminded_at stamped (an update on the assignment row).
        self::assertNotSame([], $this->wpdb->updates);
        self::assertArrayHasKey('reminded_at', $this->wpdb->updates[0]['data']);
    }

    public function testReminderDmCarriesAOneTapCancelButton(): void
    {
        $this->wpdb->nextResults[] = [$this->taskRow(5)];
        $this->wpdb->nextResults[] = [$this->assignmentRow(3)];
        $this->wpdb->nextRows[] = $this->personRow();

        $this->call()->run(25);

        // The button reuses the board's own leave callback ('l:<taskId>'), so
        // tapping it runs the exact same cancel - classification, slot-freed
        // broadcast and confirmation all included.
        $body = (string) wp_json_encode($this->lastCallTo('sendMessage'));
        self::assertStringContainsString('callback_data', $body);
        self::assertStringContainsString('l:5', $body);
    }

    public function testDoesNotRemindAnAlreadyRemindedAssignment(): void
    {
        $this->wpdb->nextResults[] = [$this->taskRow(5)];
        $this->wpdb->nextResults[] = [$this->assignmentRow(3, 'signed_up', '2026-07-20 09:00:00')];

        $sent = $this->call()->run(25);

        self::assertSame(0, $sent);
        self::assertSame([], $this->mails);
        self::assertNull($this->lastCallTo('sendMessage'));
    }

    public function testHonoursTheBatchLimit(): void
    {
        $this->wpdb->nextResults[] = [$this->taskRow(5)];
        $this->wpdb->nextResults[] = [
            $this->assignmentRow(3),
            $this->assignmentRow(4),
        ];
        $this->wpdb->nextRows[] = $this->personRow(); // only the first is reached

        $sent = $this->call()->run(1);

        self::assertSame(1, $sent);
    }
}
