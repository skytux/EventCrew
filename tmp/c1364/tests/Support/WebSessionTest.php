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

    /**
     * The issue time is what makes "sign out everywhere" possible: it is the
     * only thing distinguishing a cookie minted before a revocation from one
     * minted after.
     */
    public function testACookieCarriesWhenItWasIssued(): void
    {
        $cookie = WebSession::mint(42, 1_700_000_000);

        self::assertSame(1_700_000_000, WebSession::issuedAt($cookie, 1_700_000_000));
    }

    /**
     * Cookies minted before the issue time existed have to keep working, or
     * upgrading would sign out every crew member at once to gain a button none
     * of them has pressed. They answer 0, which is older than any revocation
     * stamp - so the first revocation retires them, exactly as intended.
     */
    public function testALegacyTwoPartCookieStillReadsAndCountsAsAncient(): void
    {
        $body = rtrim(strtr(base64_encode('42|9999999999'), '+/', '-_'), '=');
        $legacy = $body . '.' . hash_hmac('sha256', 'web_session|' . $body, 'eventcrew-test-key');

        self::assertSame(42, WebSession::read($legacy));
        self::assertSame(0, WebSession::issuedAt($legacy));
    }

    public function testAnExpiredLegacyCookieIsStillRejected(): void
    {
        $body = rtrim(strtr(base64_encode('42|1000'), '+/', '-_'), '=');
        $legacy = $body . '.' . hash_hmac('sha256', 'web_session|' . $body, 'eventcrew-test-key');

        self::assertNull(WebSession::read($legacy, 2_000));
    }

    /** A body with the wrong number of fields is not a cookie we minted. */
    public function testACookieWithTooManyPartsIsRejected(): void
    {
        $body = rtrim(strtr(base64_encode('42|1|2|9999999999'), '+/', '-_'), '=');
        $forged = $body . '.' . hash_hmac('sha256', 'web_session|' . $body, 'eventcrew-test-key');

        self::assertNull(WebSession::read($forged));
    }
}
