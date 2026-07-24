<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\LeaderEligibility;
use EventCrew\Tests\TestCase;

final class LeaderEligibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Default roles (decorate, welcome, clean); threshold 2.
        Functions\when('get_option')->alias(
            fn (string $n, mixed $d = false): mixed => LeaderEligibility::THRESHOLD_OPTION === $n ? 2 : $d
        );
    }

    private function eligibility(): LeaderEligibility
    {
        return new LeaderEligibility(new AssignmentRepository(), new PersonRepository());
    }

    public function testEligibleWhenEveryActiveRoleMeetsTheThreshold(): void
    {
        $this->wpdb->nextResults[] = [
            ['role_slug' => 'decorate', 'n' => 2],
            ['role_slug' => 'welcome', 'n' => 3],
            ['role_slug' => 'clean', 'n' => 2],
        ];

        self::assertTrue($this->eligibility()->isEligible(9));
    }

    public function testNotEligibleWhenOneRoleIsShort(): void
    {
        $this->wpdb->nextResults[] = [
            ['role_slug' => 'decorate', 'n' => 2],
            ['role_slug' => 'welcome', 'n' => 1], // below the bar
            ['role_slug' => 'clean', 'n' => 2],
        ];

        self::assertFalse($this->eligibility()->isEligible(9));
    }

    public function testNotEligibleWithNoHistory(): void
    {
        $this->wpdb->nextResults[] = []; // completedByRole -> nothing

        self::assertFalse($this->eligibility()->isEligible(9));
    }
}
