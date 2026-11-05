<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\MessageTemplates;
use EventCrew\Telegram\OnboardingService;
use EventCrew\Telegram\VerificationController;

final class OnboardingServiceTest extends TelegramTestCase
{
    private const CONFIRMED = 'Your email is confirmed. Tap a task on the board to sign up.';
    private const MOVED_AWAY = 'Your EventCrew account is now linked to another Telegram account.'
        . " Contact an organizer if that wasn't you.";

    /** @var array<string, mixed> */
    private array $transients = [];

    /** @var array<int, array{to: string, subject: string, body: string}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->transients = [];
        $this->mails = [];

        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->transients[$key] = $value;

            return true;
        });
        Functions\when('get_transient')->alias(fn (string $key): mixed => $this->transients[$key] ?? false);
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);

            return true;
        });

        Functions\when('sanitize_email')->alias(static fn (string $email): string => trim($email));
        Functions\when('is_email')->alias(
            static fn (string $email): bool => 1 === preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)
        );
        Functions\when('rest_url')->alias(static fn (string $path = ''): string => 'https://example.test/wp-json/' . $path);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

            return true;
        });
    }

    private function service(): OnboardingService
    {
        return new OnboardingService(
            new PersonRepository(),
            new AuthTokenRepository(),
            $this->client(),
            new Logger(),
            new Mailer(new Logger(), new EmailTemplate(new Logger()))
        );
    }

    public function testStartAsksANewUserForTheirEmailAndRemembersThem(): void
    {
        $this->service()->start(555, 555, 'Sam Volunteer');

        self::assertArrayHasKey('eventcrew_tg_await_email_555', $this->transients);
        self::assertSame(['sendMessage'], $this->calledMethods());
    }

    public function testStartTellsAnAlreadyVerifiedUserTheyAreSetUp(): void
    {
        // findByTelegramUserId resolves a verified person.
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => 555,
        ];

        $this->service()->start(555, 555, 'Sam');

        self::assertArrayNotHasKey('eventcrew_tg_await_email_555', $this->transients);
        self::assertSame(['sendMessage'], $this->calledMethods());
    }

    public function testCaptureEmailCreatesThePersonIssuesATokenAndSendsTheLink(): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];

        // findByTelegramUserId -> null, findByEmail -> null (both get_row, unqueued).
        $this->service()->captureEmail(555, 555, 'sam@example.com');

        // One person insert, one auth-token insert.
        self::assertCount(2, $this->wpdb->inserts);
        self::assertSame('sam@example.com', $this->wpdb->inserts[0]['data']['email']);
        // Created without Telegram ids: those wait for the click.
        self::assertNull($this->wpdb->inserts[0]['data']['telegram_user_id']);
        self::assertNull($this->wpdb->inserts[0]['data']['telegram_chat_id']);

        // The magic link was emailed and points at the verify endpoint.
        self::assertCount(1, $this->mails);
        self::assertStringContainsString('wp-json/eventcrew/v1/verify?token=', $this->mails[0]['body']);

        // The pending state is cleared and a confirmation message sent.
        self::assertArrayNotHasKey('eventcrew_tg_await_email_555', $this->transients);
        self::assertContains('sendMessage', $this->calledMethods());
    }

    public function testCaptureEmailReusesAnExistingPersonRatherThanDuplicating(): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];

        // findByTelegramUserId -> null; findByEmail -> an existing person (id 9).
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextRows[] = [
            'id' => 9,
            'email' => 'sam@example.com',
            'email_verified_at' => null,
            'telegram_user_id' => null,
        ];

        $this->service()->captureEmail(555, 555, 'sam@example.com');

        // No new person row - just the token insert - and nothing linked yet.
        self::assertCount(1, $this->wpdb->inserts);
        self::assertSame([], $this->telegramLinkUpdates());
        self::assertCount(1, $this->mails);
    }

    /**
     * The takeover this guards against: someone types another person's email.
     * Before the click nothing changes on that person; the click links the new
     * account and tells the old chat.
     */
    public function testAnExistingEmailIsLinkedToANewTelegramOnlyAfterTheClickAndTheOldChatIsTold(): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];

        // findByTelegramUserId(555) -> null; findByEmail -> Sam, verified, on Telegram 111.
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextRows[] = $this->sam(111);

        $this->service()->captureEmail(555, 555, 'sam@example.com');

        // Typing the email linked nothing; the account waits on the token.
        self::assertSame([], $this->telegramLinkUpdates());
        self::assertCount(1, $this->pendingLinks());

        $this->clickLink(9, $this->sam(111), null, $this->sam(555));

        self::assertSame(
            [['telegram_user_id' => 555, 'telegram_chat_id' => 555, 'id' => 9]],
            $this->telegramLinkUpdates()
        );
        self::assertSame([], $this->pendingLinks());

        $sent = $this->sentMessages();
        self::assertContains([111, self::MOVED_AWAY], $sent);
        self::assertContains([555, self::CONFIRMED], $sent);
    }

    public function testAnExistingEmailWithNoTelegramIsFilledAfterTheClick(): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextRows[] = $this->sam(null);

        $this->service()->captureEmail(555, 555, 'sam@example.com');
        self::assertSame([], $this->telegramLinkUpdates());

        $this->clickLink(9, $this->sam(null), null, $this->sam(555));

        self::assertSame(
            [['telegram_user_id' => 555, 'telegram_chat_id' => 555, 'id' => 9]],
            $this->telegramLinkUpdates()
        );
        // Nobody to warn: only the "confirmed" nudge in the new chat.
        self::assertSame([[555, self::CONFIRMED]], $this->sentMessages());
    }

    public function testANewEmailIsCreatedWithoutTelegramIdsAndLinkedAfterTheClick(): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];

        // findByTelegramUserId -> null, findByEmail -> null: a new person, id 1.
        $this->service()->captureEmail(555, 555, 'sam@example.com');

        self::assertNull($this->wpdb->inserts[0]['data']['telegram_user_id']);
        self::assertSame([], $this->telegramLinkUpdates());

        $fresh = ['id' => 1, 'email' => 'sam@example.com', 'email_verified_at' => null, 'telegram_user_id' => null];
        $this->clickLink(1, $fresh, null, ['telegram_user_id' => 555, 'telegram_chat_id' => 555] + $fresh);

        self::assertSame(
            [['telegram_user_id' => 555, 'telegram_chat_id' => 555, 'id' => 1]],
            $this->telegramLinkUpdates()
        );
        self::assertSame([[555, self::CONFIRMED]], $this->sentMessages());
    }

    /**
     * @return array<int, array{0: string, 1: ?string}>
     */
    public static function deadTokens(): array
    {
        return [
            'expired' => ['1999-01-01 00:00:00', null],
            'already used' => ['2999-01-01 00:00:00', '2026-07-01 00:00:00'],
        ];
    }

    /**
     * @dataProvider deadTokens
     */
    public function testAnExpiredOrUsedTokenLinksNothing(string $expiresAt, ?string $usedAt): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextRows[] = $this->sam(111);

        $this->service()->captureEmail(555, 555, 'sam@example.com');
        $this->telegramCalls = [];

        $this->wpdb->nextRows[] = ['id' => 3, 'person_id' => 9, 'expires_at' => $expiresAt, 'used_at' => $usedAt];

        self::assertFalse($this->verification()->verify($this->emailedToken()));
        self::assertSame([], $this->telegramLinkUpdates());
        self::assertSame([], $this->telegramCalls);
    }

    public function testTheClickRefusesATelegramAccountAlreadyLinkedToAnotherPerson(): void
    {
        // Telegram 555 typed Sam's email before it was linked anywhere ...
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextRows[] = $this->sam(null);

        $this->service()->captureEmail(555, 555, 'sam@example.com');

        // ... and by the click 555 belongs to Alex (id 12), through another link.
        $alex = [
            'id' => 12,
            'email' => 'alex@example.com',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => 555,
            'telegram_chat_id' => 555,
        ];
        $this->clickLink(9, $this->sam(null), $alex, $this->sam(null));

        // Not spliced: Sam stays unlinked, Alex keeps 555, and 555 is told why.
        self::assertSame([], $this->telegramLinkUpdates());
        self::assertSame(
            [[555, 'This Telegram account is already linked to another email. Contact an organizer if that is wrong.']],
            $this->sentMessages()
        );
    }

    public function testRetypingTheEmailOfThisSameTelegramAccountWarnsNobody(): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];

        // findByTelegramUserId(555) -> Sam himself, so the "another email" check passes.
        $this->wpdb->nextRows[] = $this->sam(555);
        $this->wpdb->nextRows[] = $this->sam(555);

        $this->service()->captureEmail(555, 555, 'sam@example.com');
        self::assertSame([], $this->telegramLinkUpdates());

        $this->clickLink(9, $this->sam(555), $this->sam(555), $this->sam(555));

        // The same link written again; no "linked to another account" notice.
        self::assertSame([[555, self::CONFIRMED]], $this->sentMessages());
    }

    /**
     * Sam, person 9 with a verified email, on the given Telegram account (the
     * private chat id equals the user id) or on none.
     *
     * @return array<string, mixed>
     */
    private function sam(?int $telegram): array
    {
        return [
            'id' => 9,
            'email' => 'sam@example.com',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => $telegram,
            'telegram_chat_id' => $telegram,
        ];
    }

    private function verification(): VerificationController
    {
        return new VerificationController(new AuthTokenRepository(), new PersonRepository(), $this->client());
    }

    /**
     * Clicks the emailed link. The rows are what the database answers, in
     * order: the person before linking, who holds the waiting Telegram id now,
     * and the person after.
     *
     * @param array<string, mixed>      $before
     * @param array<string, mixed>|null $holder
     * @param array<string, mixed>      $after
     */
    private function clickLink(int $personId, array $before, ?array $holder, array $after): void
    {
        $this->telegramCalls = [];
        $this->wpdb->nextRows[] = [
            'id' => 3,
            'person_id' => $personId,
            'expires_at' => '2999-01-01 00:00:00',
            'used_at' => null,
        ];
        $this->wpdb->nextQueryResults[] = 1; // the conditional UPDATE that spends the link
        $this->wpdb->nextRows[] = $before;
        $this->wpdb->nextRows[] = $holder;
        $this->wpdb->nextRows[] = $after;

        self::assertTrue($this->verification()->verify($this->emailedToken()));
    }

    private function emailedToken(): string
    {
        self::assertSame(1, preg_match('/verify\?token=([0-9a-f]{64})/', $this->mails[0]['body'] ?? '', $match));

        return $match[1];
    }

    /**
     * Every write of Telegram ids onto a person, as data plus the row id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function telegramLinkUpdates(): array
    {
        $links = [];

        foreach ($this->wpdb->updates as $update) {
            if (array_key_exists('telegram_user_id', $update['data'])) {
                $links[] = [
                    'telegram_user_id' => $update['data']['telegram_user_id'],
                    'telegram_chat_id' => $update['data']['telegram_chat_id'] ?? null,
                ] + $update['where'];
            }
        }

        return $links;
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingLinks(): array
    {
        return array_filter(
            $this->transients,
            static fn (string $key): bool => str_starts_with($key, 'eventcrew_tg_pending_link_'),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * @return array<int, array{0: mixed, 1: mixed}>
     */
    private function sentMessages(): array
    {
        $sent = [];

        foreach ($this->telegramCalls as $call) {
            if ('sendMessage' === $call['method']) {
                $sent[] = [$call['body']['chat_id'] ?? null, $call['body']['text'] ?? null];
            }
        }

        return $sent;
    }

    public function testCaptureEmailRejectsAnInvalidAddressAndKeepsWaiting(): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];

        $this->service()->captureEmail(555, 555, 'not-an-email');

        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->mails);
        // Still waiting for a better answer.
        self::assertArrayHasKey('eventcrew_tg_await_email_555', $this->transients);
    }

    public function testCaptureEmailIgnoresAMessageWhenNotAwaitingOne(): void
    {
        $this->service()->captureEmail(555, 555, 'sam@example.com');

        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->mails);
        self::assertSame([], $this->telegramCalls);
    }

    public function testCaptureEmailRefusesWhenTheTelegramIdIsLinkedToAnotherEmail(): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];

        // findByTelegramUserId -> a person whose email differs from the one given.
        $this->wpdb->nextRows[] = [
            'id' => 9,
            'email' => 'someone.else@example.com',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => 555,
        ];

        $this->service()->captureEmail(555, 555, 'sam@example.com');

        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->mails);
        self::assertArrayNotHasKey('eventcrew_tg_await_email_555', $this->transients);
    }
    public function testStartOffersThePrivacyNoticeAsANamedButton(): void
    {
        $this->options['wp_page_for_privacy_policy'] = 12;
        Functions\when('get_post_status')->justReturn('publish');
        Functions\when('get_permalink')->justReturn('https://example.test/privacy/');

        $this->service()->start(555, 555, 'Sam');

        $message = $this->lastCallTo('sendMessage');
        self::assertStringNotContainsString('https://example.test/privacy/', (string) $message['text']);
        self::assertSame('https://example.test/privacy/', $message['reply_markup']['inline_keyboard'][0][0]['url']);
    }

    public function testTheVerificationEmailNamesItsLink(): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];

        $this->service()->captureEmail(555, 555, 'sam@example.com');

        // Sent through the Mailer as HTML, so the link sits behind a named
        // button; the plain-text alternative alone carries the address.
        self::assertStringContainsString('Confirm your email', $this->mails[0]['body']);
        self::assertStringContainsString('<a ', $this->mails[0]['body']);
    }

    // ---- The Messages page template (Q297) --------------------------------

    /**
     * The confirmation email to sam@example.com as plain text, its link tokens
     * replaced by TOKEN.
     *
     * @param string $name The name the Telegram account has ('' for none).
     */
    private function confirmationEmail(string $name): array
    {
        $this->options[EmailTemplate::HTML_OPTION] = false;
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => $name];

        $this->service()->captureEmail(555, 555, 'sam@example.com');

        $mail = $this->mails[0];
        $mail['body'] = (string) preg_replace('/\?token=[^\s]+/', '?token=TOKEN', $mail['body']);

        return $mail;
    }

    /** With nothing stored, the email is what it was before the template. */
    public function testTheConfirmationEmailIsTheSameAsBeforeTheTemplate(): void
    {
        $mail = $this->confirmationEmail('Sam');

        self::assertSame('Confirm your email for EventCrew', $mail['subject']);
        self::assertSame(
            "Hi Sam,\n\nConfirm your email to finish signing up for EventCrew tasks.\n\nThe link works once and"
                . " expires in a day. If you didn't ask for this, you can ignore this email.\n\n"
                . "We store your email to run the crew.\n\n"
                . "Confirm your email: https://example.test/wp-json/eventcrew/v1/verify?token=TOKEN\n\n"
                . '—' . "\n" . 'Manage your account or stop these emails: '
                . 'https://example.test/wp-json/eventcrew/v1/manage?token=TOKEN',
            $mail['body']
        );
    }

    /** A text saved on the Messages page is sent; the link button is added all the same. */
    public function testAStoredConfirmationTextIsSentAndKeepsTheLink(): void
    {
        $stored = static fn (string $text): array => [
            'source' => $text,
            'texts' => ['fi' => $text, 'en' => $text],
            'manual' => ['fi' => false, 'en' => false],
        ];
        $this->options[MessageTemplates::OPTION_PREFIX . 'email_confirm'] = [
            MessageTemplates::SUBJECT => $stored('Welcome {name}'),
            MessageTemplates::BODY => $stored('Tap below, {name}.'),
        ];

        $mail = $this->confirmationEmail('');

        self::assertSame('Welcome sam@example.com', $mail['subject'], 'no Telegram name: the email address');
        self::assertStringStartsWith(
            "Tap below, sam@example.com.\n\nConfirm your email: "
            . 'https://example.test/wp-json/eventcrew/v1/verify?token=TOKEN',
            $mail['body']
        );
    }
}
