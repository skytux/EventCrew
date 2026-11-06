<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\ReminderCall;
use EventCrew\Support\Roles;
use EventCrew\Tests\Telegram\TelegramTestCase;

/**
 * Uses the Telegram scaffolding so the DM half of a reminder is captured on the
 * real client over a faked wp_remote_post, the same way the board tests do.
 */
final class ReminderCallTest extends TelegramTestCase
{
    use AiTextOptions;

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
            new Mailer(new Logger(), new EmailTemplate(new Logger()))
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

    /**
     * Pins the site clock, since whether a reminder may go out now is entirely
     * a question about the hour.
     */
    private function clockAt(string $utc): void
    {
        $timestamp = (int) strtotime($utc . ' UTC');

        Functions\when('current_time')->alias(
            static function (string $format) use ($timestamp): string|int {
                if ('mysql' === $format) {
                    return gmdate('Y-m-d H:i:s', $timestamp);
                }

                return 'timestamp' === $format ? $timestamp : gmdate($format, $timestamp);
            }
        );
    }

    public function testAReminderWaitsForACivilisedHour(): void
    {
        // 03:00, and the task is not until 08:00 tomorrow - comfortably after
        // the window opens at nine, so there is no reason to wake anyone.
        $this->clockAt('2026-07-20 03:00:00');

        $this->wpdb->nextResults[] = [$this->taskRow(5)];
        $this->wpdb->nextResults[] = [$this->assignmentRow(3)];

        self::assertSame(0, $this->call()->run(25));
        self::assertSame([], $this->mails);
        self::assertNotContains('sendMessage', $this->calledMethods());
    }

    public function testAReminderGoesAnywayWhenWaitingWouldMissTheTask(): void
    {
        // Also 03:00, but the task starts at 08:00 today - before the window
        // opens. Holding it until nine would deliver it an hour too late.
        $this->clockAt('2026-07-21 03:00:00');

        $this->wpdb->nextResults[] = [$this->taskRow(5)];
        $this->wpdb->nextResults[] = [$this->assignmentRow(3)];
        $this->wpdb->nextRows[] = $this->personRow();

        self::assertSame(1, $this->call()->run(25));
        self::assertCount(1, $this->mails);
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
    public function testReminderSaysWhatTheTaskInvolvesAndOffersTheGuideByName(): void
    {
        $this->options[Roles::GUIDE_URL_OPTION] = 'https://docs.example/guide';

        $this->wpdb->nextResults[] = [$this->taskRow(5)];
        $this->wpdb->nextResults[] = [$this->assignmentRow(3)];
        $this->wpdb->nextRows[] = $this->personRow();

        $this->call()->run(25);

        $dm = (array) $this->lastCallTo('sendMessage');
        self::assertStringContainsString('About the task', (string) $dm['text']);
        self::assertStringContainsString('LED candles', (string) $dm['text']);
        // The link lives in a named button, never in the text itself.
        self::assertStringNotContainsString('docs.example', (string) $dm['text']);

        // The ticket, cancel, then the guide: the same actions as the email.
        $rows = $dm['reply_markup']['inline_keyboard'];
        self::assertStringContainsString('eventcrew/v1/ticket?token=', $rows[0][0]['url']);
        self::assertSame('l:5', $rows[1][0]['callback_data']);
        self::assertSame('https://docs.example/guide', $rows[2][0]['url']);
        self::assertStringContainsString('Crew guide', $rows[2][0]['text']);
    }

    public function testWithoutAGuideLinkTheReminderHasTheTicketAndCancelButtons(): void
    {
        $this->wpdb->nextResults[] = [$this->taskRow(5)];
        $this->wpdb->nextResults[] = [$this->assignmentRow(3)];
        $this->wpdb->nextRows[] = $this->personRow();

        $this->call()->run(25);

        $rows = ((array) $this->lastCallTo('sendMessage'))['reply_markup']['inline_keyboard'];
        self::assertCount(2, $rows);
        self::assertStringContainsString('eventcrew/v1/ticket?token=', $rows[0][0]['url']);
        self::assertSame('l:5', $rows[1][0]['callback_data']);
    }

    /** MessageAiText: the event's AI text when there is one, else the template's text. */
    public function testTheReminderUsesTheEventsAiTextWhenThereIsOne(): void
    {
        $bodies = [];
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body) use (&$bodies): bool {
            $bodies[] = $body;

            return true;
        });
        $base = $this->options;
        $task = ['event_post_id' => 42] + $this->taskRow(5);

        $this->options = self::aiTextOptions(['reminder', 'reminder_dm'], 42, [
            'reminder' => ['en' => 'AI mail for {name}.', 'fi' => 'AI FI'],
            'reminder_dm' => ['en' => 'AI DM about {role}.', 'fi' => 'AI FI'],
        ]) + $base;
        $this->wpdb->nextResults[] = [$task];
        $this->wpdb->nextResults[] = [$this->assignmentRow(3)];
        $this->wpdb->nextRows[] = $this->personRow();

        $this->call()->run(25);

        self::assertStringContainsString('AI DM about Decorate.', (string) $this->lastCallTo('sendMessage')['text']);
        self::assertStringContainsString('AI mail for Sam.', $bodies[0]);

        // The tick is on but nothing was written for the event: the template's text.
        $this->options = self::aiTextOptions(['reminder', 'reminder_dm'], 42, []) + $base;
        $this->wpdb->nextResults[] = [$task];
        $this->wpdb->nextResults[] = [$this->assignmentRow(4)];
        $this->wpdb->nextRows[] = $this->personRow();

        $this->call()->run(25);

        self::assertStringContainsString('Reminder: you’re on', (string) $this->lastCallTo('sendMessage')['text']);
        self::assertStringContainsString('Just a reminder that', $bodies[1]);
        self::assertStringNotContainsString('AI ', $bodies[1]);
    }

    /**
     * E2: with a crew page, a person with no Telegram chat is told to cancel
     * there, with its link and a Manage my tasks button; a person with a chat
     * keeps the bot text and gets no such button.
     */
    public function testAWebOnlyPersonIsRemindedWithTheCrewPageInsteadOfTheBot(): void
    {
        $bodies = [];
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body) use (&$bodies): bool {
            $bodies[] = $body;

            return true;
        });
        Functions\when('get_permalink')->alias(
            static fn (int $id): string|false => 7 === $id ? 'https://example.test/crew/' : false
        );
        $this->options['eventcrew_signup_page_id'] = 7;
        $this->options[EmailTemplate::HTML_OPTION] = false;

        $this->wpdb->nextResults[] = [$this->taskRow(5)];
        $this->wpdb->nextResults[] = [$this->assignmentRow(3)];
        $this->wpdb->nextRows[] = $this->personRow(null);

        $this->call()->run(25);

        self::assertNull($this->lastCallTo('sendMessage'));
        self::assertStringContainsString(
            "Can't make it? Tap Cancel on your task in the Board tab of the crew page"
                . ' (https://example.test/crew/).',
            $bodies[0]
        );
        self::assertStringContainsString("\nManage my tasks: https://example.test/crew/?eventcrew_login=", $bodies[0]);
        self::assertStringNotContainsString('bot', $bodies[0]);

        $this->wpdb->nextResults[] = [$this->taskRow(5)];
        $this->wpdb->nextResults[] = [$this->assignmentRow(4)];
        $this->wpdb->nextRows[] = $this->personRow();

        $this->call()->run(25);

        // The bot text, and Cancel leads to the crew page itself, not a sign-in link.
        self::assertStringContainsString("Can't make it? Tap Cancel below.", $bodies[1]);
        self::assertStringNotContainsString('Manage my tasks', $bodies[1]);
        self::assertStringContainsString("\nCan’t make it? Cancel: https://example.test/crew/\n", $bodies[1]);
    }
}
