<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\EmailBody;
use EventCrew\Support\EmailClicks;
use EventCrew\Tests\TestCase;

/**
 * The counting links of the email buttons: short, signed so they cannot send
 * anyone elsewhere, never on a sign-in link, and never kept by a page cache.
 */
final class EmailClicksTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var list<string> */
    private array $calls = [];

    private array $server = [];

    private array $get = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->calls = [];
        $this->server = $_SERVER;
        $this->get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        Functions\when('wp_salt')->justReturn('salt');
        Functions\when('wp_unslash')->returnArg();
        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('UTC'));
        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
        Functions\when('nocache_headers')->alias(function (): void {
            $this->calls[] = 'nocache';
        });
        Functions\when('wp_redirect')->alias(function (string $url, int $status): void {
            $this->calls[] = 'redirect ' . $status . ' ' . $url;

            throw new \RuntimeException('exit');
        });
        Functions\when('wp_safe_redirect')->alias(function (string $url, int $status): void {
            $this->calls[] = 'safe ' . $status . ' ' . $url;

            throw new \RuntimeException('exit');
        });
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET = $this->get;

        parent::tearDown();
    }

    public function testASiteLinkIsWrappedShortAndResolvesBack(): void
    {
        $target = 'https://site.test/wp-json/eventcrew/v1/ticket?token=abc.def';
        $wrapped = EmailClicks::wrap($target, 'claim_notifier', 'ticket');

        self::assertStringStartsWith('https://site.test/?ecl=', $wrapped);
        self::assertLessThan(140, strlen($wrapped));

        $token = substr($wrapped, strlen('https://site.test/?ecl='));
        self::assertSame(
            ['mail' => 'claim_notifier', 'button' => 'ticket', 'url' => $target],
            EmailClicks::resolve($token)
        );
        // Wrapping twice changes nothing.
        self::assertSame($wrapped, EmailClicks::wrap($wrapped, 'claim_notifier', 'ticket'));
    }

    public function testAChangedTokenResolvesToNothing(): void
    {
        $wrapped = EmailClicks::wrap('https://example.org/page', 'newsletter', 'link');
        $token = substr($wrapped, strlen('https://site.test/?ecl='));
        [$body, $signature] = explode('.', $token);

        self::assertSame('https://example.org/page', EmailClicks::resolve($token)['url']);

        $evil = rtrim(strtr(base64_encode('newsletter|link|https://evil.test/'), '+/', '-_'), '=');
        self::assertNull(EmailClicks::resolve($evil . '.' . $signature));
        self::assertNull(EmailClicks::resolve($body . '.0000000000000000'));
        self::assertNull(EmailClicks::resolve($body));
        self::assertNull(EmailClicks::resolve(''));
    }

    public function testSignInLinksAreNeverWrapped(): void
    {
        $links = [
            'https://site.test/wp-admin/admin-ajax.php?action=eventcrew_login&t=x',
            'https://site.test/wp-admin/post.php?post=1',
            'https://site.test/crew/?eventcrew_login=abc',
            'https://site.test/wp-json/eventcrew/v1/verify?token=x',
            'https://site.test/?rest_route=/eventcrew/v1/manage&token=x',
            'mailto:someone@example.org',
            '',
        ];

        foreach ($links as $link) {
            self::assertSame($link, EmailClicks::wrap($link, 'mail', 'button'), $link);
        }
    }

    public function testActionsGetTheirOwnKeysOrOnesFromTheLink(): void
    {
        $actions = EmailClicks::actions(
            [
                ['label' => 'Ticket', 'url' => 'https://site.test/wp-json/eventcrew/v1/ticket?t=1'],
                ['label' => 'Tasks', 'url' => 'https://site.test/tasks/', 'mail' => 'reminder', 'button' => 'tasks'],
                ['label' => 'Bot', 'url' => 'https://t.me/somebot', 'style' => EmailBody::STYLE_TELEGRAM],
                ['label' => 'Nothing', 'url' => ''],
            ],
            'claim_notifier'
        );

        $keys = array_map(
            static function (array $action): string {
                $token = substr((string) $action['url'], strlen('https://site.test/?ecl='));
                $link = EmailClicks::resolve($token);

                return null === $link ? '-' : $link['mail'] . ':' . $link['button'];
            },
            $actions
        );

        self::assertSame(['claim_notifier:ticket', 'reminder:tasks', 'claim_notifier:telegram', '-'], $keys);
    }

    public function testButtonKeysAndKeys(): void
    {
        self::assertSame('calendar', EmailClicks::buttonKey('https://site.test/wp-json/eventcrew/v1/calendar?x=1'));
        self::assertSame('telegram', EmailClicks::buttonKey('https://t.me/somebot'));
        self::assertSame('link', EmailClicks::buttonKey('https://example.org/a/b'));
        self::assertSame('tasks', EmailClicks::buttonKey('https://site.test/fi/tasks/'));
        self::assertSame('home', EmailClicks::buttonKey('https://site.test/'));
        self::assertSame('night', EmailClicks::key('Night-20261114'));
        self::assertSame(32, strlen(EmailClicks::key(str_repeat('a', 50))));
    }

    public function testAClickCountsAndRedirectsWithoutBeingCached(): void
    {
        $wrapped = EmailClicks::wrap('https://site.test/tasks/', 'reminder', 'tasks');
        $_GET['ecl'] = substr($wrapped, strlen('https://site.test/?ecl='));
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0';

        $this->handle();

        // nocache_headers() first, so the page cache does not keep the 302.
        self::assertSame(['nocache', 'redirect 302 https://site.test/tasks/'], $this->calls);
        self::assertSame(1, $this->options['eventcrew_stats_' . gmdate('Y_m')]['clicks']['reminder']['tasks']);
    }

    public function testARobotIsSentOnButNotCounted(): void
    {
        $wrapped = EmailClicks::wrap('https://site.test/tasks/', 'reminder', 'tasks');
        $_GET['ecl'] = substr($wrapped, strlen('https://site.test/?ecl='));
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_USER_AGENT'] = 'Mimecast URL checker';

        $this->handle();

        self::assertSame(['nocache', 'redirect 302 https://site.test/tasks/'], $this->calls);
        self::assertSame([], $this->options);
    }

    public function testABrokenTokenGoesHomeAndCountsNothing(): void
    {
        $_GET['ecl'] = 'abc.def';

        $this->handle();

        self::assertSame(['nocache', 'safe 302 https://site.test/'], $this->calls);
        self::assertSame([], $this->options);
    }

    public function testARequestWithoutTheParameterIsLeftAlone(): void
    {
        unset($_GET['ecl']);

        EmailClicks::handle();

        self::assertSame([], $this->calls);
    }

    private function handle(): void
    {
        try {
            EmailClicks::handle();
        } catch (\RuntimeException $exit) {
            self::assertSame('exit', $exit->getMessage());

            return;
        }

        self::fail('handle() did not redirect.');
    }
}
