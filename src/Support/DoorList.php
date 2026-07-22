<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;

/**
 * Who gets in free on one event date: everyone working it, plus everyone
 * spending a credit on it. The two halves of the door list, unioned - a worker
 * who also happens to hold a redemption is shown once, as a worker.
 *
 * Also offers the redemption candidates: people who have a credit to spend and
 * are not already free that night, which is the pick-list behind the door's
 * "Redeem a credit" control.
 */
final class DoorList
{
    /** Upper bound on people scanned for redemption candidates. */
    private const CANDIDATE_SCAN = 500;

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly PersonRepository $people,
        private readonly RedemptionRepository $redemptions,
        private readonly StandingCalculator $standing
    ) {
    }

    /**
     * @return array{
     *     entrants: array<int, array{name: string, detail: string, standing: Standing, redemption_id: ?int}>,
     *     candidates: array<int, array{person_id: int, name: string, credit_balance: int}>
     * }
     */
    public function forDate(string $date): array
    {
        $entrants = $this->workers($date);

        foreach ($this->redemptions->forDate($date) as $redemption) {
            // A worker who also holds a redemption is already free; keep the
            // worker row and drop the duplicate.
            if (isset($entrants[$redemption->personId])) {
                continue;
            }

            $person = $this->people->find($redemption->personId);

            $entrants[$redemption->personId] = [
                'name' => null === $person ? __('(deleted person)', 'eventcrew') : $person->name(),
                'detail' => __('Free-entry credit', 'eventcrew'),
                'standing' => $this->standing->for($redemption->personId),
                'redemption_id' => $redemption->id,
            ];
        }

        return [
            'entrants' => $this->sortedByName($entrants),
            'candidates' => $this->candidates(array_keys($entrants)),
        ];
    }

    /**
     * People occupying a slot on $date, keyed by person id, each with the roles
     * they are covering rolled into one detail line.
     *
     * @return array<int, array{name: string, detail: string, standing: Standing, redemption_id: ?int}>
     */
    private function workers(string $date): array
    {
        $roles = [];

        foreach ($this->tasks->forDate($date) as $task) {
            foreach ($this->assignments->forTask($task->id) as $assignment) {
                if (! $assignment->isOccupying()) {
                    continue;
                }

                $roles[$assignment->personId][] = $task->roleLabel();
            }
        }

        $workers = [];

        foreach ($roles as $personId => $roleLabels) {
            $person = $this->people->find($personId);

            $workers[$personId] = [
                'name' => null === $person ? __('(deleted person)', 'eventcrew') : $person->name(),
                'detail' => sprintf(
                    /* translators: %s: comma-separated list of roles */
                    __('Working: %s', 'eventcrew'),
                    implode(', ', array_unique($roleLabels))
                ),
                'standing' => $this->standing->for($personId),
                'redemption_id' => null,
            ];
        }

        return $workers;
    }

    /**
     * People with at least one credit to spend who are not already free on the
     * night, for the redeem pick-list.
     *
     * @param array<int, int> $alreadyFree person ids to exclude
     * @return array<int, array{person_id: int, name: string, credit_balance: int}>
     */
    private function candidates(array $alreadyFree): array
    {
        $exclude = array_flip($alreadyFree);
        $candidates = [];

        foreach ($this->people->all(['per_page' => self::CANDIDATE_SCAN]) as $person) {
            if (isset($exclude[$person->id])) {
                continue;
            }

            $balance = $this->standing->for($person->id)->creditBalance;

            if ($balance < 1) {
                continue;
            }

            $candidates[] = [
                'person_id' => $person->id,
                'name' => $person->name(),
                'credit_balance' => $balance,
            ];
        }

        return $candidates;
    }

    /**
     * @param array<int, array{name: string, detail: string, standing: Standing, redemption_id: ?int}> $entrants
     * @return array<int, array{name: string, detail: string, standing: Standing, redemption_id: ?int}>
     */
    private function sortedByName(array $entrants): array
    {
        $rows = array_values($entrants);

        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $rows;
    }
}
