<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\LoginCode;
use EventCrew\Tests\TestCase;

final class LoginCodeTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const TTL = 30 * 60;

    /** @var array<string, mixed> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->transients = [];

        Functions\when('set_transient')->alias(function (string $key, $value): bool {
            $this->transients[$key] = $value;

            return true;
        });
        Functions\when('get_transient')->alias(fn (string $key) => $this->transients[$key] ?? false);
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);

            return true;
        });
    }

    /** A code that is not $code: the next one round, same length. */
    private static function otherThan(string $code): string
    {
        return str_pad((string) (((int) $code + 1) % 1_000_000), LoginCode::LENGTH, '0', STR_PAD_LEFT);
    }

    public function testTheRightCodeAnswersTheLinkTokensHash(): void
    {
        $code = LoginCode::issue('sam@example.com', 'rawtoken', self::TTL, self::NOW);

        self::assertMatchesRegularExpression('/^\d{6}$/', $code);

        $check = LoginCode::check('Sam@Example.com', $code, '', self::NOW + 60);

        self::assertSame(LoginCode::OK, $check['state']);
        self::assertSame(hash('sha256', 'rawtoken'), $check['token']);
    }

    public function testNeitherTheCodeNorTheTokenIsStoredAsIs(): void
    {
        $code = LoginCode::issue('sam@example.com', 'rawtoken', self::TTL, self::NOW);
        $stored = serialize($this->transients);

        self::assertStringNotContainsString($code, $stored);
        self::assertStringNotContainsString('rawtoken', $stored);
    }

    public function testSpacesAndDashesBetweenTheDigitsAreFine(): void
    {
        $code = LoginCode::issue('sam@example.com', 'rawtoken', self::TTL, self::NOW);
        $typed = substr($code, 0, 3) . ' - ' . substr($code, 3);

        self::assertSame(LoginCode::OK, LoginCode::check('sam@example.com', $typed, '', self::NOW)['state']);
    }

    public function testAWrongCodeIsRefusedAndTheRightOneStillWorks(): void
    {
        $code = LoginCode::issue('sam@example.com', 'rawtoken', self::TTL, self::NOW);

        self::assertSame(
            ['state' => LoginCode::WRONG],
            LoginCode::check('sam@example.com', self::otherThan($code), '', self::NOW)
        );
        self::assertSame(LoginCode::OK, LoginCode::check('sam@example.com', $code, '', self::NOW)['state']);
    }

    public function testACodeIsForItsOwnAddressOnly(): void
    {
        $code = LoginCode::issue('sam@example.com', 'rawtoken', self::TTL, self::NOW);

        self::assertSame(LoginCode::WRONG, LoginCode::check('kim@example.com', $code, '', self::NOW)['state']);
    }

    public function testACodeWorksOnce(): void
    {
        $code = LoginCode::issue('sam@example.com', 'rawtoken', self::TTL, self::NOW);

        self::assertSame(LoginCode::OK, LoginCode::check('sam@example.com', $code, '', self::NOW)['state']);
        self::assertSame(LoginCode::WRONG, LoginCode::check('sam@example.com', $code, '', self::NOW)['state']);
    }

    public function testAnExpiredCodeIsRefused(): void
    {
        $code = LoginCode::issue('sam@example.com', 'rawtoken', self::TTL, self::NOW);

        self::assertSame(
            LoginCode::WRONG,
            LoginCode::check('sam@example.com', $code, '', self::NOW + self::TTL + 1)['state']
        );
    }

    public function testTheCodeOfAnEarlierLinkStillWorksWhileThatLinkDoes(): void
    {
        $first = LoginCode::issue('sam@example.com', 'first', self::TTL, self::NOW);
        LoginCode::issue('sam@example.com', 'second', self::TTL, self::NOW + 60);

        $check = LoginCode::check('sam@example.com', $first, '', self::NOW + 120);

        self::assertSame(LoginCode::OK, $check['state']);
        self::assertSame(hash('sha256', 'first'), $check['token']);
    }

    public function testTheLastAllowedWrongTryRemovesTheCodes(): void
    {
        $code = LoginCode::issue('sam@example.com', 'rawtoken', self::TTL, self::NOW);
        $wrong = self::otherThan($code);

        for ($try = 1; $try < LoginCode::MAX_TRIES; ++$try) {
            self::assertSame(LoginCode::WRONG, LoginCode::check('sam@example.com', $wrong, '', self::NOW)['state']);
        }

        self::assertSame(LoginCode::LOCKED, LoginCode::check('sam@example.com', $wrong, '', self::NOW)['state']);
        // Even the right code no longer works: the link is the way in now.
        self::assertSame(LoginCode::WRONG, LoginCode::check('sam@example.com', $code, '', self::NOW)['state']);
    }

    public function testTooManyWrongCodesFromOneAddressWaitAnHour(): void
    {
        $code = LoginCode::issue('sam@example.com', 'rawtoken', self::TTL, self::NOW);

        for ($i = 0; $i < LoginCode::IP_LIMIT; ++$i) {
            LoginCode::check('nobody' . $i . '@example.com', '123456', '203.0.113.9', self::NOW);
        }

        $busy = LoginCode::check('sam@example.com', $code, '203.0.113.9', self::NOW);
        self::assertSame(LoginCode::BUSY, $busy['state']);
        // Another visitor is not held up.
        self::assertSame(LoginCode::OK, LoginCode::check('sam@example.com', $code, '198.51.100.4', self::NOW)['state']);
    }

    public function testForgetRemovesTheCodes(): void
    {
        $code = LoginCode::issue('sam@example.com', 'rawtoken', self::TTL, self::NOW);

        LoginCode::forget('sam@example.com');

        self::assertSame(LoginCode::WRONG, LoginCode::check('sam@example.com', $code, '', self::NOW)['state']);
    }

    public function testNormalizeWantsSixDigits(): void
    {
        self::assertSame('012345', LoginCode::normalize(' 012 345 '));
        self::assertNull(LoginCode::normalize('12345'));
        self::assertNull(LoginCode::normalize('1234567'));
        self::assertNull(LoginCode::normalize('12a456'));
    }
}
