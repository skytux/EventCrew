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
        // The card's own background stays inside the card.
        self::assertStringContainsString('<html lang="en" style="background:#fff">', $html);
    }

    public function testRenderPageHasNoBackgroundWhenNoneIsSet(): void
    {
        Functions\when('get_site_icon_url')->justReturn('https://site.test/icon-512.png');

        $html = $this->controller()->renderPage($this->sampleTicket());

        self::assertStringNotContainsString('url(', $html);
        self::assertStringNotContainsString('linear-gradient', $html);
        self::assertStringContainsString('<html lang="en"><head>', $html);
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

    public function testRenderPageShowsNotFoundForANullTicket(): void
    {
        $html = $this->controller()->renderPage(null);

        self::assertStringContainsString('not valid', $html);
    }
}
