<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\SignedLink;
use EventCrew\Tests\TestCase;

final class SignedLinkTest extends TestCase
{
    public function testRoundTripsAnId(): void
    {
        $token = SignedLink::sign('ticket', 42);

        self::assertSame(42, SignedLink::verify('ticket', $token));
    }

    public function testRejectsAWrongPurpose(): void
    {
        $token = SignedLink::sign('ticket', 42);

        // A ticket link must never work as a manage/delete link.
        self::assertNull(SignedLink::verify('manage', $token));
    }

    public function testRejectsATamperedToken(): void
    {
        $token = SignedLink::sign('ticket', 42);
        $tampered = $token . 'x';

        self::assertNull(SignedLink::verify('ticket', $tampered));
    }

    public function testRejectsGarbage(): void
    {
        self::assertNull(SignedLink::verify('ticket', 'not-a-token'));
        self::assertNull(SignedLink::verify('ticket', ''));
    }

    public function testIdOfReadsTheIdWithoutVerifying(): void
    {
        self::assertSame(42, SignedLink::idOf(SignedLink::sign('buyer_ticket|AB12', 42)));
        self::assertNull(SignedLink::idOf('not-a-token'));
        self::assertNull(SignedLink::idOf(''));
    }

    public function testADifferentIdProducesADifferentSignature(): void
    {
        self::assertNotSame(SignedLink::sign('ticket', 1), SignedLink::sign('ticket', 2));
    }

    public function testRoundTripsAPayload(): void
    {
        $payload = "b\n7\nhttps://shop.test/p/1?utm_source=eventcrew&x=ä";
        $token = SignedLink::signPayload('followup_go', $payload);

        self::assertSame($payload, SignedLink::verifyPayload('followup_go', $token));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_\-]+\.[0-9a-f]{64}$/', $token, 'URL-safe as it is');
    }

    public function testRejectsAPayloadTokenForAnotherPurposeOrTampered(): void
    {
        $token = SignedLink::signPayload('followup_go', 'https://shop.test/');
        [$body, $mac] = explode('.', $token);
        $other = rtrim(strtr(base64_encode('https://evil.test/'), '+/', '-_'), '=');

        self::assertNull(SignedLink::verifyPayload('followup_stop', $token));
        self::assertNull(SignedLink::verifyPayload('followup_go', $other . '.' . $mac));
        self::assertNull(SignedLink::verifyPayload('followup_go', $body . '.'));
        self::assertNull(SignedLink::verifyPayload('followup_go', $body));
        self::assertNull(SignedLink::verifyPayload('followup_go', ''));
    }
}
