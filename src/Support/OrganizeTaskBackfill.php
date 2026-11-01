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
 *
 * The same pass adds the tasks of restricted roles set to "Add to every night"
 * (DJ, VJ, ...). Roles::save() puts a role's slug on a pending list whenever it
 * newly becomes an every-night restricted role; the next request adds one task
 * of that role to every upcoming night that has none, then empties the list.
 * Switching the setting off only stops new ones: nothing is deleted.
 */
final class OrganizeTaskBackfill
{
    /** Set to '1' once the backfill has run on this site. */
    public const DONE_OPTION = 'eventcrew_organize_backfill_done';

    /** Restricted role slugs still waiting for their every-night backfill. */
    public const PENDING_OPTION = 'eventcrew_restricted_backfill_pending';

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
            'plus_ones' => Roles::defaultPlusOnes(Roles::ORGANIZE_SLUG),
            'notes' => '',
        ];
    }

    /**
     * Puts restricted role slugs on the pending list, for the next request to
     * backfill. Called by Roles when a role newly needs a task every night.
     *
     * @param array<int, string> $slugs
     */
    public static function queue(array $slugs): void
    {
        if ([] === $slugs) {
            return;
        }

        $pending = self::pending();

        foreach ($slugs as $slug) {
            if (! in_array($slug, $pending, true)) {
                $pending[] = $slug;
            }
        }

        update_option(self::PENDING_OPTION, $pending);
    }

    /**
     * @return array<int, string>
     */
    private static function pending(): array
    {
        $stored = get_option(self::PENDING_OPTION, []);

        return is_array($stored) ? array_values(array_map('strval', $stored)) : [];
    }

    /**
     * Runs the organize backfill once per site, and the restricted-role
     * backfill whenever a role is pending. Called on every request; when there
     * is nothing to do it costs two autoloaded option reads.
     */
    public function maybeRun(): void
    {
        $organizeDone = '1' === (string) get_option(self::DONE_OPTION, '');
        $pending = self::pending();

        if ($organizeDone && [] === $pending) {
            return;
        }

        global $wpdb;

        // Another request holds the lock and is running it now.
        if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::LOCK))) {
            return;
        }

        try {
            if (! $organizeDone) {
                $this->run();
                update_option(self::DONE_OPTION, '1');
            }

            if ([] !== $pending) {
                // Only the roles still set to every night: one switched off
                // again before this ran gets nothing.
                $wanted = array_map(static fn (array $role): string => $role['slug'], Roles::everyNight());
                $this->runRoles(array_values(array_intersect($pending, $wanted)));
                update_option(self::PENDING_OPTION, []);
            }
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::LOCK));
        }
    }

    /**
     * The row for one task of a restricted role on one night. Timed from the
     * role's offsets when the night's event can be read, untimed otherwise.
     * Capacity 1 for the same reason as taskData(): it is not a limit.
     *
     * @return array<string, mixed>
     */
    public static function roleTaskData(string $slug, string $date, ?int $eventPostId, string $eventLabel): array
    {
        $role = Roles::find($slug);
        $event = null === $eventPostId ? null : EventSource::describe($eventPostId);
        $startsAt = null === $event ? '' : $event['starts_at'];
        $endsAt = null === $event ? '' : $event['ends_at'];
        $planned = null === $role ? [] : TaskTemplate::build(
            $date,
            $startsAt,
            $endsAt,
            [['archived' => false] + $role],
            $eventPostId,
            null === $eventPostId ? $eventLabel : ''
        );

        $data = $planned[0] ?? self::taskData($date, $eventPostId, $eventLabel);
        $data['role_slug'] = $slug;
        $data['capacity'] = 1;

        return $data;
    }

    /**
     * Adds one task of each given restricted role to every night from today on
     * that has none of that role. Returns how many were added.
     *
     * @param array<int, string> $slugs
     */
    public function runRoles(array $slugs): int
    {
        if ([] === $slugs) {
            return 0;
        }

        $created = 0;

        foreach ($this->nights() as $night) {
            $title = $this->eventTitle($night['event_post_id'], $night['event_label']);

            foreach ($slugs as $slug) {
                if (isset($night['roles'][$slug])) {
                    continue;
                }

                // A role meant only for some events (title text) is not
                // added to the other nights.
                $role = Roles::find($slug);

                if (null !== $role && ! Roles::fitsEvent($role, $title)) {
                    continue;
                }

                $this->tasks->create(self::roleTaskData(
                    $slug,
                    $night['date'],
                    $night['event_post_id'],
                    $night['event_label']
                ));
                ++$created;
            }
        }

        if ($created > 0) {
            do_action('eventcrew/board_stale');
        }

        return $created;
    }

    /**
     * The title a night's event goes by: the event post's title, or the
     * typed label of a night with no event post.
     */
    private function eventTitle(?int $eventPostId, string $eventLabel): string
    {
        if (null === $eventPostId) {
            return $eventLabel;
        }

        $event = EventSource::describe($eventPostId);

        return null === $event ? $eventLabel : $event['title'];
    }

    /**
     * Every upcoming night, with the role slugs it already has tasks for.
     *
     * @return array<string, array{
     *     date: string,
     *     event_post_id: int|null,
     *     event_label: string,
     *     roles: array<string, bool>
     * }>
     */
    private function nights(): array
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
                    'roles' => [],
                ];
            }

            $nights[$key]['roles'][$task->roleSlug] = true;
        }

        return $nights;
    }

    /**
     * Adds an organize task to every night from today on that has none.
     * Returns how many were added.
     */
    public function run(): int
    {
        $created = 0;

        foreach ($this->nights() as $night) {
            if (isset($night['roles'][Roles::ORGANIZE_SLUG])) {
                continue;
            }

            $this->tasks->create(self::taskData($night['date'], $night['event_post_id'], $night['event_label']));
            ++$created;
        }

        return $created;
    }
}
