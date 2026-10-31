<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Support\Logger;
use EventCrew\Telegram\DohResolver;
use EventCrew\Tests\TestCase;

final class DohResolverTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $transients = [];

    /** @var array<int, string> */
    private array $lookups = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->transients = [];
        $this->lookups = [];

        // Logger touches these when the resolver logs a failed lookup.
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);

        Functions\when('get_transient')->alias(fn (string $key): mixed => $this->transients[$key] ?? false);
        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->transients[$key] = $value;

            return true;
        });
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_body')->returnArg(1);
    }

    private function resolver(): DohResolver
    {
        return new DohResolver(new Logger());
    }

    private function stubDoh(string $json): void
    {
        Functions\when('wp_remote_get')->alias(function (string $url) use ($json): string {
            $this->lookups[] = $url;

            return $json;
        });
    }

    public function testResolvesAnIpFromTheDohAnswer(): void
    {
        $this->stubDoh((string) json_encode([
            'Status' => 0,
            'Answer' => [
                ['name' => 'api.telegram.org', 'type' => 5, 'data' => 'cname.example'],
                ['name' => 'api.telegram.org', 'type' => 1, 'data' => '149.154.167.220'],
            ],
        ]));

        self::assertSame('149.154.167.220', $this->resolver()->resolve('api.telegram.org'));
        self::assertCount(1, $this->lookups);
        // The lookup goes to a literal IP, so it needs no DNS itself.
        self::assertStringContainsString('https://1.1.1.1/dns-query', $this->lookups[0]);
    }

    public function testCachesTheAnswerAndDoesNotLookUpTwice(): void
    {
        $this->stubDoh((string) json_encode([
            'Status' => 0,
            'Answer' => [['type' => 1, 'data' => '149.154.167.220']],
        ]));

        $resolver = $this->resolver();
        $resolver->resolve('api.telegram.org');
        $resolver->resolve('api.telegram.org');

        self::assertCount(1, $this->lookups);
    }

    public function testReturnsNullWhenNoARecordIsPresent(): void
    {
        // Both endpoints tried, neither yields an A record.
        $this->stubDoh((string) json_encode(['Status' => 3, 'Answer' => []]));

        self::assertNull($this->resolver()->resolve('api.telegram.org'));
        self::assertCount(2, $this->lookups);
    }

    public function testIgnoresAMalformedIp(): void
    {
        $this->stubDoh((string) json_encode([
            'Status' => 0,
            'Answer' => [['type' => 1, 'data' => 'not-an-ip']],
        ]));

        self::assertNull($this->resolver()->resolve('api.telegram.org'));
    }

    public function testReturnsNullOnATransportError(): void
    {
        Functions\when('is_wp_error')->justReturn(true);
        Functions\when('wp_remote_get')->justReturn(null);

        self::assertNull($this->resolver()->resolve('api.telegram.org'));
    }
}
