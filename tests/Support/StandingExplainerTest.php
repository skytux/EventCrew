<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\Reputation;
use EventCrew\Support\StandingExplainer;
use EventCrew\Tests\TestCase;

final class StandingExplainerTest extends TestCase
{
    public function testRowsAreTheReputationWeights(): void
    {
        // The explanation must be generated from the scoring, never restated, so
        // the two can't drift.
        self::assertSame(Reputation::outcomeWeights(), StandingExplainer::rows());
    }

    public function testLinesRenderEachOutcomePercentage(): void
    {
        $lines = StandingExplainer::lines();

        self::assertNotEmpty($lines);
        // The completed-task weight is 100%.
        self::assertNotEmpty(array_filter($lines, static fn (string $l): bool => str_contains($l, '100%')));
    }
}
