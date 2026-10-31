<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\RoleConditions;
use EventCrew\Support\Roles;
use EventCrew\Tests\TestCase;

/**
 * The two forms a role's conditions can take: completed tasks in named roles,
 * and "at least n different roles, x times each".
 */
final class RoleConditionsTest extends TestCase
{
    /**
     * @param array<string, int> $counts
     * @return array{counts: array<string, int>, min_roles: int, times_each: int}
     */
    private function conditions(array $counts = [], int $minRoles = 0, int $timesEach = 0): array
    {
        return ['counts' => $counts, 'min_roles' => $minRoles, 'times_each' => $timesEach];
    }

    public function testARoleWithNoConditionsIsNeverMet(): void
    {
        self::assertFalse(RoleConditions::meets($this->conditions(), ['welcome' => 50], 'leader'));
    }

    public function testCountsPerNamedRoleMustAllBeReached(): void
    {
        $conditions = $this->conditions(['welcome' => 2, 'clean' => 1]);

        self::assertTrue(RoleConditions::meets($conditions, ['welcome' => 2, 'clean' => 1], 'leader'));
        self::assertTrue(RoleConditions::meets($conditions, ['welcome' => 5, 'clean' => 3], 'leader'));
        self::assertFalse(RoleConditions::meets($conditions, ['welcome' => 1, 'clean' => 4], 'leader'));
        self::assertFalse(RoleConditions::meets($conditions, ['welcome' => 2], 'leader'));
    }

    public function testAtLeastNRolesXTimesEach(): void
    {
        $conditions = $this->conditions([], 3, 2);

        self::assertTrue(RoleConditions::meets($conditions, ['decorate' => 2, 'welcome' => 2, 'clean' => 3], 'leader'));
        // Only two roles reach two completions.
        self::assertFalse(RoleConditions::meets($conditions, ['decorate' => 2, 'welcome' => 2, 'clean' => 1], 'leader'));
        self::assertFalse(RoleConditions::meets($conditions, ['decorate' => 9, 'welcome' => 9], 'leader'));
    }

    public function testTheRolesOwnTasksDoNotCountTowardItsOwnConditions(): void
    {
        $conditions = $this->conditions([], 2, 1);

        self::assertFalse(RoleConditions::meets($conditions, ['leader' => 5, 'welcome' => 1], 'leader'));
        self::assertTrue(RoleConditions::meets($conditions, ['leader' => 5, 'welcome' => 1, 'clean' => 1], 'leader'));
    }

    public function testBothFormsTogetherMustBothBeMet(): void
    {
        $conditions = $this->conditions(['welcome' => 2], 2, 1);

        self::assertTrue(RoleConditions::meets($conditions, ['welcome' => 2, 'clean' => 1], 'leader'));
        self::assertFalse(RoleConditions::meets($conditions, ['welcome' => 1, 'clean' => 1], 'leader'));
        self::assertFalse(RoleConditions::meets($conditions, ['welcome' => 2], 'leader'));
    }

    public function testTheReportListsWhoMeetsEachRoleAndWhetherTheyHoldIt(): void
    {
        Functions\when('get_option')->alias(static fn (string $name, mixed $default = false): mixed => match ($name) {
            Roles::OPTION_NAME => [
                ['slug' => 'welcome', 'label' => 'Welcome'],
                ['slug' => 'clean', 'label' => 'Clean'],
                [
                    'slug' => 'leader',
                    'label' => 'Crew leader',
                    'restricted' => true,
                    'conditions' => ['counts' => ['welcome' => 2]],
                ],
            ],
            default => $default,
        });

        $this->wpdb->nextResults[] = [
            ['id' => 8, 'display_name' => 'Jo', 'role_slugs' => 'leader'],
            ['id' => 9, 'display_name' => 'Kim'],
            ['id' => 10, 'display_name' => 'Ana'],
        ];
        $this->wpdb->nextResults[] = [['role_slug' => 'welcome', 'n' => 3]];
        $this->wpdb->nextResults[] = [['role_slug' => 'welcome', 'n' => 1]];
        $this->wpdb->nextResults[] = [['role_slug' => 'welcome', 'n' => 2]];

        $report = (new RoleConditions(new AssignmentRepository(), new PersonRepository()))->report();

        self::assertSame(['leader'], array_keys($report));
        self::assertSame([8, 10], array_map(static fn (array $row): int => $row['person']->id, $report['leader']));
        self::assertTrue($report['leader'][0]['holds']);
        self::assertFalse($report['leader'][1]['holds']);
    }
}
