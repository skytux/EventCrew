<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\WebSession;
use EventCrew\Tests\TestCase;

final class WebSessionTest extends TestCase
{
    public function testMintedCookieReadsBackToThePersonId(): void
    {
        $cookie = WebSession::mint(42);

        self::assertSame(42, WebSession::read($cookie));
    }

    public function testAnExpiredCookieIsRejected(): void
    {
        // Minted "now", read from a point past the TTL.
        $cookie = WebSession::mint(42, 1_000);

        self::assertNull(WebSession::read($cookie, 1_000 + WebSession::TTL + 1));
    }

    public function testATamperedCookieIsRejected(): void
    {
        $cookie = WebSession::mint(42);

        // Flip the payload to a different id without re-signing.
        [$body, $signature] = explode('.', $cookie, 2);
        $forged = rtrim(strtr(base64_encode('99|9999999999'), '+/', '-_'), '=') . '.' . $signature;

        self::assertNull(WebSession::read($forged));
    }

    public function testMalformedCookieIsRejected(): void
    {
        self::assertNull(WebSession::read('not-a-cookie'));
        self::assertNull(WebSession::read(''));
    }

    public function testCsrfTokenVerifiesOnlyForItsOwnPerson(): void
    {
        $token = WebSession::csrfToken(42);

        self::assertTrue(WebSession::verifyCsrf(42, $token));
        self::assertFalse(WebSession::verifyCsrf(43, $token));
        self::assertFalse(WebSession::verifyCsrf(42, 'wrong'));
    }
}
