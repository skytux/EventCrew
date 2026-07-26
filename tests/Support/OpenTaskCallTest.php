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
use EventCrew\Support\OpenTaskCall;
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

    private function taskRow(): array
    {
        return [
            'id' => 5,
            'task_date' => '2026-08-01',
            'role_slug' => 'decorate',
            'capacity' => 2,
            'event_post_id' => null,
            'event_label' => 'Party',
            'starts_at' => '2026-08-01 20:00:00',
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
    private function queueOpenDate(int $taken = 0): void
    {
        $this->wpdb->nextResults[] = [$this->taskRow()];
        $this->wpdb->nextResults[] = $this->occupancyRows($taken);
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

    public function testIncludesCrewAlreadyWorkingThatDay(): void
    {
        // Being on a task that day no longer excludes someone - they may want a
        // second, non-overlapping slot - so they still get the call.
        $this->queueOpenDate();
        $this->wpdb->nextResults[] = [$this->activePerson()];
        $this->wpdb->nextVars[] = null;
        $this->queueRecap();

        self::assertSame(1, $this->call()->sendForDate('2026-08-01'));
        self::assertCount(1, $this->mails);
    }

    public function testSendsNothingWhenEverythingIsStaffed(): void
    {
        $this->queueOpenDate(2);   // the one task is full (2 of 2)

        $sent = $this->call()->sendForDate('2026-08-01');

        self::assertSame(0, $sent);
        self::assertSame([], $this->mails);
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

        $this->queueOpenDate();                                 // 07-21 open
        $this->wpdb->nextVars[] = null;                         // sentAt week
        $this->wpdb->nextVars[] = null;                         // sentAt soon
        $this->queueOpenDate();                                 // 07-22 open
        $this->wpdb->nextVars[] = null;
        $this->wpdb->nextVars[] = null;
        $this->queueRecap();

        $sent = $this->call()->sendDue($this->leads(), 25);

        self::assertSame(1, $sent);
        self::assertCount(1, $this->mails);
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
        $this->queueOpenDate();
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
        $this->queueOpenDate();
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
        $this->queueOpenDate();                                  // 07-21 open
        $this->wpdb->nextVars[] = null;                          // sentAt week
        $this->wpdb->nextVars[] = null;                          // sentAt soon
        // 08-01 is past the furthest lead, so the due loop stops there - but the
        // digest still walks it, off the same memoised dates.
        $this->queueOpenDate();                                  // 08-01 open
        $this->queueRecap();

        $this->call()->sendDue($this->leads(), 25);

        $body = $this->mails[0]['body'];
        self::assertStringContainsString('2026-07-21', $body);
        self::assertStringContainsString('2026-08-01', $body);
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
}
