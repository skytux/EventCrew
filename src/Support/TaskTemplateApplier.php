<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Repositories\TaskRepository;

/**
 * Turns "apply the role templates to this event" into actual task rows.
 *
 * Pulled out of TasksPage so the same logic can be reached from two places:
 * the organizer's own "Create an event's tasks" button, and the (opt-in)
 * listener that runs it automatically when EventMesh syncs a brand new
 * event. Both need exactly the same rule - skip a role that already has a
 * task for this event, so applying twice is always safe - and neither should
 * have its own copy of it.
 */
final class TaskTemplateApplier
{
    public function __construct(
        private readonly TaskRepository $tasks
    ) {
    }

    /**
     * @return array{created: int, untimed: int}|null Null when the event
     *         can't be found or has no start date to schedule against.
     */
    public function apply(int $eventPostId): ?array
    {
        $event = EventSource::describe($eventPostId);

        if (null === $event || '' === $event['date']) {
            return null;
        }

        $existing = [];

        foreach ($this->tasks->forDate($event['date']) as $task) {
            if ($task->eventPostId === $eventPostId) {
                $existing[$task->roleSlug] = true;
            }
        }

        $created = 0;
        $untimed = 0;

        $planned = TaskTemplate::build(
            $event['date'],
            $event['starts_at'],
            $event['ends_at'],
            Roles::active(),
            $eventPostId
        );

        foreach ($planned as $task) {
            if (isset($existing[$task['role_slug']])) {
                continue;
            }

            $this->tasks->create($task);
            ++$created;

            if (null === $task['starts_at']) {
                ++$untimed;
            }
        }

        return ['created' => $created, 'untimed' => $untimed];
    }
}
