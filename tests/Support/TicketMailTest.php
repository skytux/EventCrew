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

    private function ticketMail(): TicketMail
    {
        return new TicketMail(
            new TicketBuyerRepository(),
            new GuestRepository(),
            new TaskRepository(),
            new Mailer(new Logger(), new EmailTemplate(new Logger()))
        );
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
     * The QR is drawn as tables in the HTML: no image at all, so no data: URI
     * and no cid: attachment; the code is written under it; the email has no
     * clock or other anti-fraud markup; the subject is Finnish first, then
     * English, then the date.
     */
    public function testTheEmailDrawsTheQrAsTablesAndNoClock(): void
    {
        $buyer = TicketBuyer::fromRow($this->buyerRow(1));
        $mail = $this->ticketMail()->compose($buyer);

        self::assertSame(['subject', 'html', 'text'], array_keys($mail));
        self::assertStringContainsString('<table', $mail['html']);
        self::assertStringNotContainsString('<img', $mail['html']);
        self::assertStringNotContainsString('data:image', $mail['html']);
        self::assertStringNotContainsString('cid:', $mail['html']);
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

    /**
     * With a logo set, the QR has it in the middle, as one linked image; the
     * code's data is the same as without.
     */
    public function testTheEmailQrHasTheSiteLogoInTheMiddle(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => EmailTemplate::LOGO_OPTION === $name
                ? 'https://site.test/logo.png'
                : $default
        );
        $buyer = TicketBuyer::fromRow($this->buyerRow(1));
        $mail = $this->ticketMail()->compose($buyer);

        self::assertSame(1, substr_count($mail['html'], '<img'));
        self::assertStringContainsString('<img src="https://site.test/logo.png"', $mail['html']);
        self::assertStringContainsString(
            (new TicketQr())->table(TicketMail::qrData($buyer), 6, 'https://site.test/logo.png'),
            $mail['html']
        );
    }

    public function testTheEmailShowsTheTicketNoteUnderTheTicket(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => 'eventcrew_ticket_note' === $name
                ? "Door code 1234\nSome street 5 <b>"
                : $default
        );
        $mail = $this->ticketMail()->compose(TicketBuyer::fromRow($this->buyerRow(1)));

        self::assertStringContainsString("Door code 1234\nSome street 5 &lt;b&gt;", $mail['html']);
        self::assertStringContainsString('white-space:pre-line', $mail['html']);
        self::assertLessThan(strpos($mail['html'], 'Door code'), (int) strpos($mail['html'], 'AB12CD1'));
        self::assertStringContainsString("Door code 1234\nSome street 5 <b>", $mail['text']);
    }

    public function testWithoutATicketNoteTheEmailHasNoNoteBlock(): void
    {
        $mail = $this->ticketMail()->compose(TicketBuyer::fromRow($this->buyerRow(1)));

        self::assertStringNotContainsString('pre-line', $mail['html']);
    }

    /**
     * Every cell of the QR has its size in pixels, no text, and nothing that
     * could stretch it; a black cell has its colour three times (bgcolor,
     * background-color and a gradient image for dark mode), a white cell
     * shows its row table's white, which has the same three. Read back cell
     * by cell, the tables give exactly the code's modules inside a white
     * quiet zone of 4 modules, 6 pixels each.
     */
    public function testTheQrTablesDrawEveryModuleInFixedCells(): void
    {
        $qr = new TicketQr();
        $data = 'TESTI-0000-EVENTCREW';
        $html = $qr->table($data);
        $matrix = $qr->matrix($data);
        $side = count($matrix) + 8;

        self::assertStringNotContainsString('&nbsp;', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringStartsWith(
            '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="' . ($side * 6) . '"',
            $html
        );

        $black = ' bgcolor="#000000" style="background-color:#000;background-image:linear-gradient(#000000,#000000);';
        $cells = '/<td width="(\d+)" height="(\d+)"(' . preg_quote($black, '/') . '| style=")'
            . 'font-size:0;line-height:0"><\/td>/';
        self::assertSame(
            substr_count($html, '<td width='),
            preg_match_all($cells, $html, $all, PREG_SET_ORDER),
            'every QR cell has a fixed size, black ones all three colours, and no text'
        );
        self::assertSame(0, preg_match('/<td[^>]*>[^<]/', $html), 'no cell holds text');

        $white = 'bgcolor="#ffffff" style="border-collapse:collapse;'
            . 'background-color:#fff;background-image:linear-gradient(#ffffff,#ffffff)"';
        self::assertSame(
            substr_count($html, '<table cellpadding'),
            substr_count($html, $white),
            'every row table is white three times over'
        );

        // Error correction level H: the two level bits of the format
        // information, at column 8 of the last two rows (mask 0b10 applied).
        $n = count($matrix);
        self::assertSame(0b10, (((int) $matrix[$n - 1][8] ^ 1) << 1) | (int) $matrix[$n - 2][8]);

        // Read the modules back: one inner table per row.
        preg_match_all('/<table cellpadding[^>]*><tr>(.*?)<\/tr><\/table>/', $html, $rows);
        $drawn = [];

        foreach ($rows[1] as $row) {
            preg_match_all($cells, $row, $runs, PREG_SET_ORDER);
            $modules = [];
            $height = (int) $runs[0][2];

            foreach ($runs as $run) {
                self::assertSame($height, (int) $run[2]);
                $modules = array_merge($modules, array_fill(0, intdiv((int) $run[1], 6), $black === $run[3]));
            }

            self::assertCount($side, $modules);
            self::assertSame(0, $height % 6);

            for ($i = 0; $i < $height / 6; $i++) {
                $drawn[] = $modules;
            }
        }

        $white = array_fill(0, $side, false);
        $quiet = array_fill(0, 4, false);
        $expected = array_merge(
            array_fill(0, 4, $white),
            array_map(static fn (array $row): array => array_merge($quiet, $row, $quiet), $matrix),
            array_fill(0, 4, $white)
        );
        self::assertSame($expected, $drawn);
    }

    /**
     * Gmail clips a message over 102 KB, which would hide the QR. The whole
     * HTML of a buyer's email stays under 85 KB, with a 32-character code in
     * lower case (byte mode, the largest QR such a code makes: version 4 at
     * level H, 33 modules a side). It asks mail apps for light colours only.
     */
    public function testTheWholeBuyerEmailStaysSmall(): void
    {
        $buyer = $this->buyerRow(1);
        $buyer['ticket_code'] = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';
        $this->wpdb->nextResults[] = [$buyer];

        $this->ticketMail()->run();

        self::assertCount(1, $this->mails);
        self::assertStringContainsString('<table', $this->mails[0]['body']);
        self::assertStringNotContainsString('cid:', $this->mails[0]['body']);
        self::assertLessThan(85 * 1024, strlen($this->mails[0]['body']));
        self::assertStringContainsString('<meta name="color-scheme" content="light only">', $this->mails[0]['body']);
        self::assertStringContainsString(
            '<meta name="supported-color-schemes" content="light only">',
            $this->mails[0]['body']
        );
        self::assertStringContainsString(':root{color-scheme:light only}', $this->mails[0]['body']);
    }

    /**
     * The same for a guest with a large id, on a long site address (the
     * ticket page link is in the email too; the QR holds the short code).
     */
    public function testTheWholeGuestEmailStaysSmall(): void
    {
        Functions\when('rest_url')->alias(
            static fn (string $p = ''): string => 'https://tanssit.example-dance-events.fi/wp-json/' . $p
        );
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [$this->guestRow(123456)];

        $this->ticketMail()->run();

        self::assertCount(1, $this->mails);
        self::assertStringContainsString('<table', $this->mails[0]['body']);
        self::assertStringNotContainsString('cid:', $this->mails[0]['body']);

        self::assertLessThan(85 * 1024, strlen($this->mails[0]['body']));
    }

    /**
     * A mail plugin that throws from inside wp_mail (FluentSMTP reading a
     * string attachment as a file path did) fails that row only: it is
     * recorded as failed, the next row is still sent, and the log names
     * neither the address nor the ticket code.
     */
    public function testAThrowingMailPluginFailsOneRowAndTheBatchGoesOn(): void
    {
        $logged = [];
        Functions\when('update_option')->alias(static function (string $name, mixed $value) use (&$logged): bool {
            $logged[] = (string) json_encode($value);

            return true;
        });
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

            if ('anna1@example.test' === $to) {
                throw new \ValueError('realpath(): Argument #1 ($path) must not contain any null bytes');
            }

            return true;
        });

        $this->wpdb->nextResults[] = [$this->buyerRow(1), $this->buyerRow(2)];

        self::assertSame(2, $this->ticketMail()->run());
        self::assertSame(['anna1@example.test', 'anna2@example.test'], array_column($this->mails, 'to'));

        $updates = $this->updates();
        self::assertSame(['id' => 1], $updates[0]['where']);
        self::assertSame('failed', $updates[0]['data']['ticket_mail_status']);
        self::assertSame(['id' => 2], $updates[1]['where']);
        self::assertSame('sent', $updates[1]['data']['ticket_mail_status']);

        $log = implode("\n", $logged);
        self::assertStringContainsString('ValueError', $log);
        self::assertStringNotContainsString('anna1', $log);
        self::assertStringNotContainsString('AB12CD1', $log);
    }

    /**
     * A guest's QR holds a short signed code, which only verifies unchanged;
     * the link under it is still the full signed ticket page link.
     */
    public function testAGuestsQrHoldsTheirShortSignedCode(): void
    {
        $guest = Guest::fromRow($this->guestRow(9));
        $data = TicketMail::qrData($guest);

        self::assertMatchesRegularExpression('/^EC-G9-[0-9a-f]{16}$/', $data);
        self::assertSame(
            substr(hash_hmac('sha256', 'guest_ticket|9', 'eventcrew-test-key'), 0, 16),
            substr($data, -16)
        );
        self::assertSame(9, TicketMail::guestIdFromCode($data));
        self::assertNull(TicketMail::guestIdFromCode('EC-G8-' . substr($data, -16)));
        self::assertNull(TicketMail::guestIdFromCode(substr($data, 0, -1) . ('0' === substr($data, -1) ? '1' : '0')));
        self::assertNull(TicketMail::guestIdFromCode('AB12CD1'));

        $mail = $this->ticketMail()->compose($guest);
        self::assertStringContainsString('Cy Cole +2', $mail['html']);
        self::assertStringContainsString('Thank you for coming!', $mail['html']);

        preg_match('/href="([^"]+)"/', $mail['html'], $link);
        $url = html_entity_decode($link[1]);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertStringStartsWith('https://site.test/wp-json/eventcrew/v1/ticket?', $url);
        self::assertSame(9, SignedLink::verify('guest_ticket', (string) $query['token']));
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
