<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CheckinRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Repositories\TicketTransferRepository;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\SeveralTickets;
use EventCrew\Support\TicketMail;
use EventCrew\Support\TicketQr;
use EventCrew\Support\TicketTransferService;
use EventCrew\Telegram\TicketRedemptionService;
use EventCrew\Telegram\TransferBot;
use EventCrew\Tests\Support\AiTextOptions;
use EventCrew\Tests\Support\FakeEventNames;

/**
 * The self-service credit spend, shared by the bot and the web profile. The
 * rules that decide a credit may be spent (and record it) are what matter here;
 * routing to it is covered in UpdateRouterTest.
 */
final class TicketRedemptionServiceTest extends TelegramTestCase
{
    use AiTextOptions;
    use FakeEventNames;

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

    /**
     * The ticket DM carries the 🎁 Give away row while members can give
     * tickets away, and only the ticket button when that setting is off.
     */
    public function testTheTicketDmOffersGiveAwayOnlyWhileGivingIsOn(): void
    {
        Functions\when('wp_mail')->justReturn(true);
        $service = new TicketRedemptionService(
            new PersonRepository(),
            new TaskRepository(),
            new RedemptionRepository(),
            $this->standing(),
            new FreeEntryGate(),
            $this->client(),
            new Mailer(new Logger(), new EmailTemplate(new Logger())),
            null,
            new TicketTransferService(
                new TicketTransferRepository(),
                new RedemptionRepository(),
                new PersonRepository(),
                new TaskRepository(),
                new CheckinRepository()
            )
        );
        $keyboards = [];

        foreach (['1', '0'] as $on) {
            $this->options[TicketTransferService::OPTION] = $on;
            $this->wpdb->nextCols[] = ['2026-08-01'];
            $this->wpdb->nextResults[] = [];
            $this->queueBalanceAndEvent(2);
            $this->wpdb->nextQueryResults[] = 1;
            $this->wpdb->nextRows[] = [
                'id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam',
                'email_verified_at' => '2026-07-01 00:00:00', 'telegram_chat_id' => 999,
            ];

            $service->redeem(7, '2026-08-01');
            $keyboards[] = $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'];
        }

        self::assertCount(2, $keyboards[0]);
        self::assertStringStartsWith(TransferBot::PREFIX . 'g:', (string) $keyboards[0][1][0]['callback_data']);
        self::assertCount(1, $keyboards[1]);
        self::assertArrayHasKey('url', $keyboards[1][0][0]);
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
        self::assertStringContainsString('Your free-entry ticket', $dms[1]);
        self::assertStringContainsString('Thank you for being part of the crew', $dms[1]);
        self::assertStringContainsString('enjoy the night as our guest.', $dms[1]);
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
        $this->wpdb->nextQueryResults[] = 1; // the cancel changed the row
        $this->wpdb->nextRows[] = ['person_id' => 7, 'redeemed_for' => '2026-08-01', 'first_live' => null]; // no check-in to move
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam'];

        self::assertTrue($this->serviceWithTicketMail()->cancelRedemption(44));

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

        $this->service()->onSelect([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'message' => ['message_id' => 42, 'chat' => ['id' => 555]],
            'data' => 'tkt:2026-08-01:y',
        ]);

        self::assertSame(
            'Credit spent — your ticket is in the next message.',
            $this->lastCallTo('answerCallbackQuery')['text']
        );
        // The question became the answer, without its buttons.
        $edit = $this->lastCallTo('editMessageText');
        self::assertSame(42, $edit['message_id']);
        self::assertStringContainsString('Credit spent on', (string) $edit['text']);
        self::assertSame([], $edit['reply_markup']['inline_keyboard']);
    }

    /** The rows the question reads: the tapper, the balance, the date checks, the night's event. */
    private function queueQuestion(int $completed): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00', 'telegram_user_id' => 555, 'telegram_chat_id' => 555,
        ];
        $rows = [];
        for ($i = 0; $i < $completed; $i++) {
            $rows[] = ['id' => $i + 1, 'task_id' => $i + 1, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-07-01'];
        }
        $this->wpdb->nextResults[] = $rows;       // historyFor (balance)
        $this->wpdb->nextVars[] = 0;              // countFor redemptions
        $this->wpdb->nextVars[] = 0;              // sumFor grants
        $this->wpdb->nextCols[] = ['2026-08-01']; // upcomingDates (eligible)
        $this->wpdb->nextResults[] = [];          // forPerson -> not already redeemed
        $this->wpdb->nextResults[] = [            // forDate (the night's label)
            ['id' => 5, 'task_date' => '2026-08-01', 'role_slug' => 'clean', 'capacity' => 3, 'event_post_id' => null, 'event_label' => 'Party'],
        ];
    }

    /**
     * Owner, Q344 2026-11-07: the picker has a title line and the credits,
     * and each event button names the event with its DJs, then the day.
     */
    public function testThePickerNamesEachEventWithItsDjs(): void
    {
        $this->fakeEventNames([42 => ['en' => 'Ecstatic Dance']], [42 => ['Esimerkki', 'Aino Testinen']]);
        $this->wpdb->nextRows[] = [
            'id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00', 'telegram_user_id' => 555,
        ];
        $rows = [];
        for ($i = 0; $i < 4; $i++) {
            $rows[] = ['id' => $i + 1, 'task_id' => $i + 1, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-07-01'];
        }
        $this->wpdb->nextResults[] = $rows;       // historyFor (balance)
        $this->wpdb->nextVars[] = 0;              // countFor redemptions
        $this->wpdb->nextVars[] = 0;              // sumFor grants
        $this->wpdb->nextResults[] = [];          // forPerson -> nothing redeemed
        $this->wpdb->nextCols[] = ['2026-08-01']; // upcomingDates
        $task = [
            'id' => 5, 'task_date' => '2026-08-01', 'role_slug' => 'clean', 'capacity' => 3,
            'event_post_id' => 42, 'event_label' => 'Party',
        ];
        $this->wpdb->nextResults[] = [$task];     // forDate (eligible label)
        $this->wpdb->nextResults[] = [$task];     // forDate (the button)

        $this->service()->onTicketCommand(555, 555);

        $dm = $this->lastCallTo('sendMessage');
        self::assertSame("✨ Free-entry ticket ✨\n\n🎟 Free-entry credits: 2\n\nPick an event:", $dm['text']);
        self::assertSame(
            [
                [['text' => '✨ Ecstatic Dance · Esimerkki & Aino Testinen · Sat 1 Aug', 'callback_data' => 'tkt:2026-08-01']],
                // Back to the Tickets screen, as the last row.
                [['text' => '⬅️ Back', 'callback_data' => 'mn:tix']],
            ],
            $dm['reply_markup']['inline_keyboard']
        );
    }

    /** A long DJ list is cut with "…" so the button stays short; no name: the day alone. */
    public function testTheButtonTextCutsALongDjList(): void
    {
        self::assertSame('Dance · A & B · Sat 1 Aug', TicketRedemptionService::buttonText('Dance', 'A & B', 'Sat 1 Aug'));
        self::assertSame('Dance · Sat 1 Aug', TicketRedemptionService::buttonText('Dance', '', 'Sat 1 Aug'));
        self::assertSame('Sat 1 Aug', TicketRedemptionService::buttonText('', 'A', 'Sat 1 Aug'));

        $long = TicketRedemptionService::buttonText(
            'Ecstatic Dance',
            'Esimerkki, Aino Testinen, Bo Kokeilu & Somebody Else Again',
            'Sat 14 Nov'
        );
        self::assertSame(TicketRedemptionService::BUTTON_MAX, mb_strlen($long));
        self::assertStringStartsWith('Ecstatic Dance · Esimerkki, Aino', $long);
        self::assertStringEndsWith('… · Sat 14 Nov', $long);

        self::assertSame(
            str_repeat('N', 50) . ' · Sat 14 Nov',
            TicketRedemptionService::buttonText(str_repeat('N', 50), 'Somebody', 'Sat 14 Nov')
        );
    }

    public function testTappingANightAsksFirstAndSaysWhatItCosts(): void
    {
        $this->queueQuestion(4); // four completions -> two credits

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'tkt:2026-08-01']);

        self::assertSame([], $this->wpdb->inserts);
        self::assertStringNotContainsString('INSERT', implode("\n", $this->wpdb->queries));
        $ask = $this->lastCallTo('sendMessage');
        self::assertStringContainsString('Spend a free-entry credit on', (string) $ask['text']);
        self::assertStringContainsString('Party', (string) $ask['text']);
        self::assertStringContainsString('This uses 1 of your 2 free-entry credits.', (string) $ask['text']);
        $data = array_column(array_merge(...$ask['reply_markup']['inline_keyboard']), 'callback_data');
        self::assertSame(['tkt:2026-08-01:y', 'tkt:2026-08-01:k'], $data);
    }

    /** One live ticket of person 7 for 2026-08-01, as forPerson() reads it. */
    private const HELD_TICKET = [
        'id' => 40, 'person_id' => 7, 'redeemed_for' => '2026-08-01', 'redeemed_at' => '2026-07-20 10:00:00',
    ];

    /** Lou 2026-11-08: several free-entry tickets per night, on by default. */
    public function testASecondTicketForTheSameNightIsSpentWhileSeveralTicketsIsOn(): void
    {
        $this->wpdb->nextCols[] = ['2026-08-01'];          // upcomingDates (eligible)
        $this->wpdb->nextResults[] = [self::HELD_TICKET];  // forPerson: one ticket held
        $this->queueBalanceAndEvent(2);
        $this->wpdb->nextQueryResults[] = 1;

        $result = $this->service()->redeem(7, '2026-08-01', 'web', 1);

        self::assertSame(TicketRedemptionService::TICKET_READY, $result['code']);
        $insert = (string) current(array_filter($this->wpdb->queries, static fn (string $q): bool => str_contains($q, 'INSERT')));
        // The guard is the count the person saw, so a double-tap cannot buy two.
        self::assertStringContainsString('SELECT COUNT(*)', $insert);
        self::assertStringContainsString(") = 1", $insert);
        self::assertStringNotContainsString('NOT EXISTS', $insert);
    }

    public function testASecondTicketIsRefusedWhileSeveralTicketsIsOff(): void
    {
        $this->options[SeveralTickets::OPTION] = '0';
        $this->wpdb->nextCols[] = ['2026-08-01'];
        $this->wpdb->nextResults[] = [self::HELD_TICKET];

        $result = $this->service()->redeem(7, '2026-08-01', 'web', 1);

        self::assertSame(TicketRedemptionService::ALREADY_REDEEMED, $result['code']);
        self::assertStringNotContainsString('INSERT', implode("\n", $this->wpdb->queries));
    }

    /** A double-tap, or a page opened before the first ticket: the count is stale. */
    public function testAStaleTicketCountBuysNothing(): void
    {
        $this->wpdb->nextCols[] = ['2026-08-01'];
        $this->wpdb->nextResults[] = [self::HELD_TICKET];

        $result = $this->service()->redeem(7, '2026-08-01', 'web', 0);

        self::assertSame(TicketRedemptionService::ALREADY_REDEEMED, $result['code']);
        self::assertStringNotContainsString('INSERT', implode("\n", $this->wpdb->queries));
    }

    public function testTheSettingIsOnWhenNeverSaved(): void
    {
        unset($this->options[SeveralTickets::OPTION]);
        self::assertTrue(SeveralTickets::isOn());

        SeveralTickets::save(false);
        self::assertSame('0', $this->options[SeveralTickets::OPTION]);
        self::assertFalse(SeveralTickets::isOn());

        SeveralTickets::save(true);
        self::assertTrue(SeveralTickets::isOn());
    }

    /** The date pickers (bot, web) keep a night with a ticket only while the setting is on. */
    public function testThePickersKeepANightWithATicketOnlyWhileSeveralTicketsIsOn(): void
    {
        $this->wpdb->nextResults[] = [self::HELD_TICKET];  // forPerson
        $this->wpdb->nextCols[] = ['2026-08-01'];          // upcomingDates

        $nights = $this->service()->eligibleNights(7);

        self::assertSame(['2026-08-01'], array_keys($nights));
        self::assertSame(1, $nights['2026-08-01']['held']);

        $this->options[SeveralTickets::OPTION] = '0';
        $this->wpdb->nextResults[] = [self::HELD_TICKET];
        $this->wpdb->nextCols[] = ['2026-08-01'];

        self::assertSame([], $this->service()->eligibleDatesFor(7));
    }

    /** Owner 2026-11-13: the web page's Get a ticket options name the DJs too. */
    public function testTheWebTicketOptionsNameTheDjs(): void
    {
        $this->fakeEventNames([42 => ['en' => 'Ecstatic Dance']], [42 => ['Esimerkki']]);
        $this->wpdb->nextResults[] = [];          // forPerson -> nothing held
        $this->wpdb->nextCols[] = ['2026-08-01']; // upcomingDates
        $this->wpdb->nextResults[] = [[
            'id' => 5, 'task_date' => '2026-08-01', 'role_slug' => 'clean', 'capacity' => 3,
            'event_post_id' => 42, 'event_label' => 'Party',
        ]];                                       // forDate

        $nights = $this->service()->eligibleNights(7);

        self::assertSame('Sat 1 Aug · Ecstatic Dance · Esimerkki', $nights['2026-08-01']['label']);
    }

    /** The bot's Yes button carries the tickets held, and the tap sends it back. */
    public function testTheBotQuestionCarriesTheTicketsHeldForTheNight(): void
    {
        $this->queueQuestion(4);
        // forPerson: the ticket already held, in place of none.
        $this->wpdb->nextResults[1] = [self::HELD_TICKET];

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'tkt:2026-08-01']);

        $ask = $this->lastCallTo('sendMessage');
        $data = array_column(array_merge(...$ask['reply_markup']['inline_keyboard']), 'callback_data');
        self::assertSame(['tkt:2026-08-01:y1', 'tkt:2026-08-01:k'], $data);
    }

    public function testTheQuestionSaysWhenItIsTheLastCredit(): void
    {
        $this->queueQuestion(2); // one credit

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'tkt:2026-08-01']);

        self::assertStringContainsString(
            'This uses your only free-entry credit.',
            (string) $this->lastCallTo('sendMessage')['text']
        );
    }

    public function testANightThatCannotTakeACreditIsSaidWithoutAQuestion(): void
    {
        $this->queueQuestion(0); // no credit

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'tkt:2026-08-01']);

        self::assertNotContains('sendMessage', $this->calledMethods());
        self::assertSame('You have no free-entry credits to spend.', $this->lastCallTo('answerCallbackQuery')['text']);
    }

    public function testKeepMyCreditSpendsNothing(): void
    {
        $this->queueQuestion(2);

        $this->service()->onSelect([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'message' => ['message_id' => 42, 'chat' => ['id' => 555]],
            'data' => 'tkt:2026-08-01:k',
        ]);

        self::assertStringNotContainsString('INSERT', implode("\n", $this->wpdb->queries));
        self::assertNotContains('sendMessage', $this->calledMethods());
        self::assertStringContainsString('Kept: you have 1 free-entry credit.', (string) $this->lastCallTo('editMessageText')['text']);
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
