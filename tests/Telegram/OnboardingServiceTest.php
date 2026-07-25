<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\Logger;
use EventCrew\Telegram\OnboardingService;

final class OnboardingServiceTest extends TelegramTestCase
{
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
            new Logger()
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

    public function testStartReEnablesADisabledAccount(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => 555,
            'disabled_at' => '2026-07-10 09:00:00',
        ];

        $this->service()->start(555, 555, 'Sam');

        // The account is switched back on and no onboarding is started.
        self::assertNull($this->wpdb->updates[0]['data']['disabled_at']);
        self::assertArrayNotHasKey('eventcrew_tg_await_email_555', $this->transients);
    }

    public function testCaptureEmailCreatesThePersonIssuesATokenAndSendsTheLink(): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];

        // findByTelegramUserId -> null, findByEmail -> null (both get_row, unqueued).
        $this->service()->captureEmail(555, 555, 'sam@example.com');

        // One person insert, one auth-token insert.
        self::assertCount(2, $this->wpdb->inserts);
        self::assertSame('sam@example.com', $this->wpdb->inserts[0]['data']['email']);

        // The magic link was emailed and points at the verify endpoint.
        self::assertCount(1, $this->mails);
        self::assertStringContainsString('wp-json/eventcrew/v1/verify?token=', $this->mails[0]['body']);

        // The pending state is cleared and a confirmation message sent.
        self::assertArrayNotHasKey('eventcrew_tg_await_email_555', $this->transients);
        self::assertContains('sendMessage', $this->calledMethods());
    }

    public function testCaptureEmailLinksAnExistingPersonRatherThanDuplicating(): void
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

        // No new person row - just the token insert - and the link update.
        self::assertCount(1, $this->wpdb->inserts);
        self::assertNotSame([], $this->wpdb->updates);
        self::assertCount(1, $this->mails);
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
}
