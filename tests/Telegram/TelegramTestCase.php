<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Support\Logger;
use EventCrew\Support\StandingCalculator;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\DohResolver;
use EventCrew\Telegram\TelegramClient;
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
            BoardService::GATE_OPTION => '0',
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
     * compose one (the board's join gate, /me, and so on).
     */
    protected function standing(): StandingCalculator
    {
        return new StandingCalculator(new AssignmentRepository(), new RedemptionRepository());
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
