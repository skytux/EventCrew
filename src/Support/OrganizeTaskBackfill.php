<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Repositories\TaskRepository;

/**
 * Adds the organize task to event nights that were created before it existed.
 *
 * New events get their organize task from TaskTemplateApplier. Nights that
 * already had tasks when this release arrived have none, and the live site
 * cannot be edited with SQL, so this adds one per night in code. It runs once
 * per site: the first request after the update runs it, and an option records
 * that it ran.
 *
 * Only nights from today on are touched. A past night is over: nobody can
 * sign up for it any more, and a new task row there would change its roster
 * and history for no use.
 *
 * A night is what the boards group by: the linked event, or for a task typed
 * without an event, its label and date. A night that already has an organize
 * task is skipped, so running this twice never adds a second one.
 */
final class OrganizeTaskBackfill
{
    /** Set to '1' once the backfill has run on this site. */
    public const DONE_OPTION = 'eventcrew_organize_backfill_done';

    /** MySQL named lock, so two requests at once cannot both add the task. */
    private const LOCK = 'eventcrew_organize_backfill';

    /** More upcoming tasks than any real site has; read in one query. */
    private const READ_LIMIT = 1000;

    public function __construct(
        private readonly TaskRepository $tasks
    ) {
    }

    /**
     * The row for one organize task: untimed, with capacity 1 so the boards'
     * "capacity > 0" filter keeps it. The capacity is not a limit - the join
     * ignores it for this role (see AssignmentRepository::join()).
     *
     * @return array<string, mixed>
     */
    public static function taskData(string $date, ?int $eventPostId, string $eventLabel): array
    {
        return [
            'event_post_id' => $eventPostId,
            'event_label' => null === $eventPostId ? $eventLabel : '',
            'task_date' => $date,
            'starts_at' => null,
            'ends_at' => null,
            'role_slug' => Roles::ORGANIZE_SLUG,
            'capacity' => 1,
            'notes' => '',
        ];
    }

    /**
     * Runs the backfill once per site. Called on every request; after the
     * first run it costs one autoloaded option read.
     */
    public function maybeRun(): void
    {
        if ('1' === (string) get_option(self::DONE_OPTION, '')) {
            return;
        }

        global $wpdb;

        // Another request holds the lock and is running it now.
        if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::LOCK))) {
            return;
        }

        try {
            $this->run();
            update_option(self::DONE_OPTION, '1');
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::LOCK));
        }
    }

    /**
     * Adds an organize task to every night from today on that has none.
     * Returns how many were added.
     */
    public function run(): int
    {
        $nights = [];

        $upcoming = $this->tasks->all([
            'upcoming_only' => true,
            'orderby' => 'task_date',
            'order' => 'ASC',
            'per_page' => self::READ_LIMIT,
        ]);

        foreach ($upcoming as $task) {
            $key = null !== $task->eventPostId
                ? 'e:' . $task->eventPostId
                : 'l:' . $task->eventLabel . '|' . $task->taskDate;

            if (! isset($nights[$key])) {
                $nights[$key] = [
                    'date' => $task->taskDate,
                    'event_post_id' => $task->eventPostId,
                    'event_label' => $task->eventLabel,
                    'has_organize' => false,
                ];
            }

            if (Roles::ORGANIZE_SLUG === $task->roleSlug) {
                $nights[$key]['has_organize'] = true;
            }
        }

        $created = 0;

        foreach ($nights as $night) {
            if ($night['has_organize']) {
                continue;
            }

            $this->tasks->create(self::taskData($night['date'], $night['event_post_id'], $night['event_label']));
            ++$created;
        }

        return $created;
    }
}
