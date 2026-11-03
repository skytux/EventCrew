<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\Logger;
use EventCrew\Support\TelegramLogin;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\DohResolver;
use EventCrew\Telegram\TelegramClient;
use EventCrew\Tests\TestCase;

final class TelegramLoginTest extends TestCase
{
    private const NONCE = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, mixed> */
    private array $transients = [];

    /** @var array<int, string> every message the Logger wrote */
    private array $logged = [];

    /** How many times Telegram's keys were fetched. */
    private int $fetches = 0;

    /** What the fake key endpoint answers: [status, body]. */
    private array $jwksResponse = [200, ''];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [
            TelegramLogin::ENABLED_OPTION => true,
            TelegramClient::TOKEN_OPTION => TelegramTokens::BOT_TOKEN,
            BoardService::USERNAME_OPTION => 'crewbot',
        ];
        $this->transients = [];
        $this->logged = [];
        $this->fetches = 0;
        $this->jwksResponse = [200, TelegramTokens::jwksBody()];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            if ('eventcrew_recent_logs' === $name && is_array($value)) {
                $last = end($value);
                $this->logged[] = (string) ($last['message'] ?? '');
            }

            return true;
        });
        Functions\when('get_transient')->alias(fn (string $key): mixed => $this->transients[$key] ?? false);
        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->transients[$key] = $value;

            return true;
        });
        Functions\when('wp_remote_get')->alias(function (string $url): array {
            self::assertSame('https://oauth.telegram.org/.well-known/jwks.json', $url);
            $this->fetches++;

            return ['response' => ['code' => $this->jwksResponse[0]], 'body' => $this->jwksResponse[1]];
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(static fn (array $r): int => $r['response']['code']);
        Functions\when('wp_remote_retrieve_body')->alias(static fn (array $r): string => $r['body']);
    }

    private function login(): TelegramLogin
    {
        return new TelegramLogin(new Logger(), new DohResolver(new Logger()));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function token(array $overrides = []): string
    {
        return TelegramTokens::token(TelegramTokens::claims(self::NONCE, $overrides));
    }

    public function testAValidTokenGivesTheTelegramUserId(): void
    {
        self::assertSame(4242, $this->login()->verify($this->token(), self::NONCE));
    }

    public function testTheClientIdIsTheBotIdAloneNeverTheToken(): void
    {
        self::assertSame(TelegramTokens::BOT_ID, TelegramLogin::clientId());

        $this->options[TelegramClient::TOKEN_OPTION] = 'not-a-token';
        self::assertSame('', TelegramLogin::clientId());
    }

    public function testTheLoginIsAvailableOnlyWithTheSwitchATokenAndAUsername(): void
    {
        self::assertTrue(TelegramLogin::isAvailable());

        $required = [TelegramLogin::ENABLED_OPTION, TelegramClient::TOKEN_OPTION, BoardService::USERNAME_OPTION];

        foreach ($required as $missing) {
            $options = $this->options;
            unset($this->options[$missing]);
            self::assertFalse(TelegramLogin::isAvailable(), $missing);
            $this->options = $options;
        }
    }

    public function testAlgNoneIsRefused(): void
    {
        $token = TelegramTokens::unsigned(
            ['alg' => 'none', 'typ' => 'JWT', 'kid' => TelegramTokens::KID],
            TelegramTokens::claims(self::NONCE),
            ''
        );

        self::assertNull($this->login()->verify($token, self::NONCE));
    }

    /**
     * The classic forgery: HS256 "signed" with the public key as the shared
     * secret. Only RS256 is ever accepted, so the header alone refuses it.
     */
    public function testHs256IsRefused(): void
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT', 'kid' => TelegramTokens::KID];
        $claims = TelegramTokens::claims(self::NONCE);
        $input = TelegramTokens::b64((string) json_encode($header))
            . '.' . TelegramTokens::b64((string) json_encode($claims));
        $mac = hash_hmac('sha256', $input, TelegramTokens::jwksBody(), true);
        $token = TelegramTokens::unsigned($header, $claims, TelegramTokens::b64($mac));

        self::assertNull($this->login()->verify($token, self::NONCE));
    }

    public function testABadSignatureIsRefused(): void
    {
        $parts = explode('.', $this->token());
        // A payload that differs from the one that was signed.
        $parts[1] = TelegramTokens::b64((string) json_encode(TelegramTokens::claims(self::NONCE, ['id' => 1])));

        self::assertNull($this->login()->verify(implode('.', $parts), self::NONCE));
    }

    public function testAWrongIssuerIsRefused(): void
    {
        self::assertNull($this->login()->verify($this->token(['iss' => 'https://evil.example']), self::NONCE));
    }

    public function testAWrongAudienceIsRefused(): void
    {
        self::assertNull($this->login()->verify($this->token(['aud' => '987654321']), self::NONCE));
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        self::assertNull($this->login()->verify($this->token(['exp' => time() - 1]), self::NONCE));
    }

    public function testATokenIssuedMoreThanFiveMinutesAgoIsRefused(): void
    {
        self::assertNull($this->login()->verify($this->token(['iat' => time() - 301]), self::NONCE));
        self::assertSame(4242, $this->login()->verify($this->token(['iat' => time() - 290]), self::NONCE));
    }

    public function testAWrongNonceIsRefused(): void
    {
        self::assertNull($this->login()->verify($this->token(), str_repeat('0', 64)));
        self::assertNull($this->login()->verify($this->token(), ''));
    }

    public function testANonceWorksOnlyOnce(): void
    {
        $token = $this->token();

        self::assertSame(4242, $this->login()->verify($token, self::NONCE));
        self::assertNull($this->login()->verify($token, self::NONCE));
        // A second, freshly signed token with the same nonce is refused too.
        self::assertNull($this->login()->verify($this->token(['iat' => time() - 5]), self::NONCE));
    }

    public function testCachedKeysAreUsedWithoutAFetch(): void
    {
        $this->login()->verify($this->token(), self::NONCE);
        self::assertSame(1, $this->fetches);

        $nonce = str_repeat('b', 64);
        self::assertSame(4242, $this->login()->verify(TelegramTokens::token(TelegramTokens::claims($nonce)), $nonce));
        self::assertSame(1, $this->fetches);
    }

    public function testAnUnknownKidRefetchesTheKeysExactlyOnce(): void
    {
        // The cache holds an older key set without this kid.
        $this->transients['eventcrew_tg_jwks'] = json_decode(TelegramTokens::jwksBody('old-key'), true)['keys'];

        self::assertSame(4242, $this->login()->verify($this->token(), self::NONCE));
        self::assertSame(1, $this->fetches);
    }

    public function testAKidTelegramDoesNotHaveIsRefusedAfterOneFetch(): void
    {
        $this->transients['eventcrew_tg_jwks'] = json_decode(TelegramTokens::jwksBody('old-key'), true)['keys'];
        $this->jwksResponse = [200, TelegramTokens::jwksBody('old-key')];

        self::assertNull($this->login()->verify($this->token(), self::NONCE));
        self::assertSame(1, $this->fetches);
    }

    public function testAFailedKeyFetchIsRefused(): void
    {
        $this->jwksResponse = [500, 'Internal Server Error'];

        self::assertNull($this->login()->verify($this->token(), self::NONCE));
        self::assertArrayNotHasKey('eventcrew_tg_jwks', $this->transients);
    }

    public function testAMissingIdIsRefused(): void
    {
        self::assertNull($this->login()->verify($this->token(['id' => null]), self::NONCE));
    }

    public function testANonNumericIdIsRefused(): void
    {
        self::assertNull($this->login()->verify($this->token(['id' => '42abc']), self::NONCE));
    }

    public function testAZeroOrNegativeIdIsRefused(): void
    {
        self::assertNull($this->login()->verify($this->token(['id' => 0]), self::NONCE));
        $nonce = str_repeat('c', 64);
        $token = TelegramTokens::token(TelegramTokens::claims($nonce, ['id' => -5]));
        self::assertNull($this->login()->verify($token, $nonce));
    }

    /**
     * An id too large for PHP must not be rounded into somebody else's id.
     */
    public function testAnIdTooLargeForPhpIsRefused(): void
    {
        $json = str_replace(
            '"id":1,',
            '"id":99999999999999999999999,',
            (string) json_encode(TelegramTokens::claims(self::NONCE, ['id' => 1]))
        );
        $token = TelegramTokens::tokenFromJson($json);

        self::assertNull($this->login()->verify($token, self::NONCE));
    }

    public function testTheTokenNeverAppearsInALogMessage(): void
    {
        $tokens = [
            $this->token(['iss' => 'https://evil.example']),
            $this->token(['aud' => '1']),
            $this->token(['exp' => time() - 1]),
            $this->token(),
        ];

        foreach ($tokens as $token) {
            $this->login()->verify($token, str_repeat('f', 64));
        }

        self::assertNotEmpty($this->logged);

        foreach ($this->logged as $message) {
            foreach ($tokens as $token) {
                foreach (explode('.', $token) as $part) {
                    self::assertStringNotContainsString($part, $message);
                }
            }

            self::assertStringNotContainsString(self::NONCE, $message);
            self::assertStringNotContainsString(TelegramTokens::BOT_TOKEN, $message);
        }
    }
}
