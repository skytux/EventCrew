<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;
use EventCrew\Models\Task;
use EventCrew\Support\AssignmentStatus;

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
                'capacity' => max(1, (int) ($data['capacity'] ?? 1)),
                'notes' => (string) ($data['notes'] ?? ''),
                'created_at' => current_time('mysql'),
            ]
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        global $wpdb;

        $wpdb->update($this->table(), $data, ['id' => $id]);
    }

    /**
     * Deleting a task takes its assignments with it. There is no foreign key
     * to cascade, since WordPress installs cannot be relied on to have InnoDB
     * everywhere, so the cleanup is explicit.
     */
    public function delete(int $id): void
    {
        global $wpdb;

        $wpdb->delete($this->assignmentsTable(), ['task_id' => $id]);
        $wpdb->delete($this->table(), ['id' => $id]);
    }

    /**
     * @return array<int, Task>
     */
    public function forDate(string $date): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE task_date = %s ORDER BY starts_at ASC, id ASC",
                $date
            ),
            ARRAY_A
        );

        return $this->hydrate($rows);
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
                WHERE (ends_at IS NULL AND task_date >= %s)
                   OR (ends_at IS NOT NULL AND ends_at >= %s)
                ORDER BY task_date ASC, starts_at ASC, id ASC
                LIMIT %d",
                current_time('Y-m-d'),
                current_time('mysql'),
                $limit
            ),
            ARRAY_A
        );

        return $this->hydrate($rows);
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
                WHERE task_date >= %s
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
        $where = '1=1';

        if (! empty($args['upcoming_only'])) {
            $where .= ' AND task_date >= %s';
            $params[] = current_time('Y-m-d');
        }

        $orderBy = $this->safeOrderBy((string) ($args['orderby'] ?? 'task_date'));
        $order = 'ASC' === strtoupper((string) ($args['order'] ?? 'DESC')) ? 'ASC' : 'DESC';

        $perPage = max(1, (int) ($args['per_page'] ?? 50));
        $page = max(1, (int) ($args['page'] ?? 1));

        $params[] = $perPage;
        $params[] = ($page - 1) * $perPage;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE {$where}
                ORDER BY {$orderBy} {$order}, starts_at ASC, id ASC
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
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table()}");
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table()} WHERE task_date >= %s",
                current_time('Y-m-d')
            )
        );
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
    public function hasOpenSlotsOn(string $date): bool
    {
        $tasks = $this->forDate($date);

        if ([] === $tasks) {
            return false;
        }

        $occupancy = $this->occupancyFor(array_map(
            static fn (Task $task): int => $task->id,
            $tasks
        ));

        foreach ($tasks as $task) {
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
