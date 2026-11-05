<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\Language;
use EventCrew\Telegram\VerificationController;

final class VerificationControllerTest extends TelegramTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('esc_html')->returnArg(1);
        // No Telegram account waiting on the token; OnboardingServiceTest
        // covers the click that links one.
        Functions\when('get_transient')->justReturn(false);
        Functions\when('delete_transient')->justReturn(true);
    }

    private function controller(): VerificationController
    {
        return new VerificationController(
            new AuthTokenRepository(),
            new PersonRepository(),
            $this->client()
        );
    }

    public function testVerifyConfirmsTheEmailAndNudgesTelegram(): void
    {
        // consumeOnce(): token row, valid and unused, and the conditional
        // UPDATE that spends it changes one row.
        $this->wpdb->nextRows[] = [
            'id' => 3,
            'person_id' => 7,
            'expires_at' => '2999-01-01 00:00:00',
            'used_at' => null,
        ];
        $this->wpdb->nextQueryResults[] = 1;
        // find(): the person, with a Telegram chat to message.
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'email_verified_at' => '2026-07-20 12:00:00',
            'telegram_chat_id' => 555,
        ];

        $confirmed = $this->controller()->verify('rawtoken');

        self::assertTrue($confirmed);
        // The token burned by the conditional UPDATE, and the email marked verified.
        self::assertStringContainsString('used_at IS NULL', implode("\n", $this->wpdb->queries));
        self::assertCount(1, $this->wpdb->updates);
        self::assertContains('sendMessage', $this->calledMethods());
    }

    public function testAVerificationLinkOpenedTwiceAtOnceConfirmsOnlyOnce(): void
    {
        $token = ['id' => 3, 'person_id' => 7, 'expires_at' => '2999-01-01 00:00:00', 'used_at' => null];

        // Both requests read the token row while it is still unused.
        $this->wpdb->nextRows[] = $token;
        $this->wpdb->nextQueryResults[] = 1; // the first one's UPDATE changes the row
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'email_verified_at' => null];
        $first = $this->controller()->verify('rawtoken');
        $updatesAfterFirst = count($this->wpdb->updates);

        $this->wpdb->nextRows[] = $token;
        $this->wpdb->nextQueryResults[] = 0; // the second one's UPDATE changes nothing
        $second = $this->controller()->verify('rawtoken');

        self::assertTrue($first);
        self::assertFalse($second);
        // The second request wrote nothing.
        self::assertCount($updatesAfterFirst, $this->wpdb->updates);
    }

    public function testVerifyRejectsABadToken(): void
    {
        // No token row queued -> consume returns null.
        $confirmed = $this->controller()->verify('rawtoken');

        self::assertFalse($confirmed);
        self::assertSame([], $this->wpdb->updates);
        self::assertSame([], $this->telegramCalls);
    }

    public function testRenderPageReflectsSuccessAndFailure(): void
    {
        $ok = $this->controller()->renderPage(true);
        $bad = $this->controller()->renderPage(false);

        self::assertStringStartsWith('<!doctype html>', $ok);
        self::assertStringContainsString('Email confirmed', $ok);
        self::assertStringContainsString('invalid or has expired', $bad);
    }

    public function testThePageDeclaresTheLanguageItIsWrittenIn(): void
    {
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('get_bloginfo')->alias(static fn (string $show = ''): string => 'language' === $show ? 'en-US' : 'Test Site');

        $finnish = Language::run('fi', fn (): string => $this->controller()->renderPage(true));
        $site = $this->controller()->renderPage(true);

        self::assertStringContainsString('<html lang="fi">', $finnish);
        self::assertStringContainsString('<html lang="en-US">', $site);
    }
}
