<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CheckinRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\DoorDesk;
use EventCrew\Support\DoorHandoff;
use EventCrew\Support\DoorNewMember;
use EventCrew\Support\DoorSession;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Telegram\TicketRedemptionService;
use EventCrew\Tests\Support\SalesDeskTestCase;
use EventCrew\Web\DoorController;
use RuntimeException;

/**
 * The sales desk's two newer POST actions - the Holvi buyer missing from the
 * list and the count without a name - through the real dispatch: a request
 * without the page's token is refused before anything is written.
 *
 * dispatch() ends every answer with exit, so status_header() is stubbed to
 * throw the HTTP status instead; that stops the request at the point the
 * answer would be sent.
 */
final class DoorControllerTest extends SalesDeskTestCase
{
    private string $cookie = '';

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('nocache_headers')->justReturn(null);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('sanitize_email')->returnArg(1);
        Functions\when('is_email')->alias(
            static fn (string $email): string|false => false === filter_var($email, FILTER_VALIDATE_EMAIL) ? false : $email
        );
        Functions\when('status_header')->alias(
            static function (int $code): void {
                throw new RuntimeException('answered', $code);
            }
        );

        $this->cookie = DoorSession::mint(1, self::NIGHT);
        $_COOKIE[DoorSession::COOKIE] = $this->cookie;
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[DoorSession::COOKIE], $_SERVER['REQUEST_METHOD']);
        $_POST = [];
        $_REQUEST = [];

        parent::tearDown();
    }

    private function controller(): DoorController
    {
        $mailer = new Mailer(new Logger(), new EmailTemplate(new Logger()));

        $desk = new DoorDesk(
            new TaskRepository(),
            new AssignmentRepository(),
            new PersonRepository(),
            new RedemptionRepository(),
            new GuestRepository(),
            new TicketBuyerRepository(),
            new CheckinRepository(),
            new AuthTokenRepository(),
            $this->standing(),
            new TicketRedemptionService(
                new PersonRepository(),
                new TaskRepository(),
                new RedemptionRepository(),
                $this->standing(),
                new FreeEntryGate(),
                $this->client(),
                $mailer
            ),
            $this->salesDeskGate(),
            new CreditGrantRepository()
        );

        return new DoorController(
            $desk,
            new DoorNewMember(new PersonRepository(), new AuthTokenRepository(), $mailer, $this->client()),
            new DoorHandoff(
                $desk,
                new TaskRepository(),
                new AssignmentRepository(),
                new PersonRepository(),
                new AuthTokenRepository(),
                $this->salesDeskGate()
            )
        );
    }

    /**
     * Posts $fields to $action as the organizer holding the door cookie and
     * answers the HTTP status the controller sent.
     *
     * @param array<string, string> $fields
     */
    private function post(string $action, array $fields): int
    {
        $_POST = $fields;
        $_REQUEST = ['action' => $action] + $fields;
        // desk->session(): the organizer behind the cookie.
        $this->wpdb->nextRows[] = $this->personRow(1, true);

        try {
            $this->controller()->dispatch();
        } catch (RuntimeException $answer) {
            return $answer->getCode();
        }

        self::fail('The controller sent no answer.');
    }

    private function countOption(): string
    {
        return DoorDesk::ANONYMOUS_OPTION_PREFIX . self::NIGHT;
    }

    public function testCountingOneInWithTheTokenRaisesTheCount(): void
    {
        $status = $this->post(DoorController::COUNT_ACTION, ['csrf' => DoorSession::csrfToken($this->cookie)]);

        self::assertSame(200, $status);
        self::assertSame(1, $this->countChange());
    }

    /**
     * How much the last count statement adds inside the database.
     */
    private function countChange(): ?int
    {
        global $wpdb;

        foreach (array_reverse($wpdb->queries) as $sql) {
            if (
                str_contains($sql, "'" . $this->countOption() . "'")
                && 1 === preg_match('/GREATEST\(CAST\(option_value AS SIGNED\) \+ (-?\d+), 0\)/', $sql, $m)
            ) {
                return (int) $m[1];
            }
        }

        return null;
    }

    public function testTakingOneOffWithTheTokenLowersTheCount(): void
    {
        $this->options[$this->countOption()] = 2;

        $status = $this->post(
            DoorController::COUNT_ACTION,
            ['csrf' => DoorSession::csrfToken($this->cookie), 'step' => '-1']
        );

        self::assertSame(200, $status);
        self::assertSame(-1, $this->countChange());
    }

    public function testCountingWithABadTokenIsRefused(): void
    {
        $status = $this->post(DoorController::COUNT_ACTION, ['csrf' => 'not-the-token']);

        self::assertSame(403, $status);
        self::assertNull($this->countChange());
    }

    public function testAddingAHolviBuyerWithABadTokenIsRefused(): void
    {
        $status = $this->post(DoorController::HOLVI_ACTION, ['csrf' => 'not-the-token', 'name' => 'Ada']);

        self::assertSame(403, $status);
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testAddingAHolviBuyerWithTheTokenStoresThem(): void
    {
        $status = $this->post(
            DoorController::HOLVI_ACTION,
            ['csrf' => DoorSession::csrfToken($this->cookie), 'name' => 'Ada', 'email' => 'ada@example.com']
        );

        self::assertSame(200, $status);
        self::assertSame('desk', $this->wpdb->inserts[0]['data']['source']);
        self::assertSame('ada@example.com', $this->wpdb->inserts[0]['data']['email']);
    }

    public function testAHolviBuyerWithAnInvalidEmailIsNotStored(): void
    {
        $status = $this->post(
            DoorController::HOLVI_ACTION,
            ['csrf' => DoorSession::csrfToken($this->cookie), 'name' => 'Ada', 'email' => 'ada@']
        );

        self::assertSame(200, $status);
        self::assertSame([], $this->wpdb->inserts);
    }
}
