<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Tests\TestCase;

final class MailerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('get_permalink')->justReturn('https://site.test/signup/');
        Functions\when('rest_url')->alias(static fn (string $p): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static function (array $args, string $url): string {
                return $url . '?' . http_build_query($args);
            }
        );
    }

    public function testManageUrlPointsAtTheSignupPageWithAOneTimeLoginToken(): void
    {
        $this->options['eventcrew_signup_page_id'] = 42;

        $url = (new Mailer(new Logger()))->manageUrl(7);

        // Lands on the public profile page carrying a single-use sign-in token,
        // so the person arrives signed in where the account controls live - and
        // a forwarded old email can't be replayed as a login.
        self::assertStringStartsWith('https://site.test/signup/?', $url);
        self::assertStringContainsString('eventcrew_login=', $url);
        self::assertStringNotContainsString('wp-json', $url);
    }

    public function testManageUrlFallsBackToTheRestEndpointWithNoPublicPage(): void
    {
        // A Telegram-only install with no signup page still needs a working
        // self-service link, so the standalone REST page remains the fallback.
        $url = (new Mailer(new Logger()))->manageUrl(7);

        self::assertStringStartsWith('https://site.test/wp-json/eventcrew/v1/manage?', $url);
        self::assertStringContainsString('token=', $url);
    }
}
