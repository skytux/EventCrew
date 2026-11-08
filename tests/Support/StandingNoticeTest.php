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
use EventCrew\Support\ExtraTaskCredits;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\SignupService;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\Signature;
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

    private string $signature = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->mails = [];
        $this->sends = [];
        $this->signature = '';

        Functions\when('get_option')->alias(
            fn (string $name): mixed => match ($name) {
                TelegramClient::TOKEN_OPTION => 'BOT:TOKEN',
                'eventcrew_link_secret' => 'test-secret',
                Signature::OPTION => '' !== $this->signature ? $this->signature : false,
                ExtraTaskCredits::OPTION => '1',
                default => false,
            }
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

    private function service(bool $extra = false): StandingNotice
    {
        $ledger = new NotificationsRepository();

        return new StandingNotice(
            new TaskRepository(),
            new AssignmentRepository(),
            new PersonRepository(),
            $ledger,
            new TelegramClient(new Logger(), new DohResolver(new Logger())),
            new Mailer(new Logger(), new EmailTemplate(new Logger())),
            new StandingCalculator(new AssignmentRepository(), new RedemptionRepository(), new CreditGrantRepository()),
            $extra ? new ExtraTaskCredits() : null
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
        $ledger = array_filter($this->wpdb->queries, static fn (string $q): bool => str_contains($q, 'INSERT'));
        self::assertStringContainsString('standing_hit', implode("\n", $ledger));
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
        self::assertStringContainsString('We missed you at Decorate at Party', (string) $this->sends[1]['text']);
        self::assertStringContainsString('We missed you at Decorate at Party', $this->mails[1]);
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
            'It was a late cancellation, and early notice matters: it gives someone else time to take the place. Next'
                . ' time, tap Cancel on your task in the Board tab of the crew page (https://site.test/crew/) as soon as'
                . ' you know. You can cancel without it affecting your standing up to 48 hours before the task.',
            $this->mails[0]
        );
        self::assertStringContainsString(
            "\nManage my tasks: https://site.test/crew/?eventcrew_login=",
            $this->mails[0]
        );
        self::assertStringNotContainsString('/me', $this->mails[0]);

        self::assertStringContainsString(
            'Next time, tap Cancel as soon as you know, or find someone to take it over: they send /me to the bot and'
                . ' tap 🔁 Take over a task.',
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
            self::assertStringContainsString('You get a standing score after', (string) $dm['text'], $status);
            $buttons = array_column(array_merge(...$dm['reply_markup']['inline_keyboard']), 'callback_data', 'text');
            self::assertSame(['📋 See open tasks' => 'mn:board'], $buttons, $status);
            self::assertStringContainsString('You get a standing score after', $this->mails[$i], $status);
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

    /**
     * The no-show DM is kind (Owner 2026-11-07): it invites the person to try
     * again, says how to avoid it next time with the notice hours from
     * Settings, and keeps the hint for a mistake. The late-cancel DM thanks
     * them and names the score weights from Settings.
     */
    public function testTheNoShowDmInvitesToTryAgainAndKeepsTheMistakeHint(): void
    {
        $options = [
            TelegramClient::TOKEN_OPTION => 'BOT:TOKEN',
            'eventcrew_link_secret' => 'test-secret',
            EmailTemplate::HTML_OPTION => false,
            SignupService::NOTICE_HOURS_OPTION => 36,
        ];
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => $options[$name] ?? $default
        );

        $this->queueMark('no_show');
        $this->service()->sendDue(25);
        $this->queueMark('late_cancel');
        $this->service()->sendDue(25);

        $dm = (string) $this->sends[0]['text'];
        self::assertStringContainsString('We missed you at Decorate at Party', $dm);
        self::assertStringContainsString('Unexpected things happen', $dm);
        self::assertStringContainsString('sign up again!', $dm);
        self::assertStringContainsString('If this is a mistake, tell an organizer.', $dm);
        self::assertStringContainsString('up to 36 hours before the task', $dm);
        self::assertStringNotContainsString('⚠️', $dm);

        $late = (string) $this->sends[1]['text'];
        self::assertStringContainsString('Thanks for letting us know about Decorate at Party', $late);
        self::assertStringContainsString('A late cancellation counts 40% in your score, a completed task 100%.', $late);
        self::assertStringContainsString('sign up again!', $late);
        self::assertStringContainsString('If this is a mistake, tell an organizer.', $late);
    }

    /**
     * A completed task is thanked a day later, with the credits and the
     * standing before and after as a bar; recorded under its own ledger kind.
     */
    public function testACompletedTaskIsThankedWithTheStandingBar(): void
    {
        $row = static fn (int $id, string $status, string $date): array => [
            'id' => $id,
            'task_id' => 5,
            'person_id' => 7,
            'status' => $status,
            'status_changed_at' => $date . ' 23:00:00',
            'task_date' => $date,
        ];
        // needingStandingNotice for the marks against: none.
        $this->wpdb->nextResults[] = [];
        // needingStandingNotice for the thank-you: one completed task.
        $this->wpdb->nextResults[] = [$row(11, 'completed', '2026-07-17')];
        $this->wpdb->nextRows[] = [
            'id' => 5,
            'task_date' => '2026-07-17',
            'role_slug' => 'decorate',
            'capacity' => 2,
            'event_label' => 'Party',
        ];
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_chat_id' => 4242,
        ];
        // historyFor: this task, two more completed and an older no-show.
        $this->wpdb->nextResults[] = [
            $row(11, 'completed', '2026-07-17'),
            $row(10, 'completed', '2026-07-10'),
            $row(9, 'no_show', '2026-07-03'),
            $row(8, 'completed', '2026-06-26'),
        ];
        $this->signature = 'With love, the crew';

        self::assertSame(1, $this->service()->sendDue(25));

        $dm = (string) $this->sends[0]['text'];
        // Sent unasked by the scan: framed and signed (Owner, 2026-11-07).
        self::assertStringStartsWith('💌 ', $dm);
        self::assertStringEndsWith("\n\nWith love, the crew", $dm);
        self::assertStringContainsString('Thank you for Decorate at Party', $dm);
        self::assertMatchesRegularExpression('/[🟩🟧🟥⬜]{10} \d+% · /u', $dm);
        self::assertMatchesRegularExpression('/Standing \d+% → \d+%\./', $dm);
        self::assertStringContainsString('more completed task', $dm);
        self::assertCount(1, $this->mails);
        // The text bar; StandingBarTest draws the HTML one.
        self::assertMatchesRegularExpression('/[🟩🟧🟥⬜]{10} \d+% · /u', $this->mails[0]);
        self::assertStringContainsString('task_thanks', $this->wpdb->lastQuery());
        // The thank-you only looks back a few days, not over every task ever.
        $thanksQuery = implode("\n", array_filter(
            $this->wpdb->queries,
            static fn (string $q): bool => str_contains($q, "'task_thanks'") && str_contains($q, 'SELECT')
        ));
        self::assertStringContainsString("a.status IN ('completed','arrived_late')", $thanksQuery);
        self::assertStringContainsString('a.status_changed_at >= ', $thanksQuery);
    }

    /**
     * A thank-you on a night with two completed tasks: the credit for the
     * second is added (one row, keyed by its assignment) and told in the
     * thank-you, above the credits.
     */
    public function testTheThankYouAddsTheCreditForAnExtraTaskThatNight(): void
    {
        $this->queueThanksForTwoTasks();
        // recordExtraTask: the row went in.
        $this->wpdb->nextQueryResults[] = 1;

        self::assertSame(1, $this->service(true)->sendDue(25));

        $dm = (string) $this->sends[0]['text'];
        self::assertStringContainsString('Your extra task that night earned 1 more free-entry credit.', $dm);
        self::assertStringContainsString('Your extra task that night earned 1 more free-entry credit.', $this->mails[0]);

        $inserts = array_values(array_filter(
            $this->wpdb->queries,
            static fn (string $q): bool => str_contains($q, 'INSERT IGNORE INTO wp_eventcrew_credit_grants')
        ));
        // Only the second task of the night; the first is the ordinary one.
        self::assertCount(1, $inserts);
        self::assertStringContainsString("'extra_task', 12)", $inserts[0]);
    }

    /**
     * The credit was already added (a second run, or the late sweep before):
     * the unique key adds nothing and the thank-you says nothing about it.
     */
    public function testAnExtraTaskCreditIsAddedOnlyOnce(): void
    {
        $this->queueThanksForTwoTasks();
        // recordExtraTask: INSERT IGNORE hit the unique key.
        $this->wpdb->nextQueryResults[] = 0;

        self::assertSame(1, $this->service(true)->sendDue(25));

        self::assertStringNotContainsString('extra task', (string) $this->sends[0]['text']);
    }

    /**
     * A task marked completed after the night's thank-you went out: the late
     * sweep adds its credit and sends a short note on both channels.
     */
    public function testALateMarkedExtraTaskGetsItsCreditWithAShortNote(): void
    {
        // Marks against: none. Thank-yous: none.
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];
        // extraTasksToAdd: one night.
        $this->wpdb->nextResults[] = [['person_id' => 7, 'task_date' => '2026-07-17']];
        // creditedIdsOn, then the row went in.
        $this->wpdb->nextCols[] = [11, 12];
        $this->wpdb->nextQueryResults[] = 1;
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_chat_id' => 4242,
        ];

        self::assertSame(1, $this->service(true)->sendDue(25));

        self::assertStringContainsString(
            'Your extra task that night earned 1 more free-entry credit.',
            (string) $this->sends[0]['text']
        );
        self::assertCount(1, $this->mails);
        self::assertStringContainsString('Hi Sam,', $this->mails[0]);
        $sweep = implode("\n", array_filter(
            $this->wpdb->queries,
            static fn (string $q): bool => str_contains($q, 'SELECT DISTINCT a.person_id, s.task_date')
        ));
        // Only after the night's thank-you, and only tasks with no credit row.
        self::assertStringContainsString("n.kind = 'task_thanks'", $sweep);
        self::assertStringContainsString('NOT EXISTS (SELECT 1 FROM wp_eventcrew_credit_grants g', $sweep);
    }

    /**
     * A task changed from completed to no-show after its extra-task credit
     * was added: the credit is taken back and the person told at once.
     */
    public function testANoShowAfterTheCreditTakesItBackAndTellsThePerson(): void
    {
        // assignments->find(12), tasks->find(5).
        $this->wpdb->nextRows[] = ['id' => 12, 'task_id' => 5, 'person_id' => 7, 'status' => 'no_show'];
        $this->wpdb->nextRows[] = [
            'id' => 5,
            'task_date' => '2026-07-17',
            'role_slug' => 'decorate',
            'capacity' => 2,
            'event_label' => 'Party',
        ];
        // extraTaskAssignmentsOn: 12 had the credit; creditedIdsOn: only 11 left.
        $this->wpdb->nextCols[] = [12];
        $this->wpdb->nextCols[] = [11];
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_chat_id' => 4242,
        ];

        $this->service(true)->onStatusChanged(12, 'no_show');

        self::assertSame(12, $this->wpdb->deletes[0]['where']['assignment_id']);
        self::assertStringContainsString(
            'A task of yours was marked as not done, so 1 free-entry credit for an extra task that night was taken back.',
            (string) $this->sends[0]['text']
        );
        self::assertCount(1, $this->mails);
    }

    public function testMarkingATaskCompletedTakesNothingBack(): void
    {
        $this->service(true)->onStatusChanged(12, 'completed');
        $this->service(true)->onStatusChanged(12, 'arrived_late');

        self::assertSame([], $this->wpdb->queries);
        self::assertSame([], $this->sends);
    }

    public function testNoCreditToTakeBackSendsNothing(): void
    {
        $this->wpdb->nextRows[] = ['id' => 12, 'task_id' => 5, 'person_id' => 7, 'status' => 'no_show'];
        $this->wpdb->nextRows[] = ['id' => 5, 'task_date' => '2026-07-17', 'role_slug' => 'decorate', 'capacity' => 2];
        $this->wpdb->nextCols[] = [];

        $this->service(true)->onStatusChanged(12, 'no_show');

        self::assertSame([], $this->wpdb->deletes);
        self::assertSame([], $this->sends);
        self::assertSame([], $this->mails);
    }

    /** Without the setting, a night with two completed tasks adds nothing. */
    public function testNoExtraTaskCreditWhenTheSettingIsOff(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name): mixed => match ($name) {
                TelegramClient::TOKEN_OPTION => 'BOT:TOKEN',
                ExtraTaskCredits::OPTION => '0',
                default => false,
            }
        );
        $this->queueThanksForTwoTasks(false);

        self::assertSame(1, $this->service(true)->sendDue(25));

        self::assertStringNotContainsString('extra task', (string) $this->sends[0]['text']);
        foreach ($this->wpdb->queries as $query) {
            self::assertStringNotContainsString('INSERT IGNORE INTO wp_eventcrew_credit_grants', $query);
        }
    }

    private function queueThanksForTwoTasks(bool $credited = true): void
    {
        $row = static fn (int $id, string $date): array => [
            'id' => $id,
            'task_id' => 5,
            'person_id' => 7,
            'status' => 'completed',
            'status_changed_at' => $date . ' 23:00:00',
            'task_date' => $date,
        ];
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [$row(12, '2026-07-17')];
        $this->wpdb->nextRows[] = [
            'id' => 5,
            'task_date' => '2026-07-17',
            'role_slug' => 'decorate',
            'capacity' => 2,
            'event_label' => 'Party',
        ];
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_chat_id' => 4242,
        ];

        if ($credited) {
            // creditedIdsOn: both of the night's tasks.
            $this->wpdb->nextCols[] = [11, 12];
        }

        // historyFor.
        $this->wpdb->nextResults[] = [$row(12, '2026-07-17'), $row(11, '2026-07-17')];
    }
}
