<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\ReputationSettings;
use EventCrew\Support\StandingExplainer;
use EventCrew\Tests\TestCase;

final class StandingExplainerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No stored overrides: the explanation falls back to the shipped weights.
        Functions\when('get_option')->justReturn(false);
    }

    public function testRowsAreTheConfiguredOutcomeWeights(): void
    {
        // The explanation must be generated from the live settings, never
        // restated, so the two can't drift.
        self::assertSame((new ReputationSettings())->outcomeRows(), StandingExplainer::rows());
    }

    public function testLinesRenderEachOutcomePercentage(): void
    {
        $lines = StandingExplainer::lines();

        self::assertNotEmpty($lines);
        // The completed-task weight is 100%.
        self::assertNotEmpty(array_filter($lines, static fn (string $l): bool => str_contains($l, '100%')));
    }
}
