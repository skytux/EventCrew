<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\SignedLink;
use EventCrew\Telegram\TicketController;
use EventCrew\Tests\TestCase;

final class TicketControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('esc_html')->returnArg(1);
        Functions\when('get_option')->justReturn(null); // Roles defaults
        Functions\when('get_the_title')->justReturn('');
    }

    private function controller(): TicketController
    {
        return new TicketController(
            new AssignmentRepository(),
            new TaskRepository(),
            new PersonRepository(),
            new RedemptionRepository()
        );
    }

    public function testResolvesAValidTicket(): void
    {
        // assignments->find, tasks->find, people->find
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];
        $this->wpdb->nextRows[] = [
            'id' => 5, 'task_date' => '2026-08-01', 'role_slug' => 'decorate',
            'capacity' => 2, 'event_post_id' => null, 'event_label' => 'Party',
            'starts_at' => '2026-08-01 20:00:00', 'ends_at' => null,
        ];
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam'];

        $ticket = $this->controller()->ticketFor(SignedLink::sign('ticket', 9));

        self::assertNotNull($ticket);
        self::assertTrue($ticket['valid']);
        self::assertSame('Sam', $ticket['name']);
        self::assertSame('Party', $ticket['event']);
    }

    public function testACancelledAssignmentReadsAsDisabled(): void
    {
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'cancelled'];
        $this->wpdb->nextRows[] = [
            'id' => 5, 'task_date' => '2026-08-01', 'role_slug' => 'decorate',
            'capacity' => 2, 'event_post_id' => null, 'event_label' => 'Party',
        ];
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam'];

        $ticket = $this->controller()->ticketFor(SignedLink::sign('ticket', 9));

        self::assertFalse($ticket['valid']);
    }

    public function testRejectsABadToken(): void
    {
        self::assertNull($this->controller()->ticketFor('nonsense'));
        // A manage token must not open a ticket.
        self::assertNull($this->controller()->ticketFor(SignedLink::sign('manage', 9)));
    }

    public function testResolvesACreditTicket(): void
    {
        // redemptions->find, then people->find.
        $this->wpdb->nextRows[] = [
            'id' => 3, 'person_id' => 7, 'redeemed_for' => '2026-08-01',
            'event_post_id' => null, 'event_label' => 'Party', 'redeemed_at' => '2026-07-24 20:00:00',
        ];
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam'];

        $ticket = $this->controller()->ticketFor(SignedLink::sign('credit_ticket', 3));

        self::assertNotNull($ticket);
        self::assertTrue($ticket['valid']);
        self::assertSame('Sam', $ticket['name']);
        self::assertSame('Party', $ticket['event']);
        self::assertSame('Free entry', $ticket['role']);
        self::assertSame('2026-07-24 20:00:00', $ticket['issued']);
    }

    public function testACancelledCreditTicketReadsAsDisabled(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 3, 'person_id' => 7, 'redeemed_for' => '2026-08-01',
            'event_post_id' => null, 'event_label' => 'Party', 'redeemed_at' => '2026-07-24 20:00:00',
            'cancelled_at' => '2026-07-25 10:00:00',
        ];
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam'];

        $ticket = $this->controller()->ticketFor(SignedLink::sign('credit_ticket', 3));

        self::assertNotNull($ticket);
        self::assertFalse($ticket['valid']);
    }

    public function testACreditTicketWhoseRedemptionIsGoneIsNotFound(): void
    {
        // No redemption row queued -> find() returns null -> the link no longer resolves.
        self::assertNull($this->controller()->ticketFor(SignedLink::sign('credit_ticket', 3)));
    }

    /** @return array<string, mixed> */
    private function buyerRow(string $code): array
    {
        return [
            'id' => 4, 'event_date' => '2026-08-01', 'name' => 'Bo Buyer', 'source' => 'holvi',
            'email' => 'bo@example.test', 'ticket_code' => $code,
        ];
    }

    /**
     * An emailed buyer ticket opens the page; its QR code holds the ticket
     * code, which is written out under it.
     */
    public function testResolvesAnEmailedBuyerTicket(): void
    {
        $this->wpdb->nextRows[] = $this->buyerRow('AB12CD');

        $ticket = $this->controller()->ticketFor(SignedLink::sign('buyer_ticket|AB12CD', 4));

        self::assertNotNull($ticket);
        self::assertTrue($ticket['valid']);
        self::assertSame('Bo Buyer', $ticket['name']);
        self::assertSame('AB12CD', $ticket['qr']);
        self::assertSame('AB12CD', $ticket['code']);
        self::assertSame('Test Site', $ticket['event']);
    }

    /** Once the ticket code is deleted (RetentionPurge, erasure) the link dies. */
    public function testAnEmailedBuyerTicketDiesWithItsCode(): void
    {
        $token = SignedLink::sign('buyer_ticket|AB12CD', 4);

        $this->wpdb->nextRows[] = ['ticket_code' => null, 'email' => null, 'name' => ''] + $this->buyerRow('');
        self::assertNull($this->controller()->ticketFor($token));

        // A different code on the row (a forged id) does not verify either.
        $this->wpdb->nextRows[] = $this->buyerRow('ZZ99');
        self::assertNull($this->controller()->ticketFor($token));
    }

    public function testResolvesAnEmailedGuestTicket(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 12, 'event_date' => '2026-08-01', 'name' => 'Cy Cole', 'plus_ones' => 2,
            'email' => 'cy@example.test',
        ];

        $ticket = $this->controller()->ticketFor(SignedLink::sign('guest_ticket', 12));

        self::assertNotNull($ticket);
        self::assertSame('Cy Cole', $ticket['name']);
        self::assertSame('Guest list, +2 people', $ticket['role']);
        self::assertArrayNotHasKey('qr', $ticket);
    }

    public function testAGuestTicketWithItsEmailDeletedIsNotFound(): void
    {
        $this->wpdb->nextRows[] = ['id' => 12, 'event_date' => '2026-08-01', 'name' => 'Cy Cole', 'email' => null];

        self::assertNull($this->controller()->ticketFor(SignedLink::sign('guest_ticket', 12)));
    }

    public function testRenderPageShowsTheLiveClock(): void
    {
        $ticket = [
            'valid' => true, 'name' => 'Sam', 'event' => 'Party', 'date' => '2026-08-01',
            'role' => 'Free entry', 'time' => '', 'issued' => '2026-07-24 20:00:00',
        ];

        $html = $this->controller()->renderPage($ticket);

        self::assertStringContainsString('id="ec-clock"', $html);
        self::assertStringContainsString('Issued', $html);
    }

    public function testRenderPageShowsTheTicketBackground(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => TicketController::BACKGROUND_OPTION === $name
                ? 'https://site.test/team-background.png'
                : null
        );
        // The Site Icon is no longer drawn: only the chosen background.
        Functions\when('get_site_icon_url')->justReturn('https://site.test/icon-512.png');

        $html = $this->controller()->renderPage($this->sampleTicket());

        self::assertStringContainsString('url(&quot;https://site.test/team-background.png&quot;) center/cover no-repeat', $html);
        self::assertStringNotContainsString('icon-512', $html);
        self::assertStringContainsString('linear-gradient(rgba(255,255,255,.8),rgba(255,255,255,.8))', $html);
        // The background sits on the card's body, under the picture header.
        self::assertStringContainsString('<div class="t-body" style="background:linear-gradient(', $html);
    }

    public function testRenderPageHasNoBackgroundWhenNoneIsSet(): void
    {
        Functions\when('get_site_icon_url')->justReturn('https://site.test/icon-512.png');

        $html = $this->controller()->renderPage($this->sampleTicket());

        self::assertStringNotContainsString('url(', $html);
        self::assertStringNotContainsString('linear-gradient', $html);
        self::assertStringContainsString('<div class="t-body"><p class="t-name">Sam</p>', $html);
    }

    public function testRenderPageShowsTheEventPictureInTheHeader(): void
    {
        $ticket = $this->sampleTicket();
        $ticket['image'] = 'https://site.test/poster.jpg';

        $html = $this->controller()->renderPage($ticket);

        self::assertStringContainsString('<header class="t-head has-image" style="background-image:', $html);
        self::assertStringContainsString('url(&quot;https://site.test/poster.jpg&quot;)', $html);
        self::assertStringContainsString('<h1 class="t-event">Party</h1>', $html);
    }

    public function testRenderPageHeaderIsPlainWithoutAPicture(): void
    {
        $html = $this->controller()->renderPage($this->sampleTicket());

        self::assertStringContainsString('<header class="t-head"><p class="t-kicker">', $html);
    }

    public function testRenderPageWritesTheDateOutInFull(): void
    {
        $html = $this->controller()->renderPage($this->sampleTicket());

        self::assertStringContainsString('<p class="t-date">Saturday 1 August 2026</p>', $html);
    }

    public function testRenderPageShowsTheValidAndDisabledBands(): void
    {
        $ticket = $this->sampleTicket();

        self::assertStringContainsString(
            '<p class="t-band is-valid">VALID</p>',
            $this->controller()->renderPage($ticket)
        );

        $ticket['valid'] = false;

        self::assertStringContainsString(
            '<p class="t-band is-disabled">DISABLED</p>',
            $this->controller()->renderPage($ticket)
        );
    }

    public function testRenderPageDrawsAQrCodeWithTheLogoInTheMiddle(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => 'eventcrew_email_logo' === $name
                ? 'https://site.test/logo.png'
                : null
        );

        $html = $this->controller()->renderPage($this->sampleTicket());

        self::assertStringContainsString(
            '<div class="t-qr" id="ec-qr"><img class="t-qr-logo" src="https://site.test/logo.png" alt=""></div>',
            $html
        );
        self::assertStringContainsString('assets/vendor/qrcode-generator/qrcode.js?ver=', $html);
        // Level H: the code still scans with the logo covering its middle.
        self::assertStringContainsString('qrcode(0,"H")', $html);
        self::assertStringContainsString('addData(location.href)', $html);
    }

    public function testRenderPageQrCodePrefersTheSiteIconOverTheEmailLogo(): void
    {
        // The email logo is often white for a dark masthead: on the white
        // square in the middle of the code it cannot be seen.
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => 'eventcrew_email_logo' === $name
                ? 'https://site.test/logo-white.png'
                : null
        );
        Functions\when('has_site_icon')->justReturn(true);
        Functions\when('get_site_icon_url')->justReturn('https://site.test/icon-180.png');

        $html = $this->controller()->renderPage($this->sampleTicket());

        self::assertStringContainsString(
            '<img class="t-qr-logo" src="https://site.test/icon-180.png" alt="">',
            $html
        );
        self::assertStringNotContainsString('logo-white.png', $html);
    }

    public function testRenderPageQrCodeHasNoLogoWhenNoneIsSet(): void
    {
        $html = $this->controller()->renderPage($this->sampleTicket());

        self::assertStringContainsString('<div class="t-qr" id="ec-qr"></div>', $html);
        self::assertStringNotContainsString('t-qr-logo"', $html);
    }

    /**
     * @return array{valid: bool, name: string, event: string, date: string, role: string, time: string, issued: string}
     */
    private function sampleTicket(): array
    {
        return [
            'valid' => true, 'name' => 'Sam', 'event' => 'Party', 'date' => '2026-08-01',
            'role' => 'Free entry', 'time' => '', 'issued' => '2026-07-24 20:00:00',
        ];
    }

    public function testRenderPageShowsTheTicketNote(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => TicketController::NOTE_OPTION === $name
                ? "Door code: 7302\nSome street 1 <b>"
                : null
        );
        Functions\when('get_site_icon_url')->justReturn('');
        Functions\when('esc_html')->alias(static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES));

        $html = $this->controller()->renderPage($this->sampleTicket());

        self::assertStringContainsString(
            "<p style=\"white-space:pre-line\">Door code: 7302\nSome street 1 &lt;b&gt;</p>",
            $html
        );
    }

    public function testRenderPageHasNoNoteWhenNoneIsSet(): void
    {
        Functions\when('get_site_icon_url')->justReturn('');

        $html = $this->controller()->renderPage($this->sampleTicket());

        self::assertStringNotContainsString('pre-line', $html);
    }

    /** The rostered-slot ticket of Sam (person 7) for 1 August. */
    private function queueCrewTicket(string $status = 'signed_up'): void
    {
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => $status];
        $this->wpdb->nextRows[] = [
            'id' => 5, 'task_date' => '2026-08-01', 'role_slug' => 'decorate',
            'capacity' => 2, 'event_post_id' => null, 'event_label' => 'Party',
        ];
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam'];
    }

    public function testACrewTicketCheckedInAtTheDoorShowsTheOrangeBand(): void
    {
        $this->queueCrewTicket();
        $this->wpdb->nextResults[] = [['ref_id' => 7, 'checked_in_at' => '2026-08-01 21:05:00']];

        $ticket = $this->controller()->ticketFor(SignedLink::sign('ticket', 9));

        self::assertSame('2026-08-01 21:05:00', $ticket['used']);
        $sql = (string) end($this->wpdb->queries);
        self::assertStringContainsString("event_date = '2026-08-01' AND kind = 'crew' AND ref_id IN (7)", $sql);

        $html = $this->controller()->renderPage($ticket);

        self::assertStringContainsString('<p class="t-band is-used">Already used · 21:05</p>', $html);
        self::assertStringNotContainsString('is-valid">', $html);
    }

    public function testACrewTicketMarkedInAsCreditAlsoReadsAsUsed(): void
    {
        $this->queueCrewTicket();
        $this->wpdb->nextResults[] = []; // not in as crew
        $this->wpdb->nextResults[] = [['ref_id' => 7, 'checked_in_at' => '2026-08-01 22:40:00']];

        $ticket = $this->controller()->ticketFor(SignedLink::sign('ticket', 9));

        self::assertSame('2026-08-01 22:40:00', $ticket['used']);
        self::assertStringContainsString("kind = 'credit'", (string) end($this->wpdb->queries));
    }

    public function testACrewTicketNotYetCheckedInStaysValid(): void
    {
        $this->queueCrewTicket();

        $ticket = $this->controller()->ticketFor(SignedLink::sign('ticket', 9));

        self::assertNull($ticket['used']);
        self::assertStringContainsString(
            '<p class="t-band is-valid">VALID</p>',
            $this->controller()->renderPage($ticket)
        );
    }

    public function testAGuestTicketCheckedInShowsTheOrangeBand(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 12, 'event_date' => '2026-08-01', 'name' => 'Cy Cole', 'plus_ones' => 0,
            'email' => 'cy@example.test',
        ];
        $this->wpdb->nextResults[] = []; // tasks->forDate
        $this->wpdb->nextResults[] = [['ref_id' => 12, 'checked_in_at' => '2026-08-01 20:15:00']];

        $ticket = $this->controller()->ticketFor(SignedLink::sign('guest_ticket', 12));

        self::assertStringContainsString("kind = 'guest' AND ref_id IN (12)", (string) end($this->wpdb->queries));
        self::assertStringContainsString(
            '<p class="t-band is-used">Already used · 20:15</p>',
            $this->controller()->renderPage($ticket)
        );
    }

    /** Bo's ticket AB12CD is row 4. Holvi gives every ticket its own code. */
    private function queueBuyerTicket(): void
    {
        $this->wpdb->nextRows[] = $this->buyerRow('AB12CD');
        $this->wpdb->nextResults[] = []; // tasks->forDate
    }

    public function testABuyerTicketNotYetCheckedInStaysValid(): void
    {
        $this->queueBuyerTicket();

        $ticket = $this->controller()->ticketFor(SignedLink::sign('buyer_ticket|AB12CD', 4));

        self::assertNull($ticket['used']);
        self::assertStringContainsString("kind = 'buyer' AND ref_id IN (4)", (string) end($this->wpdb->queries));
        $html = $this->controller()->renderPage($ticket);

        self::assertStringContainsString('<p class="t-band is-valid">VALID</p>', $html);
        self::assertStringNotContainsString(' used<', $html);
    }

    /** Only this row is read: no other row with the same code is looked up. */
    public function testABuyerTicketCheckedInShowsTheOrangeBandWithItsTime(): void
    {
        $this->queueBuyerTicket();
        $this->wpdb->nextResults[] = [['ref_id' => 4, 'checked_in_at' => '2026-08-01 23:10:00']];

        $ticket = $this->controller()->ticketFor(SignedLink::sign('buyer_ticket|AB12CD', 4));

        self::assertSame('2026-08-01 23:10:00', $ticket['used']);
        foreach ($this->wpdb->queries as $sql) {
            self::assertStringNotContainsString('ticket_code', (string) $sql);
        }
        self::assertStringContainsString(
            '<p class="t-band is-used">Already used · 23:10</p>',
            $this->controller()->renderPage($ticket)
        );
    }

    public function testACancelledTicketStaysDisabledEvenWhenCheckedIn(): void
    {
        $this->queueCrewTicket('cancelled');
        $this->wpdb->nextResults[] = [['ref_id' => 7, 'checked_in_at' => '2026-08-01 21:05:00']];

        $ticket = $this->controller()->ticketFor(SignedLink::sign('ticket', 9));
        $html = $this->controller()->renderPage($ticket);

        self::assertNotNull($ticket['used']);
        self::assertStringContainsString('<p class="t-band is-disabled">DISABLED</p>', $html);
        self::assertStringNotContainsString('is-used">', $html);
    }

    public function testRenderPageShowsNotFoundForANullTicket(): void
    {
        $html = $this->controller()->renderPage(null);

        self::assertStringContainsString('not valid', $html);
    }
}
