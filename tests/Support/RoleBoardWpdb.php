<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- wpdb's own method names.

/**
 * Answers the board's reads by what they ask for, not by their order: the
 * upcoming tasks, who is on them, the person behind the Telegram id, and
 * whether they are on a task, and a task by id. Anything else falls back to
 * FakeWpdb's queues. An insert of an assignment for person 7 puts them on.
 */
final class RoleBoardWpdb extends \FakeWpdb
{
    /** @var list<array<string, mixed>> */
    public array $tasks = [];

    /** @var list<array{task_id: int, display_name: string}> */
    public array $occupants = [];

    /** @var array<string, mixed>|null */
    public ?array $person = null;

    /** @var array<int, true> Task ids the person is on. */
    public array $onTask = [];

    public function get_results(string $sql, string $output = 'OBJECT'): mixed
    {
        if (str_contains($sql, 'capacity > 0') && str_contains($sql, 'ORDER BY task_date')) {
            $this->queries[] = $sql;

            return $this->tasks;
        }

        if (str_contains($sql, 'p.display_name')) {
            $this->queries[] = $sql;

            return $this->occupants;
        }

        return parent::get_results($sql, $output);
    }

    public function get_row(string $sql, string $output = 'OBJECT'): mixed
    {
        if (str_contains($sql, 'WHERE telegram_user_id =') || 1 === preg_match('/people\b.* WHERE id = /', $sql)) {
            $this->queries[] = $sql;

            return $this->person;
        }

        if (1 === preg_match('/WHERE task_id = (\d+) AND person_id = /', $sql, $m)) {
            $this->queries[] = $sql;

            return isset($this->onTask[(int) $m[1]])
                ? ['id' => 1, 'task_id' => (int) $m[1], 'person_id' => 7, 'status' => 'signed_up']
                : null;
        }

        if (1 === preg_match('/tasks WHERE id = (\d+)/', $sql, $m)) {
            $this->queries[] = $sql;

            foreach ($this->tasks as $row) {
                if ((int) $row['id'] === (int) $m[1]) {
                    return $row;
                }
            }

            return null;
        }

        return parent::get_row($sql, $output);
    }

    public function query(string $sql): int|bool
    {
        $result = parent::query($sql);

        if (is_int($result) && $result > 0 && 1 === preg_match('/INSERT INTO \S*assignments.*SELECT (\d+), 7/s', $sql, $m)) {
            $this->onTask[(int) $m[1]] = true;
            $this->occupants[] = ['task_id' => (int) $m[1], 'display_name' => 'Sam'];
        }

        return $result;
    }
}
