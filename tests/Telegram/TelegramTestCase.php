<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\CreditGrantNotifier;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\LeaderEligibility;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\RosterAssembler;
use EventCrew\Support\SignupService;
use EventCrew\Support\StandingCalculator;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\DohResolver;
use EventCrew\Telegram\GiftService;
use EventCrew\Telegram\NotificationSettingsService;
use EventCrew\Telegram\OnboardingService;
use EventCrew\Telegram\PermissionService;
use EventCrew\Telegram\ProfileService;
use EventCrew\Telegram\ReplacementService;
use EventCrew\Telegram\RosterService;
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
    protected function updateRouter(): UpdateRouter
    {
        $mailer = new Mailer(new Logger(), new EmailTemplate(new Logger()));

        return new UpdateRouter(
            new OnboardingService(new PersonRepository(), new AuthTokenRepository(), $this->client(), new Logger()),
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
                new LeaderEligibility(new AssignmentRepository(), new PersonRepository()),
                $mailer
            ),
            new NotificationSettingsService(new PersonRepository(), $this->client())
        );
    }

    private function boardService(): BoardService
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
