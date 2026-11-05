<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\TicketMail;
use EventCrew\Support\TicketQr;
use EventCrew\Telegram\TicketRedemptionService;
use EventCrew\Tests\Support\AiTextOptions;

/**
 * The self-service credit spend, shared by the bot and the web profile. The
 * rules that decide a credit may be spent (and record it) are what matter here;
 * routing to it is covered in UpdateRouterTest.
 */
final class TicketRedemptionServiceTest extends TelegramTestCase
{
    use AiTextOptions;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_the_title')->justReturn('');
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
    }

    private function service(): TicketRedemptionService
    {
        return new TicketRedemptionService(
            new PersonRepository(),
            new TaskRepository(),
            new RedemptionRepository(),
            $this->standing(),
            new FreeEntryGate(),
            $this->client(),
            new Mailer(new Logger(), new EmailTemplate(new Logger()))
        );
    }

    /**
     * Queues the reads redeem() makes once it is past the date/closed/redeemed
     * guards: the person's history (for the credit balance) and the day's task
     * (for the event the credit is recorded against). $completed completions earn
     * intdiv($completed, 2) credits.
     */
    private function queueBalanceAndEvent(int $completed): void
    {
        $rows = [];
        for ($i = 0; $i < $completed; $i++) {
            $rows[] = ['id' => $i + 1, 'task_id' => $i + 1, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-07-01'];
        }

        $this->wpdb->nextResults[] = $rows; // historyFor (balance)
        $this->wpdb->nextVars[] = 0;        // countFor redemptions
        $this->wpdb->nextVars[] = 0;        // sumFor grants
        $this->wpdb->nextResults[] = [      // forDate (eventContext)
            ['id' => 5, 'task_date' => '2026-08-01', 'role_slug' => 'clean', 'capacity' => 3, 'event_post_id' => null, 'event_label' => 'Party'],
        ];
    }

    public function testRedeemSpendsACreditAndReturnsATicketLink(): void
    {
        $this->wpdb->nextCols[] = ['2026-08-01']; // upcomingDates (eligible)
        $this->wpdb->nextResults[] = [];          // forPerson -> not already redeemed
        $this->queueBalanceAndEvent(2);           // two completions -> one credit
        $this->wpdb->nextQueryResults[] = 1;      // the conditional insert wrote a row

        $result = $this->service()->redeem(7, '2026-08-01');

        self::assertSame(TicketRedemptionService::TICKET_READY, $result['code']);
        self::assertStringContainsString('wp-json/eventcrew/v1/ticket?token=', $result['url']);
    }

    /**
     * The write is the guard, so a request that got past the "already
     * redeemed?" check while another was in flight is turned away by the insert
     * writing nothing - and must be reported as the duplicate it is, not as a
     * ticket nobody was issued.
     */
    public function testRedeemReportsADuplicateTheInsertRefused(): void
    {
        $this->wpdb->nextCols[] = ['2026-08-01'];
        $this->wpdb->nextResults[] = [];
        $this->queueBalanceAndEvent(2);
        $this->wpdb->nextQueryResults[] = 0;      // NOT EXISTS matched; nothing written

        $result = $this->service()->redeem(7, '2026-08-01');

        self::assertSame(TicketRedemptionService::ALREADY_REDEEMED, $result['code']);
        self::assertSame('', $result['url']);
    }

    public function testRedeemEmailsAndDmsTheTicketLink(): void
    {
        $mails = [];
        Functions\when('wp_mail')->alias(
            function (string $to, string $subject, string $body) use (&$mails): bool {
                $mails[] = ['to' => $to, 'body' => $body];

                return true;
            }
        );

        $this->wpdb->nextCols[] = ['2026-08-01']; // upcomingDates (eligible)
        $this->wpdb->nextResults[] = [];          // forPerson -> not already redeemed
        $this->queueBalanceAndEvent(2);           // two completions -> one credit
        $this->wpdb->nextQueryResults[] = 1;      // the conditional insert wrote a row
        $this->wpdb->nextRows[] = [               // people->find(7) for the DM + email
            'id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00', 'telegram_chat_id' => 999,
        ];

        $result = $this->service()->redeem(7, '2026-08-01');

        self::assertSame(TicketRedemptionService::TICKET_READY, $result['code']);
        // The link lands in the person's DM...
        self::assertSame(999, $this->lastCallTo('sendMessage')['chat_id']);
        // ...as a named button, never a raw address in the text...
        $dm = $this->lastCallTo('sendMessage');
        self::assertStringNotContainsString('token=', (string) $dm['text']);
        self::assertSame($result['url'], $dm['reply_markup']['inline_keyboard'][0][0]['url']);
        // ...and in an email carrying the same link.
        self::assertNotEmpty($mails);
        self::assertStringContainsString($result['url'], $mails[0]['body']);
    }

    /** MessageAiText: the event's AI text when there is one, else the template's text. */
    public function testTheTicketDmUsesTheEventsAiTextWhenThereIsOne(): void
    {
        Functions\when('wp_mail')->justReturn(true);
        $base = $this->options;
        $dms = [];

        foreach ([['free_ticket_dm' => ['en' => 'AI ticket for {date}.', 'fi' => 'AI FI']], []] as $texts) {
            $this->options = self::aiTextOptions(['free_ticket_dm'], 42, $texts) + $base;
            $this->wpdb->nextCols[] = ['2026-08-01'];
            $this->wpdb->nextResults[] = [];
            $this->wpdb->nextResults[] = [
                ['id' => 1, 'task_id' => 1, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-07-01'],
                ['id' => 2, 'task_id' => 2, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-07-01'],
            ];
            $this->wpdb->nextVars[] = 0;
            $this->wpdb->nextVars[] = 0;
            $this->wpdb->nextResults[] = [
                [
                    'id' => 5, 'task_date' => '2026-08-01', 'role_slug' => 'clean', 'capacity' => 3,
                    'event_post_id' => 42, 'event_label' => 'Party',
                ],
            ];
            $this->wpdb->nextQueryResults[] = 1;
            $this->wpdb->nextRows[] = [
                'id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam',
                'email_verified_at' => '2026-07-01 00:00:00', 'telegram_chat_id' => 999,
            ];

            $this->service()->redeem(7, '2026-08-01');
            $dms[] = (string) $this->lastCallTo('sendMessage')['text'];
        }

        self::assertStringContainsString('AI ticket for Sat 1 Aug.', $dms[0]);
        // The tick is on but nothing was written for the event: the template's text.
        self::assertStringContainsString('Your free-entry ticket for', $dms[1]);
    }

    private function serviceWithTicketMail(): TicketRedemptionService
    {
        Functions\when('is_email')->alias(static fn (string $email): bool => str_contains($email, '@'));
        $mailer = new Mailer(new Logger(), new EmailTemplate(new Logger()));

        return new TicketRedemptionService(
            new PersonRepository(),
            new TaskRepository(),
            new RedemptionRepository(),
            $this->standing(),
            new FreeEntryGate(),
            $this->client(),
            $mailer,
            new TicketMail(
                new TicketBuyerRepository(),
                new GuestRepository(),
                new TaskRepository(),
                $mailer,
                new TicketQr(),
                new AssignmentRepository(),
                new RedemptionRepository()
            )
        );
    }

    /** @return \ArrayObject<int, string> the subjects of the emails sent, filled as they go */
    private function captureSubjects(): \ArrayObject
    {
        $subjects = new \ArrayObject();
        Functions\when('wp_mail')->alias(static function (string $to, string $subject) use ($subjects): bool {
            $subjects[] = $subject;

            return true;
        });

        return $subjects;
    }

    /**
     * Spending a credit sends the one "Your ticket" email that buyers and
     * guests get, and no second, plainer email with the ticket link.
     */
    public function testSpendingACreditSendsOnlyTheOneTicketEmail(): void
    {
        $subjects = $this->captureSubjects();
        $this->wpdb->nextCols[] = ['2026-08-01'];
        $this->wpdb->nextResults[] = [];
        $this->queueBalanceAndEvent(2);
        $this->wpdb->nextQueryResults[] = 1;
        $this->wpdb->nextRows[] = [
            'id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
        ];

        $this->serviceWithTicketMail()->redeem(7, '2026-08-01');

        self::assertCount(1, $subjects);
        self::assertStringContainsString('Your ticket – ', $subjects[0]);
        $marked = array_values(array_filter(
            $this->wpdb->updates,
            static fn (array $u): bool => 'wp_eventcrew_redemptions' === $u['table']
        ));
        self::assertArrayHasKey('ticket_mailed_at', $marked[0]['data']);
    }

    /** Decision 4: cancelling a credit whose ticket was emailed sends "Your ticket is cancelled". */
    public function testCancellingACreditWithAnEmailedTicketSendsTheCancelEmail(): void
    {
        $subjects = $this->captureSubjects();
        $this->wpdb->nextRows[] = [
            'id' => 44, 'person_id' => 7, 'redeemed_for' => '2026-08-01', 'cancelled_at' => null,
            'ticket_mailed_at' => '2026-07-19 10:00:00',
        ];
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam'];

        $this->serviceWithTicketMail()->cancelRedemption(44);

        self::assertStringContainsString('SET cancelled_at', implode("\n", $this->wpdb->queries));
        self::assertCount(1, $subjects);
        self::assertStringContainsString('Your ticket is cancelled', $subjects[0]);
    }

    public function testCancellingACreditWhoseTicketWasNotEmailedSendsNothing(): void
    {
        $subjects = $this->captureSubjects();
        $this->wpdb->nextRows[] = ['id' => 44, 'person_id' => 7, 'redeemed_for' => '2026-08-01', 'cancelled_at' => null];

        $this->serviceWithTicketMail()->cancelRedemption(44);

        self::assertStringContainsString('SET cancelled_at', $this->wpdb->lastQuery());
        self::assertCount(0, $subjects);
    }

    public function testTappingANightSaysWhereTheTicketIs(): void
    {
        Functions\when('wp_mail')->justReturn(true);
        $person = [
            'id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00', 'telegram_user_id' => 555, 'telegram_chat_id' => 555,
        ];
        $this->wpdb->nextRows[] = $person;        // the tapper
        $this->wpdb->nextCols[] = ['2026-08-01']; // upcomingDates (eligible)
        $this->wpdb->nextResults[] = [];          // forPerson -> not already redeemed
        $this->queueBalanceAndEvent(2);           // two completions -> one credit
        $this->wpdb->nextQueryResults[] = 1;      // the conditional insert wrote a row
        $this->wpdb->nextRows[] = $person;        // people->find(7) for the DM + email

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'tkt:2026-08-01']);

        self::assertSame(
            'Credit spent — your ticket is in the next message.',
            $this->lastCallTo('answerCallbackQuery')['text']
        );
    }

    public function testRedeemRefusesWithNoCredit(): void
    {
        $this->wpdb->nextCols[] = ['2026-08-01']; // upcomingDates
        $this->wpdb->nextResults[] = [];          // forPerson -> not redeemed
        $this->wpdb->nextResults[] = [];          // historyFor -> no completions -> no credit
        $this->wpdb->nextVars[] = 0;              // countFor
        $this->wpdb->nextVars[] = 0;              // sumFor

        $result = $this->service()->redeem(7, '2026-08-01');

        self::assertSame(TicketRedemptionService::NO_CREDIT, $result['code']);
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testRedeemRefusesAClosedDate(): void
    {
        $this->options[FreeEntryGate::OPTION] = ['2026-08-01'];
        $this->wpdb->nextCols[] = ['2026-08-01']; // upcomingDates (a real event, but closed)

        $result = $this->service()->redeem(7, '2026-08-01');

        self::assertSame(TicketRedemptionService::ENTRY_CLOSED, $result['code']);
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testRedeemRefusesADateWithNoEvent(): void
    {
        $this->wpdb->nextCols[] = ['2026-09-09']; // upcomingDates does not include the asked date

        $result = $this->service()->redeem(7, '2026-08-01');

        self::assertSame(TicketRedemptionService::NOT_ELIGIBLE, $result['code']);
        self::assertSame([], $this->wpdb->inserts);
    }
}
