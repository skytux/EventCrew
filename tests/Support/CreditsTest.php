<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\Credits;
use EventCrew\Tests\TestCase;

final class CreditsTest extends TestCase
{
    public function testOneCreditPerTwoCompletedTasks(): void
    {
        self::assertSame(0, Credits::balance(0, 0));
        self::assertSame(0, Credits::balance(1, 0));
        self::assertSame(1, Credits::balance(2, 0));
        self::assertSame(1, Credits::balance(3, 0));
        self::assertSame(2, Credits::balance(4, 0));
    }

    public function testRedeemedCreditsAreSubtracted(): void
    {
        self::assertSame(1, Credits::balance(4, 1));
        self::assertSame(0, Credits::balance(4, 2));
    }

    public function testBalanceNeverGoesNegative(): void
    {
        // Only reachable through hand-edited data - redemption is guarded at the
        // point it is recorded - but the balance still must not read below zero.
        self::assertSame(0, Credits::balance(2, 5));
    }

    public function testGrantedCreditsAreAddedOnTopOfEarned(): void
    {
        // A hand-granted bonus adds to the earned-minus-redeemed balance.
        self::assertSame(1, Credits::balance(0, 0, 1));  // nothing earned, one granted
        self::assertSame(3, Credits::balance(4, 0, 1));  // two earned + one granted
        self::assertSame(1, Credits::balance(4, 2, 1));  // two earned - two spent + one granted
    }

    public function testAConfiguredRatioChangesWhatIsEarned(): void
    {
        // Three completed tasks per credit: six earns two, five earns one.
        self::assertSame(2, Credits::balance(6, 0, 0, 3));
        self::assertSame(1, Credits::balance(5, 0, 0, 3));
        // A nonsensical zero ratio is floored at one, never a divide-by-zero.
        self::assertSame(4, Credits::balance(4, 0, 0, 0));
    }
}
