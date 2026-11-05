<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\Logger;
use EventCrew\Support\TelegramLogin;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\TelegramClient;
use EventCrew\Tests\TestCase;

final class TelegramLoginTest extends TestCase
{
    private const BOT_TOKEN = '123456:test-token-for-the-login-tests';

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, mixed> */
    private array $transients = [];

    /** @var array<int, string> every message the Logger wrote */
    private array $logged = [];

    /** How many times get_transient() was called. */
    private int $reads = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [
            TelegramLogin::ENABLED_OPTION => true,
            TelegramClient::TOKEN_OPTION => self::BOT_TOKEN,
            BoardService::USERNAME_OPTION => '@crewbot',
        ];
        $this->transients = [];
        $this->logged = [];
        $this->reads = 0;

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
        Functions\when('get_transient')->alias(function (string $key): mixed {
            $this->reads++;

            return $this->transients[$key] ?? false;
        });
        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->transients[$key] = $value;

            return true;
        });
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);

            return true;
        });
    }

    private function login(): TelegramLogin
    {
        return new TelegramLogin(new Logger());
    }

    /**
     * A fresh code and its browser secret.
     *
     * @return array{code: string, secret: string, match: string}
     */
    private function created(): array
    {
        $made = $this->login()->create('203.0.113.7');
        self::assertNotNull($made);

        return $made;
    }

    /** Moves every stored code's creation time back by $seconds. */
    private function age(int $seconds): void
    {
        foreach ($this->transients as $key => $value) {
            if (is_array($value) && isset($value['created'])) {
                $this->transients[$key]['created'] = (int) $value['created'] - $seconds;
            }
        }
    }

    public function testAvailableOnlyWithTheSwitchTheTokenAndTheUsername(): void
    {
        self::assertTrue(TelegramLogin::isAvailable());

        $this->options[TelegramLogin::ENABLED_OPTION] = false;
        self::assertFalse(TelegramLogin::isAvailable());

        $this->options[TelegramLogin::ENABLED_OPTION] = true;
        $this->options[BoardService::USERNAME_OPTION] = '';
        self::assertFalse(TelegramLogin::isAvailable());

        $this->options[BoardService::USERNAME_OPTION] = 'crewbot';
        $this->options[TelegramClient::TOKEN_OPTION] = '';
        self::assertFalse(TelegramLogin::isAvailable());
    }

    public function testACodeIsUrlSafeAndItsStartPayloadFitsTelegramsLimit(): void
    {
        $codes = [];

        for ($i = 0; $i < 20; $i++) {
            $code = $this->created()['code'];
            $codes[] = $code;
            $payload = TelegramLogin::PAYLOAD_PREFIX . $code;

            self::assertTrue(TelegramLogin::isCode($code));
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,64}$/', $payload);
            self::assertLessThanOrEqual(64, strlen(TelegramLogin::CALLBACK_PREFIX . $code . ':0000'));
            $this->transients = []; // past the rate limit is not this test's business
        }

        self::assertCount(20, array_unique($codes));
        self::assertSame('https://t.me/crewbot?start=login-' . $codes[0], TelegramLogin::startUrl($codes[0]));
    }

    public function testTheStoreHoldsOnlyHashesOfTheCodeAndTheSecret(): void
    {
        ['code' => $code, 'secret' => $secret] = $this->created();

        $stored = serialize($this->transients);
        self::assertStringNotContainsString($code, $stored);
        self::assertStringNotContainsString($secret, $stored);

        $record = $this->transients['eventcrew_tgl_' . hash('sha256', $code)];
        self::assertSame(hash('sha256', $secret), $record['secret_hash']);
        self::assertSame(TelegramLogin::STATE_PENDING, $record['state']);
        self::assertEqualsWithDelta(time(), $record['created'], 2);
    }

    public function testANewCodeHasAFourDigitMatchCodeForThePageAndTheBot(): void
    {
        ['code' => $code, 'match' => $match] = $this->created();

        self::assertMatchesRegularExpression('/^[1-9][0-9]{3}$/', $match);
        self::assertSame($match, $this->login()->matchCode($code));
    }

    public function testAnExpiredOrUnknownCodeHasNoMatchCode(): void
    {
        ['code' => $code] = $this->created();
        $this->age(TelegramLogin::TTL + 1);

        self::assertSame('', $this->login()->matchCode($code));
        self::assertSame('', $this->login()->matchCode('nope'));
    }

    public function testTheBotsThreeChoicesAreTheMatchCodeAndTwoFixedWrongCodes(): void
    {
        ['code' => $code, 'match' => $match] = $this->created();
        $login = $this->login();

        $choices = $login->choices($code);
        self::assertCount(3, $choices);
        self::assertCount(3, array_unique($choices));
        self::assertContains($match, $choices);

        foreach ($choices as $choice) {
            self::assertMatchesRegularExpression('/^[1-9][0-9]{3}$/', $choice);
        }

        $again = $login->choices($code);
        sort($choices);
        sort($again);
        self::assertSame($choices, $again, 'the wrong codes stay the same');

        self::assertSame([], $login->choices('nope'));
        $this->age(TelegramLogin::TTL + 1);
        self::assertSame([], $login->choices($code));
    }

    public function testTheRightChoiceIsAcceptedOnceAndLeavesTheCodePending(): void
    {
        ['code' => $code, 'secret' => $secret, 'match' => $match] = $this->created();
        $login = $this->login();
        $login->claimForTelegramUser($code, 4242);

        self::assertSame(TelegramLogin::CHOICE_RIGHT, $login->choose($code, 4242, $match));
        self::assertSame(TelegramLogin::STATE_PENDING, $login->poll($code, $secret)['state']);
        self::assertSame(TelegramLogin::CHOICE_DONE, $login->choose($code, 4242, $match));
        self::assertTrue($login->approve($code, 4242, 7));
    }

    public function testAWrongChoiceCancelsTheCodeForGood(): void
    {
        ['code' => $code, 'secret' => $secret, 'match' => $match] = $this->created();
        $login = $this->login();
        $wrong = array_values(array_diff($login->choices($code), [$match]))[0];
        $login->claimForTelegramUser($code, 4242);

        self::assertSame(TelegramLogin::CHOICE_WRONG, $login->choose($code, 4242, $wrong));
        self::assertSame(TelegramLogin::CHOICE_DONE, $login->choose($code, 4242, $match));
        self::assertFalse($login->approve($code, 4242, 7));
        self::assertFalse($login->claimForTelegramUser($code, 4242));
        self::assertSame(TelegramLogin::STATE_EXPIRED, $login->poll($code, $secret)['state']);
    }

    public function testOnlyTheTelegramAccountThatOpenedTheCodeMayChoose(): void
    {
        ['code' => $code, 'secret' => $secret, 'match' => $match] = $this->created();
        $login = $this->login();

        self::assertSame(TelegramLogin::CHOICE_GONE, $login->choose($code, 4242, $match), 'nobody opened it yet');

        $login->claimForTelegramUser($code, 4242);
        self::assertSame(TelegramLogin::CHOICE_GONE, $login->choose($code, 6666, '0000'));
        self::assertSame(TelegramLogin::STATE_PENDING, $login->poll($code, $secret)['state'], 'not cancelled');
        self::assertSame(TelegramLogin::CHOICE_GONE, $login->choose('nope', 4242, $match));
    }

    public function testANewCodeIsPending(): void
    {
        ['code' => $code, 'secret' => $secret] = $this->created();

        self::assertSame(
            ['state' => TelegramLogin::STATE_PENDING, 'person_id' => 0],
            $this->login()->poll($code, $secret)
        );
    }

    public function testThePollIsOneTransientRead(): void
    {
        ['code' => $code, 'secret' => $secret] = $this->created();
        $this->reads = 0;

        $this->login()->poll($code, $secret);

        self::assertSame(1, $this->reads);
    }

    public function testAnApprovedCodeSignsInOnceThenIsUsed(): void
    {
        ['code' => $code, 'secret' => $secret] = $this->created();
        $login = $this->login();

        self::assertTrue($login->claimForTelegramUser($code, 4242));
        self::assertTrue($login->approve($code, 4242, 17));

        self::assertSame(['state' => TelegramLogin::STATE_APPROVED, 'person_id' => 17], $login->poll($code, $secret));
        self::assertSame(['state' => TelegramLogin::STATE_EXPIRED, 'person_id' => 0], $login->poll($code, $secret));
        self::assertFalse($login->approve($code, 4242, 17), 'a used code cannot be approved again');
    }

    public function testACodeIsApprovedOnlyOnce(): void
    {
        ['code' => $code] = $this->created();
        $login = $this->login();

        self::assertTrue($login->claimForTelegramUser($code, 4242));
        self::assertTrue($login->approve($code, 4242, 17));
        self::assertFalse($login->approve($code, 4242, 17));
        self::assertFalse($login->claimForTelegramUser($code, 4242), 'an approved code is not opened again');
    }

    public function testAWrongCookieCannotSignIn(): void
    {
        ['code' => $code] = $this->created();
        $login = $this->login();
        $login->claimForTelegramUser($code, 4242);
        $login->approve($code, 4242, 17);

        $expired = ['state' => TelegramLogin::STATE_EXPIRED, 'person_id' => 0];
        self::assertSame($expired, $login->poll($code, bin2hex(random_bytes(32))));
        self::assertSame($expired, $login->poll($code, ''));

        $record = $this->transients['eventcrew_tgl_' . hash('sha256', $code)];
        self::assertSame(TelegramLogin::STATE_APPROVED, $record['state'], 'a wrong cookie does not use the code up');
    }

    public function testAnotherBrowsersCookieCannotSignIn(): void
    {
        ['code' => $mine] = $this->created();
        ['secret' => $theirs] = $this->created();
        $login = $this->login();
        $login->claimForTelegramUser($mine, 4242);
        $login->approve($mine, 4242, 17);

        self::assertSame(TelegramLogin::STATE_EXPIRED, $login->poll($mine, $theirs)['state']);
    }

    public function testOnlyTheTelegramUserWhoOpenedTheCodeCanApproveIt(): void
    {
        ['code' => $code, 'secret' => $secret] = $this->created();
        $login = $this->login();

        self::assertTrue($login->claimForTelegramUser($code, 4242));
        self::assertFalse($login->claimForTelegramUser($code, 9999), 'a second Telegram account is refused');
        self::assertTrue($login->claimForTelegramUser($code, 4242), 'the first one may open it again');

        self::assertFalse($login->approve($code, 9999, 18));
        self::assertSame(TelegramLogin::STATE_PENDING, $login->poll($code, $secret)['state']);

        self::assertTrue($login->approve($code, 4242, 17));
        self::assertSame(17, $login->poll($code, $secret)['person_id']);
    }

    public function testACodeNobodyOpenedInTheBotCannotBeApproved(): void
    {
        ['code' => $code] = $this->created();

        self::assertFalse($this->login()->approve($code, 4242, 17));
    }

    public function testACodeExpiresAfterThirtyMinutes(): void
    {
        ['code' => $code, 'secret' => $secret] = $this->created();
        $login = $this->login();
        $login->claimForTelegramUser($code, 4242);
        $login->approve($code, 4242, 17);

        $this->age(TelegramLogin::TTL + 1);

        self::assertSame(TelegramLogin::STATE_EXPIRED, $login->poll($code, $secret)['state']);
        self::assertSame(30 * 60, TelegramLogin::TTL);
    }

    public function testAnExpiredCodeCannotBeOpenedOrApproved(): void
    {
        ['code' => $code] = $this->created();
        $this->age(TelegramLogin::TTL + 1);

        self::assertFalse($this->login()->claimForTelegramUser($code, 4242));
        self::assertFalse($this->login()->approve($code, 4242, 17));
    }

    public function testAnUnknownOrMalformedCodeIsExpired(): void
    {
        $login = $this->login();

        self::assertSame(TelegramLogin::STATE_EXPIRED, $login->poll(str_repeat('a', 24), 'x')['state']);
        self::assertSame(TelegramLogin::STATE_EXPIRED, $login->poll('../../etc', 'x')['state']);
        self::assertFalse($login->claimForTelegramUser(str_repeat('b', 24), 4242));
        self::assertFalse(TelegramLogin::isCode('short'));
        self::assertFalse(TelegramLogin::isCode(str_repeat('a', 23) . '!'));
    }

    public function testASignUpInTheBotApprovesTheRememberedCodeWhenTheAccountIsLinked(): void
    {
        ['code' => $code, 'secret' => $secret] = $this->created();
        $login = $this->login();

        self::assertTrue($login->claimForTelegramUser($code, 4242));
        $login->rememberForSignUp($code, 4242);

        self::assertFalse($login->approveWaiting(9999, 17), 'nothing waits for another Telegram account');
        self::assertTrue($login->approveWaiting(4242, 17));
        self::assertSame(['state' => TelegramLogin::STATE_APPROVED, 'person_id' => 17], $login->poll($code, $secret));
        self::assertFalse($login->approveWaiting(4242, 17), 'the remembered code is used once');
    }

    public function testASignUpFinishedAfterTheCodeExpiredApprovesNothing(): void
    {
        ['code' => $code, 'secret' => $secret] = $this->created();
        $login = $this->login();
        $login->claimForTelegramUser($code, 4242);
        $login->rememberForSignUp($code, 4242);

        $this->age(TelegramLogin::TTL + 1);

        self::assertFalse($login->approveWaiting(4242, 17));
        self::assertSame(TelegramLogin::STATE_EXPIRED, $login->poll($code, $secret)['state']);
    }

    public function testCodeCreationIsRateLimitedPerAddress(): void
    {
        $login = $this->login();

        for ($i = 0; $i < 10; $i++) {
            self::assertNotNull($login->create('198.51.100.1'), 'attempt ' . ($i + 1));
        }

        self::assertNull($login->create('198.51.100.1'));
        self::assertNotNull($login->create('198.51.100.2'), 'another address is not affected');
        self::assertStringNotContainsString('198.51.100.1', serialize($this->transients), 'the address is stored hashed');
    }

    public function testRefusalsAreLoggedWithoutCodesSecretsOrIds(): void
    {
        ['code' => $code, 'secret' => $secret] = $this->created();
        $login = $this->login();

        $login->claimForTelegramUser($code, 4242);
        $login->claimForTelegramUser($code, 98765);
        $login->approve($code, 98765, 17);
        $login->poll($code, 'wrong-secret');
        $login->poll(str_repeat('z', 24), $secret);
        $match = $login->matchCode($code);
        $login->choose($code, 98765, $match);
        $login->choose($code, 4242, '0000');

        for ($i = 0; $i < 11; $i++) {
            $login->create('192.0.2.55');
        }

        self::assertNotEmpty($this->logged);

        foreach ($this->logged as $message) {
            self::assertStringStartsWith('Telegram sign-in refused:', $message);
            self::assertStringNotContainsString($code, $message);
            self::assertStringNotContainsString($secret, $message);
            self::assertStringNotContainsString($match, $message);
            self::assertStringNotContainsString('0000', $message);
            self::assertStringNotContainsString('98765', $message);
            self::assertStringNotContainsString('192.0.2.55', $message);
            self::assertStringNotContainsString(self::BOT_TOKEN, $message);
        }
    }
}
