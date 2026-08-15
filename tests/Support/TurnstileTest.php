<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\Logger;
use EventCrew\Support\Turnstile;
use EventCrew\Tests\TestCase;

/**
 * The CAPTCHA gate. Light coverage of the three outcomes that matter:
 * unconfigured lets everyone through, a solved token passes, an empty or
 * rejected token is blocked.
 */
final class TurnstileTest extends TestCase
{
    private function turnstile(): Turnstile
    {
        return new Turnstile(new Logger());
    }

    /**
     * @param array<string, mixed> $verifyBody the JSON Cloudflare replies with
     */
    private function withKeys(bool $configured, array $verifyBody = []): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name): mixed => match ($name) {
                Turnstile::SITE_KEY_OPTION => $configured ? 'site-key' : '',
                Turnstile::SECRET_KEY_OPTION => $configured ? 'secret-key' : '',
                default => false,
            }
        );

        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_body')->justReturn((string) json_encode($verifyBody));
        Functions\when('wp_remote_post')->justReturn(['body' => '']);
    }

    public function testDisabledGateWavesEveryoneThrough(): void
    {
        $this->withKeys(false);

        self::assertFalse($this->turnstile()->isEnabled());
        self::assertTrue($this->turnstile()->verify('')); // no token needed when off
    }

    public function testAnEmptyTokenIsRejectedWhenEnabled(): void
    {
        $this->withKeys(true);

        self::assertTrue($this->turnstile()->isEnabled());
        self::assertFalse($this->turnstile()->verify(''));
    }

    public function testASolvedTokenPasses(): void
    {
        $this->withKeys(true, ['success' => true]);

        self::assertTrue($this->turnstile()->verify('good-token'));
    }

    public function testARejectedTokenIsBlocked(): void
    {
        $this->withKeys(true, ['success' => false, 'error-codes' => ['invalid-input-response']]);

        self::assertFalse($this->turnstile()->verify('bad-token'));
    }

    /**
     * Cloudflare says why it refused, and that reason has to reach Diagnostics.
     *
     * Without it the only evidence of a refusal is "couldn't verify you're
     * human" on the visitor's screen, which is the same message whatever went
     * wrong - so a stale token, a spent one and a mistyped secret key all look
     * identical to the organizer trying to work out why nobody can sign in.
     */
    public function testARefusalLogsCloudflaresReason(): void
    {
        $logged = [];

        Functions\when('update_option')->alias(
            static function (string $name, mixed $value) use (&$logged): bool {
                $logged = is_array($value) ? $value : [];

                return true;
            }
        );

        $this->withKeys(true, ['success' => false, 'error-codes' => ['timeout-or-duplicate']]);

        self::assertFalse($this->turnstile()->verify('stale-token'));

        $messages = implode(' ', array_map(
            static fn (array $entry): string => (string) ($entry['message'] ?? ''),
            $logged
        ));

        self::assertStringContainsString('timeout-or-duplicate', $messages);
    }
}
