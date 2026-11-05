<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\TelegramLogin;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\OnboardingService;
use EventCrew\Telegram\UpdateRouter;
use EventCrew\Telegram\VerificationController;
use EventCrew\Telegram\WebLoginBot;

/**
 * The bot's half of signing in to the Crew app: "/start login-<code>" in the
 * private chat, the "Sign in to the crew app" button, and the sign-up path
 * that approves the code when the confirmation link links the account.
 * Driven through the real router, so the routing is under test too.
 */
final class WebLoginBotTest extends TelegramTestCase
{
    private const EXPIRED = 'This sign-in link has expired. Press Telegram on the crew app again.';
    private const SIGNED_IN = 'Signed in. You can go back to the crew app.';
    private const CANCELLED = 'That is not the code on the crew app, so this sign-in is cancelled.'
        . ' Press Telegram on the crew app to start again.';

    /** @var array<string, mixed> */
    private array $transients = [];

    /** @var array<int, string> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->transients = [];
        $this->mails = [];
        $this->options[TelegramLogin::ENABLED_OPTION] = true;
        $this->options[BoardService::USERNAME_OPTION] = 'crewbot';

        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->transients[$key] = $value;

            return true;
        });
        Functions\when('get_transient')->alias(fn (string $key): mixed => $this->transients[$key] ?? false);
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);

            return true;
        });
        Functions\when('get_the_title')->justReturn('');
        Functions\when('esc_html')->returnArg(1);
        Functions\when('sanitize_email')->alias(static fn (string $email): string => trim($email));
        Functions\when('is_email')->alias(
            static fn (string $email): bool => 1 === preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)
        );
        Functions\when('rest_url')->alias(static fn (string $path = ''): string => 'https://example.test/wp-json/' . $path);
        Functions\when('admin_url')->alias(static fn (string $path = ''): string => 'https://example.test/wp-admin/' . $path);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = $body;

            return true;
        });
    }

    private function login(): TelegramLogin
    {
        return new TelegramLogin(new Logger());
    }

    private function bot(): WebLoginBot
    {
        $mailer = new Mailer(new Logger(), new EmailTemplate(new Logger()));

        return new WebLoginBot(
            $this->login(),
            new PersonRepository(),
            new OnboardingService(new PersonRepository(), new AuthTokenRepository(), $this->client(), new Logger(), $mailer),
            $this->client(),
            new Logger()
        );
    }

    private function router(): UpdateRouter
    {
        return $this->updateRouter(null, null, null, $this->bot());
    }

    /**
     * A code the page made, and its browser secret.
     *
     * @return array{code: string, secret: string, match: string}
     */
    private function pageCode(): array
    {
        $made = $this->login()->create('203.0.113.7');
        self::assertNotNull($made);

        return $made;
    }

    private function sendStart(int $telegramUserId, string $payload, string $chatType = 'private'): void
    {
        $this->router()->dispatch([
            'message' => [
                'text' => '/start ' . $payload,
                'chat' => ['id' => 'private' === $chatType ? $telegramUserId : -100, 'type' => $chatType],
                'from' => ['id' => $telegramUserId, 'first_name' => 'Sam'],
            ],
        ]);
    }

    /**
     * Presses a code button. $choice null presses the right code (the one the
     * page shows); otherwise the given 4 digits.
     */
    private function pressButton(int $telegramUserId, string $code, ?string $choice = null): void
    {
        $choice ??= (string) $this->storedCode($code)['match'];

        $this->router()->dispatch([
            'callback_query' => [
                'id' => 'cbq',
                'from' => ['id' => $telegramUserId, 'first_name' => 'Sam'],
                'message' => ['message_id' => 31, 'chat' => ['id' => $telegramUserId]],
                'data' => TelegramLogin::CALLBACK_PREFIX . $code . ':' . $choice,
            ],
        ]);
    }

    /** One of the two wrong codes stored with the code. */
    private function wrongChoice(string $code): string
    {
        return (string) $this->storedCode($code)['decoys'][0];
    }

    /**
     * The texts of all messages the bot sent.
     *
     * @return list<string>
     */
    private function sentTexts(): array
    {
        return array_values(array_map(
            static fn (array $call): string => (string) ($call['body']['text'] ?? ''),
            array_filter($this->telegramCalls, static fn (array $call): bool => 'sendMessage' === $call['method'])
        ));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function person(array $overrides = []): array
    {
        return $overrides + [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => 4242,
            'telegram_chat_id' => 4242,
        ];
    }

    /** The text of the last message the bot sent. */
    private function lastText(): string
    {
        return (string) ($this->lastCallTo('sendMessage')['text'] ?? '');
    }

    public function testALinkedPersonGetsThreeCodeButtonsOneOfThemThePagesCode(): void
    {
        ['code' => $code, 'match' => $match] = $this->pageCode();
        $this->wpdb->nextRows[] = $this->person();

        $this->sendStart(4242, 'login-' . $code);

        $sent = $this->lastCallTo('sendMessage');
        self::assertNotNull($sent);
        self::assertSame(4242, $sent['chat_id']);
        self::assertSame(
            'To sign in to the crew app, press the code that the crew app on your screen shows. If you did not'
            . ' just press Telegram on the crew app yourself, press nothing.',
            $sent['text']
        );
        self::assertStringNotContainsString($match, $sent['text'], 'the text does not say which code is right');

        $rows = $sent['reply_markup']['inline_keyboard'];
        self::assertCount(1, $rows);
        self::assertCount(3, $rows[0]);

        $shown = array_column($rows[0], 'text');
        self::assertContains($match, $shown);
        self::assertCount(3, array_unique($shown), 'three different codes');
        $decoys = $this->storedCode($code)['decoys'];
        $wrong = array_values(array_diff($shown, [$match]));
        sort($decoys);
        sort($wrong);
        self::assertSame(
            $decoys,
            $wrong,
            'the two wrong codes are the stored ones'
        );

        foreach ($rows[0] as $button) {
            self::assertMatchesRegularExpression('/^[0-9]{4}$/', $button['text']);
            self::assertSame('tgl:' . $code . ':' . $button['text'], $button['callback_data']);
            self::assertLessThanOrEqual(64, strlen($button['callback_data']), 'Telegram allows 64 bytes');
        }
    }

    public function testOpeningTheLinkAgainShowsTheSameThreeCodes(): void
    {
        ['code' => $code] = $this->pageCode();
        $this->wpdb->nextRows[] = $this->person();
        $this->sendStart(4242, 'login-' . $code);
        $first = array_column($this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'][0], 'text');

        $this->telegramCalls = [];
        $this->wpdb->nextRows[] = $this->person();
        $this->sendStart(4242, 'login-' . $code);
        $second = array_column($this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'][0], 'text');

        sort($first);
        sort($second);
        self::assertSame($first, $second, 'a second look must not show which code both sets share');
    }

    public function testPressingTheButtonApprovesTheCodeAndThePageSignsIn(): void
    {
        ['code' => $code, 'secret' => $secret] = $this->pageCode();
        $this->wpdb->nextRows[] = $this->person();
        $this->sendStart(4242, 'login-' . $code);

        $this->telegramCalls = [];
        $this->wpdb->nextRows[] = $this->person();
        $this->pressButton(4242, $code);

        self::assertSame(['answerCallbackQuery', 'editMessageText'], $this->calledMethods());
        self::assertSame(self::SIGNED_IN, $this->lastCallTo('editMessageText')['text']);
        self::assertSame(
            [[[
                'text' => '✅ Back to the crew app',
                'url' => 'https://example.test/wp-admin/admin-ajax.php?action=eventcrew_web_tg_return&login=' . $code,
            ]]],
            $this->lastCallTo('editMessageText')['reply_markup']['inline_keyboard']
        );
        self::assertSame(['state' => TelegramLogin::STATE_APPROVED, 'person_id' => 7], $this->login()->poll($code, $secret));
    }

    public function testAButtonPressedByAnotherTelegramUserApprovesNothing(): void
    {
        ['code' => $code, 'secret' => $secret] = $this->pageCode();
        $this->wpdb->nextRows[] = $this->person();
        $this->sendStart(4242, 'login-' . $code);

        // Someone else got hold of the button (a forwarded message) and has
        // their own crew account.
        $this->telegramCalls = [];
        $this->wpdb->nextRows[] = $this->person(['id' => 8, 'email' => 'eve@example.com', 'telegram_user_id' => 6666]);
        $this->pressButton(6666, $code);

        self::assertSame(self::EXPIRED, $this->lastCallTo('answerCallbackQuery')['text']);
        self::assertSame(TelegramLogin::STATE_PENDING, $this->login()->poll($code, $secret)['state']);
    }

    public function testASecondTelegramUserOpeningTheSameLinkIsToldItExpired(): void
    {
        ['code' => $code] = $this->pageCode();
        $this->wpdb->nextRows[] = $this->person();
        $this->sendStart(4242, 'login-' . $code);

        $this->telegramCalls = [];
        $this->sendStart(6666, 'login-' . $code);

        self::assertSame(self::EXPIRED, $this->lastText());
        self::assertNull($this->lastCallTo('editMessageText'));
    }

    public function testAPressAfterTheRightCodeDoesNothing(): void
    {
        ['code' => $code, 'secret' => $secret] = $this->pageCode();
        $this->wpdb->nextRows[] = $this->person();
        $this->sendStart(4242, 'login-' . $code);
        $this->wpdb->nextRows[] = $this->person();
        $this->pressButton(4242, $code);

        // The right code again, and then a wrong one: neither changes anything.
        foreach ([null, $this->wrongChoice($code)] as $choice) {
            $this->telegramCalls = [];
            $this->wpdb->nextRows[] = $this->person();
            $this->pressButton(4242, $code, $choice);

            self::assertSame(['answerCallbackQuery'], $this->calledMethods());
            self::assertSame('', $this->lastCallTo('answerCallbackQuery')['text'], 'no message');
            self::assertSame(TelegramLogin::STATE_APPROVED, $this->storedCode($code)['state']);
        }

        self::assertSame(
            ['state' => TelegramLogin::STATE_APPROVED, 'person_id' => 7],
            $this->login()->poll($code, $secret)
        );
    }

    public function testAWrongCodeCancelsTheSignIn(): void
    {
        ['code' => $code, 'secret' => $secret] = $this->pageCode();
        $this->wpdb->nextRows[] = $this->person();
        $this->sendStart(4242, 'login-' . $code);

        $this->telegramCalls = [];
        $this->wpdb->nextRows[] = $this->person();
        $this->pressButton(4242, $code, $this->wrongChoice($code));

        self::assertSame(['answerCallbackQuery', 'editMessageText'], $this->calledMethods());
        self::assertSame(self::CANCELLED, $this->lastCallTo('answerCallbackQuery')['text']);
        self::assertSame(self::CANCELLED, $this->lastCallTo('editMessageText')['text']);
        self::assertNull($this->lastCallTo('editMessageText')['reply_markup'] ?? null, 'the code buttons are gone');
        self::assertSame(TelegramLogin::STATE_CANCELLED, $this->storedCode($code)['state']);
        self::assertSame(TelegramLogin::STATE_EXPIRED, $this->login()->poll($code, $secret)['state']);
    }

    public function testAPressAfterAWrongCodeDoesNothing(): void
    {
        ['code' => $code, 'secret' => $secret] = $this->pageCode();
        $this->wpdb->nextRows[] = $this->person();
        $this->sendStart(4242, 'login-' . $code);
        $this->wpdb->nextRows[] = $this->person();
        $this->pressButton(4242, $code, $this->wrongChoice($code));

        // Now the right code, and a wrong one again: the sign-in stays cancelled.
        foreach ([null, $this->wrongChoice($code)] as $choice) {
            $this->telegramCalls = [];
            $this->wpdb->nextRows[] = $this->person();
            $this->pressButton(4242, $code, $choice);

            self::assertSame(['answerCallbackQuery'], $this->calledMethods());
            self::assertSame('', $this->lastCallTo('answerCallbackQuery')['text'], 'no message');
            self::assertSame(TelegramLogin::STATE_CANCELLED, $this->storedCode($code)['state']);
        }

        self::assertSame(TelegramLogin::STATE_EXPIRED, $this->login()->poll($code, $secret)['state']);
    }

    public function testAWrongCodeFromSomeoneWithoutAnAccountStartsNoSignUp(): void
    {
        ['code' => $code, 'secret' => $secret] = $this->pageCode();
        $this->sendStart(4242, 'login-' . $code);

        $this->telegramCalls = [];
        $this->pressButton(4242, $code, $this->wrongChoice($code));

        self::assertSame(self::CANCELLED, $this->lastCallTo('editMessageText')['text']);
        self::assertSame([], $this->sentTexts());
        self::assertArrayNotHasKey('eventcrew_tg_await_email_4242', $this->transients);
        self::assertArrayNotHasKey('eventcrew_tgl_wait_4242', $this->transients);
        self::assertSame(TelegramLogin::STATE_EXPIRED, $this->login()->poll($code, $secret)['state']);
    }

    public function testAnOldSignInButtonWithoutACodeIsExpiredAndChangesNothing(): void
    {
        ['code' => $code, 'secret' => $secret] = $this->pageCode();
        $this->wpdb->nextRows[] = $this->person();
        $this->sendStart(4242, 'login-' . $code);

        $this->telegramCalls = [];
        $this->wpdb->nextRows[] = $this->person();
        $this->router()->dispatch([
            'callback_query' => [
                'id' => 'cbq',
                'from' => ['id' => 4242],
                'message' => ['message_id' => 31, 'chat' => ['id' => 4242]],
                'data' => TelegramLogin::CALLBACK_PREFIX . $code,
            ],
        ]);

        self::assertSame(self::EXPIRED, $this->lastCallTo('answerCallbackQuery')['text']);
        self::assertSame(TelegramLogin::STATE_PENDING, $this->login()->poll($code, $secret)['state']);
    }

    public function testTheCodesNeverReachTheLog(): void
    {
        ['code' => $code] = $this->pageCode();
        $stored = $this->storedCode($code);
        $this->wpdb->nextRows[] = $this->person();
        $this->sendStart(4242, 'login-' . $code);
        $this->wpdb->nextRows[] = $this->person();
        $this->pressButton(4242, $code, $this->wrongChoice($code));
        $this->wpdb->nextRows[] = $this->person();
        $this->pressButton(4242, $code);

        $log = serialize($this->options['eventcrew_recent_logs'] ?? []);
        self::assertStringContainsString('wrong code', $log, 'the wrong press is logged');
        self::assertStringNotContainsString($code, $log);
        self::assertStringNotContainsString((string) $stored['match'], $log);
        self::assertStringNotContainsString((string) $stored['decoys'][0], $log);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function deadPayloads(): array
    {
        return [
            'unknown code' => ['login-' . str_repeat('a', 24)],
            'malformed code' => ['login-nope'],
            'empty code' => ['login-'],
        ];
    }

    /**
     * @dataProvider deadPayloads
     */
    public function testAnUnknownCodeGetsTheExpiredMessage(string $payload): void
    {
        $this->sendStart(4242, $payload);

        self::assertSame(['sendMessage'], $this->calledMethods());
        self::assertSame(self::EXPIRED, $this->lastText());
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testAnExpiredCodeGetsTheExpiredMessage(): void
    {
        ['code' => $code] = $this->pageCode();

        foreach ($this->transients as $key => $value) {
            if (is_array($value) && isset($value['created'])) {
                $this->transients[$key]['created'] -= TelegramLogin::TTL + 1;
            }
        }

        $this->wpdb->nextRows[] = $this->person();
        $this->sendStart(4242, 'login-' . $code);

        self::assertSame(self::EXPIRED, $this->lastText());
    }

    public function testAUsedCodeGetsTheExpiredMessage(): void
    {
        ['code' => $code, 'secret' => $secret] = $this->pageCode();
        $login = $this->login();
        $login->claimForTelegramUser($code, 4242);
        $login->approve($code, 4242, 7);
        $login->poll($code, $secret);

        $this->wpdb->nextRows[] = $this->person();
        $this->sendStart(4242, 'login-' . $code);

        self::assertSame(self::EXPIRED, $this->lastText());
    }

    public function testTheSignInSwitchedOffGetsTheExpiredMessage(): void
    {
        ['code' => $code] = $this->pageCode();
        $this->options[TelegramLogin::ENABLED_OPTION] = false;

        $this->sendStart(4242, 'login-' . $code);

        self::assertSame(self::EXPIRED, $this->lastText());
    }

    public function testABlockedPersonIsRefused(): void
    {
        ['code' => $code, 'secret' => $secret] = $this->pageCode();
        $this->wpdb->nextRows[] = $this->person(['banned_at' => '2026-08-01 00:00:00']);

        $this->sendStart(4242, 'login-' . $code);

        self::assertSame('Your account is blocked. Talk to an organizer.', $this->lastText());
        self::assertNull($this->lastCallTo('sendMessage')['reply_markup'] ?? null);

        $this->telegramCalls = [];
        $this->wpdb->nextRows[] = $this->person(['banned_at' => '2026-08-01 00:00:00']);
        $this->pressButton(4242, $code);

        self::assertSame('Your account is blocked. Talk to an organizer.', $this->lastCallTo('answerCallbackQuery')['text']);
        self::assertSame(TelegramLogin::STATE_PENDING, $this->login()->poll($code, $secret)['state']);
    }

    public function testInAGroupTheLoginLinkIsTheNormalNudge(): void
    {
        ['code' => $code, 'secret' => $secret] = $this->pageCode();

        $this->sendStart(4242, 'login-' . $code, 'supergroup');

        self::assertNotSame(self::EXPIRED, $this->lastText());
        self::assertSame(0, $this->storedCode($code)['telegram_user_id'], 'a group /start does not claim the code');
        self::assertSame(TelegramLogin::STATE_PENDING, $this->login()->poll($code, $secret)['state']);
    }

    public function testAPersonWithoutAnAccountSignsUpAndThePageSignsInWhenTheLinkIsClicked(): void
    {
        ['code' => $code, 'secret' => $secret] = $this->pageCode();

        // No crew account for Telegram user 4242: the bot shows the three
        // code buttons first; the sign-up starts only after the right press.
        $this->sendStart(4242, 'login-' . $code);

        self::assertCount(1, $this->sentTexts());
        self::assertCount(3, $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'][0]);
        self::assertArrayNotHasKey('eventcrew_tg_await_email_4242', $this->transients);

        $this->telegramCalls = [];
        $this->pressButton(4242, $code);

        self::assertSame('The code is right.', $this->lastCallTo('editMessageText')['text']);
        self::assertArrayHasKey('eventcrew_tg_await_email_4242', $this->transients);
        self::assertStringContainsString("What's your email address?", $this->lastText());
        self::assertSame(TelegramLogin::STATE_PENDING, $this->login()->poll($code, $secret)['state']);

        // They answer with their email; the bot mails the confirmation link.
        $this->router()->dispatch([
            'message' => [
                'text' => 'sam@example.com',
                'chat' => ['id' => 4242, 'type' => 'private'],
                'from' => ['id' => 4242, 'first_name' => 'Sam'],
            ],
        ]);
        self::assertCount(1, $this->mails);
        self::assertSame(1, preg_match('/verify\?token=([0-9a-f]{64})/', $this->mails[0], $match));

        // The click: token row, the person before linking, nobody else holding
        // the Telegram id, and the person after (confirmed and linked).
        $this->wpdb->nextRows[] = ['id' => 3, 'person_id' => 7, 'expires_at' => '2999-01-01 00:00:00', 'used_at' => null];
        $this->wpdb->nextQueryResults[] = 1; // the conditional UPDATE that spends the link
        $this->wpdb->nextRows[] = $this->person(['email_verified_at' => null, 'telegram_user_id' => null, 'telegram_chat_id' => null]);
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextRows[] = $this->person();

        $verification = new VerificationController(
            new AuthTokenRepository(),
            new PersonRepository(),
            $this->client(),
            $this->login()
        );
        self::assertTrue($verification->verify($match[1]));

        self::assertSame(['state' => TelegramLogin::STATE_APPROVED, 'person_id' => 7], $this->login()->poll($code, $secret));
    }

    public function testASignUpFinishedAfterTheCodeExpiredSignsNothingIn(): void
    {
        ['code' => $code, 'secret' => $secret] = $this->pageCode();
        $this->sendStart(4242, 'login-' . $code);
        $this->pressButton(4242, $code);
        $this->router()->dispatch([
            'message' => [
                'text' => 'sam@example.com',
                'chat' => ['id' => 4242, 'type' => 'private'],
                'from' => ['id' => 4242, 'first_name' => 'Sam'],
            ],
        ]);
        self::assertSame(1, preg_match('/verify\?token=([0-9a-f]{64})/', $this->mails[0] ?? '', $match));

        foreach ($this->transients as $key => $value) {
            if (is_array($value) && isset($value['created'])) {
                $this->transients[$key]['created'] -= TelegramLogin::TTL + 1;
            }
        }

        $this->wpdb->nextRows[] = ['id' => 3, 'person_id' => 7, 'expires_at' => '2999-01-01 00:00:00', 'used_at' => null];
        $this->wpdb->nextQueryResults[] = 1; // the conditional UPDATE that spends the link
        $this->wpdb->nextRows[] = $this->person(['email_verified_at' => null, 'telegram_user_id' => null, 'telegram_chat_id' => null]);
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextRows[] = $this->person();

        $verification = new VerificationController(
            new AuthTokenRepository(),
            new PersonRepository(),
            $this->client(),
            $this->login()
        );
        self::assertTrue($verification->verify($match[1]), 'the email is still confirmed');
        self::assertSame(TelegramLogin::STATE_EXPIRED, $this->login()->poll($code, $secret)['state']);
    }

    public function testAClickWithNoWaitingCodeApprovesNothing(): void
    {
        ['code' => $code, 'secret' => $secret] = $this->pageCode();
        $this->login()->claimForTelegramUser($code, 4242);

        // A pending link for 4242, but the code was never remembered for it.
        $token = str_repeat('c', 64);
        $this->transients['eventcrew_tg_pending_link_' . hash('sha256', $token)] = [
            'telegram_user_id' => 4242,
            'telegram_chat_id' => 4242,
        ];
        $this->wpdb->nextRows[] = ['id' => 3, 'person_id' => 7, 'expires_at' => '2999-01-01 00:00:00', 'used_at' => null];
        $this->wpdb->nextQueryResults[] = 1; // the conditional UPDATE that spends the link
        $this->wpdb->nextRows[] = $this->person(['email_verified_at' => null, 'telegram_user_id' => null]);
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextRows[] = $this->person();

        $verification = new VerificationController(
            new AuthTokenRepository(),
            new PersonRepository(),
            $this->client(),
            $this->login()
        );
        self::assertTrue($verification->verify($token));
        self::assertSame(TelegramLogin::STATE_PENDING, $this->login()->poll($code, $secret)['state']);
    }

    public function testTheBotsMessagesNeverCarryTheBrowserSecret(): void
    {
        ['code' => $code, 'secret' => $secret] = $this->pageCode();
        $this->wpdb->nextRows[] = $this->person();
        $this->sendStart(4242, 'login-' . $code);
        $this->wpdb->nextRows[] = $this->person();
        $this->pressButton(4242, $code);

        self::assertStringNotContainsString($secret, (string) json_encode($this->telegramCalls));
    }

    /**
     * The stored record of a code.
     *
     * @return array<string, mixed>
     */
    private function storedCode(string $code): array
    {
        $record = $this->transients['eventcrew_tgl_' . hash('sha256', $code)] ?? null;
        self::assertIsArray($record);

        return $record;
    }
}
