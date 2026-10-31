<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\Standing;
use EventCrew\Tests\TestCase;

final class StandingTest extends TestCase
{
    public function testScorePercentRoundsToAWholeNumber(): void
    {
        $standing = new Standing(Standing::GOOD, 0.824, 5, 2);

        self::assertSame(82, $standing->scorePercent());
    }

    public function testRatedSummaryAppendsTheScoreWhenRated(): void
    {
        $standing = new Standing(Standing::GOOD, 0.82, 5, 2);

        // __ is stubbed to return its format string, so sprintf still fills it in.
        self::assertStringContainsString('82%', $standing->ratedSummary());
    }

    public function testRatedSummaryHidesTheScoreWhenUnrated(): void
    {
        $standing = new Standing(Standing::UNRATED, 0.0, 1, 0);

        // No bare "0%" for someone who simply hasn't worked enough yet.
        self::assertStringNotContainsString('%', $standing->ratedSummary());
    }
}
