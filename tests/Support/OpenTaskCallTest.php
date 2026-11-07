<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\OpenTaskCall;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\DohResolver;
use EventCrew\Telegram\TelegramClient;
use EventCrew\Tests\TestCase;

final class OpenTaskCallTest extends TestCase
{
    /** @var array<int, array{to: string, subject: string}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mails = [];

        // Roles::all() (via Task::roleLabel) and the mailer footer read options.
        Functions\when('get_option')->justReturn(null);
        Functions\when('get_the_title')->justReturn('');

        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body = ''): bool {
            $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

            return true;
        });
        Functions\when('rest_url')->alias(static fn (string $path = ''): string => 'https://example.test/wp-json/' . $path);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
    }

    private function call(): OpenTaskCall
    {
        return new OpenTaskCall(
            new TaskRepository(),
            new AssignmentRepository(),
            new PersonRepository(),
            new NotificationsRepository(),
            new Mailer(new Logger(), new EmailTemplate(new Logger())),
            new TelegramClient(new Logger(), new DohResolver(new Logger()))
        );
    }

    /**
     * @param int $taken occupancy to report for the task
     * @return array<int, array<string, mixed>>
     */
    private function occupancyRows(int $taken): array
    {
        return $taken > 0 ? [['task_id' => 5, 'taken' => $taken]] : [];
    }

    private function taskRow(string $date = '2026-08-01'): array
    {
        return [
            'id' => 5,
            'task_date' => $date,
            'role_slug' => 'decorate',
            'capacity' => 2,
            'event_post_id' => null,
            'event_label' => 'Party',
            'starts_at' => $date . ' 20:00:00',
            'ends_at' => null,
        ];
    }

    private function activePerson(): array
    {
        return [
            'id' => 7,
            'email' => 'sam@example.com',
            'email_verified_at' => '2026-07-01 00:00:00',
        ];
    }

    /** The two leads the scheduler passes: a week out, then 48 hours out. */
    private function leads(): array
    {
        return [OpenTaskCall::KIND_WEEK => 168, OpenTaskCall::KIND => 48];
    }

    /** Queues the open-task lookup for one date: forDate, then occupancyFor. */
    private function queueOpenDate(int $taken = 0, string $date = '2026-08-01'): void
    {
        $this->wpdb->nextResults[] = [$this->taskRow($date)];
        $this->wpdb->nextResults[] = $this->occupancyRows($taken);
    }

    /**
     * As above, plus the earliest start on the date - which the automated call
     * asks for so a lead counts back from when the work begins rather than from
     * the date's midnight. Early in the day, so the lead is always reached.
     */
    private function queueDueDate(int $taken = 0, string $date = '2026-08-01'): void
    {
        $this->queueOpenDate($taken, $date);
        $this->wpdb->nextVars[] = '2026-07-21 09:00:00';
    }

    /** Queues the two reads the personal recap makes. */
    private function queueRecap(): void
    {
        $this->wpdb->nextVars[] = 0;       // countCompletedFor
        $this->wpdb->nextResults[] = [];   // historyFor
    }

    public function testMailsAnActiveRecipientAndRecordsTheSend(): void
    {
        $this->queueOpenDate();                                 // hasOpenSlots
        $this->wpdb->nextResults[] = [$this->activePerson()];   // activeEmailRecipients
        $this->wpdb->nextVars[] = null;                         // ledger.hasSent -> not sent
        $this->queueRecap();

        $sent = $this->call()->sendForDate('2026-08-01');

        self::assertSame(1, $sent);
        self::assertCount(1, $this->mails);
        self::assertSame('sam@example.com', $this->mails[0]['to']);
        // Recorded in the send-once ledger, as an upsert so a re-send refreshes
        // the timestamp the "anything added since?" check measures from.
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $this->wpdb->lastQuery());
    }

    /** The date heading and the recent tasks show the day as "Sat 1 Aug", not 2026-08-01. */
    public function testTheDatesAreReadableDays(): void
    {
        $this->queueOpenDate();
        $this->wpdb->nextResults[] = [$this->activePerson()];
        $this->wpdb->nextVars[] = null;
        $this->wpdb->nextCols[] = ['2026-08-01'];                                         // upcomingDates
        $this->wpdb->nextVars[] = 1;                                                     // countCompletedFor
        $this->wpdb->nextResults[] = [['id' => 3, 'status' => 'signed_up', 'task_date' => '2026-07-18']];

        $this->call()->sendForDate('2026-08-01');

        $body = $this->mails[0]['body'];
        self::assertStringContainsString('Sat 1 Aug', $body);
        self::assertStringContainsString('- Sat 18 Jul: ', $body);
        self::assertStringNotContainsString('2026-08-01', $body);
        self::assertStringNotContainsString('2026-07-18', $body);
    }

    public function testSendsNothingWhenEverythingIsStaffed(): void
    {
        $this->queueOpenDate(2);   // the one task is full (2 of 2)

        $sent = $this->call()->sendForDate('2026-08-01');

        self::assertSame(0, $sent);
        self::assertSame([], $this->mails);
    }

    /**
     * The organize task has no limit and is for organizers only: an empty one
     * is no reason to mail anyone.
     */
    public function testAnEmptyOrganizeTaskIsNoReasonToMail(): void
    {
        $organize = array_merge($this->taskRow(), ['id' => 6, 'role_slug' => 'organize', 'capacity' => 1, 'starts_at' => null]);
        $this->wpdb->nextResults[] = [$this->taskRow(), $organize];
        $this->wpdb->nextResults[] = [['task_id' => 5, 'taken' => 2]]; // decorate full, organize empty

        self::assertSame(0, $this->call()->sendForDate('2026-08-01'));
        self::assertSame([], $this->mails);
    }

    public function testTheMailDoesNotListTheOrganizeTask(): void
    {
        $organize = array_merge($this->taskRow(), ['id' => 6, 'role_slug' => 'organize', 'capacity' => 1, 'starts_at' => null]);
        $this->wpdb->nextResults[] = [$this->taskRow(), $organize];
        $this->wpdb->nextResults[] = [];                        // nobody on either
        $this->wpdb->nextResults[] = [$this->activePerson()];
        $this->wpdb->nextVars[] = null;
        $this->wpdb->nextCols[] = ['2026-08-01'];               // upcomingDates, for the list
        $this->queueRecap();

        self::assertSame(1, $this->call()->sendForDate('2026-08-01'));
        self::assertStringContainsString('Decorate', $this->mails[0]['body']);
        self::assertStringNotContainsString('Organize', $this->mails[0]['body']);
    }

    public function testSkipsAnyoneAlreadyMailedForThatDate(): void
    {
        $this->queueOpenDate();
        $this->wpdb->nextResults[] = [$this->activePerson()];
        $this->wpdb->nextVars[] = '2026-07-19 09:00:00';   // ledger.hasSent -> already sent

        $sent = $this->call()->sendForDate('2026-08-01');

        self::assertSame(0, $sent);
        self::assertSame([], $this->mails);
    }

    public function testOneEmailCoversEveryDueDate(): void
    {
        // "now" is 2026-07-20 12:00. Two dates inside the 48h window would once
        // have been two separate emails to the same person.
        $this->wpdb->nextResults[] = [$this->activePerson()];   // activeEmailRecipients
        $this->wpdb->nextVars[] = null;                         // daily cap: nothing today
        $this->wpdb->nextCols[] = ['2026-07-21', '2026-07-22']; // upcomingDates

        $this->queueDueDate();                                 // 07-21 open
        $this->wpdb->nextVars[] = null;                         // sentAt week
        $this->wpdb->nextVars[] = null;                         // sentAt soon
        $this->queueDueDate();                                 // 07-22 open
        $this->wpdb->nextVars[] = null;
        $this->wpdb->nextVars[] = null;
        $this->queueRecap();

        $sent = $this->call()->sendDue($this->leads(), 25);

        self::assertSame(1, $sent);
        self::assertCount(1, $this->mails);
    }

    /**
     * The lead counts back from when the work starts, not from the date's
     * midnight. Reported from the field: a 48-hour last call for a task at
     * 17:00 arrived 65 hours ahead, because midnight was what the clock was
     * being compared against.
     */
    public function testTheLeadCountsBackFromTheTaskNotTheMidnightBeforeIt(): void
    {
        // "now" is 2026-07-20 12:00. The task is at 17:00 on the 22nd, which is
        // 53 hours away - outside a 48-hour last call, though its midnight is
        // only 36 hours away and would once have triggered one.
        $this->wpdb->nextResults[] = [$this->activePerson()];
        $this->wpdb->nextVars[] = null;                          // daily cap clear
        $this->wpdb->nextCols[] = ['2026-07-22'];
        $this->queueOpenDate();                                  // open slots
        $this->wpdb->nextVars[] = '2026-07-22 17:00:00';         // earliest start

        // The week lead still reaches it, so a heads-up is due and the last
        // call is not: one ledger read, for the week kind alone.
        $this->wpdb->nextVars[] = null;                          // sentAt week
        $this->queueRecap();

        self::assertSame(1, $this->call()->sendDue($this->leads(), 25));

        $recorded = implode("\n", $this->wpdb->queries);
        self::assertStringContainsString(OpenTaskCall::KIND_WEEK, $recorded);
    }

    public function testTheDailyCapStopsASecondEmailTheSameDay(): void
    {
        $this->wpdb->nextResults[] = [$this->activePerson()];
        // Already had an open-task notice today: nothing else is even looked up.
        $this->wpdb->nextVars[] = 3;

        $sent = $this->call()->sendDue($this->leads(), 25);

        self::assertSame(0, $sent);
        self::assertSame([], $this->mails);
    }

    public function testATaskAddedSinceTheLastSendEarnsAnotherCall(): void
    {
        // The gap this closes: the ledger recorded "told them about this date",
        // so a job added to an already-announced day was never announced.
        $this->wpdb->nextResults[] = [$this->activePerson()];
        $this->wpdb->nextVars[] = null;                          // daily cap clear
        $this->wpdb->nextCols[] = ['2026-07-21'];
        $this->queueDueDate();
        $this->wpdb->nextVars[] = '2026-07-19 09:00:00';         // sentAt week
        $this->wpdb->nextVars[] = 11;                            // a task created since
        $this->wpdb->nextVars[] = '2026-07-19 09:00:00';         // sentAt soon
        $this->wpdb->nextVars[] = 11;
        $this->queueRecap();

        self::assertSame(1, $this->call()->sendDue($this->leads(), 25));
        self::assertCount(1, $this->mails);
    }

    public function testNothingNewSinceTheLastSendMeansNoEmail(): void
    {
        // The other half of the same rule: no daily drip while a date sits open
        // and unchanged.
        $this->wpdb->nextResults[] = [$this->activePerson()];
        $this->wpdb->nextVars[] = null;
        $this->wpdb->nextCols[] = ['2026-07-21'];
        $this->queueDueDate();
        $this->wpdb->nextVars[] = '2026-07-19 09:00:00';   // sentAt week
        $this->wpdb->nextVars[] = null;                    // nothing created since
        $this->wpdb->nextVars[] = '2026-07-19 09:00:00';   // sentAt soon
        $this->wpdb->nextVars[] = null;

        $sent = $this->call()->sendDue($this->leads(), 25);

        self::assertSame(0, $sent);
        self::assertSame([], $this->mails);
    }

    public function testTheMessageListsWhatIsComingBeyondTheDueDate(): void
    {
        // The call is due on 07-21, but someone deciding whether they can help
        // wants to see what is behind it too - so the list runs on past the
        // date that triggered the send.
        $this->wpdb->nextResults[] = [$this->activePerson()];
        $this->wpdb->nextVars[] = null;                          // daily cap clear
        $this->wpdb->nextCols[] = ['2026-07-21', '2026-08-01'];  // upcomingDates
        $this->queueDueDate(0, '2026-07-21');                   // 07-21 open
        $this->wpdb->nextVars[] = null;                          // sentAt week
        $this->wpdb->nextVars[] = null;                          // sentAt soon
        // 08-01 is past the furthest lead, so the due loop stops there - but the
        // digest still walks it, off the same memoised dates.
        $this->queueOpenDate();                                  // 08-01 open
        $this->queueRecap();

        $this->call()->sendDue($this->leads(), 25);

        $body = $this->mails[0]['body'];
        self::assertStringContainsString('Tue 21 Jul', $body);
        self::assertStringContainsString('Sat 1 Aug', $body);
    }

    public function testTheGroupLinkIsOfferedWhenOneIsConfigured(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name): mixed => 'eventcrew_telegram_group_link' === $name
                ? 'https://t.me/+abc123'
                : null
        );

        $this->queueOpenDate();                                 // hasOpenSlots
        $this->wpdb->nextResults[] = [$this->activePerson()];
        $this->wpdb->nextVars[] = null;                         // ledger.hasSent
        $this->wpdb->nextCols[] = ['2026-08-01'];               // upcomingDates (digest)
        $this->queueRecap();

        $this->call()->sendForDate('2026-08-01');

        self::assertStringContainsString('https://t.me/+abc123', $this->mails[0]['body']);
    }

    public function testDatesBeyondTheFurthestLeadAreNotLookedUpAtAll(): void
    {
        // "now" is 2026-07-20; a 168h lead reaches 2026-07-27. Since
        // upcomingDates is ascending, the loop stops rather than querying on.
        $this->wpdb->nextResults[] = [$this->activePerson()];
        $this->wpdb->nextVars[] = null;
        $this->wpdb->nextCols[] = ['2026-08-01'];

        $sent = $this->call()->sendDue($this->leads(), 25);

        self::assertSame(0, $sent);
        self::assertSame([], $this->mails);
    }

    // ---- The Messages page templates (Q297) -------------------------------

    /**
     * Sends for 2026-08-01 to Sam, who has an email and a Telegram chat, with
     * a bot token, a group link, plain-text email and $options. Returns the
     * DMs sent.
     *
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    private function sendToSam(array $options = []): array
    {
        $dms = [];
        $options += [
            TelegramClient::TOKEN_OPTION => 'BOT:TOKEN',
            'eventcrew_telegram_group_link' => 'https://t.me/+abc',
            'eventcrew_email_html' => false,
        ];
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => $options[$name] ?? $default
        );
        Functions\when('wp_json_encode')->alias(static fn (mixed $d): string => (string) json_encode($d));
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_body')->returnArg(1);
        Functions\when('wp_remote_post')->alias(static function (string $url, array $args) use (&$dms): string {
            $dms[] = (array) json_decode((string) $args['body'], true);

            return (string) json_encode(['ok' => true, 'result' => true]);
        });

        $this->queueOpenDate();
        $this->wpdb->nextResults[] = [[
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => 555,
            'telegram_chat_id' => 4242,
        ]];
        $this->wpdb->nextVars[] = null;            // ledger.hasSent
        $this->wpdb->nextCols[] = ['2026-08-01'];  // upcomingDates (digest)
        $this->queueRecap();

        $this->call()->sendForDate('2026-08-01');

        return $dms;
    }

    /** The open tasks under their date, then a short line on each role. */
    private const OPEN = "📅 Sat 1 Aug\n\n🎈 Decorate 20:00 at Party\n2 of 2 places free\n\n📝 What each task involves\n"
        . '🎈 Decorate: Set up before the dance: close the curtains, check the bathrooms, place the LED candles…';

    /**
     * With nothing stored: the built-in texts, the tasks under their date
     * with what each role involves, and one See open tasks instead of a
     * Sign up per task (Owner 2026-11-07).
     */
    public function testWithNothingStoredTheBuiltInTextsAndOneBoardButtonAreSent(): void
    {
        $dms = $this->sendToSam();

        self::assertSame('Some tasks still need people', $this->mails[0]['subject']);
        self::assertSame(
            "Hi Sam,\n\nSome tasks still need people:\n\n" . self::OPEN
                . "\n\nThis will be your first task — welcome!\n\nTasks completed in total: 0.\n\n"
                . "Open the group in Telegram: https://t.me/+abc\n\n—\nManage your account or stop these emails: "
                . 'https://example.test/wp-json/eventcrew/v1/manage?token='
                . 'Nw.2e7412c689f539f6489b9a1e298151a2431bfc264f90e62776a73bce4d9e9398',
            $this->mails[0]['body']
        );
        self::assertSame(
            "📣 Test Site\n\nHi Sam,\n\n🔔 Some tasks still need people:\n\n" . self::OPEN,
            (string) $dms[0]['text']
        );
        // The DM has the email's actions: the board, the group.
        self::assertSame(
            [
                [['text' => '📋 See open tasks', 'callback_data' => 'mn:board']],
                [['text' => 'Open the group', 'url' => 'https://t.me/+abc']],
            ],
            $dms[0]['reply_markup']['inline_keyboard']
        );
    }

    /**
     * In the email the one button is a link to the crew page's board, with
     * the bot's board as the Telegram link (Owner 2026-11-07).
     */
    public function testTheEmailLinksTheCrewPageBoardAndTheBotsBoard(): void
    {
        Functions\when('get_permalink')->justReturn('https://site.test/crew/');

        $this->sendToSam([
            'eventcrew_signup_page_id' => 9,
            BoardService::USERNAME_OPTION => 'crewbot',
        ]);

        $body = $this->mails[0]['body'];
        self::assertStringContainsString('See open tasks: https://site.test/crew/#eventcrew-panel-board', $body);
        self::assertStringContainsString('https://t.me/crewbot?start=board', $body);
        self::assertStringNotContainsString('Sign up:', $body);
    }

    /** A text saved on the Messages page is what is sent, with its placeholders filled. */
    public function testAStoredOpenTaskTextIsSent(): void
    {
        $stored = static fn (string $text): array => [
            'source' => $text,
            'texts' => ['fi' => $text, 'en' => $text],
            'manual' => ['fi' => false, 'en' => false],
        ];

        $dms = $this->sendToSam([
            MessageTemplates::OPTION_PREFIX . 'open_task_call' => [
                MessageTemplates::SUBJECT => $stored('Help, {name}'),
                MessageTemplates::BODY => $stored("Open:\n{open_tasks}\n{recap}"),
            ],
            MessageTemplates::OPTION_PREFIX . 'open_task_call_dm' => [
                MessageTemplates::BODY => $stored('Open: {open_tasks}'),
            ],
        ]);

        self::assertSame('Help, Sam', $this->mails[0]['subject']);
        self::assertStringStartsWith(
            "Open:\n" . self::OPEN . "\nThis will be your first task — welcome!",
            $this->mails[0]['body']
        );
        self::assertSame(
            "📣 Test Site\n\nHi Sam,\n\nOpen: " . self::OPEN,
            (string) $dms[0]['text']
        );
    }
}
