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
            '<p class="t-band is-valid" id="ec-band" aria-live="polite">VALID</p>',
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

    public function testACrewTicketCheckedInAtTheDoorShowsTheGoldenBand(): void
    {
        $this->queueCrewTicket();
        $this->wpdb->nextResults[] = [['ref_id' => 7, 'checked_in_at' => '2026-08-01 21:05:00']];

        $ticket = $this->controller()->ticketFor(SignedLink::sign('ticket', 9));

        self::assertSame('2026-08-01 21:05:00', $ticket['used']);
        $sql = (string) end($this->wpdb->queries);
        self::assertStringContainsString("event_date = '2026-08-01' AND kind = 'crew' AND ref_id IN (7)", $sql);

        $html = $this->controller()->renderPage($ticket);

        self::assertStringContainsString('<span class="t-in-text">You’re in, welcome!</span><span class="t-at">Checked in at 21:05</span></p>', $html);
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
            '<p class="t-band is-valid" id="ec-band" aria-live="polite">VALID</p>',
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
            '<span class="t-in-text">You’re in, welcome!</span><span class="t-at">Checked in at 20:15</span></p>',
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

        self::assertStringContainsString('<p class="t-band is-valid" id="ec-band" aria-live="polite">VALID</p>', $html);
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
            '<span class="t-in-text">You’re in, welcome!</span><span class="t-at">Checked in at 23:10</span></p>',
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

    /** Before the doors close the band is VALID and the page knows when they close. */
    public function testBeforeTheDoorsCloseTheTicketIsValidAndCountsDownToThem(): void
    {
        $html = $this->controller()->renderPage($this->ticketForEventStartingAt('19:00'));

        self::assertStringContainsString('<p class="t-band is-valid" id="ec-band" aria-live="polite">VALID</p>', $html);
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
            '<p class="t-band is-expired" id="ec-band" aria-live="polite">Sorry, this ticket has expired</p>',
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
