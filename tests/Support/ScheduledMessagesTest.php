<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\BoardPush;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\FollowupMail;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\MonthlyReport;
use EventCrew\Support\Newsletter;
use EventCrew\Support\ReminderCall;
use EventCrew\Support\ScheduledMessages;
use EventCrew\Support\WelcomeSender;
use EventCrew\Telegram\AssistantActions;
use EventCrew\Tests\Telegram\FakeEvents;
use EventCrew\Tests\Telegram\TelegramTestCase;

/**
 * The Scheduled messages card (Owner 2026-11-14): every message listed, the
 * switch is wp-admin's own option where there is one, a switched-off
 * message is not sent, and "send now" goes through the real sender once,
 * asked with the count only.
 */
final class ScheduledMessagesTest extends TelegramTestCase
{
    use AiTextOptions;
    use FakeEvents;

    /** @var list<string> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mails = [];

        Functions\when('get_the_title')->justReturn('');
        Functions\when('get_the_post_thumbnail_url')->justReturn('');
        Functions\when('rest_url')->alias(static fn (string $path = ''): string => 'https://example.test/wp-json/' . $path);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_mail')->alias(function (string $to): bool {
            $this->mails[] = $to;

            return true;
        });

        $timestamp = (int) strtotime('2026-07-20 12:00:00 UTC');
        Functions\when('current_time')->alias(
            static function (string $format) use ($timestamp): string|int {
                if ('mysql' === $format) {
                    return gmdate('Y-m-d H:i:s', $timestamp);
                }

                return 'timestamp' === $format ? $timestamp : gmdate($format, $timestamp);
            }
        );
    }

    private function reminders(): ReminderCall
    {
        return new ReminderCall(
            new TaskRepository(),
            new AssignmentRepository(),
            new PersonRepository(),
            $this->client(),
            new Mailer(new Logger(), new EmailTemplate(new Logger()))
        );
    }

    private function scheduled(): ScheduledMessages
    {
        return new ScheduledMessages($this->reminders());
    }

    /** The next date's task, two people on it, the first not reminded yet. */
    private function queueNextNight(): void
    {
        $this->wpdb->nextCols[] = ['2026-07-21'];
        $this->wpdb->nextResults[] = [[
            'id' => 5,
            'task_date' => '2026-07-21',
            'role_slug' => 'decorate',
            'capacity' => 3,
            'event_post_id' => null,
            'event_label' => 'Dance',
            'starts_at' => '2026-07-21 18:00:00',
            'ends_at' => null,
        ]];
        $this->wpdb->nextResults[] = [
            ['id' => 3, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up', 'reminded_at' => null],
            ['id' => 4, 'task_id' => 5, 'person_id' => 8, 'status' => 'signed_up', 'reminded_at' => null],
            ['id' => 6, 'task_id' => 5, 'person_id' => 9, 'status' => 'signed_up', 'reminded_at' => '2026-07-20 09:00:00'],
        ];
        $this->wpdb->nextRows[] = self::person(7, 'sam@example.com');
        $this->wpdb->nextRows[] = self::person(8, 'kim@example.com');
    }

    /** @return array<string, mixed> */
    private static function person(int $id, string $email): array
    {
        return [
            'id' => $id,
            'email' => $email,
            'display_name' => 'Person ' . $id,
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_chat_id' => null,
        ];
    }

    public function testEveryMessageIsListedWithItsSwitchAndSendNow(): void
    {
        $rows = ScheduledMessages::rows();

        self::assertSame(ScheduledMessages::KEYS, array_column($rows, 'key'));
        self::assertSame('Task reminder', $rows[0]['name']);
        self::assertSame('Email + DM', $rows[0]['channel']);
        self::assertSame('24 h before', $rows[0]['when']);
        // New switches start on; welcome, newsletter and post-event email
        // keep wp-admin's off default.
        self::assertTrue($rows[0]['on']);
        self::assertFalse(ScheduledMessages::isOn(ScheduledMessages::WELCOME));
        self::assertFalse(ScheduledMessages::isOn(ScheduledMessages::NEWSLETTER));
        self::assertTrue(ScheduledMessages::isOn(ScheduledMessages::BOARD));
        self::assertSame(
            [ScheduledMessages::REMINDERS, ScheduledMessages::OPEN_TASKS, ScheduledMessages::BOARD, ScheduledMessages::MONTHLY],
            array_column(array_filter($rows, static fn (array $row): bool => $row['sendable']), 'key')
        );
        self::assertStringContainsString('✅ Task reminder (reminders)', ScheduledMessages::listText());
    }

    public function testTheSwitchIsWpAdminsOwnOptionWhereThereIsOne(): void
    {
        $scheduled = $this->scheduled();

        self::assertSame('admin_sched_off_board', $scheduled->apply(ScheduledMessages::BOARD, 'off'));
        self::assertSame('0', $this->options[BoardPush::ENABLED_OPTION]);
        self::assertFalse(ScheduledMessages::isOn(ScheduledMessages::BOARD));

        $scheduled->apply(ScheduledMessages::WELCOME, 'on');
        self::assertSame('1', $this->options[WelcomeSender::ENABLED_OPTION]);
        self::assertTrue(WelcomeSender::enabled());

        $scheduled->apply(ScheduledMessages::NEWSLETTER, 'on');
        self::assertTrue(Newsletter::enabled());

        // The post-event email notes when it was switched on, as wp-admin does.
        $scheduled->apply(ScheduledMessages::FOLLOWUP, 'on');
        self::assertTrue(FollowupMail::enabled());
        self::assertGreaterThan(0, (int) $this->options[FollowupMail::ENABLED_AT_OPTION]);

        $scheduled->apply(ScheduledMessages::MONTHLY, 'off');
        self::assertFalse(MonthlyReport::isOn());

        self::assertSame('admin_sched_off_reminders', $scheduled->apply(ScheduledMessages::REMINDERS, 'off'));
        self::assertSame('0', $this->options[ScheduledMessages::OPTIONS[ScheduledMessages::REMINDERS]]);
        self::assertSame('Task reminder: off.', ScheduledMessages::noticeText('admin_sched_off_reminders'));

        self::assertSame('admin_sched_unknown', $scheduled->apply('nosuch', 'off'));
        self::assertSame('admin_sched_unknown', $scheduled->apply(ScheduledMessages::WELCOME, 'send'));
    }

    public function testASwitchedOffReminderIsNotSent(): void
    {
        ScheduledMessages::setOn(ScheduledMessages::REMINDERS, false);
        $this->wpdb->nextResults[] = [['id' => 5, 'task_date' => '2026-07-21', 'role_slug' => 'decorate', 'starts_at' => '2026-07-21 08:00:00']];

        self::assertSame(0, $this->reminders()->run(25));
        self::assertSame([], $this->mails);
        // Nothing was even read.
        self::assertCount(1, $this->wpdb->nextResults);
    }

    public function testSendNowAsksWithTheCountOnly(): void
    {
        $this->queueNextNight();

        $question = $this->scheduled()->question(ScheduledMessages::REMINDERS);

        self::assertSame('Send "Task reminder" now to 2 people?', $question);
        self::assertStringNotContainsString('@', $question);
        self::assertSame([], $this->mails);
    }

    public function testSendNowGoesThroughTheReminderOnceEach(): void
    {
        $this->queueNextNight();

        $code = $this->scheduled()->apply(ScheduledMessages::REMINDERS, 'send');

        self::assertSame('admin_sched_sent_reminders_2', $code);
        self::assertSame('"Task reminder" sent to 2 people.', ScheduledMessages::noticeText($code));
        self::assertSame(['sam@example.com', 'kim@example.com'], $this->mails);
        // Each marked reminded, so the scheduled run skips them later.
        self::assertSame([3, 4], array_map(static fn (array $update): int => (int) $update['where']['id'], $this->wpdb->updates));
        self::assertArrayHasKey('reminded_at', $this->wpdb->updates[0]['data']);
    }

    public function testSendNowWithNobodyToSendTo(): void
    {
        self::assertSame('', $this->scheduled()->question(ScheduledMessages::REMINDERS));
        self::assertSame('admin_sched_none', $this->scheduled()->apply(ScheduledMessages::REMINDERS, 'send'));
        self::assertSame('Nobody to send it to.', ScheduledMessages::noticeText('admin_sched_none'));
    }

    public function testTheHelperLineIsCheckedLikeTheCard(): void
    {
        $organizer = ['organizer' => true, 'welcomeCrew' => false, 'member' => true, 'web' => true];

        $ok = AssistantActions::validate('/scheduled Reminders OFF', $organizer, null, '2026-07-20', [12]);
        self::assertTrue($ok['ok']);
        self::assertSame('/scheduled reminders off', $ok['action']);

        self::assertTrue(AssistantActions::validate('/scheduled', $organizer, null, '2026-07-20', [12])['ok']);
        self::assertFalse(AssistantActions::validate('/scheduled welcome send', $organizer, null, '2026-07-20', [12])['ok']);
        self::assertFalse(AssistantActions::validate('/scheduled nosuch on', $organizer, null, '2026-07-20', [12])['ok']);
        self::assertFalse(
            AssistantActions::validate('/scheduled reminders off', ['organizer' => false, 'welcomeCrew' => false, 'member' => true], null, '2026-07-20', [12])['ok']
        );
    }
}
