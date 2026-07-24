<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\Reputation;
use EventCrew\Support\ReputationSettings;
use EventCrew\Tests\TestCase;

final class ReputationSettingsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
    }

    public function testFallsBackToTheShippedWeightsWhenUnset(): void
    {
        self::assertSame(Reputation::defaultWeights(), (new ReputationSettings())->weights());
    }

    public function testReadsStoredPercentagesAsFractions(): void
    {
        $this->options[ReputationSettings::WEIGHTS_OPTION] = [
            AssignmentStatus::COMPLETED => 100,
            AssignmentStatus::NO_SHOW => 20,
        ];

        $weights = (new ReputationSettings())->weights();

        self::assertSame(1.0, $weights[AssignmentStatus::COMPLETED]);
        self::assertSame(0.2, $weights[AssignmentStatus::NO_SHOW]);
        // An outcome left out of the stored option keeps its shipped default.
        self::assertSame(0.8, $weights[AssignmentStatus::REPLACED]);
    }

    public function testAnOutOfRangeWeightFallsBackToItsDefault(): void
    {
        $this->options[ReputationSettings::WEIGHTS_OPTION] = [
            AssignmentStatus::COMPLETED => 999, // nonsense
            AssignmentStatus::NO_SHOW => -5,    // nonsense
        ];

        $weights = (new ReputationSettings())->weights();

        self::assertSame(1.0, $weights[AssignmentStatus::COMPLETED]);
        self::assertSame(0.0, $weights[AssignmentStatus::NO_SHOW]);
    }

    public function testThresholdOutsideRangeFallsBackToDefault(): void
    {
        $this->options[ReputationSettings::THRESHOLD_OPTION] = 5.0;

        self::assertSame(Reputation::DEFAULT_THRESHOLD, (new ReputationSettings())->threshold());
    }
}
