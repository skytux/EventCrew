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

    public function testADifferentIdProducesADifferentSignature(): void
    {
        self::assertNotSame(SignedLink::sign('ticket', 1), SignedLink::sign('ticket', 2));
    }
}
