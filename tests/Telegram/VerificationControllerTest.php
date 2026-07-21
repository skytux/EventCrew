<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Telegram\VerificationController;

final class VerificationControllerTest extends TelegramTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('esc_html')->returnArg(1);
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
        // consume(): token row, valid and unused.
        $this->wpdb->nextRows[] = [
            'id' => 3,
            'person_id' => 7,
            'expires_at' => '2999-01-01 00:00:00',
            'used_at' => null,
        ];
        // find(): the person, with a Telegram chat to message.
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'email_verified_at' => '2026-07-20 12:00:00',
            'telegram_chat_id' => 555,
        ];

        $confirmed = $this->controller()->verify('rawtoken');

        self::assertTrue($confirmed);
        // Two updates: the token burned, the email marked verified.
        self::assertCount(2, $this->wpdb->updates);
        self::assertContains('sendMessage', $this->calledMethods());
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
}
