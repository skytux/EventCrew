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

    /**
     * The loader is an inline script with no src: it adds api.js (explicit
     * render, with the ready callback) on the first use of a form, holds a
     * submit until the token is there, and has no "&" or "<" in it, which
     * WordPress would mangle inside a block's HTML.
     */
    public function testTheLoaderAddsCloudflaresScriptOnlyWhenAFormIsUsed(): void
    {
        Functions\when('wp_json_encode')->alias(static fn (mixed $data): string => (string) json_encode($data));

        $html = Turnstile::loaderScript();

        self::assertStringStartsWith('<script>', $html);
        self::assertStringEndsWith('</script>', $html);
        self::assertStringNotContainsString(' src=', $html);

        $js = substr($html, strlen('<script>'), -strlen('</script>'));
        self::assertStringNotContainsString('&', $js);
        self::assertStringNotContainsString('<', $js);
        self::assertStringContainsString('turnstile\/v0\/api.js?render=explicit\\u0026onload=eventcrewTsReady', $js);
        self::assertStringContainsString('window.' . Turnstile::READY_CALLBACK . '=function()', $js);
        self::assertStringContainsString('["focusin","pointerdown","touchstart","keydown"]', $js);
        // A submit without a token waits ("Checking…") and is sent again once it is there.
        self::assertStringContainsString('"Checking\\u2026"', $js);
        self::assertStringContainsString('e.preventDefault();e.stopImmediatePropagation();', $js);
        self::assertStringContainsString('f.requestSubmit(b)', $js);
        // Printed twice on a page, only the first copy runs.
        self::assertStringStartsWith('(function(){if(window.eventcrewTs){return;}', $js);
        self::assertSame($html, Turnstile::loaderScript());
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

    /**
     * The empty-token refusal has to say so.
     *
     * It is the likeliest of the lot - a form posted before the widget wrote
     * its hidden field - and it used to return false without logging anything,
     * so an install hitting it every time showed a completely empty
     * Diagnostics. That reads as "the check never ran", which sent the last two
     * releases chasing the wrong cause.
     */
    public function testAMissingTokenSaysSoInTheLog(): void
    {
        $logged = [];

        Functions\when('update_option')->alias(
            static function (string $name, mixed $value) use (&$logged): bool {
                $logged = is_array($value) ? $value : [];

                return true;
            }
        );

        $this->withKeys(true);

        self::assertFalse($this->turnstile()->verify(''));

        $messages = implode(' ', array_map(
            static fn (array $entry): string => (string) ($entry['message'] ?? ''),
            $logged
        ));

        self::assertStringContainsString('no token', $messages);
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
