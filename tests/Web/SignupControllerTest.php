<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\SignupService;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\Turnstile;
use EventCrew\Support\WebSession;
use EventCrew\Telegram\DohResolver;
use EventCrew\Telegram\TelegramClient;
use EventCrew\Telegram\TicketRedemptionService;
use EventCrew\Tests\TestCase;
use EventCrew\Web\SignupController;

final class SignupControllerTest extends TestCase
{
    /** @var array<int, string> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mails = [];
        $_COOKIE = [];
        $_REQUEST = [];

        Functions\when('get_option')->justReturn(false);
        Functions\when('get_the_title')->justReturn('');
        Functions\when('sanitize_email')->alias(static fn (string $e): string => trim($e));
        Functions\when('is_email')->alias(
            static fn (string $e): bool => 1 === preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $e)
        );
        Functions\when('admin_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-admin/' . $p);
        Functions\when('home_url')->alias(static fn (string $p = '/'): string => 'https://site.test' . $p);
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('wp_validate_redirect')->alias(static fn (string $u, string $d): string => '' === $u ? $d : $u);
        Functions\when('add_query_arg')->alias(
            static function ($args, $url = ''): string {
                if (is_array($args)) {
                    return $url . '?' . http_build_query($args);
                }

                return $url . '?' . $args . '=x';
            }
        );
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = $body;

            return true;
        });
    }

    private function controller(): SignupController
    {
        $people = new PersonRepository();
        $assignments = new AssignmentRepository();

        return new SignupController(
            $people,
            new AuthTokenRepository(),
            new TaskRepository(),
            $assignments,
            new SignupService($assignments, new StandingCalculator($assignments, new RedemptionRepository(), new CreditGrantRepository())),
            new StandingCalculator($assignments, new RedemptionRepository(), new CreditGrantRepository()),
            new Mailer(new Logger()),
            new ClaimNotifier(
                new TaskRepository(),
                $assignments,
                new Mailer(new Logger()),
                new TelegramClient(new Logger(), new DohResolver(new Logger())),
                new StandingCalculator($assignments, new RedemptionRepository(), new CreditGrantRepository())
            ),
            new Turnstile(new Logger()),
            new TicketRedemptionService(
                $people,
                new TaskRepository(),
                new RedemptionRepository(),
                new StandingCalculator($assignments, new RedemptionRepository(), new CreditGrantRepository()),
                new FreeEntryGate(),
                new TelegramClient(new Logger(), new DohResolver(new Logger()))
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(): array
    {
        return [
            'id' => 5,
            'task_date' => '2026-08-01',
            'role_slug' => 'decorate',
            'capacity' => 2,
            'event_post_id' => null,
            'event_label' => 'Party',
            'starts_at' => null,
            'ends_at' => null,
            'notes' => '',
            'created_at' => '2026-07-01 00:00:00',
        ];
    }

    public function testViewModelForALoggedOutVisitorShowsTheBoardAndNoPerson(): void
    {
        $this->wpdb->nextResults[] = [$this->taskRow()]; // upcoming
        $this->wpdb->nextResults[] = [];                 // occupancyFor

        $view = $this->controller()->viewModel();

        self::assertNull($view['person']);
        self::assertSame('', $view['csrf']);
        self::assertCount(1, $view['groups']);
        self::assertSame(1, count($view['groups'][0]['tasks']));
    }

    public function testViewModelForASignedInPersonResolvesThemAndTheirStanding(): void
    {
        $_COOKIE[WebSession::COOKIE] = WebSession::mint(7);

        // currentPerson -> find(7)
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam', 'email_verified_at' => '2026-07-01 00:00:00'];
        $this->wpdb->nextResults[] = [$this->taskRow()]; // upcoming
        $this->wpdb->nextResults[] = [];                 // occupancyFor
        $this->wpdb->nextResults[] = [];                 // forPerson (occupiedTaskIds)
        $this->wpdb->nextResults[] = [];                 // standing historyFor
        $this->wpdb->nextVars[] = 0;                     // standing countFor

        $view = $this->controller()->viewModel();

        self::assertNotNull($view['person']);
        self::assertSame('Sam', $view['person']->name());
        self::assertNotSame('', $view['csrf']);
        self::assertNotNull($view['standing']);
    }

    public function testLoginByEmailIssuesATokenAndMailsALink(): void
    {
        $this->wpdb->nextRows[] = null; // findByEmail -> not found -> create

        $code = $this->controller()->loginByEmail('sam@example.com');

        self::assertSame('check_email', $code);
        // A person row and an auth token row were inserted.
        self::assertGreaterThanOrEqual(2, count($this->wpdb->inserts));
        self::assertCount(1, $this->mails);
        self::assertStringContainsString('action=' . SignupController::LOGIN_ACTION, $this->mails[0]);
    }

    public function testLoginByEmailRejectsABadAddress(): void
    {
        $code = $this->controller()->loginByEmail('not-an-email');

        self::assertSame('bad_email', $code);
        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->mails);
    }

    public function testConsumeMagicLinkVerifiesAndReturnsThePersonId(): void
    {
        // AuthTokenRepository::consume -> a live, unused token for person 7.
        $this->wpdb->nextRows[] = [
            'id' => 1,
            'person_id' => 7,
            'expires_at' => '2026-12-31 00:00:00',
            'used_at' => null,
        ];

        $personId = $this->controller()->consumeMagicLink('rawtoken');

        self::assertSame(7, $personId);
        // used_at burned, and the person marked verified: two updates.
        self::assertGreaterThanOrEqual(2, count($this->wpdb->updates));
    }

    public function testConsumeMagicLinkReturnsNullForABadToken(): void
    {
        // No token row queued -> consume returns null.
        self::assertNull($this->controller()->consumeMagicLink('nope'));
    }

    public function testClaimForMapsAJoinToAClaimedNotice(): void
    {
        // Gate off, no overlap, capacity free, insert succeeds.
        $this->wpdb->nextVars[] = 0; // hasOverlapping
        $this->wpdb->nextVars[] = 2; // taskCapacity
        $this->wpdb->nextRows[] = null; // join findFor
        $this->wpdb->nextQueryResults[] = 1; // insert

        self::assertSame('claimed', $this->controller()->claimFor(7, 5));
    }

    public function testDropForMapsACancellationToADroppedNotice(): void
    {
        $this->wpdb->nextRows[] = ['id' => 3, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];
        $this->wpdb->nextRows[] = ['starts_at' => '2026-09-01 20:00:00', 'task_date' => '2026-09-01'];

        self::assertSame('dropped', $this->controller()->dropFor(7, 5));
    }
}
