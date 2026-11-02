<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\Guest;
use EventCrew\Models\TicketBuyer;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\SignedLink;
use EventCrew\Support\TicketMail;
use EventCrew\Support\TicketQr;
use EventCrew\Tests\TestCase;

/**
 * The ticket email: who is picked, what is recorded, what the email holds.
 * wp_mail is a stub that records each message; nothing is sent.
 */
final class TicketMailTest extends TestCase
{
    /** @var list<array{to: string, subject: string, body: string}> */
    private array $mails = [];

    private bool $mailWorks = true;

    /** @var list<string> */
    private array $scheduled = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mails = [];
        $this->mailWorks = true;
        $this->scheduled = [];

        Functions\when('get_option')->justReturn(false);
        Functions\when('get_the_title')->justReturn('');
        Functions\when('get_permalink')->justReturn('https://site.test/crew/');
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('is_email')->alias(static fn (string $email): bool => str_contains($email, '@'));
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->alias(function (int $time, string $hook): bool {
            $this->scheduled[] = $hook;

            return true;
        });
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

            return $this->mailWorks;
        });
    }

    private function ticketMail(?TicketQr $qr = null): TicketMail
    {
        return new TicketMail(
            new TicketBuyerRepository(),
            new GuestRepository(),
            new TaskRepository(),
            new Mailer(new Logger(), new EmailTemplate(new Logger())),
            $qr ?? new TicketQr()
        );
    }

    /** A QR drawer that answers a PNG, as on a server with GD. */
    private function pngQr(): TicketQr
    {
        return new class () extends TicketQr {
            public function png(string $data, int $scale = 6): ?string
            {
                return "\x89PNG-fake";
            }
        };
    }

    /** @return array<string, mixed> */
    private function buyerRow(int $id, string $status = ''): array
    {
        return [
            'id' => $id,
            'event_date' => '2026-11-14',
            'name' => 'Anna Virtanen',
            'source' => 'holvi',
            'batch' => 'b1',
            'email' => 'anna' . $id . '@example.test',
            'ticket_code' => 'AB12CD' . $id,
            'ticket_mail_status' => $status,
        ];
    }

    /** @return array<string, mixed> */
    private function guestRow(int $id): array
    {
        return [
            'id' => $id,
            'event_date' => '2026-11-14',
            'name' => 'Cy Cole',
            'plus_ones' => 2,
            'note' => '',
            'email' => 'cy@example.test',
            'ticket_mail_status' => '',
        ];
    }

    /** @return list<array{table: string, data: array<string, mixed>, where: array<string, mixed>}> */
    private function updates(): array
    {
        return array_values($this->wpdb->updates);
    }

    public function testTheRunSendsUntriedTicketsAndRecordsSentAndFailed(): void
    {
        $this->wpdb->nextResults[] = [$this->buyerRow(1), $this->buyerRow(2)]; // buyers
        $this->wpdb->nextResults[] = [$this->guestRow(9)];                      // guests

        $mail = $this->ticketMail();
        $this->mailWorks = true;

        self::assertSame(3, $mail->run());
        self::assertSame(
            ['anna1@example.test', 'anna2@example.test', 'cy@example.test'],
            array_column($this->mails, 'to')
        );

        $updates = $this->updates();
        self::assertCount(3, $updates);
        self::assertSame('wp_eventcrew_ticket_buyers', $updates[0]['table']);
        self::assertSame('sent', $updates[0]['data']['ticket_mail_status']);
        self::assertSame(['id' => 1], $updates[0]['where']);
        self::assertSame('wp_eventcrew_guests', $updates[2]['table']);
        self::assertSame(['id' => 9], $updates[2]['where']);

        // A batch that was not full does not schedule another.
        self::assertSame([], $this->scheduled);
    }

    public function testAFailedSendIsRecordedAsFailed(): void
    {
        $this->wpdb->nextResults[] = [$this->buyerRow(1)];
        $this->mailWorks = false;

        $this->ticketMail()->run();

        self::assertSame('failed', $this->updates()[0]['data']['ticket_mail_status']);
    }

    /**
     * The automatic run asks only for rows never tried (status ''), from
     * today on; a failed row is not tried again by itself.
     */
    public function testTheRunPicksOnlyUntriedRowsFromToday(): void
    {
        $this->ticketMail()->run();

        $buyers = $this->wpdb->queries[0];
        self::assertStringContainsString('FROM wp_eventcrew_ticket_buyers', $buyers);
        self::assertStringContainsString("event_date >= '" . current_time('Y-m-d') . "'", $buyers);
        self::assertStringContainsString("ticket_mail_status = ''", $buyers);
        self::assertStringContainsString("source NOT IN ('door', 'desk')", $buyers);
        self::assertStringContainsString('LIMIT 25', $buyers);
        self::assertStringContainsString('FROM wp_eventcrew_guests', $this->wpdb->queries[1]);
        self::assertStringContainsString("ticket_mail_status = ''", $this->wpdb->queries[1]);
    }

    public function testAFullBatchSchedulesTheNextOne(): void
    {
        $rows = [];

        for ($id = 1; $id <= TicketMail::BATCH; $id++) {
            $rows[] = $this->buyerRow($id);
        }

        $this->wpdb->nextResults[] = $rows;

        self::assertSame(TicketMail::BATCH, $this->ticketMail()->run());
        self::assertSame([TicketMail::HOOK], $this->scheduled);
        // The guests are not asked for when the buyers filled the batch.
        foreach ($this->wpdb->queries as $sql) {
            self::assertStringNotContainsString('wp_eventcrew_guests', $sql);
        }
    }

    /** "Send the rest now" picks every row of the night not 'sent', failed ones included. */
    public function testSendTheRestPicksEveryRowNotSent(): void
    {
        $this->wpdb->nextResults[] = [$this->buyerRow(1, 'failed')];
        $this->wpdb->nextResults[] = [['status' => 'sent', 'n' => 3], ['status' => 'failed', 'n' => 0]];

        $result = $this->ticketMail()->sendRest('2026-11-14', TicketMail::KIND_BUYERS);

        self::assertSame(['sent' => 1, 'failed' => 0, 'more' => false], $result);
        self::assertStringContainsString("event_date = '2026-11-14'", $this->wpdb->queries[0]);
        self::assertStringContainsString("ticket_mail_status <> 'sent'", $this->wpdb->queries[0]);
        self::assertStringNotContainsString("ticket_mail_status = ''", $this->wpdb->queries[0]);
        self::assertSame('sent', $this->updates()[0]['data']['ticket_mail_status']);
    }

    /** The test goes to the given address and changes no row. */
    public function testSendTestGoesToTheGivenAddressAndChangesNoStatus(): void
    {
        $this->wpdb->nextResults[] = [$this->buyerRow(1)];

        $result = $this->ticketMail()->sendTest('2026-11-14', 'lou@example.test');

        self::assertSame(TicketMail::TEST_SENT, $result);
        self::assertSame(['lou@example.test'], array_column($this->mails, 'to'));
        self::assertSame([], $this->wpdb->updates);
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testSendTestWithNobodyToBuildFromSendsNothing(): void
    {
        self::assertSame(TicketMail::TEST_NONE, $this->ticketMail()->sendTest('2026-11-14', 'lou@example.test'));
        self::assertSame([], $this->mails);
        self::assertSame([], $this->wpdb->updates);
    }

    /**
     * With GD the QR is an inline image (cid:), never a data: URI; the code is
     * written under it; the email has no clock or other anti-fraud markup;
     * the subject is Finnish first, then English, then the date.
     */
    public function testTheEmailCarriesTheQrInlineAndNoClock(): void
    {
        $buyer = TicketBuyer::fromRow($this->buyerRow(1));
        $mail = $this->ticketMail($this->pngQr())->compose($buyer);

        self::assertStringContainsString('src="cid:' . TicketMail::QR_CID . '"', $mail['html']);
        self::assertStringNotContainsString('data:image', $mail['html']);
        self::assertSame("\x89PNG-fake", $mail['png']);
        self::assertStringContainsString('AB12CD1', $mail['html']);
        self::assertStringContainsString('Open the ticket in your browser', $mail['html']);
        self::assertStringContainsString('Thank you for buying a ticket!', $mail['html']);

        foreach (['clock', 't-clock', 'ec-clock', 'setInterval', '<script', 'frozen'] as $antiFraud) {
            self::assertStringNotContainsStringIgnoringCase($antiFraud, $mail['html']);
        }

        self::assertSame('Lippusi / Your ticket – 14.11.2026', $mail['subject']);

        // Finnish comes before English in the body.
        $fi = strpos($mail['html'], 'Kiitos');
        $en = strpos($mail['html'], 'Thank you');
        self::assertNotFalse($fi);
        self::assertNotFalse($en);
        self::assertLessThan($en, $fi);
    }

    /** Without GD the QR is drawn as a table and no image is attached. */
    public function testWithoutGdTheQrIsATable(): void
    {
        $noGd = new class () extends TicketQr {
            public function png(string $data, int $scale = 6): ?string
            {
                return null;
            }
        };

        $mail = $this->ticketMail($noGd)->compose(TicketBuyer::fromRow($this->buyerRow(1)));

        self::assertNull($mail['png']);
        self::assertStringContainsString('<table', $mail['html']);
        self::assertStringNotContainsString('cid:', $mail['html']);
    }

    /** A guest's QR holds their ticket page link, which the desk accepts. */
    public function testAGuestsQrHoldsTheirSignedTicketLink(): void
    {
        $guest = Guest::fromRow($this->guestRow(9));
        $data = TicketMail::qrData($guest);

        parse_str((string) parse_url($data, PHP_URL_QUERY), $query);
        self::assertStringStartsWith('https://site.test/wp-json/eventcrew/v1/ticket?', $data);
        self::assertSame(9, SignedLink::verify('guest_ticket', (string) $query['token']));

        $mail = $this->ticketMail($this->pngQr())->compose($guest);
        self::assertStringContainsString('Cy Cole +2', $mail['html']);
        self::assertStringContainsString('Thank you for coming!', $mail['html']);
    }

    /**
     * A buyer's link signs the ticket code, so it stops working once the code
     * is blanked (RetentionPurge, erasure); the QR itself is the bare code.
     */
    public function testABuyersLinkDiesWithItsTicketCode(): void
    {
        $buyer = TicketBuyer::fromRow($this->buyerRow(1));
        $token = TicketMail::buyerToken($buyer);

        self::assertSame('AB12CD1', TicketMail::qrData($buyer));
        self::assertSame(1, SignedLink::idOf($token));
        self::assertSame(1, SignedLink::verify('buyer_ticket|AB12CD1', $token));
        self::assertNull(SignedLink::verify('buyer_ticket|', $token));
        self::assertNull(SignedLink::verify('guest_ticket', $token));
    }

    public function testTheStatusLine(): void
    {
        self::assertSame(
            'Ticket emails: 38 sent · 2 failed · 2 not sent',
            TicketMail::statusLine(['sent' => 38, 'failed' => 2, 'unsent' => 2])
        );
        self::assertSame('', TicketMail::statusLine(['sent' => 0, 'failed' => 0, 'unsent' => 0]));
    }
}
