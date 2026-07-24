<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Task;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;

/**
 * Assembles "who is on what" for one date: every task that day, each with the
 * people signed up, how their attendance stands, and their standing (so the
 * organizer sees at a glance who is reliable).
 *
 * Pulled out so the wp-admin Roster page and the bot's /roster read the same
 * shape from one place - the admin page uses the assignment id to build its
 * marking forms, the bot ignores it, and neither rebuilds the join by hand.
 */
final class RosterAssembler
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly PersonRepository $people,
        private readonly StandingCalculator $standing
    ) {
    }

    /**
     * @return array<int, array{task: Task, people: array<int, array{
     *     assignment_id: int, name: string, status: string,
     *     status_label: string, occupying: bool, standing: ?Standing}>}>
     */
    public function forDate(string $date): array
    {
        $roster = [];

        foreach ($this->tasks->forDate($date) as $task) {
            $people = [];

            foreach ($this->assignments->forTask($task->id) as $assignment) {
                $person = $this->people->find($assignment->personId);

                $people[] = [
                    'assignment_id' => $assignment->id,
                    'name' => null === $person
                        ? __('(deleted person)', 'eventcrew')
                        : $person->name(),
                    'status' => $assignment->status,
                    'status_label' => $assignment->statusLabel(),
                    'occupying' => $assignment->isOccupying(),
                    'standing' => null === $person ? null : $this->standing->for($person->id),
                ];
            }

            $roster[] = ['task' => $task, 'people' => $people];
        }

        return $roster;
    }
}
