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
}
