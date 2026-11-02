<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\CreditGrantNotifier;
use EventCrew\Support\DoorAccess;
use EventCrew\Support\DoorLinkSender;
use EventCrew\Support\DoorList;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\RoleConditions;
use EventCrew\Support\RoleTaskService;
use EventCrew\Support\RosterAssembler;
use EventCrew\Support\SalesDeskGate;
use EventCrew\Support\SignupService;
use EventCrew\Support\StandingCalculator;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\DohResolver;
use EventCrew\Telegram\DoorListService;
use EventCrew\Telegram\GiftService;
use EventCrew\Telegram\GuestListService;
use EventCrew\Telegram\HelpService;
use EventCrew\Telegram\LanguageService;
use EventCrew\Telegram\NotificationSettingsService;
use EventCrew\Telegram\OnboardingService;
use EventCrew\Telegram\PermissionService;
use EventCrew\Telegram\ProfileService;
use EventCrew\Telegram\ReplacementService;
use EventCrew\Telegram\RoleTaskBot;
use EventCrew\Telegram\RosterService;
use EventCrew\Telegram\SalesLinkService;
use EventCrew\Telegram\TelegramClient;
use EventCrew\Telegram\TicketRedemptionService;
use EventCrew\Telegram\UpdateRouter;
use EventCrew\Tests\TestCase;

/**
 * Shared scaffolding for the bot tests.
 *
 * The Telegram client is final and, more usefully, is the one real seam to the
 * outside world, so rather than mock it these tests wire the real client over a
 * captured wp_remote_post - the same posture the rest of the suite takes with
 * $wpdb. Every API call the code makes lands in $telegramCalls for assertion,
 * and an options map stands in for wp_options.
 */
abstract class TelegramTestCase extends TestCase
{
    /** @var array<int, array{method: string, body: array<string, mixed>}> */
    protected array $telegramCalls = [];

    /** @var array<string, mixed> */
    protected array $options = [];

    /** @var array<string, mixed> Per-method Telegram `result` payloads; true by default. */
    protected array $telegramResults = [];

    /**
     * Per-method Bot API error descriptions. A method named here answers
     * `ok: false` with that description instead of succeeding, which is the
     * only way to exercise the paths that care whether a call worked - a board
     * deleted from the group, a bot removed. Empty by default: the overwhelming
     * majority of tests want a working bot and should not have to say so.
     *
     * @var array<string, string>
     */
    protected array $telegramErrors = [];

    /**
     * Per-method count of calls answered "Too Many Requests: retry after 1"
     * (with parameters.retry_after) before the method starts succeeding.
     *
     * @var array<string, int>
     */
    protected array $telegramRateLimits = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegramCalls = [];
        // The reputation join gate ships on, but it reads a person's history the
        // moment a join is attempted, which would consume queued rows the
        // ungated join tests line up. Default it off here; the tests that
        // exercise the gate turn it back on explicitly.
        $this->options = [
            TelegramClient::TOKEN_OPTION => 'BOT:TOKEN',
            SignupService::GATE_OPTION => '0',
        ];
        $this->telegramResults = [];
        $this->telegramErrors = [];
        $this->telegramRateLimits = [];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(
            function (string $name, mixed $value): bool {
                $this->options[$name] = $value;

                return true;
            }
        );

        Functions\when('wp_json_encode')->alias(static fn (mixed $data): string => (string) json_encode($data));
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_body')->returnArg(1);
        Functions\when('wp_remote_post')->alias(
            function (string $url, array $args): string {
                $method = substr($url, (int) strrpos($url, '/') + 1);
                $this->telegramCalls[] = [
                    'method' => $method,
                    'body' => (array) json_decode((string) $args['body'], true),
                ];

                if (($this->telegramRateLimits[$method] ?? 0) > 0) {
                    $this->telegramRateLimits[$method]--;

                    return (string) json_encode([
                        'ok' => false,
                        'error_code' => 429,
                        'description' => 'Too Many Requests: retry after 1',
                        'parameters' => ['retry_after' => 1],
                    ]);
                }

                if (isset($this->telegramErrors[$method])) {
                    return (string) json_encode([
                        'ok' => false,
                        'description' => $this->telegramErrors[$method],
                    ]);
                }

                return (string) json_encode([
                    'ok' => true,
                    'result' => $this->telegramResults[$method] ?? true,
                ]);
            }
        );
    }

    protected function client(): TelegramClient
    {
        return new TelegramClient(new Logger(), new DohResolver(new Logger()));
    }

    /**
     * A real StandingCalculator over the fake wpdb, for the services that
     * compose one (the People list, /me, and so on).
     */
    protected function standing(): StandingCalculator
    {
        return new StandingCalculator(new AssignmentRepository(), new RedemptionRepository(), new CreditGrantRepository());
    }

    /**
     * A real SignupService over the fake wpdb - the claim/drop policy the board
     * now delegates to.
     */
    protected function signup(): SignupService
    {
        return new SignupService(new AssignmentRepository(), $this->standing(), new PersonRepository(), new TaskRepository());
    }

    /**
     * The whole bot wired over the fake wpdb and the captured client - one place
     * the router's object graph is built, so a new collaborator is a single edit
     * here rather than in every bot test that needs a router.
     */
    /**
     * Without $language the router asks nobody to choose, as most routing
     * tests want; the language tests pass one in.
     */
    protected function updateRouter(
        ?LanguageService $language = null,
        ?\EventCrew\Telegram\AssistantService $assistant = null
    ): UpdateRouter
    {
        $mailer = new Mailer(new Logger(), new EmailTemplate(new Logger()));

        return new UpdateRouter(
            new OnboardingService(new PersonRepository(), new AuthTokenRepository(), $this->client(), new Logger(), $mailer),
            $this->boardService(),
            new RosterService(
                new RosterAssembler(new TaskRepository(), new AssignmentRepository(), new PersonRepository(), $this->standing()),
                new TaskRepository(),
                new PersonRepository(),
                new AssignmentRepository(),
                $this->client()
            ),
            new ReplacementService(
                new AssignmentRepository(),
                new TaskRepository(),
                new PersonRepository(),
                $this->boardService(),
                $this->client(),
                $mailer
            ),
            new ProfileService(
                new PersonRepository(),
                new AssignmentRepository(),
                new TaskRepository(),
                $this->standing(),
                $this->client()
            ),
            new TicketRedemptionService(
                new PersonRepository(),
                new TaskRepository(),
                new RedemptionRepository(),
                $this->standing(),
                new FreeEntryGate(),
                $this->client(),
                $mailer
            ),
            new GiftService(
                new PersonRepository(),
                new CreditGrantRepository(),
                $this->standing(),
                $this->client(),
                new CreditGrantNotifier($mailer, $this->client())
            ),
            new PermissionService(
                new PersonRepository(),
                $this->client(),
                new RoleConditions(new AssignmentRepository(), new PersonRepository()),
                $mailer
            ),
            new NotificationSettingsService(new PersonRepository(), $this->client()),
            $this->doorListService(),
            $this->guestListService(),
            $this->salesLinkService(),
            $language,
            $this->roleTaskBot(),
            new HelpService(
                new PersonRepository(),
                new DoorAccess(new TaskRepository(), new AssignmentRepository()),
                $this->client()
            ),
            $assistant
        );
    }

    protected function roleTaskService(): RoleTaskService
    {
        $mailer = new Mailer(new Logger(), new EmailTemplate(new Logger()));
        $assignments = new AssignmentRepository();

        return new RoleTaskService(
            new PersonRepository(),
            new TaskRepository(),
            $assignments,
            $this->signup(),
            new ClaimNotifier(new TaskRepository(), $assignments, $mailer, $this->client(), $this->standing())
        );
    }

    protected function roleTaskBot(): RoleTaskBot
    {
        $mailer = new Mailer(new Logger(), new EmailTemplate(new Logger()));
        $assignments = new AssignmentRepository();

        return new RoleTaskBot(
            new PersonRepository(),
            new TaskRepository(),
            $assignments,
            $this->signup(),
            $this->roleTaskService(),
            new ClaimNotifier(new TaskRepository(), $assignments, $mailer, $this->client(), $this->standing()),
            $this->client()
        );
    }

    protected function salesDeskGate(): SalesDeskGate
    {
        return new SalesDeskGate(new TaskRepository(), new AssignmentRepository());
    }

    protected function doorLinkSender(): DoorLinkSender
    {
        return new DoorLinkSender(
            new TaskRepository(),
            new AssignmentRepository(),
            new PersonRepository(),
            new NotificationsRepository(),
            new AuthTokenRepository(),
            $this->salesDeskGate(),
            $this->client(),
            new Mailer(new Logger(), new EmailTemplate(new Logger()))
        );
    }

    protected function welcomeSender(): \EventCrew\Support\WelcomeSender
    {
        return new \EventCrew\Support\WelcomeSender(
            new TaskRepository(),
            new PersonRepository(),
            new NotificationsRepository(),
            new Mailer(new Logger(), new EmailTemplate(new Logger())),
            $this->client()
        );
    }

    protected function commandMenu(): \EventCrew\Telegram\CommandMenu
    {
        return new \EventCrew\Telegram\CommandMenu(
            new PersonRepository(),
            new AssignmentRepository(),
            new TaskRepository(),
            new DoorAccess(new TaskRepository(), new AssignmentRepository()),
            $this->client()
        );
    }

    protected function salesLinkService(): SalesLinkService
    {
        return new SalesLinkService($this->salesDeskGate(), new PersonRepository(), $this->doorLinkSender(), $this->client());
    }

    protected function doorListService(): DoorListService
    {
        return new DoorListService(
            new DoorList(
                new TaskRepository(),
                new AssignmentRepository(),
                new PersonRepository(),
                new RedemptionRepository(),
                $this->standing()
            ),
            new GuestRepository(),
            new DoorAccess(new TaskRepository(), new AssignmentRepository()),
            new PersonRepository(),
            $this->client()
        );
    }

    protected function guestListService(): GuestListService
    {
        return new GuestListService(
            new GuestRepository(),
            new DoorAccess(new TaskRepository(), new AssignmentRepository()),
            new PersonRepository(),
            $this->client()
        );
    }

    protected function boardService(): BoardService
    {
        return new BoardService(
            new TaskRepository(),
            new AssignmentRepository(),
            new PersonRepository(),
            $this->client(),
            new Logger(),
            new ClaimNotifier(
                new TaskRepository(),
                new AssignmentRepository(),
                new Mailer(new Logger(), new EmailTemplate(new Logger())),
                $this->client(),
                $this->standing()
            ),
            $this->signup()
        );
    }

    /**
     * @return array<int, string>
     */
    protected function calledMethods(): array
    {
        return array_map(static fn (array $call): string => $call['method'], $this->telegramCalls);
    }

    /**
     * The body of the last call to a given API method, or null if never called.
     *
     * @return array<string, mixed>|null
     */
    protected function lastCallTo(string $method): ?array
    {
        for ($i = count($this->telegramCalls) - 1; $i >= 0; $i--) {
            if ($method === $this->telegramCalls[$i]['method']) {
                return $this->telegramCalls[$i]['body'];
            }
        }

        return null;
    }
}
