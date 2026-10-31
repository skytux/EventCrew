<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;

/**
 * All reads and writes of the door check-ins table: who has come in on one
 * night, and how many people they brought.
 *
 * A row exists only while someone is marked in. Un-marking deletes it, so the
 * table answers "who is in right now" without a status column.
 */
final class CheckinRepository
{
    public const KIND_CREW = 'crew';
    public const KIND_CREDIT = 'credit';
    public const KIND_GUEST = 'guest';
    public const KIND_BUYER = 'buyer';

    /** Most plus-ones one row may carry - the same typo guard the guest list uses. */
    public const MAX_PLUS_ONES = GuestRepository::MAX_PLUS_ONES;

    private function table(): string
    {
        return Schema::table(Schema::CHECKINS);
    }

    /**
     * @return array<int, string>
     */
    public static function kinds(): array
    {
        return [self::KIND_CREW, self::KIND_CREDIT, self::KIND_GUEST, self::KIND_BUYER];
    }

    /**
     * Every check-in on $date, keyed "kind:ref_id", each holding its plus-ones.
     *
     * @return array<string, int>
     */
    public function forDate(string $date): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT kind, ref_id, plus_ones FROM {$this->table()} WHERE event_date = %s",
                $date
            ),
            ARRAY_A
        );

        $checkins = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $checkins[(string) $row['kind'] . ':' . (int) $row['ref_id']] = (int) $row['plus_ones'];
        }

        return $checkins;
    }

    /**
     * Marks one entry in. A second mark of the same entry - two door phones
     * tapping at once - lands on the unique key and only refreshes plus_ones,
     * so there is never a second row. Returns true when this call created the
     * row, false when the entry was already in.
     */
    public function mark(string $date, string $kind, int $refId, int $plusOnes, int $checkedBy): bool
    {
        global $wpdb;

        // MySQL reports 1 affected row for a fresh insert, 2 for an update
        // through ON DUPLICATE KEY, and 0 when the update changed nothing.
        $affected = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$this->table()} (event_date, kind, ref_id, plus_ones, checked_in_at, checked_by)
                VALUES (%s, %s, %d, %d, %s, %d)
                ON DUPLICATE KEY UPDATE plus_ones = VALUES(plus_ones)",
                $date,
                $kind,
                $refId,
                self::clampPlusOnes($plusOnes),
                current_time('mysql'),
                $checkedBy
            )
        );

        return 1 === $affected;
    }

    /**
     * Un-marks one entry. Returns true when a row was removed.
     */
    public function unmark(string $date, string $kind, int $refId): bool
    {
        global $wpdb;

        return (int) $wpdb->delete(
            $this->table(),
            ['event_date' => $date, 'kind' => $kind, 'ref_id' => $refId],
            ['%s', '%s', '%d']
        ) > 0;
    }

    /**
     * Changes the plus-ones of an entry that is already in. An entry not in
     * has no row, and this writes nothing for it.
     */
    public function setPlusOnes(string $date, string $kind, int $refId, int $plusOnes): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table(),
            ['plus_ones' => self::clampPlusOnes($plusOnes)],
            ['event_date' => $date, 'kind' => $kind, 'ref_id' => $refId],
            ['%d'],
            ['%s', '%s', '%d']
        );
    }

    public static function clampPlusOnes(int $plusOnes): int
    {
        return max(0, min(self::MAX_PLUS_ONES, $plusOnes));
    }
}
