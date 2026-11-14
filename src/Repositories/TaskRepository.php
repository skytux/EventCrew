<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;
use EventCrew\Models\Task;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\BoardVisibility;
use EventCrew\Support\History;
use EventCrew\Support\Roles;
use EventCrew\Support\TaskOrder;

/**
 * All reads and writes of the tasks table, plus the occupancy counts that
 * every board and roster needs alongside them.
 */
final class TaskRepository
{
    private function table(): string
    {
        return Schema::table(Schema::TASKS);
    }

    private function assignmentsTable(): string
    {
        return Schema::table(Schema::ASSIGNMENTS);
    }

    public function find(int $id): ?Task
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id),
            ARRAY_A
        );

        return is_array($row) ? Task::fromRow($row) : null;
    }

    /**
     * find() for many ids in one query, keyed by id; deleted tasks included,
     * missing ids left out. For lists that would otherwise read one task per
     * row.
     *
     * @param array<int, int> $ids
     * @return array<int, Task>
     */
    public function findMany(array $ids): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ([] === $ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$this->table()} WHERE id IN ({$placeholders})", ...$ids),
            ARRAY_A
        );
        $tasks = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $task = Task::fromRow($row);
            $tasks[$task->id] = $task;
        }

        return $tasks;
    }

    /** Like find(), but null for a deleted task. */
    public function findLive(int $id): ?Task
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d AND deleted_at IS NULL", $id),
            ARRAY_A
        );

        return is_array($row) ? Task::fromRow($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        global $wpdb;

        $wpdb->insert(
            $this->table(),
            [
                'event_post_id' => $data['event_post_id'] ?? null,
                'event_label' => (string) ($data['event_label'] ?? ''),
                'task_date' => (string) ($data['task_date'] ?? ''),
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'role_slug' => (string) ($data['role_slug'] ?? ''),
                'capacity' => max(0, (int) ($data['capacity'] ?? 1)),
                'plus_ones' => Task::clampPlusOnes((int) ($data['plus_ones'] ?? 0)),
                'notes' => (string) ($data['notes'] ?? ''),
                'created_at' => current_time('mysql'),
            ]
        );

        $id = (int) $wpdb->insert_id;

        if ($id > 0) {
            History::record(History::TASK_CREATED, [
                'task_id' => $id,
                'event_date' => (string) ($data['task_date'] ?? ''),
            ]);
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        global $wpdb;

        // Recorded before the write: the recorder compares with the stored
        // task to keep only the names of the fields that change.
        History::record(History::TASK_CHANGED, ['task_id' => $id, 'fields' => $data]);

        $wpdb->update($this->table(), $data, ['id' => $id]);
    }

    /**
     * "Deletes" a task without removing any row: the task is hidden
     * everywhere (deleted_at) and the sign-ups still holding a place on it are
     * cancelled, so their tickets keep working and show DISABLED. Finished
     * history (completed, no-show, ...) stays as it was.
     *
     * Fires eventcrew/task_deleted with the task id and the ids of the people
     * whose sign-up it cancelled, so TaskCancelledNotice can email them.
     */
    public function delete(int $id): void
    {
        global $wpdb;

        $now = current_time('mysql');
        $statuses = AssignmentStatus::occupying();
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));

        $personIds = array_map('intval', (array) $wpdb->get_col(
            $wpdb->prepare(
                "SELECT person_id FROM {$this->assignmentsTable()}
                WHERE task_id = %d AND status IN ({$statusPlaceholders})",
                ...array_merge([$id], $statuses)
            )
        ));

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->assignmentsTable()}
                SET status = %s, status_changed_at = %s
                WHERE task_id = %d AND status IN ({$statusPlaceholders})",
                ...array_merge([AssignmentStatus::CANCELLED, $now, $id], $statuses)
            )
        );
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table()} SET deleted_at = %s WHERE id = %d AND deleted_at IS NULL",
                $now,
                $id
            )
        );

        foreach ($personIds as $personId) {
            History::record(History::TAKEN_OFF, [
                'task_id' => $id,
                'person_id' => $personId,
                'details' => ['task_deleted' => 1],
            ]);
        }

        History::record(History::TASK_DELETED, ['task_id' => $id, 'details' => ['places' => count($personIds)]]);

        do_action('eventcrew/task_deleted', $id, $personIds);
    }

    /**
     * forDate() for several dates in one query: date => its tasks, in
     * forDate()'s order. A date with no tasks is left out.
     *
     * @param array<int, string> $dates
     * @return array<string, array<int, Task>>
     */
    public function forDates(array $dates): array
    {
        global $wpdb;

        $dates = array_values(array_unique(array_map('strval', $dates)));

        if ([] === $dates) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($dates), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only; the table name comes from Schema.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE task_date IN ({$placeholders}) AND deleted_at IS NULL
                ORDER BY starts_at ASC, id ASC",
                ...$dates
            ),
            ARRAY_A
        );
        $byDate = [];

        foreach ($this->hydrate(is_array($rows) ? $rows : []) as $task) {
            $byDate[$task->taskDate][] = $task;
        }

        return array_map(static fn (array $tasks): array => TaskOrder::sort($tasks), $byDate);
    }

    /**
     * One night's tasks, in the role order (TaskOrder).
     *
     * @return array<int, Task>
     */
    public function forDate(string $date): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE task_date = %s AND deleted_at IS NULL
                ORDER BY starts_at ASC, id ASC",
                $date
            ),
            ARRAY_A
        );

        return TaskOrder::sort($this->hydrate($rows));
    }

    /**
     * Every task linked to one event, on any date, by date and then role order.
     * Deleted tasks are left out.
     *
     * @return array<int, Task>
     */
    public function forEvent(int $eventPostId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE event_post_id = %d AND deleted_at IS NULL
                ORDER BY starts_at ASC, id ASC",
                $eventPostId
            ),
            ARRAY_A
        );

        return TaskOrder::sort($this->hydrate($rows));
    }

    /**
     * The open board: tasks that have not yet finished.
     *
     * A timed task drops the moment its end passes, so a day's board winds down
     * through the evening rather than lingering until midnight. A task with no
     * end time cannot be known to have finished, so it stays up to the end of
     * its filing day (task_date). This also keeps an after-midnight cleanup -
     * filed under the event's day but ending the next morning - on the board
     * until it actually ends, which a bare task_date test would drop at midnight.
     *
     * @return array<int, Task>
     */
    public function upcoming(int $limit = 100): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()}
                WHERE capacity > 0
                  AND deleted_at IS NULL
                  AND (
                    (ends_at IS NULL AND task_date >= %s)
                    OR (ends_at IS NOT NULL AND ends_at >= %s)
                  )
                ORDER BY task_date ASC, starts_at ASC, id ASC
                LIMIT %d",
                current_time('Y-m-d'),
                current_time('mysql'),
                $limit
            ),
            ARRAY_A
        );

        return TaskOrder::sort($this->hydrate($rows));
    }

    /**
     * Timed tasks whose start falls in [$from, $to], for the task reminder.
     * Untimed tasks (no starts_at) cannot be reminded to the minute and are
     * left out - they have no start for a "24h before" to hang off.
     *
     * @return array<int, Task>
     */
    public function startingBetween(string $from, string $to): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()}
                WHERE starts_at IS NOT NULL
                  AND deleted_at IS NULL
                  AND starts_at >= %s
                  AND starts_at <= %s
                ORDER BY starts_at ASC, id ASC",
                $from,
                $to
            ),
            ARRAY_A
        );

        return $this->hydrate($rows);
    }

    /**
     * Distinct future dates that have at least one task, for the roster date
     * picker and the Telegram board.
     *
     * @return array<int, string>
     */
    public function upcomingDates(int $limit = 20): array
    {
        global $wpdb;

        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT task_date FROM {$this->table()}
                WHERE task_date >= %s AND deleted_at IS NULL
                ORDER BY task_date ASC
                LIMIT %d",
                current_time('Y-m-d'),
                $limit
            )
        );

        return array_map('strval', is_array($rows) ? $rows : []);
    }

    /**
     * Distinct dates that have at least one task, most recent first and
     * including past ones - which the upcoming list deliberately omits. This
     * feeds the roster's date picker: attendance is marked after an event, so
     * the day you need is almost always one that has already happened.
     *
     * @return array<int, string>
     */
    public function datesWithTasks(int $limit = 30): array
    {
        global $wpdb;

        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT task_date FROM {$this->table()}
                WHERE deleted_at IS NULL
                ORDER BY task_date DESC
                LIMIT %d",
                $limit
            )
        );

        return array_map('strval', is_array($rows) ? $rows : []);
    }

    /**
     * @param array{orderby?: string, order?: string, per_page?: int, page?: int, upcoming_only?: bool} $args
     * @return array<int, Task>
     */
    public function all(array $args = []): array
    {
        global $wpdb;

        $params = [];
        $where = 'deleted_at IS NULL';

        if (! empty($args['upcoming_only'])) {
            $where .= ' AND task_date >= %s';
            $params[] = current_time('Y-m-d');
        }

        $orderBy = $this->safeOrderBy((string) ($args['orderby'] ?? 'task_date'));
        $order = 'ASC' === strtoupper((string) ($args['order'] ?? 'DESC')) ? 'ASC' : 'DESC';
        // Sorting by role means the organizers' role order, not the slug's
        // alphabet; after the chosen column, a night's tasks follow it too.
        $rank = TaskOrder::sqlRank();
        $orderBy = 'role_slug' === $orderBy ? $rank : $orderBy;

        $perPage = max(1, (int) ($args['per_page'] ?? 50));
        $page = max(1, (int) ($args['page'] ?? 1));

        $params[] = $perPage;
        $params[] = ($page - 1) * $perPage;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE {$where}
                ORDER BY {$orderBy} {$order}, {$rank} ASC, starts_at ASC, id ASC
                LIMIT %d OFFSET %d",
                ...$params
            ),
            ARRAY_A
        );

        return $this->hydrate($rows);
    }

    public function count(bool $upcomingOnly = false): int
    {
        global $wpdb;

        if (! $upcomingOnly) {
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table()} WHERE deleted_at IS NULL");
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table()} WHERE task_date >= %s AND deleted_at IS NULL",
                current_time('Y-m-d')
            )
        );
    }

    /**
     * Who holds a slot of each of the given tasks, keyed by task id: each
     * person's display name ('' when they gave none), in sign-up order. Only
     * statuses that occupy a slot count, as in occupancyFor(); the length of
     * a task's list is its occupancy. Never the email address.
     *
     * @param array<int, int> $taskIds
     * @return array<int, list<string>>
     */
    public function occupantsFor(array $taskIds): array
    {
        global $wpdb;

        $taskIds = array_values(array_unique(array_map('intval', $taskIds)));

        if ([] === $taskIds) {
            return [];
        }

        $idPlaceholders = implode(',', array_fill(0, count($taskIds), '%d'));
        $statuses = AssignmentStatus::occupying();
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));
        $people = Schema::table(Schema::PEOPLE);

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only; the table names come from Schema.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.task_id, p.display_name
                FROM {$this->assignmentsTable()} a
                LEFT JOIN {$people} p ON p.id = a.person_id
                WHERE a.task_id IN ({$idPlaceholders})
                  AND a.status IN ({$statusPlaceholders})
                ORDER BY a.signed_up_at ASC, a.id ASC",
                ...array_merge($taskIds, $statuses)
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $names = array_fill_keys($taskIds, []);

        foreach (is_array($rows) ? $rows : [] as $row) {
            $names[(int) $row['task_id']][] = trim((string) ($row['display_name'] ?? ''));
        }

        return $names;
    }

    /**
     * How many slots of each of the given tasks are currently taken, keyed by
     * task id. Only statuses that still occupy a slot are counted, so a
     * no-show frees their place for someone else.
     *
     * @param array<int, int> $taskIds
     * @return array<int, int>
     */
    public function occupancyFor(array $taskIds): array
    {
        global $wpdb;

        $taskIds = array_values(array_unique(array_map('intval', $taskIds)));

        if ([] === $taskIds) {
            return [];
        }

        $idPlaceholders = implode(',', array_fill(0, count($taskIds), '%d'));
        $statuses = AssignmentStatus::occupying();
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT task_id, COUNT(*) AS taken
                FROM {$this->assignmentsTable()}
                WHERE task_id IN ({$idPlaceholders})
                  AND status IN ({$statusPlaceholders})
                GROUP BY task_id",
                ...array_merge($taskIds, $statuses)
            ),
            ARRAY_A
        );

        $counts = array_fill_keys($taskIds, 0);

        foreach (is_array($rows) ? $rows : [] as $row) {
            $counts[(int) $row['task_id']] = (int) $row['taken'];
        }

        return $counts;
    }

    /**
     * Whether a date still has unfilled slots anywhere. Drives the 48h
     * open-task call, which must send nothing at all when everything is
     * already staffed.
     */
    /**
     * When the day's work actually begins: the earliest start time recorded on
     * $date, or null when nothing on it has a time.
     *
     * This is what a lead time should count back from. Counting from the date's
     * midnight instead - which is what every lead did until 1.17.1 - makes a
     * "48 hours before" notice arrive 65 hours before a task that starts at
     * five in the afternoon.
     */
    public function earliestStartOn(string $date): ?string
    {
        global $wpdb;

        $earliest = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MIN(starts_at) FROM {$this->table()}
                WHERE task_date = %s AND starts_at IS NOT NULL AND deleted_at IS NULL",
                $date
            )
        );

        return null === $earliest ? null : (string) $earliest;
    }

    /**
     * Whether a task on $date was created after $since (a 'Y-m-d H:i:s' local
     * timestamp). This is what separates "we already told them about this date"
     * from "we told them about this date, but a job has been added since" - the
     * second is worth mailing about again, the first is not.
     *
     * Restricted tasks (Organize, DJ, ...) do not count: they are not places
     * anyone is asked to fill, so adding one to a date is no reason to mail
     * everyone again.
     */
    public function hasTaskCreatedSince(string $date, string $since): bool
    {
        global $wpdb;

        $restricted = Roles::restrictedSlugs();
        $rolePlaceholders = implode(',', array_fill(0, count($restricted), '%s'));

        return null !== $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->table()}
                WHERE task_date = %s AND created_at > %s AND deleted_at IS NULL
                  AND role_slug NOT IN ({$rolePlaceholders})
                LIMIT 1",
                array_merge([$date, $since], $restricted)
            )
        );
    }

    /**
     * Whether the guest board (the Telegram group's) has a task with a free
     * place on $date: what BoardPush re-posts the board for. A task the guest
     * board does not show (BoardVisibility: an archived role, Guest display
     * off, restricted) does not count.
     */
    public function hasOpenSlotsOn(string $date): bool
    {
        $tasks = array_values(array_filter(
            $this->forDate($date),
            static fn (Task $task): bool => BoardVisibility::onGuestBoard($task)
        ));

        if ([] === $tasks) {
            return false;
        }

        $occupancy = $this->occupancyFor(array_map(
            static fn (Task $task): int => $task->id,
            $tasks
        ));

        foreach ($tasks as $task) {
            // A task with no limit always has room, so it never makes a date
            // look as if it still needs people.
            if ($task->hasNoLimit()) {
                continue;
            }

            if (($occupancy[$task->id] ?? 0) < $task->capacity) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $rows
     * @return array<int, Task>
     */
    private function hydrate(mixed $rows): array
    {
        return array_map(
            static fn (array $row): Task => Task::fromRow($row),
            is_array($rows) ? $rows : []
        );
    }

    private function safeOrderBy(string $column): string
    {
        $allowed = ['id', 'task_date', 'role_slug', 'capacity'];

        return in_array($column, $allowed, true) ? $column : 'task_date';
    }
}
