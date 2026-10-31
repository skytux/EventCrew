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
        private readonly TaskRepository $tasks,
        private readonly LeaderGate $leaderGate
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

        // One reserved leader slot per event, when the feature is on for the day
        // and the event doesn't already carry one. Untimed - the leader is on
        // for the whole night, not a slice of it.
        if ($this->leaderGate->isEnabled($event['date']) && ! isset($existing[Roles::LEADER_SLUG])) {
            $this->tasks->create([
                'event_post_id' => $eventPostId,
                'event_label' => '',
                'task_date' => $event['date'],
                'starts_at' => null,
                'ends_at' => null,
                'role_slug' => Roles::LEADER_SLUG,
                'capacity' => 1,
                'notes' => '',
            ]);
            ++$created;
        }

        // A new batch of tasks makes the Telegram board out of date. The hook
        // covers both callers (the manual button and the EventMesh listener)
        // in one place; when the bot is not configured the listener no-ops.
        // See Telegram\BoardRefreshListener::HOOK.
        if ($created > 0) {
            do_action('eventcrew/board_stale');
        }

        return ['created' => $created, 'untimed' => $untimed];
    }
}
