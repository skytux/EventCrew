<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- wpdb's own method names.

/**
 * Answers the crew page's reads by what they ask for, not by their order, so a
 * test of the Me tab or the board can render the whole page: the signed-in
 * person, the upcoming tasks, a task by id, the assignments of a person or of
 * a task, how many are on each task, the people on a task, and the
 * person's free-entry tickets with any offer waiting on them. Anything else falls back to FakeWpdb's
 * queues (empty answers when nothing is queued).
 */
final class MeTabWpdb extends \FakeWpdb
{
    /** @var array<int, array<string, mixed>> People by id. */
    public array $people = [];

    /** @var list<array<string, mixed>> Task rows, the board's upcoming list. */
    public array $tasks = [];

    /** @var list<array<string, mixed>> Assignment rows. */
    public array $assignments = [];

    /** @var list<array<string, mixed>> Free-entry tickets (redemption rows). */
    public array $redemptions = [];

    /** @var list<array<string, mixed>> Ticket transfer rows (offers). */
    public array $transfers = [];

    public function get_row(string $sql, string $output = 'OBJECT'): mixed
    {
        if (1 === preg_match('/eventcrew_people WHERE id = (\d+)/', $sql, $m)) {
            $this->queries[] = $sql;

            return $this->people[(int) $m[1]] ?? null;
        }

        if (1 === preg_match('/eventcrew_tasks WHERE id = (\d+)/', $sql, $m)) {
            $this->queries[] = $sql;

            foreach ($this->tasks as $task) {
                if ((int) $task['id'] === (int) $m[1]) {
                    return $task;
                }
            }

            return null;
        }

        if (1 === preg_match('/eventcrew_assignments WHERE id = (\d+)/', $sql, $m)) {
            $this->queries[] = $sql;

            foreach ($this->assignments as $row) {
                if ((int) $row['id'] === (int) $m[1]) {
                    return $row;
                }
            }

            return null;
        }

        if (1 === preg_match('/eventcrew_redemptions WHERE id = (\d+)/', $sql, $m)) {
            $this->queries[] = $sql;

            foreach ($this->redemptions as $row) {
                if ((int) $row['id'] === (int) $m[1]) {
                    return $row;
                }
            }

            return null;
        }

        if (1 === preg_match("/eventcrew_ticket_transfers WHERE redemption_id = (\\d+) AND status = '?(\\w+)'?/", $sql, $m)) {
            $this->queries[] = $sql;

            foreach (array_reverse($this->transfers) as $row) {
                if ((int) $row['redemption_id'] === (int) $m[1] && $row['status'] === $m[2]) {
                    return $row;
                }
            }

            return null;
        }

        if (1 === preg_match('/eventcrew_assignments WHERE task_id = (\d+) AND person_id = (\d+)/', $sql, $m)) {
            $this->queries[] = $sql;

            foreach ($this->assignments as $row) {
                if ((int) $row['task_id'] === (int) $m[1] && (int) $row['person_id'] === (int) $m[2]) {
                    return $row;
                }
            }

            return null;
        }

        return parent::get_row($sql, $output);
    }

    public function get_results(string $sql, string $output = 'OBJECT'): mixed
    {
        if (1 === preg_match('/eventcrew_tasks WHERE id IN \(([\d, ]+)\)/', $sql, $m)) {
            $this->queries[] = $sql;
            $ids = array_map('intval', explode(',', $m[1]));

            return array_values(array_filter(
                $this->tasks,
                static fn (array $task): bool => in_array((int) $task['id'], $ids, true)
            ));
        }

        if (str_contains($sql, 'eventcrew_tasks') && str_contains($sql, 'ORDER BY task_date') && ! str_contains($sql, 'JOIN')) {
            $this->queries[] = $sql;

            return $this->tasks;
        }

        if (1 === preg_match('/eventcrew_assignments WHERE person_id = (\d+) ORDER BY/', $sql, $m)) {
            $this->queries[] = $sql;

            return array_values(array_filter(
                $this->assignments,
                static fn (array $row): bool => (int) $row['person_id'] === (int) $m[1]
            ));
        }

        if (1 === preg_match('/eventcrew_assignments WHERE task_id = (\d+) ORDER BY/', $sql, $m)) {
            $this->queries[] = $sql;

            return array_values(array_filter(
                $this->assignments,
                static fn (array $row): bool => (int) $row['task_id'] === (int) $m[1]
            ));
        }

        if (1 === preg_match('/eventcrew_redemptions WHERE person_id = (\d+) AND cancelled_at IS NULL/', $sql, $m)) {
            $this->queries[] = $sql;

            return array_values(array_filter(
                $this->redemptions,
                static fn (array $row): bool => (int) $row['person_id'] === (int) $m[1] && null === ($row['cancelled_at'] ?? null)
            ));
        }

        // occupancyFor(): the signed-up rows per task.
        if (str_contains($sql, 'COUNT(*) AS taken') && str_contains($sql, 'GROUP BY task_id')) {
            $this->queries[] = $sql;
            $counts = [];

            foreach ($this->assignments as $row) {
                if (in_array($row['status'], \EventCrew\Support\AssignmentStatus::occupying(), true)) {
                    $counts[(int) $row['task_id']] = ($counts[(int) $row['task_id']] ?? 0) + 1;
                }
            }

            return array_map(
                static fn (int $taskId, int $taken): array => ['task_id' => $taskId, 'taken' => $taken],
                array_keys($counts),
                array_values($counts)
            );
        }

        return parent::get_results($sql, $output);
    }
}
