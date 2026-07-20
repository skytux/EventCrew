<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;
use EventCrew\Models\Assignment;
use EventCrew\Support\AssignmentStatus;

/**
 * All reads and writes of the assignments table.
 *
 * This is the storage seam the rest of the plugin is written against - no
 * service builds assignment SQL of its own - so if the table ever needs to
 * become something else, this is the only file that changes.
 */
final class AssignmentRepository
{
    /** The join succeeded and a row now exists. */
    public const JOIN_OK = 'joined';

    /** This volunteer already holds a slot in this shift. */
    public const JOIN_DUPLICATE = 'already_joined';

    /** Every slot was taken by the time the write ran. */
    public const JOIN_FULL = 'full';

    /** No shift with that id. */
    public const JOIN_UNKNOWN_SHIFT = 'unknown_shift';

    private function table(): string
    {
        return Schema::table(Schema::ASSIGNMENTS);
    }

    private function shiftsTable(): string
    {
        return Schema::table(Schema::SHIFTS);
    }

    /**
     * Claims a slot, or explains why it couldn't.
     *
     * The capacity check and the insert are deliberately one statement. Two
     * volunteers tapping the same [Join] button in a Telegram group land in
     * two PHP processes within milliseconds of each other, so a read-then-write
     * would let both see "1 of 2 taken" and both write - overbooking the shift.
     * Here the database evaluates the count at write time, and the loser simply
     * inserts zero rows.
     *
     * The unique key on (shift_id, volunteer_id) covers the other race: the
     * same volunteer double-tapping. That one surfaces as an insert failure
     * rather than a duplicate row.
     *
     * @return self::JOIN_*
     */
    public function join(int $shiftId, int $volunteerId): string
    {
        global $wpdb;

        if (null === $this->shiftCapacity($shiftId)) {
            return self::JOIN_UNKNOWN_SHIFT;
        }

        if ($this->findFor($shiftId, $volunteerId) instanceof Assignment) {
            return self::JOIN_DUPLICATE;
        }

        $statuses = AssignmentStatus::occupying();
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));

        // The occupancy subquery reads the same table this statement inserts
        // into. MySQL rejects that when the subquery is uncorrelated and
        // directly names the target, so it is wrapped in a derived table -
        // which also forces materialisation, giving a stable count.
        $sql = "INSERT INTO {$this->table()} (shift_id, volunteer_id, status, signed_up_at)
            SELECT %d, %d, %s, %s
            FROM (SELECT 1) AS placeholder
            WHERE (
                SELECT COUNT(*)
                FROM (SELECT shift_id, status FROM {$this->table()}) AS existing
                WHERE existing.shift_id = %d
                  AND existing.status IN ({$statusPlaceholders})
            ) < COALESCE((SELECT capacity FROM {$this->shiftsTable()} WHERE id = %d), 0)";

        $params = array_merge(
            [$shiftId, $volunteerId, AssignmentStatus::SIGNED_UP, current_time('mysql'), $shiftId],
            $statuses,
            [$shiftId]
        );

        // The statement is assembled above from table-name constants and
        // generated placeholders, then passed through prepare() with every
        // value bound - the sniff only flags it because it cannot follow a
        // query held in a variable.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $inserted = $wpdb->query($wpdb->prepare($sql, ...$params));

        if (1 === $inserted) {
            return self::JOIN_OK;
        }

        // Zero rows means the capacity condition failed; false means the write
        // itself errored, and the only error this statement can realistically
        // hit is the unique key - i.e. a double-tap that beat us here.
        if (false === $inserted) {
            return $this->findFor($shiftId, $volunteerId) instanceof Assignment
                ? self::JOIN_DUPLICATE
                : self::JOIN_FULL;
        }

        return self::JOIN_FULL;
    }

    /**
     * Capacity of a shift, or null when no such shift exists.
     *
     * Read separately from the conditional insert purely so a missing shift
     * can be reported as its own outcome - the insert itself would just write
     * nothing, which is indistinguishable from a full shift.
     */
    private function shiftCapacity(int $shiftId): ?int
    {
        global $wpdb;

        $capacity = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT capacity FROM {$this->shiftsTable()} WHERE id = %d",
                $shiftId
            )
        );

        return null === $capacity ? null : (int) $capacity;
    }

    /**
     * Gives up a slot. The row is deleted rather than marked cancelled when
     * the shift is still far enough out that nobody was let down; the caller
     * decides which of those it is, since only it knows the notice period.
     */
    public function leave(int $shiftId, int $volunteerId): bool
    {
        global $wpdb;

        return 1 === $wpdb->delete(
            $this->table(),
            ['shift_id' => $shiftId, 'volunteer_id' => $volunteerId]
        );
    }

    public function find(int $id): ?Assignment
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id),
            ARRAY_A
        );

        return is_array($row) ? Assignment::fromRow($row) : null;
    }

    public function findFor(int $shiftId, int $volunteerId): ?Assignment
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE shift_id = %d AND volunteer_id = %d",
                $shiftId,
                $volunteerId
            ),
            ARRAY_A
        );

        return is_array($row) ? Assignment::fromRow($row) : null;
    }

    public function setStatus(int $id, string $status, ?int $changedBy = null): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table(),
            [
                'status' => $status,
                'status_changed_at' => current_time('mysql'),
                'changed_by' => $changedBy,
            ],
            ['id' => $id]
        );
    }

    public function markReminded(int $id): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table(),
            ['reminded_at' => current_time('mysql')],
            ['id' => $id]
        );
    }

    /**
     * @return array<int, Assignment>
     */
    public function forShift(int $shiftId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE shift_id = %d ORDER BY signed_up_at ASC, id ASC",
                $shiftId
            ),
            ARRAY_A
        );

        return $this->hydrate($rows);
    }

    /**
     * @return array<int, Assignment>
     */
    public function forVolunteer(int $volunteerId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE volunteer_id = %d ORDER BY id DESC",
                $volunteerId
            ),
            ARRAY_A
        );

        return $this->hydrate($rows);
    }

    /**
     * A volunteer's assignments with each one's shift date attached, which is
     * what the reputation weighting needs to know how long ago something
     * happened without a second query per row.
     *
     * @return array<int, array{assignment: Assignment, shift_date: string}>
     */
    public function historyFor(int $volunteerId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.*, s.shift_date
                FROM {$this->table()} a
                INNER JOIN {$this->shiftsTable()} s ON s.id = a.shift_id
                WHERE a.volunteer_id = %d
                ORDER BY s.shift_date DESC",
                $volunteerId
            ),
            ARRAY_A
        );

        $history = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $history[] = [
                'assignment' => Assignment::fromRow($row),
                'shift_date' => (string) ($row['shift_date'] ?? ''),
            ];
        }

        return $history;
    }

    /**
     * Volunteer ids already holding a slot on a given date. The 48h open-shift
     * call excludes these people - nagging someone who has already signed up is
     * the fastest way to lose their consent.
     *
     * @return array<int, int>
     */
    public function volunteerIdsAssignedOn(string $date): array
    {
        global $wpdb;

        $statuses = AssignmentStatus::occupying();
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT a.volunteer_id
                FROM {$this->table()} a
                INNER JOIN {$this->shiftsTable()} s ON s.id = a.shift_id
                WHERE s.shift_date = %s AND a.status IN ({$statusPlaceholders})",
                $date,
                ...$statuses
            )
        );

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    public function countCompletedFor(int $volunteerId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table()} WHERE volunteer_id = %d AND status = %s",
                $volunteerId,
                AssignmentStatus::COMPLETED
            )
        );
    }

    /**
     * Whether a volunteer already holds a slot in any shift overlapping the
     * given one, used to stop someone signing up for two jobs at once.
     */
    public function hasOverlapping(int $volunteerId, int $shiftId): bool
    {
        global $wpdb;

        $statuses = AssignmentStatus::occupying();
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));

        // Shifts with no times recorded cannot be proven to overlap, so they
        // are treated as not overlapping rather than blocking a legitimate
        // second signup on the same day.
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                FROM {$this->table()} a
                INNER JOIN {$this->shiftsTable()} s ON s.id = a.shift_id
                INNER JOIN {$this->shiftsTable()} target ON target.id = %d
                WHERE a.volunteer_id = %d
                  AND a.shift_id <> target.id
                  AND s.shift_date = target.shift_date
                  AND a.status IN ({$statusPlaceholders})
                  AND s.starts_at IS NOT NULL AND s.ends_at IS NOT NULL
                  AND target.starts_at IS NOT NULL AND target.ends_at IS NOT NULL
                  AND s.starts_at < target.ends_at
                  AND target.starts_at < s.ends_at",
                $shiftId,
                $volunteerId,
                ...$statuses
            )
        );

        return $count > 0;
    }

    public function deleteForVolunteer(int $volunteerId): void
    {
        global $wpdb;

        $wpdb->delete($this->table(), ['volunteer_id' => $volunteerId]);
    }

    /**
     * @param mixed $rows
     * @return array<int, Assignment>
     */
    private function hydrate(mixed $rows): array
    {
        return array_map(
            static fn (array $row): Assignment => Assignment::fromRow($row),
            is_array($rows) ? $rows : []
        );
    }
}
