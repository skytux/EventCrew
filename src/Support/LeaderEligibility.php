<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;

/**
 * Who has earned their way to being offered crew-leader: a person who has
 * completed at least N tasks in every active role, so they know every job.
 *
 * "Eligible" is only the suggestion - an organizer still grants the permission
 * with /allow (or the People page). This is what the Leaders admin page and the
 * hourly notifier read to spot new candidates.
 */
final class LeaderEligibility
{
    /** Completed tasks required in each active role to be eligible. */
    public const THRESHOLD_OPTION = 'eventcrew_leader_experience';

    public function __construct(
        private readonly AssignmentRepository $assignments,
        private readonly PersonRepository $people
    ) {
    }

    /** The per-role completion bar, never below 1. Default 2. */
    public function threshold(): int
    {
        return max(1, (int) get_option(self::THRESHOLD_OPTION, 2));
    }

    public function isEligible(int $personId): bool
    {
        $roles = Roles::active();

        // With no active roles there is nothing to prove competence at, so no
        // one is eligible rather than everyone.
        if ([] === $roles) {
            return false;
        }

        $byRole = $this->assignments->completedByRole($personId);
        $threshold = $this->threshold();

        foreach ($roles as $role) {
            if (($byRole[$role['slug']] ?? 0) < $threshold) {
                return false;
            }
        }

        return true;
    }

    /**
     * Everyone who meets the bar right now.
     *
     * @return array<int, Person>
     */
    public function eligiblePeople(): array
    {
        $eligible = [];

        foreach ($this->people->all(['per_page' => 1000]) as $person) {
            if ($this->isEligible($person->id)) {
                $eligible[] = $person;
            }
        }

        return $eligible;
    }

    /**
     * Completions in each active role for one person, for the Leaders page's
     * per-role columns. Keyed by role slug, every active role present.
     *
     * @return array<string, int>
     */
    public function byActiveRole(int $personId): array
    {
        $byRole = $this->assignments->completedByRole($personId);
        $out = [];

        foreach (Roles::active() as $role) {
            $out[$role['slug']] = $byRole[$role['slug']] ?? 0;
        }

        return $out;
    }
}
