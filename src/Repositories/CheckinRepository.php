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

    /** The kinds a task's ticket is marked in as, for inAt(), in order. */
    public const TASK_TICKET_KINDS = [self::KIND_CREW];

    /** The kinds a free-entry ticket is marked in as, for inAt(), in order. */
    public const CREDIT_TICKET_KINDS = [self::KIND_CREDIT];

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
     * Every check-in on $date, keyed "kind:ref_id", each holding its
     * plus-ones and when it was marked in (site time, '' when not known).
     *
     * @return array<string, array{plus: int, at: string}>
     */
    public function forDate(string $date): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT kind, ref_id, plus_ones, checked_in_at FROM {$this->table()} WHERE event_date = %s",
                $date
            ),
            ARRAY_A
        );

        $checkins = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $checkins[(string) $row['kind'] . ':' . (int) $row['ref_id']] = [
                'plus' => (int) $row['plus_ones'],
                'at' => (string) ($row['checked_in_at'] ?? ''),
            ];
        }

        return $checkins;
    }

    /**
     * Every night this person was marked in as crew or as a free-entry
     * credit, in one read: event date => kind => checked_in_at (site time).
     * For a list of their tickets (inAtFrom()).
     *
     * @return array<string, array<string, string>>
     */
    public function personTimes(int $personId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT event_date, kind, checked_in_at FROM {$this->table()}
                WHERE ref_id = %d AND kind IN (%s, %s)",
                $personId,
                self::KIND_CREW,
                self::KIND_CREDIT
            ),
            ARRAY_A
        );

        $times = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $times[(string) $row['event_date']][(string) $row['kind']] = (string) $row['checked_in_at'];
        }

        return $times;
    }

    /**
     * inAt() answered from personTimes(): when the person was marked in on
     * $date under the first of $kinds that has a time, or null.
     *
     * @param array<string, array<string, string>> $times
     * @param array<int, string> $kinds
     */
    public static function inAtFrom(array $times, string $date, array $kinds): ?string
    {
        foreach ($kinds as $kind) {
            if (isset($times[$date][$kind])) {
                return $times[$date][$kind];
            }
        }

        return null;
    }

    /**
     * When this entry was marked in on $date (site time), or null when it is
     * not in. $kinds are tried in order and the first that has a row answers.
     * A task's ticket is the crew row and a free-entry ticket the credit row,
     * each checked in on its own.
     *
     * @param array<int, string> $kinds
     */
    public function inAt(string $date, array $kinds, int $refId): ?string
    {
        foreach ($kinds as $kind) {
            $times = $this->checkedInAt($date, $kind, [$refId]);

            if ([] !== $times) {
                return reset($times);
            }
        }

        return null;
    }

    /**
     * When this entry was marked in on $date under any of $kinds (site time),
     * or null when it is not in. One query on the one_per_entry key, cheap
     * enough for the ticket page's live wait to ask once a second.
     *
     * @param array<int, string> $kinds
     */
    public function anyInAt(string $date, array $kinds, int $refId): ?string
    {
        global $wpdb;

        $kinds = array_values(array_intersect(self::kinds(), $kinds));

        if ([] === $kinds || $refId <= 0) {
            return null;
        }

        $in = implode(', ', array_fill(0, count($kinds), '%s'));

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only; the table name comes from Schema.
        $at = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT checked_in_at FROM {$this->table()}
                WHERE event_date = %s AND kind IN ({$in}) AND ref_id = %d
                ORDER BY checked_in_at ASC LIMIT 1",
                ...array_merge([$date], $kinds, [$refId])
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return null === $at ? null : (string) $at;
    }

    /**
     * When each of these entries of one kind was marked in on $date, keyed by
     * ref_id. An entry not in has no row, and no key.
     *
     * @param array<int, int> $refIds
     * @return array<int, string> ref_id => checked_in_at (site time)
     */
    public function checkedInAt(string $date, string $kind, array $refIds): array
    {
        global $wpdb;

        $refIds = array_values(array_unique(array_map('intval', $refIds)));

        if ([] === $refIds) {
            return [];
        }

        $in = implode(', ', array_fill(0, count($refIds), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only; the table name comes from Schema.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ref_id, checked_in_at FROM {$this->table()}
                WHERE event_date = %s AND kind = %s AND ref_id IN ({$in})",
                ...array_merge([$date, $kind], $refIds)
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $times = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $times[(int) $row['ref_id']] = (string) $row['checked_in_at'];
        }

        return $times;
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
