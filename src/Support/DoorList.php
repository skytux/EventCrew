<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketTransferRepository;

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
        private readonly StandingCalculator $standing,
        private readonly ?TicketTransferRepository $transfers = null,
        private readonly ?DoorAddedBy $addedBy = null
    ) {
    }

    /**
     * @return array{
     *     entrants: array<int, array{
     *         person_id: int, name: string, detail: string, standing: Standing, redemption_id: ?int, added_by: string
     *     }>,
     *     candidates: array<int, array{person_id: int, name: string, credit_balance: int}>
     * }
     */
    public function forDate(string $date): array
    {
        $entrants = $this->entrantsFor($date);

        return [
            'entrants' => $entrants,
            'candidates' => $this->candidates(array_map(
                static fn (array $entrant): int => $entrant['person_id'],
                $entrants
            )),
        ];
    }

    /**
     * Only the people who get in free on $date, sorted by name - without the
     * redemption candidates, whose scan reads every person's standing.
     * added_by is "added by Lou · 9 Nov 14:02" for an entry someone else
     * put there (DoorAddedBy), '' otherwise.
     *
     * @return array<int, array{
     *     person_id: int, name: string, detail: string, standing: Standing, redemption_id: ?int, added_by: string
     * }>
     */
    public function entrantsFor(string $date): array
    {
        $workerTasks = [];
        $entrants = $this->workers($date, $workerTasks);
        $creditPeople = [];

        foreach ($this->redemptions->forDate($date) as $redemption) {
            $creditPeople[] = $redemption->personId;

            // A worker who also holds a free-entry ticket gets a second row:
            // they may give that ticket to someone else (Owner 2026-11-07).
            $person = $this->people->find($redemption->personId);

            $entrants['credit:' . $redemption->id] = [
                'person_id' => $redemption->personId,
                'name' => null === $person ? __('(deleted person)', 'eventcrew') : $person->name(),
                'detail' => __('Free-entry credit', 'eventcrew'),
                'standing' => $this->standing->for($redemption->personId),
                'redemption_id' => $redemption->id,
                'added_by' => '',
            ];
        }

        foreach (null === $this->transfers ? [] : $this->transfers->issuedForDate($date) as $transfer) {
            $giver = $this->people->find($transfer->giverPersonId);
            $giverName = null === $giver ? __('(deleted person)', 'eventcrew') : $giver->name();
            $receiver = null === $transfer->receiverPersonId ? null : $this->people->find($transfer->receiverPersonId);
            $receiverName = null === $receiver ? $transfer->name : $receiver->name();

            // The receiver's ticket, its own category. Its Remove reaches the
            // original ticket, which cancels the transfer (TicketRedemptionService).
            $entrants['transfer:' . $transfer->id] = [
                'person_id' => null === $receiver ? 0 : $receiver->id,
                'name' => '' === $receiverName ? __('(name erased)', 'eventcrew') : $receiverName,
                'detail' => __('Transferred', 'eventcrew') . ' · ' . sprintf(
                    /* translators: %s: the name of the person who gave the ticket away */
                    __('from %s', 'eventcrew'),
                    $giverName
                ),
                'standing' => null === $receiver
                    ? new Standing(Standing::UNRATED, 0.0, 0, 0)
                    : $this->standing->for($receiver->id),
                'redemption_id' => $transfer->redemptionId,
                'added_by' => '',
            ];

            // The giver's cancelled ticket, so the list says where it went.
            $entrants['given:' . $transfer->id] = [
                'person_id' => $transfer->giverPersonId,
                'name' => $giverName,
                'detail' => sprintf(
                    /* translators: %s: the name of the person the ticket was given to */
                    __('Given to %s', 'eventcrew'),
                    '' === $receiverName ? __('(name erased)', 'eventcrew') : $receiverName
                ),
                'standing' => $this->standing->for($transfer->giverPersonId),
                'redemption_id' => null,
                'added_by' => '',
            ];
        }

        if (null !== $this->addedBy) {
            $lines = $this->addedBy->forNight($date, $workerTasks, $creditPeople);

            foreach ($entrants as $key => $entrant) {
                if (is_int($key)) {
                    $entrants[$key]['added_by'] = $lines['workers'][$key] ?? '';
                } elseif (str_starts_with((string) $key, 'credit:')) {
                    $entrants[$key]['added_by'] = $lines['credits'][$entrant['person_id']] ?? '';
                }
            }
        }

        return $this->sortedByName($entrants);
    }

    /**
     * People occupying a slot on $date, keyed by person id, each with the roles
     * they are covering rolled into one detail line. $taskIds gets each
     * person's task ids, for who added them (DoorAddedBy).
     *
     * @param array<int, array<int, int>> $taskIds
     * @return array<int, array{
     *     person_id: int, name: string, detail: string, standing: Standing, redemption_id: ?int, added_by: string
     * }>
     */
    private function workers(string $date, array &$taskIds = []): array
    {
        $roles = [];

        foreach ($this->tasks->forDate($date) as $task) {
            foreach ($this->assignments->forTask($task->id) as $assignment) {
                if (! $assignment->isOccupying()) {
                    continue;
                }

                $roles[$assignment->personId][] = $task->roleLabel();
                $taskIds[$assignment->personId][] = $task->id;
            }
        }

        $workers = [];

        foreach ($roles as $personId => $roleLabels) {
            $person = $this->people->find($personId);

            $workers[$personId] = [
                'person_id' => $personId,
                'name' => null === $person ? __('(deleted person)', 'eventcrew') : $person->name(),
                'detail' => sprintf(
                    /* translators: %s: comma-separated list of roles */
                    __('Working: %s', 'eventcrew'),
                    implode(', ', array_unique($roleLabels))
                ),
                'standing' => $this->standing->for($personId),
                'redemption_id' => null,
                'added_by' => '',
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
     * @param array<int, array{
     *     person_id: int, name: string, detail: string, standing: Standing, redemption_id: ?int, added_by: string
     * }> $entrants
     * @return array<int, array{
     *     person_id: int, name: string, detail: string, standing: Standing, redemption_id: ?int, added_by: string
     * }>
     */
    private function sortedByName(array $entrants): array
    {
        $rows = array_values($entrants);

        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $rows;
    }
}
