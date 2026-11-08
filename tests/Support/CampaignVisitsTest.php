<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\CampaignVisits;
use EventCrew\Tests\TestCase;
use WP_REST_Request;

/**
 * Campaign visits, counted from the browser: the same tiny script on every
 * page (safe in a page cache), and an endpoint that cleans the name and
 * counts only a browser's beacon.
 */
final class CampaignVisitsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    private array $server = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->server = $_SERVER;
        Functions\when('wp_unslash')->returnArg();
        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('UTC'));
        Functions\when('wp_json_encode')->alias(
            static fn (mixed $value, int $flags = 0): string => (string) json_encode($value, $flags)
        );
        Functions\when('rest_url')->alias(
            static fn (string $path = ''): string => 'https://site.test/wp-json/' . $path
        );
        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0';
        foreach (['HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_PURPOSE', 'HTTP_X_MOZ'] as $header) {
            unset($_SERVER[$header]);
        }
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;

        parent::tearDown();
    }

    public function testTheScriptIsTheSameForEveryoneAndSendsOnlyTheName(): void
    {
        $script = CampaignVisits::script();

        self::assertStringContainsString('["utm_campaign","c"]', $script);
        self::assertStringContainsString('navigator.sendBeacon("https://site.test/wp-json/eventcrew/v1/hit"', $script);
        self::assertStringContainsString('{campaign:', $script);
        self::assertStringNotContainsString('cookie', $script);
        self::assertStringNotContainsString('Storage', $script);
        self::assertLessThan(300, strlen($script));
    }

    public function testEditorsDoNotGetTheScript(): void
    {
        Functions\when('current_user_can')->justReturn(true);

        ob_start();
        CampaignVisits::printScript();
        self::assertSame('', ob_get_clean());

        Functions\when('current_user_can')->justReturn(false);

        ob_start();
        CampaignVisits::printScript();
        self::assertStringStartsWith('<script>(function(){', (string) ob_get_clean());
    }

    public function testABeaconCountsTheCleanName(): void
    {
        $response = CampaignVisits::hit($this->request(' Newsletter<b> '));

        self::assertSame(204, $response->get_status());
        self::assertStringContainsString('no-cache', $response->get_headers()['Cache-Control']);

        $month = $this->options['eventcrew_stats_' . gmdate('Y_m')];
        self::assertSame(['newsletter' => 1], $month['campaigns']);
        self::assertSame(1, $month['visits']);
    }

    public function testRobotsPrefetchesAndEmptyNamesCountNothing(): void
    {
        CampaignVisits::hit($this->request('<>'));
        CampaignVisits::hit(new WP_REST_Request());

        $_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';
        CampaignVisits::hit($this->request('newsletter'));
        unset($_SERVER['HTTP_SEC_PURPOSE']);

        $_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1';
        $response = CampaignVisits::hit($this->request('newsletter'));

        self::assertSame(204, $response->get_status());
        self::assertSame([], $this->options);
    }

    private function request(string $campaign): WP_REST_Request
    {
        $request = new WP_REST_Request();
        $request->method = 'POST';
        $request->params = ['campaign' => $campaign];

        return $request;
    }
}
