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

    public function testRatingAndCreditKnobsDefaultThenReadStoredValues(): void
    {
        $settings = new ReputationSettings();

        // Unset: the shipped defaults.
        self::assertSame(2, $settings->tasksPerCredit());
        self::assertSame(3, $settings->minRatedTasks());
        self::assertSame(180, $settings->halfLifeDays());

        // Stored sane values are read back.
        $this->options[ReputationSettings::TASKS_PER_CREDIT_OPTION] = 3;
        $this->options[ReputationSettings::MIN_RATED_TASKS_OPTION] = 5;
        $this->options[ReputationSettings::HALF_LIFE_OPTION] = 90;

        self::assertSame(3, $settings->tasksPerCredit());
        self::assertSame(5, $settings->minRatedTasks());
        self::assertSame(90, $settings->halfLifeDays());
    }

    public function testAnInvalidKnobFallsBackToItsDefault(): void
    {
        // A blanket non-integer stub (as some tests use) must not read as 1.
        $this->options[ReputationSettings::TASKS_PER_CREDIT_OPTION] = 0.6;
        $this->options[ReputationSettings::MIN_RATED_TASKS_OPTION] = 0;

        $settings = new ReputationSettings();

        self::assertSame(2, $settings->tasksPerCredit());
        self::assertSame(3, $settings->minRatedTasks());
    }
}
