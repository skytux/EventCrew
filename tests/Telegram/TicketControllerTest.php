<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\Organization;
use EventCrew\Support\EventTimes;
use EventCrew\Support\ShortCode;
use EventCrew\Support\SignedLink;
use EventCrew\Support\TicketMail;
use EventCrew\Telegram\TicketController;
use EventCrew\Tests\Support\FakeEventNames;
use EventCrew\Tests\TestCase;
use WP_REST_Request;

final class TicketControllerTest extends TestCase
{
    use FakeEventNames;

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

    /**
     * After an organizer changed the guest's email address, the link in the
     * old ticket email opens nothing; the new link opens the ticket.
     */
    public function testAGuestTicketLinkFromBeforeAnEmailChangeIsNotFound(): void
    {
        $row = ['id' => 12, 'event_date' => '2026-08-01', 'name' => 'Cy Cole', 'email' => 'new@example.test', 'ticket_rev' => 1];

        $this->wpdb->nextRows[] = $row;
        self::assertNull($this->controller()->ticketFor(TicketMail::guestToken(12)));

        $this->wpdb->nextRows[] = $row;
        $ticket = $this->controller()->ticketFor(TicketMail::guestToken(12, 1));
        self::assertNotNull($ticket);
        self::assertSame('Cy Cole', $ticket['name']);
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
        self::assertStringNotContainsString('linear-gradient(rgba', $html);
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

    public function testRenderPageNamesTheOrganizationInTheHeaderAndTheNote(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => match ($name) {
                Organization::OPTION => 'Ecstatic Dance Helsinki',
                TicketController::NOTE_OPTION => 'Welcome to {organization}.',
                default => $default,
            }
        );
        Functions\when('get_site_icon_url')->justReturn('');

        $html = $this->controller()->renderPage($this->sampleTicket());

        self::assertStringContainsString('<p class="t-kicker">Your Ecstatic Dance Helsinki ticket</p>', $html);
        self::assertStringContainsString('<title>Your Ecstatic Dance Helsinki ticket', $html);
        self::assertStringContainsString('Welcome to Ecstatic Dance Helsinki.', $html);
        self::assertStringNotContainsString('EventCrew', $html);
    }

    public function testRenderPageShowsTheCleanEventName(): void
    {
        Functions\when('get_site_icon_url')->justReturn('');
        $this->fakeEventNames([41 => ['en' => 'Ecstatic Dance']]);
        $ticket = $this->sampleTicket();
        $ticket['event'] = 'ECSTATIC DANCE 14.11.2026 / DJ X';
        $ticket['event_id'] = 41;

        $html = $this->controller()->renderPage($ticket);

        self::assertStringContainsString('<h1 class="t-event">Ecstatic Dance</h1>', $html);
    }

    public function testRenderPageWritesTheDateOutInFull(): void
    {
        $html = $this->controller()->renderPage($this->sampleTicket());

        self::assertStringContainsString('<p class="t-date">Saturday 1 August 2026</p>', $html);
    }

    public function testRenderPageShowsTheEventsStartTime(): void
    {
        Functions\when('get_site_icon_url')->justReturn('');
        Functions\when('post_type_exists')->justReturn(true);
        Functions\when('get_post')->justReturn((object) ['post_type' => 'eventmesh_event', 'post_title' => 'Party']);
        Functions\when('get_post_meta')->alias(
            static fn (int $id, string $key): string => '_eventmesh_starts_at' === $key ? '2026-08-01T19:00:00' : ''
        );
        $ticket = $this->sampleTicket();
        $ticket['event_id'] = 41;

        $html = $this->controller()->renderPage($ticket);

        self::assertStringContainsString('<p class="t-date">Saturday 1 August 2026<br>19:00</p>', $html);
    }

    /** The task and its time are on rows of their own (Owner, 2026-11-06). */
    public function testRenderPagePutsTheTaskAndItsTimeOnRowsOfTheirOwn(): void
    {
        $ticket = $this->sampleTicket();
        $ticket['role'] = 'Bar';
        $ticket['time'] = '19:00–22:00';

        self::assertStringContainsString(
            '<p class="t-task">Bar<br>19:00–22:00</p>',
            $this->controller()->renderPage($ticket)
        );
        self::assertStringContainsString(
            '<p class="t-task">Free entry</p>',
            $this->controller()->renderPage($this->sampleTicket())
        );
    }

    public function testRenderPageShowsTheValidAndDisabledBands(): void
    {
        $ticket = $this->sampleTicket();

        self::assertStringContainsString(
            '<p class="t-band is-valid" id="ec-band" aria-live="polite"><span class="t-state">VALID</span>'
            . '<span class="t-in t-ghost" aria-hidden="true">',
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
            '<div class="t-qr" id="ec-qr" hidden><img class="t-qr-logo" src="https://site.test/logo.png" alt=""></div>',
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

        self::assertStringContainsString('<div class="t-qr" id="ec-qr" hidden></div>', $html);
        self::assertStringNotContainsString('t-qr-logo"', $html);
    }

    /**
     * Owner, 2026-11-07: a person with a crew ticket and a free-entry ticket
     * for the night gets two cards on the page, stacked. Each card has its
     * own band, QR code and clock (own ids) and its own wait for the door.
     */
    public function testRenderPageDrawsOneCardPerTicket(): void
    {
        Functions\when('get_site_icon_url')->justReturn('');
        $crew = ['role' => 'decorate', 'time' => '18:00–20:00', 'poll' => 'https://site.test/state?token=crew']
            + $this->sampleTicket();
        $credit = ['poll' => 'https://site.test/state?token=credit', 'short' => 'K7P2Q'] + $this->sampleTicket();

        $html = $this->controller()->renderPage($crew, [$credit]);

        self::assertStringContainsString('<main class="t-stack">', $html);
        self::assertSame(2, substr_count($html, '<section class="t">'));
        self::assertStringContainsString('id="ec-band"', $html);
        self::assertStringContainsString('id="ec-band-2"', $html);
        self::assertStringContainsString('id="ec-clock"', $html);
        self::assertStringContainsString('id="ec-clock-2"', $html);
        self::assertStringContainsString('"band":"ec-band-2"', $html);
        self::assertStringContainsString('token=crew', $html);
        self::assertStringContainsString('token=credit', $html);
        self::assertStringContainsString('K7P2Q', $html);
        self::assertSame(1, substr_count($html, 'qrcode.js'));
        self::assertStringContainsString('id="ec-qr-2"', $html);

        // One ticket alone is drawn as before.
        $single = $this->controller()->renderPage($crew);
        self::assertStringContainsString('<main class="t">', $single);
        self::assertStringNotContainsString('<section', $single);
    }

    /**
     * A crew ticket's link also shows the person's free-entry ticket for the
     * same night; a free-entry link shows only itself.
     */
    public function testACrewTicketLinkAlsoShowsTheNightsFreeEntryTicket(): void
    {
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];
        $this->wpdb->nextRows[] = [
            'id' => 5, 'task_date' => '2026-08-01', 'role_slug' => 'decorate',
            'capacity' => 2, 'event_post_id' => null, 'event_label' => 'Party',
        ];
        $this->wpdb->nextResults[] = [
            ['id' => 3, 'person_id' => 7, 'redeemed_for' => '2026-08-01', 'event_label' => 'Party'],
            ['id' => 4, 'person_id' => 7, 'redeemed_for' => '2026-08-08', 'event_label' => 'Party'],
        ];
        $this->wpdb->nextRows[] = ['id' => 3, 'person_id' => 7, 'redeemed_for' => '2026-08-01', 'event_label' => 'Party'];
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam'];

        $more = $this->controller()->moreTickets(SignedLink::sign('ticket', 9));

        self::assertCount(1, $more);
        self::assertSame('2026-08-01', $more[0]['date']);
        self::assertStringContainsString(
            http_build_query(['token' => SignedLink::sign('credit_ticket', 3)]),
            (string) $more[0]['poll']
        );
        self::assertSame([], $this->controller()->moreTickets(SignedLink::sign('credit_ticket', 3)));
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

    public function testACrewTicketCheckedInAtTheDoorShowsTheGoldenBand(): void
    {
        $this->queueCrewTicket();
        $this->wpdb->nextResults[] = [['ref_id' => 7, 'checked_in_at' => '2026-08-01 21:05:00']];

        $ticket = $this->controller()->ticketFor(SignedLink::sign('ticket', 9));

        self::assertSame('2026-08-01 21:05:00', $ticket['used']);
        $sql = (string) end($this->wpdb->queries);
        self::assertStringContainsString("event_date = '2026-08-01' AND kind = 'crew' AND ref_id IN (7)", $sql);

        $html = $this->controller()->renderPage($ticket);

        self::assertStringContainsString(
            '<span class="t-in"><span class="t-in-text">You’re in. Welcome, Sam!</span>'
            . '<span class="t-at">Checked in at 21:05</span></span></p>',
            $html
        );
        self::assertStringNotContainsString('is-valid">', $html);
    }

    public function testACrewTicketReadsOnlyTheCrewCheckIn(): void
    {
        // The free-entry ticket is its own door row (Owner 2026-11-07): its
        // check-in does not use up the task's ticket.
        $this->queueCrewTicket();
        $this->wpdb->nextResults[] = []; // not in as crew
        $this->wpdb->nextResults[] = [['ref_id' => 7, 'checked_in_at' => '2026-08-01 22:40:00']];

        $ticket = $this->controller()->ticketFor(SignedLink::sign('ticket', 9));

        self::assertNull($ticket['used']);
        self::assertStringNotContainsString("kind = 'credit'", implode("\n", $this->wpdb->queries));
    }

    public function testACrewTicketNotYetCheckedInStaysValid(): void
    {
        $this->queueCrewTicket();

        $ticket = $this->controller()->ticketFor(SignedLink::sign('ticket', 9));

        self::assertNull($ticket['used']);
        self::assertStringContainsString(
            '<p class="t-band is-valid" id="ec-band" aria-live="polite"><span class="t-state">VALID</span>'
            . '<span class="t-in t-ghost" aria-hidden="true">',
            $this->controller()->renderPage($ticket)
        );
    }

    public function testAGuestTicketCheckedInShowsTheGoldenBand(): void
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
            '<span class="t-in-text">You’re in. Welcome, Cy!</span>'
            . '<span class="t-at">Checked in at 20:15</span></span></p>',
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

        self::assertStringContainsString(
            '<p class="t-band is-valid" id="ec-band" aria-live="polite"><span class="t-state">VALID</span>'
            . '<span class="t-in t-ghost" aria-hidden="true">',
            $html
        );
        self::assertStringNotContainsString(' used<', $html);
    }

    /** Only this row is read: no other row with the same code is looked up. */
    public function testABuyerTicketCheckedInShowsTheGoldenBandWithItsTime(): void
    {
        $this->queueBuyerTicket();
        $this->wpdb->nextResults[] = [['ref_id' => 4, 'checked_in_at' => '2026-08-01 23:10:00']];

        $ticket = $this->controller()->ticketFor(SignedLink::sign('buyer_ticket|AB12CD', 4));

        self::assertSame('2026-08-01 23:10:00', $ticket['used']);
        foreach ($this->wpdb->queries as $sql) {
            self::assertStringNotContainsString('ticket_code', (string) $sql);
        }
        self::assertStringContainsString(
            '<span class="t-in-text">You’re in. Welcome, Bo!</span>'
            . '<span class="t-at">Checked in at 23:10</span></span></p>',
            $this->controller()->renderPage($ticket)
        );
    }

    public function testACancelledTicketStaysDisabledEvenWhenCheckedIn(): void
    {
        $this->queueCrewTicket('cancelled');
        $this->wpdb->nextResults[] = [['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'cancelled']];
        $this->wpdb->nextResults[] = [['ref_id' => 7, 'checked_in_at' => '2026-08-01 21:05:00']];

        $ticket = $this->controller()->ticketFor(SignedLink::sign('ticket', 9));
        $html = $this->controller()->renderPage($ticket);

        self::assertNotNull($ticket['used']);
        self::assertStringContainsString('<p class="t-band is-disabled">DISABLED</p>', $html);
        self::assertStringNotContainsString('is-used">', $html);
    }

    /** Q228: one ticket per person per night, so its code stays valid while they hold another task. */
    public function testACancelledTasksTicketStaysValidWhileAnotherTaskThatNightIsHeld(): void
    {
        $this->queueCrewTicket('cancelled');
        $this->wpdb->nextResults[] = [
            ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'cancelled'],
            ['id' => 10, 'task_id' => 6, 'person_id' => 7, 'status' => 'signed_up'],
        ];

        $ticket = $this->controller()->ticketFor(SignedLink::sign('ticket', 9));

        self::assertTrue($ticket['valid']);
        self::assertStringContainsString("s.task_date = '2026-08-01'", implode("\n", $this->wpdb->queries));
    }

    public function testRenderPageShowsNotFoundForANullTicket(): void
    {
        $html = $this->controller()->renderPage(null);

        self::assertStringContainsString('not valid', $html);
    }

    /** Each kind of ticket carries the short code of its door row. */
    public function testEveryTicketKindCarriesItsDoorRowsShortCode(): void
    {
        $this->queueCrewTicket();
        $crew = $this->controller()->ticketFor(SignedLink::sign('ticket', 9));

        $this->wpdb->nextRows[] = [
            'id' => 3, 'person_id' => 7, 'redeemed_for' => '2026-08-01',
            'event_post_id' => null, 'event_label' => 'Party', 'redeemed_at' => '2026-07-24 20:00:00',
        ];
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam'];
        $credit = $this->controller()->ticketFor(SignedLink::sign('credit_ticket', 3));

        $this->queueBuyerTicket();
        $buyer = $this->controller()->ticketFor(SignedLink::sign('buyer_ticket|AB12CD', 4));

        // Sam's crew and free-entry tickets are two rows at the door (Owner 2026-11-07).
        self::assertSame(ShortCode::forPerson(7, '2026-08-01'), $crew['short']);
        self::assertSame(ShortCode::forCredit(7, '2026-08-01'), $credit['short']);
        self::assertNotSame($crew['short'], $credit['short']);
        self::assertSame(ShortCode::forBuyer(4), $buyer['short']);
        self::assertMatchesRegularExpression('/^[' . ShortCode::ALPHABET . ']{5}$/', $buyer['short']);
    }

    public function testRenderPageWritesTheShortCodeUnderTheQrCode(): void
    {
        Functions\when('get_site_icon_url')->justReturn('');
        $ticket = $this->sampleTicket();
        $ticket['qr'] = 'AB12CD';
        $ticket['code'] = 'AB12CD';
        $ticket['short'] = 'K7M4X';

        $html = $this->controller()->renderPage($ticket);

        self::assertStringContainsString('<p class="t-short">K7M4X</p>', $html);
        self::assertLessThan(strpos($html, 't-code">AB12CD'), strpos($html, 't-short">K7M4X'));
    }

    /**
     * An EventMesh event (post 41) on the test's "today", 2026-07-20, starting
     * at $start, whose doors close 30 minutes after it.
     *
     * @return array<string, mixed>
     */
    private function ticketForEventStartingAt(string $start): array
    {
        Functions\when('get_site_icon_url')->justReturn('');
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => EventTimes::DOORS_ON_OPTION === $name ? '1' : $default
        );
        Functions\when('post_type_exists')->justReturn(true);
        Functions\when('get_post')->justReturn((object) ['post_type' => 'eventmesh_event', 'post_title' => 'Party']);
        Functions\when('get_post_meta')->alias(
            static fn (int $id, string $key): string => '_eventmesh_starts_at' === $key ? '2026-07-20T' . $start . ':00' : ''
        );
        $ticket = $this->sampleTicket();
        $ticket['date'] = '2026-07-20';
        $ticket['event_id'] = 41;
        $ticket['poll'] = 'https://site.test/wp-json/eventcrew/v1/ticket/state?token=abc';

        return $ticket;
    }

    /** Turning gold plays the finger cymbals at 30% volume (Owner 2026-11-07). */
    public function testTurningGoldPlaysTheCymbalsQuietly(): void
    {
        $html = $this->controller()->renderPage($this->ticketForEventStartingAt('19:00'));

        self::assertStringContainsString(
            '"sound":' . json_encode(EVENTCREW_PLUGIN_URL . TicketController::IN_SOUND . '?ver=' . EVENTCREW_VERSION),
            $html
        );
        self::assertStringContainsString('snd.volume=0.3;', $html);
        self::assertStringContainsString('b.innerHTML=c.inHtml;ding();', $html);
        self::assertFileExists(dirname(__DIR__, 2) . '/' . TicketController::IN_SOUND);
    }

    /** The QR stays hidden behind a Show QR code button; the click reveals it. */
    public function testTheQrCodeIsHiddenUntilTheShowButtonIsClicked(): void
    {
        $html = $this->controller()->renderPage($this->ticketForEventStartingAt('19:00'));

        $button = strpos($html, 'id="ec-qr-show"');
        $qr = strpos($html, 'id="ec-qr" hidden');
        self::assertNotFalse($button);
        self::assertNotFalse($qr);
        self::assertLessThan($qr, $button);
        self::assertStringContainsString('>Show QR code</button>', $html);
        self::assertStringContainsString('h.hidden=false', $html);
    }

    /**
     * Showing the QR code moves nothing on the page (Owner 2026-11-07): the
     * button covers the code's own square, which is laid out while hidden.
     */
    public function testShowingTheQrCodeDoesNotMoveThePage(): void
    {
        $html = $this->controller()->renderPage($this->ticketForEventStartingAt('19:00'));

        self::assertStringContainsString(
            '<div class="t-qr-wrap" id="ec-qr-wrap"><button type="button" class="t-qr-show" id="ec-qr-show">',
            $html
        );
        self::assertStringContainsString(
            'Show QR code</button><div class="t-qr" id="ec-qr" hidden></div></div>',
            $html
        );
        self::assertStringContainsString('.t-qr{position:relative;aspect-ratio:1/1}', $html);
        self::assertStringContainsString('.t-qr[hidden]{display:block;visibility:hidden}', $html);
        self::assertStringContainsString('.t-qr-show{position:absolute;inset:0;', $html);
        self::assertStringContainsString('min-height:48px', $html);
        self::assertStringContainsString('.t-qr-show:focus-visible{outline:3px solid', $html);
    }

    /** Before the doors close the band is VALID and the page knows when they close. */
    public function testBeforeTheDoorsCloseTheTicketIsValidAndCountsDownToThem(): void
    {
        $html = $this->controller()->renderPage($this->ticketForEventStartingAt('19:00'));

        self::assertStringContainsString(
            '<p class="t-band is-valid" id="ec-band" aria-live="polite"><span class="t-state">VALID</span>'
            . '<span class="t-in t-ghost" aria-hidden="true">',
            $html
        );
        // Doors close at 19:30; the site's clock says 12:00.
        self::assertStringContainsString('"closesIn":27000', $html);
        self::assertStringContainsString(
            '"poll":' . json_encode('https://site.test/wp-json/eventcrew/v1/ticket/state?token=abc'),
            $html
        );
        self::assertStringContainsString('"expired":"Sorry, this ticket has expired"', $html);
    }

    /** Once the doors have closed a ticket not checked in reads as expired. */
    public function testAfterTheDoorsCloseATicketNotCheckedInHasExpired(): void
    {
        $html = $this->controller()->renderPage($this->ticketForEventStartingAt('10:00'));

        self::assertStringContainsString(
            '<p class="t-band is-expired" id="ec-band" aria-live="polite"><span class="t-state">Sorry, this ticket has expired</span>',
            $html
        );
        self::assertStringNotContainsString('>VALID<', $html);
        // Doors closed at 10:30, 90 minutes ago. The page still asks: the
        // door may count it in, and it then turns golden.
        self::assertStringContainsString('"closesIn":-5400', $html);
    }

    /** A ticket checked in before the doors closed stays golden after. */
    public function testACheckedInTicketStaysGoldenAfterTheDoorsClose(): void
    {
        $ticket = $this->ticketForEventStartingAt('10:00');
        $ticket['used'] = '2026-07-20 10:20:00';

        $html = $this->controller()->renderPage($ticket);

        self::assertStringContainsString('<p class="t-band is-in" id="ec-band" aria-live="polite">', $html);
        self::assertStringContainsString('Checked in at 10:20', $html);
        self::assertStringNotContainsString('is-expired" id', $html);
        // Nothing left to wait for: no live script.
        self::assertStringNotContainsString('closesIn', $html);
    }

    /**
     * The gold band glows and loops without snapping: the gold drifts by one
     * whole 200% tile, the glows alternate, the rim's light turns a full
     * circle, and less motion stops it all, the card's rim and glow too.
     */
    public function testTheGoldBandLoopsSeamlesslyAndStopsForLessMotion(): void
    {
        $ticket = $this->ticketForEventStartingAt('10:00');
        $ticket['used'] = '2026-07-20 10:20:00';

        $html = $this->controller()->renderPage($ticket);

        self::assertStringContainsString('background-size:100% 100%,200% 100%', $html);
        self::assertStringContainsString(
            '@keyframes t-gold{from{background-position:0 0,0 0}to{background-position:0 0,200% 0}}',
            $html
        );
        self::assertStringContainsString('@keyframes t-glow{', $html);
        self::assertStringContainsString('t-glow 3s ease-in-out infinite alternate', $html);
        self::assertStringContainsString('t-halo 3.5s ease-in-out infinite alternate', $html);
        self::assertStringContainsString('@keyframes t-rim{from{--t-rim:0deg}to{--t-rim:360deg}}', $html);
        self::assertStringContainsString('@keyframes t-sheen{', $html);
        self::assertStringContainsString('@keyframes t-twinkle{0%,100%{', $html);
        self::assertSame(1, preg_match('/@media \(prefers-reduced-motion:reduce\)\{(.*?\})\}/', $html, $m));
        [$off, $hidden] = explode('{animation:none}', $m[1], 2);
        foreach (
            [
                '.t-band.is-in', '.t-band.is-new', '.t-stars i', '.t:has(.t-band.is-in)::before',
                '.t:has(.t-band.is-in)::after', '.t:has(.t-band.is-new)::before', '.t:has(.t-band.is-new)::after',
            ] as $selector
        ) {
            self::assertContains($selector, explode(',', $off), $selector . ' still moves');
        }
        self::assertStringStartsWith('.t-band.is-in::before,.t-band.is-new::after,.t-fx{display:none}', $hidden);
        // The static gold and its glows stay, outside any animation.
        self::assertStringContainsString('inset 0 0 1em rgba(255,250,220,.5);animation:t-gold', $html);
        self::assertStringContainsString('box-shadow:inset 0 0 1.2em rgba(235,175,30,.35);background:', $html);
    }

    /**
     * Nothing of the gold moment reaches outside the card (Owner 2026-11-07):
     * every glow is inset, nothing full-width grows above its own size, and
     * the card, which clips, holds the rim, glow, sweep and sparkles.
     */
    public function testTheGoldBandStaysInsideItsFrame(): void
    {
        $ticket = $this->ticketForEventStartingAt('10:00');
        $ticket['used'] = '2026-07-20 10:20:00';

        $html = $this->controller()->renderPage($ticket);

        self::assertStringContainsString('.t{position:relative;isolation:isolate;', $html);
        self::assertMatchesRegularExpression('/\.t\{[^}]*border-radius:16px;overflow:hidden;/', $html);
        self::assertStringContainsString('.t-band.is-in{position:relative;overflow:hidden;', $html);
        // The rim, the glow and the sparkles lie on the card, inside its edge.
        self::assertStringContainsString('.t:has(.t-band.is-in)::before{content:"";position:absolute;inset:0;', $html);
        self::assertStringContainsString('.t:has(.t-band.is-in)::after{content:"";position:absolute;inset:0;', $html);
        self::assertStringContainsString('.t-fx{position:absolute;inset:0;', $html);
        // The gold rules and keyframes, up to the QR code's.
        self::assertSame(1, preg_match('/\.t-band\.is-in\{.*(?=\.t-qr-wrap\{)/s', $html, $m));
        $css = $m[0];
        preg_match_all('/box-shadow:([^;}]*)/', $css, $shadows);
        self::assertGreaterThan(5, count($shadows[1]));
        foreach ($shadows[1] as $shadow) {
            foreach (preg_split('/,(?![^(]*\))/', $shadow) ?: [] as $layer) {
                self::assertStringStartsWith('inset', trim($layer), 'outer glow: ' . $layer);
            }
        }
        preg_match_all('/scale[XY]?\(([\d.]+)\)/', $css, $scales);
        self::assertNotEmpty($scales[1]);
        foreach ($scales[1] as $scale) {
            self::assertLessThanOrEqual(1.0, (float) $scale);
        }
        self::assertStringNotContainsString('t-pop', $css);
    }

    /**
     * Turning gold live is a moment (Owner 2026-11-07: it must feel magical):
     * the band opens, the card flares and a golden light sweeps across it,
     * the rim's light races round, and sparkles fly from the band across
     * the card; the out tick clears them.
     */
    public function testTurningGoldLiveSweepsTheCardAndThrowsSparkles(): void
    {
        $html = $this->controller()->renderPage($this->ticketForEventStartingAt('19:00'));

        self::assertStringContainsString('t-open .7s', $html);
        self::assertStringContainsString(
            '.t:has(.t-band.is-new)::after{animation:t-flare 2.2s ease-out both,t-sweep 1.8s',
            $html
        );
        self::assertStringContainsString('@keyframes t-sweep{from{background-position:130% 0}to{background-position:-30% 0}}', $html);
        self::assertStringContainsString('.t:has(.t-band.is-new)::before{animation:t-rim 1.5s linear 2 both,', $html);
        self::assertStringContainsString('b.innerHTML=c.inHtml;ding();burst();', $html);
        self::assertStringContainsString('f.className="t-fx";f.setAttribute("aria-hidden","true");', $html);
        self::assertStringContainsString('for(var i=0;i<48;i++)', $html);
        // The sparkles' own scale (--s) stays at most 1.
        self::assertStringContainsString('--s:"+(.5+Math.random()*.5).toFixed(2)', $html);
        self::assertStringContainsString('animation:t-fly var(--t,6.5s) linear var(--d,0s) both', $html);
        // They stay about twice as long (Owner 2026-11-07): 5.5 to 8.2 s each,
        // cleared after 9.5 s, once the last has faded.
        self::assertStringContainsString('--t:"+(5.5+Math.random()*2.7).toFixed(2)+"s"', $html);
        self::assertStringContainsString('f.parentNode.removeChild(f);}},9500);', $html);
    }

    /**
     * The gold band greets the holder by first name (Owner 2026-11-07), escaped,
     * on the page opened already in and in the band the live script draws.
     */
    public function testTheGoldBandWelcomesTheFirstNameEscaped(): void
    {
        Functions\when('esc_html')->alias(static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES));
        $ticket = $this->ticketForEventStartingAt('19:00');
        $ticket['name'] = '<b>Al&</b> Smith';

        $html = $this->controller()->renderPage($ticket);

        self::assertStringContainsString('You’re in. Welcome, &lt;b&gt;Al&amp;&lt;/b&gt;!', $html);
        self::assertStringNotContainsString('<b>Al', $html);
        self::assertStringNotContainsString('Smith!', $html);
        // The live script's copy is JSON with < > & hexed.
        self::assertStringContainsString('Welcome, \u0026lt;b\u0026gt;Al\u0026amp;\u0026lt;\/b\u0026gt;!', $html);

        $ticket['used'] = '2026-07-20 18:50:00';
        self::assertStringContainsString(
            '<span class="t-in-text">You’re in. Welcome, &lt;b&gt;Al&amp;&lt;/b&gt;!</span>',
            $this->controller()->renderPage($ticket)
        );

        $ticket['name'] = '';
        self::assertStringContainsString(
            '<span class="t-in-text">You’re in, welcome!</span>',
            $this->controller()->renderPage($ticket)
        );
    }

    /**
     * Turning gold moves nothing on the page (Owner 2026-11-07: it moved
     * down): the open band holds the gold band's words hidden in the same
     * grid cell, with the gold band's padding, and the live script keeps
     * them whenever it rewrites the band (expired).
     */
    public function testTheOpenBandReservesTheGoldBandsSpace(): void
    {
        $html = $this->controller()->renderPage($this->ticketForEventStartingAt('19:00'));

        self::assertStringContainsString(
            '<span class="t-state">VALID</span><span class="t-in t-ghost" aria-hidden="true">'
            . '<span class="t-in-text">You’re in. Welcome, Sam!</span><span class="t-at">Checked in at 00:00</span>'
            . '</span></p>',
            $html
        );
        self::assertStringContainsString(
            '[id^="ec-band"]{display:grid;align-items:center;padding:.7em .45em}[id^="ec-band"]>*{grid-area:1/1}',
            $html
        );
        self::assertStringContainsString('.t-in{display:block;letter-spacing:.04em}.t-ghost{visibility:hidden}', $html);
        // The gold band itself has the same padding.
        self::assertStringContainsString(
            '.t-band.is-in{position:relative;overflow:hidden;isolation:isolate;padding:.7em .45em;',
            $html
        );
        self::assertStringContainsString(
            '"ghost":"\u003Cspan class=\"t-in t-ghost\" aria-hidden=\"true\"\u003E',
            $html
        );
        self::assertStringContainsString('function show(k,s){b.className=k;b.innerHTML=c.ghost;', $html);
        self::assertStringContainsString('show("t-band is-expired",c.expired);', $html);
        self::assertStringNotContainsString('b.textContent=', $html);
    }

    /** With no doors time the ticket never expires on the page. */
    public function testWithoutADoorsTimeTheTicketDoesNotExpire(): void
    {
        Functions\when('get_site_icon_url')->justReturn('');

        $html = $this->controller()->renderPage($this->sampleTicket());

        self::assertStringContainsString('"closesIn":null', $html);
    }

    /** Sam's free-entry ticket (redemption 3, person 7) for $date. */
    private function stateRequest(string $date, string $token = ''): WP_REST_Request
    {
        $this->wpdb->nextRows[] = [
            'id' => 3, 'person_id' => 7, 'redeemed_for' => $date,
            'event_post_id' => null, 'event_label' => 'Party', 'redeemed_at' => '2026-07-14 20:00:00',
        ];
        $request = new WP_REST_Request();
        $request->params['token'] = '' === $token ? SignedLink::sign('credit_ticket', 3) : $token;

        return $request;
    }

    /** A controller whose live wait counts its seconds instead of sleeping. */
    private function waitingController(int &$slept): TicketController
    {
        return new TicketController(
            new AssignmentRepository(),
            new TaskRepository(),
            new PersonRepository(),
            new RedemptionRepository(),
            sleep: static function (int $seconds) use (&$slept): void {
                $slept += $seconds;
            }
        );
    }

    public function testTheStateAnswersAtOnceForATicketAlreadyIn(): void
    {
        $slept = 0;
        $this->wpdb->nextVars[] = '2026-07-20 11:58:00';

        $response = $this->waitingController($slept)->state($this->stateRequest('2026-07-20'));

        self::assertSame(200, $response->status);
        self::assertSame(['in' => true, 'at' => '11:58', 'next' => null], $response->data);
        self::assertSame(0, $slept);
        self::assertSame('no-store, max-age=0', $response->get_headers()['Cache-Control']);
        $sql = (string) end($this->wpdb->queries);
        self::assertStringContainsString("event_date = '2026-07-20' AND kind IN ('credit') AND ref_id = 7", $sql);
    }

    /** The wait answers the second the door marks the ticket in. */
    public function testTheStateAnswersTheMomentTheDoorMarksTheTicketIn(): void
    {
        $slept = 0;
        array_push($this->wpdb->nextVars, null, null, null, '2026-07-20 12:00:03');

        $response = $this->waitingController($slept)->state($this->stateRequest('2026-07-20'));

        self::assertSame(['in' => true, 'at' => '12:00', 'next' => null], $response->data);
        self::assertSame(3, $slept);
    }

    /** Not in during the night: held WAIT_SECONDS, then "ask again now". */
    public function testTheStateWaitsItsSecondsAndSaysAskAgainWhileTheNightIsOn(): void
    {
        $slept = 0;

        $response = $this->waitingController($slept)->state($this->stateRequest('2026-07-20'));

        self::assertSame(['in' => false, 'at' => '', 'next' => 0], $response->data);
        self::assertSame(TicketController::WAIT_SECONDS, $slept);
    }

    /** Last night's ticket until 06:00 is still on: the door may be late. */
    public function testTheNightRunsPastMidnight(): void
    {
        $slept = 0;

        $response = $this->waitingController($slept)->state($this->stateRequest('2026-07-19'));

        // The test's clock says 2026-07-20 12:00, past 06:00: over.
        self::assertSame(['in' => false, 'at' => '', 'next' => null], $response->data);
        self::assertSame(0, $slept);
    }

    /** Before its night the ticket is not held and asks again much later. */
    public function testBeforeItsNightTheStateAnswersAtOnceAndAsksLater(): void
    {
        $slept = 0;

        $response = $this->waitingController($slept)->state($this->stateRequest('2026-07-25'));

        self::assertSame(['in' => false, 'at' => '', 'next' => TicketController::BEFORE_NIGHT_RETRY], $response->data);
        self::assertSame(0, $slept);
    }

    /** A bad signature answers 404 and reads nothing. */
    public function testTheStateRefusesABadSignature(): void
    {
        $slept = 0;
        $token = SignedLink::sign('credit_ticket', 3);
        $at = strpos($token, '.') + 2;
        $bad = substr_replace($token, 'A' === $token[$at] ? 'B' : 'A', $at, 1);

        $response = $this->waitingController($slept)->state($this->stateRequest('2026-07-20', $bad));

        self::assertSame(404, $response->status);
        self::assertSame(['code' => 'not_found'], $response->data);
        // A buyer link is checked against its row's code, so that row may be
        // read; the check-ins never are.
        foreach ($this->wpdb->queries as $sql) {
            self::assertStringNotContainsString('checkins', (string) $sql);
        }
        self::assertSame(0, $slept);

        // Nor does another kind of signed link open it.
        $response = $this->waitingController($slept)->state($this->stateRequest('2026-07-20', SignedLink::sign('manage', 3)));
        self::assertSame(404, $response->status);
    }
}
