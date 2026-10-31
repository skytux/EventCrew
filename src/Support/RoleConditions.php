<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;

/**
 * Whether a person meets the conditions set on a role (Settings, Roles).
 *
 * A role's conditions can ask for two things, and a person meets them when
 * both parts that are set are met:
 * - counts: completed tasks in named roles, e.g. 2 in Welcome and 1 in Clean;
 * - min_roles / times_each: at least n different roles, each done x times.
 *
 * Completed means a completed (or arrived-late) task, whether or not it
 * counted toward free-entry credit. The role's own tasks never count toward
 * its own conditions.
 *
 * Meeting the conditions does not let anyone sign up. It only drives the
 * notice to the organizers (RoleNotifier), the "Role conditions" tab on the
 * People page and the bot's /eligible. Giving the role is the organizer's
 * choice, with /roles, the crew page or the People page.
 */
final class RoleConditions
{
    public function __construct(
        private readonly AssignmentRepository $assignments,
        private readonly PersonRepository $people
    ) {
    }

    /**
     * Whether completions per role ($byRole, keyed by role slug) meet the
     * conditions of role $slug. A role with no conditions is never met, so
     * nobody is ever announced for it.
     *
     * @param array{counts: array<string, int>, min_roles: int, times_each: int} $conditions
     * @param array<string, int> $byRole
     */
    public static function meets(array $conditions, array $byRole, string $slug): bool
    {
        if (! Roles::hasConditions($conditions)) {
            return false;
        }

        foreach ($conditions['counts'] as $countSlug => $count) {
            if (($byRole[$countSlug] ?? 0) < $count) {
                return false;
            }
        }

        if ($conditions['min_roles'] > 0) {
            $roles = 0;

            foreach ($byRole as $doneSlug => $done) {
                if ($doneSlug !== $slug && $done >= $conditions['times_each']) {
                    ++$roles;
                }
            }

            if ($roles < $conditions['min_roles']) {
                return false;
            }
        }

        return true;
    }

    /**
     * The active roles that have conditions set.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rolesWithConditions(): array
    {
        return array_values(array_filter(
            Roles::active(),
            static fn (array $role): bool => Roles::hasConditions($role['conditions'])
        ));
    }

    /**
     * Completed tasks per role for one person.
     *
     * @return array<string, int>
     */
    public function completedByRole(int $personId): array
    {
        return $this->assignments->completedByRole($personId);
    }

    /**
     * For every role with conditions, everyone who meets them right now, with
     * whether they already hold the role. Keyed by role slug; a role nobody
     * meets is present with an empty list.
     *
     * @return array<string, list<array{person: Person, holds: bool, by_role: array<string, int>}>>
     */
    public function report(): array
    {
        $roles = self::rolesWithConditions();
        $out = [];

        foreach ($roles as $role) {
            $out[$role['slug']] = [];
        }

        if ([] === $roles) {
            return $out;
        }

        foreach ($this->people->all(['per_page' => 1000]) as $person) {
            if ($person->isAnonymized()) {
                continue;
            }

            $byRole = $this->completedByRole($person->id);

            foreach ($roles as $role) {
                if (self::meets($role['conditions'], $byRole, $role['slug'])) {
                    $out[$role['slug']][] = [
                        'person' => $person,
                        'holds' => $person->hasRole($role['slug']),
                        'by_role' => $byRole,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * The conditions in one plain line, e.g. "2 × 🙋 Welcome; at least 3
     * different roles, 2 times each". '' when none are set.
     *
     * @param array{counts: array<string, int>, min_roles: int, times_each: int} $conditions
     */
    public static function describe(array $conditions): string
    {
        $parts = [];

        foreach ($conditions['counts'] as $slug => $count) {
            $parts[] = $count . ' × ' . Roles::display((string) $slug);
        }

        if ($conditions['min_roles'] > 0) {
            $parts[] = sprintf(
                /* translators: 1: number of different roles, 2: times in each */
                __('at least %1$d different roles, %2$d times each', 'eventcrew'),
                $conditions['min_roles'],
                $conditions['times_each']
            );
        }

        return implode('; ', $parts);
    }
}
