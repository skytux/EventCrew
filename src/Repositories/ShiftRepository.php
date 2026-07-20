<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;
use EventCrew\Models\Shift;
use EventCrew\Support\AssignmentStatus;

/**
 * All reads and writes of the shifts table, plus the occupancy counts that
 * every board and roster needs alongside them.
 */
final class ShiftRepository
{
    private function table(): string
    {
        return Schema::table(Schema::SHIFTS);
    }

    private function assignmentsTable(): string
    {
        return Schema::table(Schema::ASSIGNMENTS);
    }

    public function find(int $id): ?Shift
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id),
            ARRAY_A
        );

        return is_array($row) ? Shift::fromRow($row) : null;
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
                'shift_date' => (string) ($data['shift_date'] ?? ''),
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'task_slug' => (string) ($data['task_slug'] ?? ''),
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
     * Deleting a shift takes its assignments with it. There is no foreign key
     * to cascade, since WordPress installs cannot be relied on to have InnoDB
     * everywhere, so the cleanup is explicit.
     */
    public function delete(int $id): void
    {
        global $wpdb;

        $wpdb->delete($this->assignmentsTable(), ['shift_id' => $id]);
        $wpdb->delete($this->table(), ['id' => $id]);
    }

    /**
     * @return array<int, Shift>
     */
    public function forDate(string $date): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE shift_date = %s ORDER BY starts_at ASC, id ASC",
                $date
            ),
            ARRAY_A
        );

        return $this->hydrate($rows);
    }

    /**
     * @return array<int, Shift>
     */
    public function upcoming(int $limit = 100): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()}
                WHERE shift_date >= %s
                ORDER BY shift_date ASC, starts_at ASC, id ASC
                LIMIT %d",
                current_time('Y-m-d'),
                $limit
            ),
            ARRAY_A
        );

        return $this->hydrate($rows);
    }

    /**
     * Distinct future dates that have at least one shift, for the roster date
     * picker and the Telegram board.
     *
     * @return array<int, string>
     */
    public function upcomingDates(int $limit = 20): array
    {
        global $wpdb;

        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT shift_date FROM {$this->table()}
                WHERE shift_date >= %s
                ORDER BY shift_date ASC
                LIMIT %d",
                current_time('Y-m-d'),
                $limit
            )
        );

        return array_map('strval', is_array($rows) ? $rows : []);
    }

    /**
     * @param array{orderby?: string, order?: string, per_page?: int, page?: int, upcoming_only?: bool} $args
     * @return array<int, Shift>
     */
    public function all(array $args = []): array
    {
        global $wpdb;

        $params = [];
        $where = '1=1';

        if (! empty($args['upcoming_only'])) {
            $where .= ' AND shift_date >= %s';
            $params[] = current_time('Y-m-d');
        }

        $orderBy = $this->safeOrderBy((string) ($args['orderby'] ?? 'shift_date'));
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
                "SELECT COUNT(*) FROM {$this->table()} WHERE shift_date >= %s",
                current_time('Y-m-d')
            )
        );
    }

    /**
     * How many slots of each of the given shifts are currently taken, keyed by
     * shift id. Only statuses that still occupy a slot are counted, so a
     * no-show frees their place for someone else.
     *
     * @param array<int, int> $shiftIds
     * @return array<int, int>
     */
    public function occupancyFor(array $shiftIds): array
    {
        global $wpdb;

        $shiftIds = array_values(array_unique(array_map('intval', $shiftIds)));

        if ([] === $shiftIds) {
            return [];
        }

        $idPlaceholders = implode(',', array_fill(0, count($shiftIds), '%d'));
        $statuses = AssignmentStatus::occupying();
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT shift_id, COUNT(*) AS taken
                FROM {$this->assignmentsTable()}
                WHERE shift_id IN ({$idPlaceholders})
                  AND status IN ({$statusPlaceholders})
                GROUP BY shift_id",
                ...array_merge($shiftIds, $statuses)
            ),
            ARRAY_A
        );

        $counts = array_fill_keys($shiftIds, 0);

        foreach (is_array($rows) ? $rows : [] as $row) {
            $counts[(int) $row['shift_id']] = (int) $row['taken'];
        }

        return $counts;
    }

    /**
     * Whether a date still has unfilled slots anywhere. Drives the 48h
     * open-shift call, which must send nothing at all when everything is
     * already staffed.
     */
    public function hasOpenSlotsOn(string $date): bool
    {
        $shifts = $this->forDate($date);

        if ([] === $shifts) {
            return false;
        }

        $occupancy = $this->occupancyFor(array_map(
            static fn (Shift $shift): int => $shift->id,
            $shifts
        ));

        foreach ($shifts as $shift) {
            if (($occupancy[$shift->id] ?? 0) < $shift->capacity) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $rows
     * @return array<int, Shift>
     */
    private function hydrate(mixed $rows): array
    {
        return array_map(
            static fn (array $row): Shift => Shift::fromRow($row),
            is_array($rows) ? $rows : []
        );
    }

    private function safeOrderBy(string $column): string
    {
        $allowed = ['id', 'shift_date', 'task_slug', 'capacity'];

        return in_array($column, $allowed, true) ? $column : 'shift_date';
    }
}
