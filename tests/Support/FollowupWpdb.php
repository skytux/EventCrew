<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- wpdb's own method names.

/**
 * A database double for FollowupMailTest that answers the post-event
 * email's reads from arrays instead of a queue, so a test states the night
 * rather than the order of its queries, and that applies the status updates
 * to those arrays.
 */
final class FollowupWpdb extends \FakeWpdb
{
    /** @var array<int, array<string, mixed>> */
    public array $buyers = [];

    /** @var array<int, array<string, mixed>> */
    public array $guests = [];

    /** @var list<array{kind: string, ref_id: int}> */
    public array $checkins = [];

    /** @var list<array<string, mixed>> */
    public array $organizers = [];

    /** @var list<list<array<string, mixed>>> counts() rows, buyers then guests. */
    public array $countRows = [];

    /** @var list<array<string, mixed>> The night's task rows (TaskRepository::forDate()). */
    public array $tasks = [];

    public function get_results(string $sql, string $output = 'OBJECT'): mixed
    {
        $this->queries[] = $sql;

        if (str_contains($sql, 'eventcrew_tasks') && [] !== $this->tasks) {
            return $this->tasks;
        }

        if (str_contains($sql, 'eventcrew_checkins')) {
            return $this->checkins;
        }

        if (str_contains($sql, 'COUNT(*)')) {
            return array_shift($this->countRows) ?? [];
        }

        if (str_contains($sql, 'eventcrew_ticket_buyers')) {
            return $this->rowsOf($this->buyers, $sql);
        }

        if (str_contains($sql, 'eventcrew_guests')) {
            return $this->rowsOf($this->guests, $sql);
        }

        if (str_contains($sql, 'is_organizer = 1')) {
            return $this->organizers;
        }

        return array_shift($this->nextResults) ?? [];
    }

    public function get_col(string $sql): mixed
    {
        $this->queries[] = $sql;

        if (1 !== preg_match("/BETWEEN '([0-9-]+)' AND '([0-9-]+)'/", $sql, $range)) {
            return array_shift($this->nextCols) ?? [];
        }

        $dates = [];

        foreach (array_merge($this->buyers, $this->guests) as $row) {
            if ('' !== $row['email'] && $row['event_date'] >= $range[1] && $row['event_date'] <= $range[2]) {
                $dates[] = $row['event_date'];
            }
        }

        return array_values(array_unique($dates));
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        $id = (int) ($where['id'] ?? 0);

        if (str_ends_with($table, 'eventcrew_ticket_buyers') && isset($this->buyers[$id])) {
            $this->buyers[$id] = array_merge($this->buyers[$id], $data);
        } elseif (str_ends_with($table, 'eventcrew_guests') && isset($this->guests[$id])) {
            $this->guests[$id] = array_merge($this->guests[$id], $data);
        }

        return parent::update($table, $data, $where);
    }

    public function statusOf(string $kind, int $id): string
    {
        $rows = 'guest' === $kind ? $this->guests : $this->buyers;

        return (string) ($rows[$id]['followup_mail_status'] ?? '');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function rowsOf(array $rows, string $sql): array
    {
        preg_match("/event_date = '([0-9-]+)'/", $sql, $date);
        // A row without an address is only left out when the query asks.
        $withEmail = str_contains($sql, "email <> ''");

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['event_date'] === ($date[1] ?? ''))
                && (! $withEmail || '' !== $row['email'])
        ));
    }
}
