<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\ShortCode;
use EventCrew\Tests\TestCase;

final class ShortCodeTest extends TestCase
{
    public function testACodeIsFiveCharactersWithNothingThatReadsAsAnother(): void
    {
        foreach (range(1, 300) as $id) {
            foreach ([ShortCode::forBuyer($id), ShortCode::forGuest($id), ShortCode::forPerson($id, '2026-11-14')] as $code) {
                self::assertMatchesRegularExpression('/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{5}$/', $code);
            }
        }

        self::assertSame(31, strlen(ShortCode::ALPHABET));
        self::assertSame(0, preg_match('/[01OIL]/', ShortCode::ALPHABET));
    }

    public function testTheSameRowAlwaysGetsTheSameCode(): void
    {
        self::assertSame(ShortCode::forBuyer(42), ShortCode::forBuyer(42));
        self::assertSame(ShortCode::forPerson(7, '2026-11-14'), ShortCode::forPerson(7, '2026-11-14'));
    }

    /** One buyer's two tickets are two rows, so they show two codes. */
    public function testTwoTicketsGetTwoCodes(): void
    {
        self::assertNotSame(ShortCode::forBuyer(11), ShortCode::forBuyer(12));

        $codes = array_map([ShortCode::class, 'forBuyer'], range(1, 300));
        self::assertCount(300, array_unique($codes));
    }

    /** A buyer, a guest and a person with the same id are different rows. */
    public function testEachKindHasItsOwnCodes(): void
    {
        self::assertNotSame(ShortCode::forBuyer(5), ShortCode::forGuest(5));
        self::assertNotSame(ShortCode::forGuest(5), ShortCode::forPerson(5, '2026-11-14'));
    }

    /** A worker's free-entry ticket is its own door row, so its own code (Owner 2026-11-07). */
    public function testACreditTicketAndACrewTicketOfOnePersonDiffer(): void
    {
        self::assertNotSame(ShortCode::forPerson(7, '2026-11-14'), ShortCode::forCredit(7, '2026-11-14'));
    }

    /** A person is one door row per night: another night is another code. */
    public function testAPersonsCodeChangesWithTheNight(): void
    {
        self::assertNotSame(ShortCode::forPerson(7, '2026-11-14'), ShortCode::forPerson(7, '2026-11-21'));
    }

    public function testForRowFollowsTheDoorRowsKind(): void
    {
        self::assertSame(ShortCode::forBuyer(4), ShortCode::forRow('buyer', 4, '2026-11-14'));
        self::assertSame(ShortCode::forGuest(4), ShortCode::forRow('guest', 4, '2026-11-14'));
        self::assertSame(ShortCode::forPerson(4, '2026-11-14'), ShortCode::forRow('crew', 4, '2026-11-14'));
        self::assertSame(ShortCode::forCredit(4, '2026-11-14'), ShortCode::forRow('credit', 4, '2026-11-14'));
    }

    public function testNoRowNoCode(): void
    {
        self::assertSame('', ShortCode::forBuyer(0));
        self::assertSame('', ShortCode::forPerson(-1, '2026-11-14'));
    }
}
