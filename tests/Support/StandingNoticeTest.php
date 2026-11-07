<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\StandingNotice;
use EventCrew\Telegram\DohResolver;
use EventCrew\Telegram\TelegramClient;
use EventCrew\Tests\TestCase;

/**
 * The delayed no-show / late-cancel notice. Light coverage: a due no-show is
 * told on both channels and recorded, so the next run won't repeat it.
 */
final class StandingNoticeTest extends TestCase
{
    use AiTextOptions;

    /** @var array<int, string> */
    private array $mails = [];

    /** @var array<int, array<string, mixed>> */
    private array $sends = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mails = [];
        $this->sends = [];

        Functions\when('get_option')->alias(
            fn (string $name): mixed => TelegramClient::TOKEN_OPTION === $name ? 'BOT:TOKEN'
                : ('eventcrew_link_secret' === $name ? 'test-secret' : false)
        );
        Functions\when('get_the_title')->justReturn('');
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_json_encode')->alias(static fn (mixed $d): string => (string) json_encode($d));
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_body')->returnArg(1);
        Functions\when('wp_remote_post')->alias(function (string $url, array $args): string {
            $this->sends[] = (array) json_decode((string) $args['body'], true);

            return (string) json_encode(['ok' => true, 'result' => true]);
        });
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = $body;

            return true;
        });
    }

    private function service(): StandingNotice
    {
        $ledger = new NotificationsRepository();

        return new StandingNotice(
            new TaskRepository(),
            new AssignmentRepository(),
            new PersonRepository(),
            $ledger,
            new TelegramClient(new Logger(), new DohResolver(new Logger())),
            new Mailer(new Logger(), new EmailTemplate(new Logger())),
            new StandingCalculator(new AssignmentRepository(), new RedemptionRepository(), new CreditGrantRepository())
        );
    }

    public function testADueNoShowIsToldOnBothChannelsAndRecorded(): void
    {
        // needingStandingNotice -> one no-show marked more than a day ago.
        $this->wpdb->nextResults[] = [
            [
                'id' => 11,
                'task_id' => 5,
                'person_id' => 7,
                'status' => 'no_show',
                'status_changed_at' => '2026-07-18 09:00:00',
                'task_date' => '2026-07-17',
            ],
        ];
        // tasks->find(5)
        $this->wpdb->nextRows[] = [
            'id' => 5,
            'task_date' => '2026-07-17',
            'role_slug' => 'decorate',
            'capacity' => 2,
            'event_label' => 'Party',
        ];
        // people->find(7) — linked to Telegram, active.
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_chat_id' => 4242,
        ];

        $sent = $this->service()->sendDue(25);

        self::assertSame(1, $sent);
        self::assertCount(1, $this->sends);
        self::assertSame(4242, $this->sends[0]['chat_id']);
        self::assertStringContainsString('no-show', (string) $this->sends[0]['text']);
        // The day as the other emails write it, not 2026-07-17.
        self::assertStringContainsString('Decorate at Party, Fri 17 Jul', (string) $this->sends[0]['text']);
        self::assertCount(1, $this->mails);
        self::assertStringContainsString('Decorate at Party, Fri 17 Jul', $this->mails[0]);
        self::assertStringNotContainsString('2026-07-17', $this->mails[0]);
        // The ledger row that stops the next run repeating it.
        self::assertStringContainsString('standing_hit', $this->wpdb->lastQuery());
    }

    /** MessageAiText: the event's AI text when there is one, else the template's text. */
    public function testTheNoticeUsesTheEventsAiTextWhenThereIsOne(): void
    {
        $texts = [
            'no_show' => ['en' => 'AI mail for {name}.', 'fi' => 'AI FI'],
            'no_show_dm' => ['en' => 'AI DM about {task}.', 'fi' => 'AI FI'],
        ];

        foreach ([$texts, []] as $stored) {
            $options = self::aiTextOptions(['no_show', 'no_show_dm'], 42, $stored) + [
                TelegramClient::TOKEN_OPTION => 'BOT:TOKEN',
                'eventcrew_link_secret' => 'test-secret',
            ];
            Functions\when('get_option')->alias(
                static fn (string $name, mixed $default = false): mixed => $options[$name] ?? $default
            );
            $this->wpdb->nextResults[] = [[
                'id' => 11, 'task_id' => 5, 'person_id' => 7, 'status' => 'no_show',
                'status_changed_at' => '2026-07-18 09:00:00', 'task_date' => '2026-07-17',
            ]];
            $this->wpdb->nextRows[] = [
                'id' => 5, 'task_date' => '2026-07-17', 'role_slug' => 'decorate', 'capacity' => 2,
                'event_post_id' => 42, 'event_label' => 'Party',
            ];
            $this->wpdb->nextRows[] = [
                'id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam',
                'email_verified_at' => '2026-07-01 00:00:00', 'telegram_chat_id' => 4242,
            ];

            $this->service()->sendDue(25);
        }

        self::assertCount(2, $this->sends);
        self::assertCount(2, $this->mails);
        self::assertStringContainsString('AI DM about Decorate at Party', (string) $this->sends[0]['text']);
        self::assertStringContainsString('AI mail for Sam.', $this->mails[0]);
        // The tick is on but nothing was written for the event: the template's text.
        self::assertStringContainsString('You were marked as a no-show', (string) $this->sends[1]['text']);
        self::assertStringContainsString('You were marked as a no-show', $this->mails[1]);
        self::assertStringNotContainsString('AI ', $this->mails[1]);
    }

    /**
     * E2: the late-cancel email gives a person with no Telegram chat the crew
     * page link and a Manage my tasks button; a person with a chat keeps the
     * bot text and gets no button.
     */
    public function testALateCancelNoticeGivesAWebOnlyPersonTheCrewPage(): void
    {
        $options = [
            TelegramClient::TOKEN_OPTION => 'BOT:TOKEN',
            'eventcrew_link_secret' => 'test-secret',
            'eventcrew_signup_page_id' => 7,
            EmailTemplate::HTML_OPTION => false,
        ];
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => $options[$name] ?? $default
        );
        Functions\when('get_permalink')->alias(
            static fn (int $id): string|false => 7 === $id ? 'https://site.test/crew/' : false
        );

        foreach ([null, 4242] as $chatId) {
            $this->wpdb->nextResults[] = [[
                'id' => 11, 'task_id' => 5, 'person_id' => 7, 'status' => 'late_cancel',
                'status_changed_at' => '2026-07-18 09:00:00', 'task_date' => '2026-07-17',
            ]];
            $this->wpdb->nextRows[] = [
                'id' => 5, 'task_date' => '2026-07-17', 'role_slug' => 'decorate', 'capacity' => 2,
                'event_label' => 'Party',
            ];
            $this->wpdb->nextRows[] = [
                'id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam',
                'email_verified_at' => '2026-07-01 00:00:00', 'telegram_chat_id' => $chatId,
            ];

            $this->service()->sendDue(25);
        }

        self::assertCount(2, $this->mails);
        self::assertStringContainsString(
            'It counts against your standing. More notice next time keeps it clear: tap Cancel on your task in the'
                . ' Board tab of the crew page (https://site.test/crew/) as soon as you know.',
            $this->mails[0]
        );
        self::assertStringContainsString(
            "\nManage my tasks: https://site.test/crew/?eventcrew_login=",
            $this->mails[0]
        );
        self::assertStringNotContainsString('/me', $this->mails[0]);

        self::assertStringContainsString(
            'It counts against your standing. Next time tap Cancel early or find someone to take it.',
            $this->mails[1]
        );
        self::assertStringNotContainsString('Manage my tasks', $this->mails[1]);
    }

    /** One due mark of $status for person 7 (Telegram chat 4242) on task 5. */
    private function queueMark(string $status): void
    {
        $this->wpdb->nextResults[] = [[
            'id' => 11, 'task_id' => 5, 'person_id' => 7, 'status' => $status,
            'status_changed_at' => '2026-07-18 09:00:00', 'task_date' => '2026-07-17',
        ]];
        $this->wpdb->nextRows[] = [
            'id' => 5, 'task_date' => '2026-07-17', 'role_slug' => 'decorate', 'capacity' => 2,
            'event_label' => 'Party',
        ];
        $this->wpdb->nextRows[] = [
            'id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00', 'telegram_chat_id' => 4242,
        ];
    }

    /**
     * Less friction: both notices say where the person stands now and offer
     * the open tasks, in the DM as a button and in the email as a link.
     */
    public function testBothNoticesShowTheStandingAndTheOpenTasks(): void
    {
        $options = [
            TelegramClient::TOKEN_OPTION => 'BOT:TOKEN',
            'eventcrew_link_secret' => 'test-secret',
            EmailTemplate::HTML_OPTION => false,
            'eventcrew_signup_page_id' => 7,
        ];
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => $options[$name] ?? $default
        );
        Functions\when('get_permalink')->justReturn('https://site.test/crew/');

        foreach (['late_cancel', 'no_show'] as $i => $status) {
            $this->queueMark($status);
            $this->service()->sendDue(25);

            $dm = $this->sends[$i];
            self::assertStringContainsString('Your standing: ', (string) $dm['text'], $status);
            self::assertStringContainsString('free-entry credits.', (string) $dm['text'], $status);
            $buttons = array_column(array_merge(...$dm['reply_markup']['inline_keyboard']), 'callback_data', 'text');
            self::assertSame(['📋 See open tasks' => 'mn:board'], $buttons, $status);
            self::assertStringContainsString('Your standing: ', $this->mails[$i], $status);
            self::assertStringContainsString('See open tasks: https://site.test/crew/', $this->mails[$i], $status);
        }
    }

    /**
     * "Not right? Tell us" under a no-show, on both channels, only while the
     * bot's helper forwards chats to the organizers; never under a late
     * cancellation.
     */
    public function testANoShowOffersTellUsOnlyWhileTheHelperForwards(): void
    {
        $options = [
            TelegramClient::TOKEN_OPTION => 'BOT:TOKEN',
            'eventcrew_link_secret' => 'test-secret',
            EmailTemplate::HTML_OPTION => false,
            'eventcrew_telegram_bot_username' => 'crewbot',
            AssistantSettings::ENABLED_OPTION => true,
            AssistantSettings::URL_OPTION => 'https://llm.test/v1',
            AssistantSettings::MODEL_OPTION => 'm',
        ];
        Functions\when('get_option')->alias(
            static function (string $name, mixed $default = false) use (&$options): mixed {
                return $options[$name] ?? $default;
            }
        );

        $this->queueMark('no_show');
        $this->service()->sendDue(25);
        $this->queueMark('late_cancel');
        $this->service()->sendDue(25);
        $options[AssistantSettings::FORWARD_OPTION] = false;
        $this->queueMark('no_show');
        $this->service()->sendDue(25);

        $tap = static fn (array $dm): array => array_column(
            array_merge(...$dm['reply_markup']['inline_keyboard']),
            'callback_data',
            'text'
        );
        self::assertSame(
            ['📋 See open tasks' => 'mn:board', '💬 Not right? Tell us' => 'mn:tell'],
            $tap($this->sends[0])
        );
        self::assertStringContainsString('Not right? Tell us in Telegram: https://t.me/crewbot?start=tell', $this->mails[0]);
        self::assertSame(['📋 See open tasks' => 'mn:board'], $tap($this->sends[1]));
        self::assertStringNotContainsString('Tell us', $this->mails[1]);
        self::assertSame(['📋 See open tasks' => 'mn:board'], $tap($this->sends[2]));
        self::assertStringNotContainsString('Tell us', $this->mails[2]);
    }
}
