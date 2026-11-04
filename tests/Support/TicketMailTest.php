<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\CrewTicket;
use EventCrew\Models\Guest;
use EventCrew\Models\Person;
use EventCrew\Models\TicketBuyer;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\RedemptionRepository;
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
            new Mailer(new Logger(), new EmailTemplate(new Logger())),
            new TicketQr(),
            new AssignmentRepository(),
            new RedemptionRepository()
        );
    }

    /** @param array<string, array<string, bool>> $prefs */
    private function person(string $email = 'mia@example.test', array $prefs = []): Person
    {
        return new Person(8, $email, 'Mia Crew', '2026-01-01 00:00:00', notifyPrefs: $prefs);
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
        // A buyer added at the desk with an email is mailed too; a door sale is not.
        self::assertStringContainsString("source <> 'door'", $buyers);
        self::assertStringNotContainsString("'desk'", $buyers);
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

        $result = $this->ticketMail()->sendTest('2026-11-14', 'owner@example.test');

        self::assertSame(TicketMail::TEST_SENT, $result);
        self::assertSame(['owner@example.test'], array_column($this->mails, 'to'));
        self::assertSame([], $this->wpdb->updates);
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testSendTestWithNobodyToBuildFromSendsNothing(): void
    {
        self::assertSame(TicketMail::TEST_NONE, $this->ticketMail()->sendTest('2026-11-14', 'owner@example.test'));
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
        $qr = new TicketQr();
        $data = TicketMail::qrData($buyer);
        self::assertStringContainsString(
            $qr->table($data, $qr->fitCell($data, 224, 'https://site.test/logo.png'), 'https://site.test/logo.png'),
            $mail['html']
        );
    }

    /**
     * The ticket is drawn like the ticket page: the dark header with the
     * event and the date, then the holder's name, the QR code, the code and
     * the link, in that order, on the page's grey. Inline styles and tables
     * only: no style block, no linked stylesheet, no flex or grid layout.
     */
    public function testTheTicketLooksLikeTheTicketPage(): void
    {
        $buyer = TicketBuyer::fromRow($this->buyerRow(1));
        $mail = $this->ticketMail()->compose($buyer);
        $html = $mail['html'];
        $qr = new TicketQr();
        $data = TicketMail::qrData($buyer);
        $cell = $qr->fitCell($data, 224);

        self::assertStringContainsString($qr->table($data, $cell), $html);
        self::assertLessThanOrEqual(224, (count($qr->matrix($data)) + 8) * $cell);
        self::assertStringContainsString('bgcolor="#e8e6e3"', $html);
        self::assertStringContainsString('bgcolor="#2b2b30"', $html);
        self::assertStringContainsString('max-width:416px', $html);
        self::assertStringContainsString('font-family:system-ui,', $html);

        $order = [
            'Lippusi / Your ticket',
            'Test Site</p>',
            '14.11.2026</p>',
            'Anna Virtanen</p>',
            '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width=',
            'AB12CD1</p>',
            'Avaa lippu selaimessa / Open the ticket in your browser',
        ];
        $last = -1;

        foreach ($order as $text) {
            $at = strpos($html, $text, $last + 1);
            self::assertNotFalse($at, $text);
            self::assertGreaterThan($last, $at, $text);
            $last = $at;
        }

        foreach (['<style', '<link', '@import', 'display:flex', 'display:grid', '<div'] as $css) {
            self::assertStringNotContainsStringIgnoringCase($css, $html);
        }

        // The FI / EN tags of the two texts are still there.
        self::assertStringContainsString('>FI<', $html);
        self::assertStringContainsString('>EN<', $html);
    }

    /** With an event picture, it is behind the header under the page's dark layer. */
    public function testTheTicketHeaderHasTheEventPicture(): void
    {
        Functions\when('get_the_title')->justReturn('Salsa Night');
        Functions\when('get_the_post_thumbnail_url')->justReturn('https://site.test/poster.jpg');
        $this->wpdb->nextResults[] = [[
            'id' => 3,
            'task_date' => '2026-11-14',
            'event_post_id' => 55,
        ]];
        $mail = $this->ticketMail()->compose(TicketBuyer::fromRow($this->buyerRow(1)));

        self::assertStringContainsString(
            'background-image:linear-gradient(rgba(0,0,0,.2),rgba(0,0,0,.72)),'
                . 'url(&quot;https://site.test/poster.jpg&quot;)',
            $mail['html']
        );
        self::assertStringContainsString('Salsa Night</p>', $mail['html']);
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

    /**
     * Decision 1 and 2: a crew sign-up gets the same "Your ticket" email as a
     * ticket buyer, at once, with a short signed EC-C code in the QR and the
     * sign-up's old ticket link under it; the send is recorded on the sign-up.
     */
    public function testACrewSignupGetsTheTicketEmailWithACrewCode(): void
    {
        $mail = $this->ticketMail();

        self::assertTrue($mail->sendCrewTicket($this->person(), 31, '2026-11-14'));
        self::assertSame(['mia@example.test'], array_column($this->mails, 'to'));
        self::assertSame('Lippusi / Your ticket – 14.11.2026', $this->mails[0]['subject']);
        self::assertStringContainsString('Kiitos, että otit tehtävän!', $this->mails[0]['body']);
        self::assertStringContainsString('Thank you for taking a task!', $this->mails[0]['body']);
        self::assertStringContainsString('Open the ticket in your browser', $this->mails[0]['body']);

        $update = $this->updates()[0];
        self::assertSame('wp_eventcrew_assignments', $update['table']);
        self::assertSame(['id' => 31], $update['where']);
        self::assertSame('2026-07-20 12:00:00', $update['data']['ticket_mailed_at']);

        // The QR holds EC-C<id>-<16 hex>, which only verifies unchanged.
        $ticket = new CrewTicket(CrewTicket::KIND_CREW, 31, '2026-11-14', 'Mia Crew', 'mia@example.test');
        $code = TicketMail::qrData($ticket);
        self::assertMatchesRegularExpression('/^EC-C31-[0-9a-f]{16}$/', $code);
        self::assertSame(31, TicketMail::crewIdFromCode($code));
        self::assertNull(TicketMail::crewIdFromCode('EC-C32-' . substr($code, -16)));
        self::assertNull(TicketMail::creditIdFromCode('EC-F31-' . substr($code, -16)));
        self::assertNull(TicketMail::guestIdFromCode('EC-G31-' . substr($code, -16)));

        // The link is the sign-up's existing ticket link, so old links keep working.
        $html = $mail->compose($ticket)['html'];
        preg_match('/href="([^"]+eventcrew\/v1\/ticket[^"]*)"/', $html, $link);
        parse_str((string) parse_url(html_entity_decode($link[1]), PHP_URL_QUERY), $query);
        self::assertSame(31, SignedLink::verify('ticket', (string) $query['token']));
    }

    /**
     * A bought ticket, a guest-list ticket, a crew sign-up and a spent credit
     * get the one email: the same subject, the same card in the same order,
     * a QR code and the link. Only the thank-you line, a buyer's written-out
     * code and a guest's plus-ones line differ.
     */
    public function testEveryKindOfTicketGetsTheSameEmail(): void
    {
        $mail = $this->ticketMail();
        $rows = [
            TicketBuyer::fromRow($this->buyerRow(1)),
            Guest::fromRow($this->guestRow(9)),
            new CrewTicket(CrewTicket::KIND_CREW, 31, '2026-11-14', 'Mia Crew', 'mia@example.test'),
            new CrewTicket(CrewTicket::KIND_CREDIT, 12, '2026-11-14', 'Mia Crew', 'mia@example.test'),
        ];

        foreach ($rows as $row) {
            $email = $mail->compose($row);
            $qr = new TicketQr();
            $data = TicketMail::qrData($row);

            self::assertSame('Lippusi / Your ticket – 14.11.2026', $email['subject']);

            $order = [
                '>FI<',
                '>EN<',
                'bgcolor="#e8e6e3"',
                'bgcolor="#2b2b30"',
                'Lippusi / Your ticket',
                'Test Site</p>',
                '14.11.2026</p>',
                $qr->table($data, $qr->fitCell($data, 224)),
                'Avaa lippu selaimessa / Open the ticket in your browser',
            ];
            $last = -1;

            foreach ($order as $text) {
                $at = strpos($email['html'], $text, $last + 1);
                self::assertNotFalse($at, get_class($row) . ': ' . substr($text, 0, 40));
                $last = $at;
            }
        }
    }

    /** A credit spent on a night gets the same email, with an EC-F code. */
    public function testACreditGetsTheTicketEmailWithACreditCode(): void
    {
        self::assertTrue($this->ticketMail()->sendCreditTicket($this->person(), 12, '2026-11-14'));
        self::assertStringContainsString('Käytit vapaalipun tähän iltaan.', $this->mails[0]['body']);
        self::assertStringContainsString('You spent a free-entry credit on this night.', $this->mails[0]['body']);

        $update = $this->updates()[0];
        self::assertSame('wp_eventcrew_redemptions', $update['table']);
        self::assertSame(['id' => 12], $update['where']);

        $code = TicketMail::creditCode(12);
        self::assertMatchesRegularExpression('/^EC-F12-[0-9a-f]{16}$/', $code);
        self::assertSame(12, TicketMail::creditIdFromCode($code));
        self::assertNull(TicketMail::crewIdFromCode('EC-C12-' . substr($code, -16)));
    }

    /**
     * A credit's ticket email follows the person's "Free-entry ticket ready"
     * email choice; nothing goes to a placeholder address.
     */
    public function testNoCrewTicketEmailWhenSwitchedOffOrNoAddress(): void
    {
        $mail = $this->ticketMail();
        $off = $this->person('mia@example.test', ['ticket' => ['email' => false]]);

        self::assertFalse($mail->sendCreditTicket($off, 12, '2026-11-14'));
        self::assertFalse($mail->sendCrewTicket($this->person('person-8@crew.invalid'), 31, '2026-11-14'));
        self::assertSame([], $this->mails);
        self::assertSame([], $this->wpdb->updates);
    }

    /**
     * Decision 4: a guest who was sent a ticket gets "Your ticket is
     * cancelled" in both languages; a guest who was not sent one gets nothing.
     */
    public function testARemovedGuestWithASentTicketGetsTheCancelEmail(): void
    {
        $mail = $this->ticketMail();
        $sent = Guest::fromRow(['ticket_mail_status' => 'sent'] + $this->guestRow(9));
        $unsent = Guest::fromRow($this->guestRow(10));

        self::assertFalse($mail->guestCancelled($unsent));
        self::assertSame([], $this->mails);

        self::assertTrue($mail->guestCancelled($sent));
        self::assertSame(['cy@example.test'], array_column($this->mails, 'to'));
        self::assertSame('Lippusi on peruttu / Your ticket is cancelled – 14.11.2026', $this->mails[0]['subject']);
        self::assertStringContainsString('Lippusi tapahtumaan Test Site 14.11.2026 on peruttu.', $this->mails[0]['body']);
        self::assertStringContainsString(
            'Your ticket to Test Site on 14.11.2026 is cancelled. The QR code in the ticket email no longer works',
            $this->mails[0]['body']
        );
        self::assertStringContainsString('you had a ticket to this event', $this->mails[0]['body']);
    }

    /**
     * The cancel email is drawn like the ticket email: the two texts, then
     * the same card with the dark header, and a red band saying the ticket
     * is cancelled in both languages. No QR code: the old one no longer works.
     */
    public function testTheCancelEmailLooksLikeTheTicketWithARedBand(): void
    {
        $mail = $this->ticketMail()->composeCancelled('Cy Cole', '2026-11-14');
        $html = $mail['html'];

        self::assertStringContainsString('bgcolor="#e8e6e3"', $html);
        self::assertStringContainsString('bgcolor="#2b2b30"', $html);
        self::assertStringContainsString('max-width:416px', $html);
        self::assertStringContainsString('bgcolor="#b32d2e"', $html);

        $order = [
            '>FI<',
            '>EN<',
            'Lippusi / Your ticket',
            'Test Site</p>',
            '14.11.2026</p>',
            'LIPPU PERUTTU / TICKET CANCELLED',
            'Cy Cole</p>',
        ];
        $last = -1;

        foreach ($order as $text) {
            $at = strpos($html, $text, $last + 1);
            self::assertNotFalse($at, $text);
            self::assertGreaterThan($last, $at, $text);
            $last = $at;
        }

        self::assertStringNotContainsString('bgcolor="#000000"', $html);
        self::assertStringContainsString('LIPPU PERUTTU / TICKET CANCELLED', $mail['text']);

        foreach (['<style', '<link', '@import', 'display:flex', 'display:grid', '<div'] as $css) {
            self::assertStringNotContainsStringIgnoringCase($css, $html);
        }
    }

    /**
     * A crew cancel email goes only when that ticket was emailed, and never
     * for a night already past.
     */
    public function testTheCrewCancelEmailNeedsASentTicketAndAComingNight(): void
    {
        $mail = $this->ticketMail();

        self::assertFalse($mail->crewCancelled($this->person(), null, '2026-11-14'));
        self::assertFalse($mail->crewCancelled($this->person(), '2026-07-01 10:00:00', '2026-07-19'));
        self::assertSame([], $this->mails);

        self::assertTrue($mail->crewCancelled($this->person(), '2026-07-01 10:00:00', '2026-11-14'));
        self::assertSame(['mia@example.test'], array_column($this->mails, 'to'));
    }

    /** Decision 5: a desk buyer's ticket code is a short signed EC-B code. */
    public function testADeskBuyerCodeVerifies(): void
    {
        $code = TicketMail::deskBuyerCode(40);

        self::assertMatchesRegularExpression('/^EC-B40-[0-9a-f]{16}$/', $code);
        self::assertSame(40, TicketMail::deskBuyerIdFromCode($code));
        self::assertNull(TicketMail::deskBuyerIdFromCode('EC-B41-' . substr($code, -16)));
    }

    public function testTheStatusLine(): void
    {
        self::assertSame(
            'Ticket emails: 38 sent · 2 failed · 2 not sent',
            TicketMail::statusLine(['sent' => 38, 'failed' => 2, 'unsent' => 2])
        );
        self::assertSame('', TicketMail::statusLine(['sent' => 0, 'failed' => 0, 'unsent' => 0]));
    }

    // --- the 10-minute wait before a send ------------------------------------

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var list<array{at: int, hook: string}> */
    private array $events = [];

    /** @var list<string> */
    private array $cleared = [];

    /**
     * Options kept in memory, and every scheduled event with its time, so a
     * test can read the waiting send and when it goes out.
     */
    private function useOptionStore(): void
    {
        $this->options = [];
        $this->events = [];
        $this->cleared = [];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
        Functions\when('delete_option')->alias(function (string $name): bool {
            unset($this->options[$name]);

            return true;
        });
        Functions\when('wp_schedule_single_event')->alias(function (int $time, string $hook): bool {
            $this->scheduled[] = $hook;
            $this->events[] = ['at' => $time, 'hook' => $hook];

            return true;
        });
        Functions\when('wp_clear_scheduled_hook')->alias(function (string $hook): int {
            $this->cleared[] = $hook;

            return 1;
        });
    }

    public function testASendWaitsTenMinutesAndNothingGoesOutAtOnce(): void
    {
        $this->useOptionStore();
        $before = time();

        TicketMail::schedule();

        self::assertCount(1, $this->events);
        self::assertSame(TicketMail::START_HOOK, $this->events[0]['hook']);
        self::assertGreaterThanOrEqual($before + 600, $this->events[0]['at']);
        self::assertLessThanOrEqual(time() + 600, $this->events[0]['at']);
        self::assertSame($this->events[0]['at'], TicketMail::pending()['at'] ?? null);

        // A batch run while the send waits sends nothing and reads no row.
        self::assertSame(0, $this->ticketMail()->run());
        self::assertSame([], $this->mails);
        self::assertSame([], $this->wpdb->queries);
        self::assertSame([], $this->wpdb->updates);
    }

    public function testWhenTheTimeComesTheSendGoesOutAsBefore(): void
    {
        $this->useOptionStore();
        TicketMail::schedule();
        $this->wpdb->nextResults[] = [$this->buyerRow(1)]; // buyers
        $this->wpdb->nextResults[] = [];                    // guests

        self::assertSame(1, $this->ticketMail()->start());
        self::assertSame(['anna1@example.test'], array_column($this->mails, 'to'));
        self::assertSame('sent', $this->updates()[0]['data']['ticket_mail_status']);
        self::assertNull(TicketMail::pending());
    }

    public function testCancelSendsNothingAndTheTicketsStayNotSent(): void
    {
        $this->useOptionStore();
        TicketMail::schedule();

        self::assertTrue(TicketMail::cancel());
        self::assertSame([TicketMail::START_HOOK], $this->cleared);
        self::assertNull(TicketMail::pending());

        // Should the event still fire, it finds nothing to start.
        $this->wpdb->nextResults[] = [$this->buyerRow(1)];
        self::assertSame(0, $this->ticketMail()->start());
        self::assertSame([], $this->mails);
        self::assertSame([], $this->wpdb->updates);
        self::assertSame([], $this->wpdb->queries);

        // A second cancel finds nothing waiting.
        self::assertFalse(TicketMail::cancel());
    }

    public function testPressingSendTwiceLeavesOneWaitingSend(): void
    {
        $this->useOptionStore();

        $first = TicketMail::scheduleRest('2026-11-14', TicketMail::KIND_BUYERS);
        $second = TicketMail::scheduleRest('2026-11-14', TicketMail::KIND_BUYERS);
        $guests = TicketMail::scheduleRest('2026-11-14', TicketMail::KIND_GUESTS);
        TicketMail::schedule(); // an import meanwhile

        self::assertTrue($first['started']);
        self::assertFalse($second['started']);
        self::assertFalse($guests['started']);
        self::assertSame($first['at'], $second['at']);
        self::assertSame($first['at'], $guests['at']);
        self::assertSame([TicketMail::START_HOOK], $this->scheduled);
        self::assertSame(['2026-11-14|buyers', '2026-11-14|guests'], TicketMail::pending()['rest'] ?? null);
        self::assertSame([], $this->mails);
    }

    /** "Send the rest now" retries the night's failed tickets once the send starts. */
    public function testTheRestOfANightIsRetriedWhenTheSendStarts(): void
    {
        $this->useOptionStore();
        TicketMail::scheduleRest('2026-11-14', TicketMail::KIND_BUYERS);
        $this->wpdb->nextResults[] = [];                                // buyers not tried
        $this->wpdb->nextResults[] = [];                                // guests not tried
        $this->wpdb->nextResults[] = [$this->buyerRow(1, 'failed')];    // the night's not sent
        $this->wpdb->nextResults[] = [['status' => 'sent', 'n' => 3]];  // counts afterwards

        self::assertSame(1, $this->ticketMail()->start());
        self::assertSame(['anna1@example.test'], array_column($this->mails, 'to'));
        self::assertArrayNotHasKey(TicketMail::REST_OPTION, $this->options);
        self::assertArrayNotHasKey(TicketMail::PENDING_OPTION, $this->options);
    }

    public function testTheTestSendIsNotDelayed(): void
    {
        $this->useOptionStore();
        TicketMail::schedule();
        $this->wpdb->nextResults[] = [$this->buyerRow(1)];

        self::assertSame(TicketMail::TEST_SENT, $this->ticketMail()->sendTest('2026-11-14', 'owner@example.test'));
        self::assertSame(['owner@example.test'], array_column($this->mails, 'to'));
        self::assertNotNull(TicketMail::pending());
    }

    public function testAnImportStartsTheDelayedSendAndMailsNobodyYet(): void
    {
        $this->useOptionStore();
        $this->wpdb->nextVars[] = '0';   // hasBatch: not seen before
        $this->wpdb->nextResults[] = []; // nobody on the list yet
        $import = new \EventCrew\Support\BuyerImport(
            new TicketBuyerRepository(),
            new \EventCrew\Support\DoorAccess(new TaskRepository(), new AssignmentRepository())
        );

        $result = $import->import('2026-11-14', "Anna\nBo", 'email', 'b1');

        self::assertSame(2, $result['added']);
        self::assertSame([TicketMail::START_HOOK], array_column($this->events, 'hook'));
        self::assertGreaterThanOrEqual(time() + 599, $this->events[0]['at']);
        self::assertSame([], $this->mails);
    }

    /** Without wp_date (as in these tests) the clock falls back to UTC. */
    public function testThePendingLineSaysWhenAndToHowMany(): void
    {
        self::assertSame(
            'Ticket emails go out at 21:40 to 12 buyers, 2 guests.',
            TicketMail::pendingLine(['at' => gmmktime(21, 40, 0, 11, 4, 2026), 'buyers' => 12, 'guests' => 2])
        );
        self::assertSame(
            'Ticket emails go out at 21:40 to 1 buyer.',
            TicketMail::pendingLine(['at' => gmmktime(21, 40, 0, 11, 4, 2026), 'buyers' => 1, 'guests' => 0])
        );
    }
}
