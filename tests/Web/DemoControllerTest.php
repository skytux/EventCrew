<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\DemoTicket;
use EventCrew\Support\ShortCode;
use EventCrew\Support\SignedLink;
use EventCrew\Telegram\TicketController;
use EventCrew\Tests\TestCase;
use EventCrew\Web\DemoController;
use WP_REST_Request;

final class DemoControllerTest extends TestCase
{
    /** @var array<string, mixed> The options table of the test. */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
        Functions\when('rest_url')->alias(static fn (string $path = ''): string => 'https://site.test/wp-json/' . $path);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_json_encode')->alias(static fn (mixed $data, int $flags = 0): string|false => json_encode($data, $flags));
        Functions\when('get_the_title')->justReturn('');
    }

    /** A controller whose live wait counts its seconds, and runs $tick each second. */
    private function controller(int &$slept = 0, ?\Closure $tick = null): DemoController
    {
        $tickets = new TicketController(
            new AssignmentRepository(),
            new TaskRepository(),
            new PersonRepository(),
            new RedemptionRepository()
        );

        return new DemoController($tickets, static function (int $seconds) use (&$slept, $tick): void {
            $slept += $seconds;

            if (null !== $tick) {
                $tick($slept);
            }
        });
    }

    private static function tokenOf(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) $query['token'];
    }

    /** @param array<string, string> $params */
    private static function request(array $params): WP_REST_Request
    {
        $request = new WP_REST_Request();
        $request->params = $params;

        return $request;
    }

    public function testLinksStartADemoAndKeepItForADay(): void
    {
        $links = DemoTicket::links();

        self::assertStringStartsWith('https://site.test/wp-json/eventcrew/v1/demo/ticket?token=', $links['ticket']);
        self::assertStringStartsWith('https://site.test/wp-json/eventcrew/v1/demo/desk?token=', $links['desk']);

        $demo = DemoTicket::current();
        self::assertNotNull($demo);
        self::assertSame('', $demo['in']);
        self::assertSame((int) strtotime('2026-07-20 12:00:00') + DemoTicket::TTL, $demo['expires']);

        // Asked again, the same demo and the same links.
        self::assertSame($links, DemoTicket::links());
    }

    public function testAnEndedDemoIsGoneAndItsLinksNoLongerOpen(): void
    {
        $links = DemoTicket::links();
        $this->options[DemoTicket::OPTION]['expires'] = (int) strtotime('2026-07-20 11:59:00');

        self::assertNull(DemoTicket::current());
        self::assertNull(DemoTicket::forToken(DemoTicket::TICKET_PURPOSE, self::tokenOf($links['ticket'])));
        self::assertFalse(DemoTicket::mark(self::tokenOf($links['desk']), true));

        // The next links start a new demo.
        self::assertNotSame($links, DemoTicket::links());
    }

    public function testTheDeskTicksTheDemoInAndOut(): void
    {
        $desk = self::tokenOf(DemoTicket::links()['desk']);

        self::assertTrue(DemoTicket::mark($desk, true));
        self::assertSame('2026-07-20 12:00:00', DemoTicket::current()['in'] ?? null);

        self::assertTrue(DemoTicket::mark($desk, false));
        self::assertSame('', DemoTicket::current()['in'] ?? null);
    }

    public function testTheTicketLinkCannotTickItselfIn(): void
    {
        $links = DemoTicket::links();
        $ticket = self::tokenOf($links['ticket']);

        self::assertFalse(DemoTicket::mark($ticket, true));
        self::assertNull(DemoTicket::forToken(DemoTicket::TICKET_PURPOSE, self::tokenOf($links['desk'])));
        // Nor does another signed link of the same id, or a forged one.
        $id = (int) $this->options[DemoTicket::OPTION]['id'];
        self::assertFalse(DemoTicket::mark(SignedLink::sign('ticket', $id), true));
        self::assertFalse(DemoTicket::mark('nonsense', true));
        self::assertSame('', DemoTicket::current()['in'] ?? null);
    }

    /** The demo never reaches the database tables: no door list, count, export or email sees it. */
    public function testTheDemoNeverTouchesTheDatabase(): void
    {
        $links = DemoTicket::links();
        $desk = self::tokenOf($links['desk']);
        $controller = $this->controller();

        DemoTicket::mark($desk, true);
        $controller->state(self::request(['token' => $desk]));
        $controller->mark(self::request(['token' => $desk, 'in' => '0']));
        $controller->deskHtml($desk);

        self::assertSame([], $this->wpdb->queries);
        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->wpdb->updates);
        self::assertSame([DemoTicket::OPTION], array_keys($this->options));
    }

    public function testTheTicketPageIsTheRealTicketWithTheDemoHolder(): void
    {
        $ticket = self::tokenOf(DemoTicket::links()['ticket']);
        $id = (int) $this->options[DemoTicket::OPTION]['id'];

        $html = $this->controller()->ticketHtml($ticket);

        self::assertStringContainsString('Demo Guest', $html);
        self::assertStringContainsString('Demo event', $html);
        self::assertStringContainsString('<p class="t-short">' . ShortCode::forDemo($id) . '</p>', $html);
        self::assertStringContainsString('t-band is-valid', $html);
        self::assertStringContainsString('id="ec-qr"', $html);
        // Waits on the demo's state, and keeps waiting after it is in.
        self::assertStringContainsString('demo\/state?token=', $html);
        self::assertStringContainsString('"again":true', $html);
    }

    public function testATicketTickedInOpensGoldAndStillWaits(): void
    {
        $links = DemoTicket::links();
        DemoTicket::mark(self::tokenOf($links['desk']), true);

        $html = $this->controller()->ticketHtml(self::tokenOf($links['ticket']));

        self::assertStringContainsString('t-band is-in', $html);
        self::assertStringContainsString('Checked in at 12:00', $html);
        self::assertStringContainsString('"again":true', $html);
    }

    public function testABadTicketLinkShowsNotFound(): void
    {
        DemoTicket::links();

        self::assertStringContainsString('Ticket not found', $this->controller()->ticketHtml('nonsense'));
        self::assertStringContainsString('This demo has ended.', $this->controller()->deskHtml('nonsense'));
    }

    public function testARealTicketDoesNotKeepWaitingAfterItIsIn(): void
    {
        $tickets = new TicketController(
            new AssignmentRepository(),
            new TaskRepository(),
            new PersonRepository(),
            new RedemptionRepository()
        );
        $html = $tickets->renderPage([
            'valid' => true, 'name' => 'Sam', 'event' => 'Party', 'date' => '2026-07-20', 'role' => 'Ticket',
            'time' => '', 'issued' => '', 'language' => null, 'used' => '2026-07-20 11:00:00',
        ]);

        self::assertStringNotContainsString('"again"', $html);
    }

    /** The wait answers the second the desk ticks the demo in. */
    public function testTheStateAnswersTheMomentTheDeskTicksIn(): void
    {
        $links = DemoTicket::links();
        $desk = self::tokenOf($links['desk']);
        $slept = 0;
        $controller = $this->controller($slept, static function (int $second) use ($desk): void {
            if (3 === $second) {
                DemoTicket::mark($desk, true);
            }
        });

        $response = $controller->state(self::request(['token' => self::tokenOf($links['ticket']), 'in' => '0']));

        self::assertSame(200, $response->status);
        self::assertSame(['in' => true, 'at' => '12:00', 'next' => 0], $response->data);
        self::assertSame(3, $slept);
        self::assertSame('no-store, max-age=0', $response->get_headers()['Cache-Control']);
    }

    /** And the second it ticks it out again. */
    public function testTheStateAnswersTheMomentTheDeskTicksOut(): void
    {
        $links = DemoTicket::links();
        $desk = self::tokenOf($links['desk']);
        DemoTicket::mark($desk, true);
        $slept = 0;
        $controller = $this->controller($slept, static function (int $second) use ($desk): void {
            if (2 === $second) {
                DemoTicket::mark($desk, false);
            }
        });

        $response = $controller->state(self::request(['token' => self::tokenOf($links['ticket']), 'in' => '1']));

        self::assertSame(['in' => false, 'at' => '', 'next' => 0], $response->data);
        self::assertSame(2, $slept);
    }

    public function testNoChangeWaitsItsSecondsAndSaysAskAgain(): void
    {
        $links = DemoTicket::links();
        $slept = 0;

        $response = $this->controller($slept)->state(self::request(['token' => self::tokenOf($links['ticket']), 'in' => '0']));

        self::assertSame(['in' => false, 'at' => '', 'next' => 0], $response->data);
        self::assertSame(TicketController::WAIT_SECONDS, $slept);
    }

    public function testTheStateRefusesABadLink(): void
    {
        DemoTicket::links();
        $slept = 0;

        $response = $this->controller($slept)->state(self::request(['token' => 'nonsense', 'in' => '0']));

        self::assertSame(404, $response->status);
        self::assertSame(0, $slept);
    }

    public function testTheDeskMarkAnswersTheNewState(): void
    {
        $desk = self::tokenOf(DemoTicket::links()['desk']);

        $response = $this->controller()->mark(self::request(['token' => $desk, 'in' => '1']));

        self::assertSame(['ok' => true, 'in' => true, 'at' => '12:00', 'next' => 0], $response->data);

        $response = $this->controller()->mark(self::request(['token' => 'nonsense', 'in' => '1']));
        self::assertSame(404, $response->status);
    }

    public function testTheDeskShowsTheOneDemoRow(): void
    {
        $desk = self::tokenOf(DemoTicket::links()['desk']);
        $id = (int) $this->options[DemoTicket::OPTION]['id'];

        $html = $this->controller()->deskHtml($desk);

        self::assertStringContainsString('Demo sales desk', $html);
        self::assertStringContainsString('Demo Guest<span class="ec-short">' . ShortCode::forDemo($id) . '</span>', $html);
        self::assertStringContainsString('aria-pressed="false"', $html);
        self::assertStringContainsString('Not in yet', $html);
        self::assertSame(1, substr_count($html, '<li '));
    }
}
