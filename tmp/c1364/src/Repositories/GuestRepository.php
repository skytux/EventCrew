<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;
use EventCrew\Models\Guest;

/**
 * All reads and writes of the guest list table.
 */
final class GuestRepository
{
    /** Most plus-ones one guest may bring - a typo guard, not a policy. */
    public const MAX_PLUS_ONES = 20;

    private function table(): string
    {
        return Schema::table(Schema::GUESTS);
    }

    /**
     * Every guest on $date, in the order they were added - so the numbers the
     * bot shows stay put while the list grows.
     *
     * @return array<int, Guest>
     */
    public function forDate(string $date): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE event_date = %s ORDER BY id ASC",
                $date
            ),
            ARRAY_A
        );

        return array_map(static fn (array $row): Guest => Guest::fromRow($row), is_array($rows) ? $rows : []);
    }

    public function find(int $id): ?Guest
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id),
            ARRAY_A
        );

        return is_array($row) ? Guest::fromRow($row) : null;
    }

    /**
     * Adds a guest and returns the new row's id (0 on failure).
     */
    public function add(string $date, string $name, int $plusOnes, string $note, ?int $addedBy): int
    {
        global $wpdb;

        $row = [
            'event_date' => $date,
            'name' => $name,
            'plus_ones' => self::clampPlusOnes($plusOnes),
            'note' => $note,
            'created_at' => current_time('mysql'),
        ];
        $formats = ['%s', '%s', '%d', '%s', '%s'];

        // Left out rather than written as 0 when unknown, so the column stays NULL.
        if (null !== $addedBy) {
            $row['added_by'] = $addedBy;
            $formats[] = '%d';
        }

        $wpdb->insert($this->table(), $row, $formats);

        return (int) $wpdb->insert_id;
    }

    public function update(int $id, string $name, int $plusOnes, string $note): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table(),
            ['name' => $name, 'plus_ones' => self::clampPlusOnes($plusOnes), 'note' => $note],
            ['id' => $id],
            ['%s', '%d', '%s'],
            ['%d']
        );
    }

    public function delete(int $id): void
    {
        global $wpdb;

        $wpdb->delete($this->table(), ['id' => $id], ['%d']);
    }

    private static function clampPlusOnes(int $plusOnes): int
    {
        return max(0, min(self::MAX_PLUS_ONES, $plusOnes));
    }
}
