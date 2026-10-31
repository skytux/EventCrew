<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\DoorSession;

/**
 * The signed sales desk cookie and link. The clock reads 2026-07-20 12:00.
 */
final class DoorSessionTest extends SalesDeskTestCase
{
    public function testAMintedCookieReadsBack(): void
    {
        $cookie = DoorSession::mint(7, self::NIGHT);

        self::assertSame(['person_id' => 7, 'date' => self::NIGHT], DoorSession::read($cookie));
    }

    public function testATamperedCookieIsRejected(): void
    {
        $cookie = DoorSession::mint(7, self::NIGHT);
        [$body, $mac] = explode('.', $cookie);

        // Someone else's id in the body, with the old signature.
        $forged = rtrim(strtr(base64_encode('8|' . self::NIGHT . '|2026-07-21 06:00:00'), '+/', '-_'), '=');

        self::assertNull(DoorSession::read($forged . '.' . $mac));
        self::assertNull(DoorSession::read($body . '.' . str_repeat('0', 64)));
        self::assertNull(DoorSession::read($body));
        self::assertNull(DoorSession::read(''));
    }

    public function testACookieForANightThatIsOverIsRejected(): void
    {
        // Yesterday's night ended at 06:00 this morning.
        self::assertNull(DoorSession::read(DoorSession::mint(7, '2026-07-19')));
    }

    public function testTheCsrfTokenBelongsToOneCookie(): void
    {
        $cookie = DoorSession::mint(7, self::NIGHT);
        $other = DoorSession::mint(8, self::NIGHT);

        self::assertTrue(DoorSession::verifyCsrf($cookie, DoorSession::csrfToken($cookie)));
        self::assertFalse(DoorSession::verifyCsrf($other, DoorSession::csrfToken($cookie)));
        self::assertFalse(DoorSession::verifyCsrf('', DoorSession::csrfToken('')));
    }

    public function testTheLinkSignatureBindsTheTokenToItsNight(): void
    {
        $signature = DoorSession::linkSignature('raw', self::NIGHT);

        self::assertTrue(DoorSession::verifyLink('raw', self::NIGHT, $signature));
        self::assertFalse(DoorSession::verifyLink('raw', '2026-07-27', $signature));
        self::assertStringContainsString('action=eventcrew_door', DoorSession::loginUrl('raw', self::NIGHT));
    }
}
