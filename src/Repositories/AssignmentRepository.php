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

    /** A previously freed row (a past cancellation) was reactivated. */
    public const JOIN_REJOINED = 'rejoined';

    /** This person already holds a slot in this task. */
    public const JOIN_DUPLICATE = 'already_joined';

    /** Every slot was taken by the time the write ran. */
    public const JOIN_FULL = 'full';

    /** No task with that id. */
    public const JOIN_UNKNOWN_TASK = 'unknown_task';

    private function table(): string
    {
        return Schema::table(Schema::ASSIGNMENTS);
    }

    private function tasksTable(): string
    {
        return Schema::table(Schema::TASKS);
    }

    /**
     * Claims a slot, or explains why it couldn't.
     *
     * The capacity check and the insert are deliberately one statement. Two
     * people tapping the same [Join] button in a Telegram group land in
     * two PHP processes within milliseconds of each other, so a read-then-write
     * would let both see "1 of 2 taken" and both write - overbooking the task.
     * Here the database evaluates the count at write time, and the loser simply
     * inserts zero rows.
     *
     * The unique key on (task_id, person_id) covers the other race: the
     * same person double-tapping. That one surfaces as an insert failure
     * rather than a duplicate row.
     *
     * @return self::JOIN_*
     */
    public function join(int $taskId, int $personId): string
    {
        global $wpdb;

        if (null === $this->taskCapacity($taskId)) {
            return self::JOIN_UNKNOWN_TASK;
        }

        $existing = $this->findFor($taskId, $personId);

        if ($existing instanceof Assignment) {
            // An occupying row is a genuine duplicate; a freed one (a prior
            // cancellation) is reactivated, since the unique key means we
            // cannot insert a second row for this person and task.
            return $existing->isOccupying()
                ? self::JOIN_DUPLICATE
                : $this->reactivate($existing->id, $taskId);
        }

        $statuses = AssignmentStatus::occupying();
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));

        // The occupancy subquery reads the same table this statement inserts
        // into. MySQL rejects that when the subquery is uncorrelated and
        // directly names the target, so it is wrapped in a derived table -
        // which also forces materialisation, giving a stable count.
        $sql = "INSERT INTO {$this->table()} (task_id, person_id, status, signed_up_at)
            SELECT %d, %d, %s, %s
            FROM (SELECT 1) AS placeholder
            WHERE (
                SELECT COUNT(*)
                FROM (SELECT task_id, status FROM {$this->table()}) AS existing
                WHERE existing.task_id = %d
                  AND existing.status IN ({$statusPlaceholders})
            ) < COALESCE((SELECT capacity FROM {$this->tasksTable()} WHERE id = %d), 0)";

        $params = array_merge(
            [$taskId, $personId, AssignmentStatus::SIGNED_UP, current_time('mysql'), $taskId],
            $statuses,
            [$taskId]
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
            return $this->findFor($taskId, $personId) instanceof Assignment
                ? self::JOIN_DUPLICATE
                : self::JOIN_FULL;
        }

        return self::JOIN_FULL;
    }

    /**
     * Brings a freed row (a past cancellation) back to signed_up under the same
     * capacity guard the insert uses, so re-joining after a cancel cannot
     * overbook. The occupancy subquery is wrapped in a derived table for the
     * same reason as in join() - MySQL forbids naming the UPDATE target in an
     * uncorrelated subquery directly.
     *
     * @return self::JOIN_REJOINED|self::JOIN_FULL
     */
    private function reactivate(int $assignmentId, int $taskId): string
    {
        global $wpdb;

        $statuses = AssignmentStatus::occupying();
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));
        $now = current_time('mysql');

        $sql = "UPDATE {$this->table()} AS target
            SET target.status = %s, target.signed_up_at = %s, target.status_changed_at = %s
            WHERE target.id = %d
              AND (
                SELECT COUNT(*)
                FROM (SELECT task_id, status FROM {$this->table()}) AS existing
                WHERE existing.task_id = %d
                  AND existing.status IN ({$statusPlaceholders})
              ) < COALESCE((SELECT capacity FROM {$this->tasksTable()} WHERE id = %d), 0)";

        $params = array_merge(
            [AssignmentStatus::SIGNED_UP, $now, $now, $assignmentId, $taskId],
            $statuses,
            [$taskId]
        );

        // Assembled from table-name constants and generated placeholders, then
        // bound through prepare(); the sniff cannot follow a query in a variable.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return 1 === $wpdb->query($wpdb->prepare($sql, ...$params))
            ? self::JOIN_REJOINED
            : self::JOIN_FULL;
    }

    /**
     * Capacity of a task, or null when no such task exists.
     *
     * Read separately from the conditional insert purely so a missing task
     * can be reported as its own outcome - the insert itself would just write
     * nothing, which is indistinguishable from a full task.
     */
    private function taskCapacity(int $taskId): ?int
    {
        global $wpdb;

        $capacity = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT capacity FROM {$this->tasksTable()} WHERE id = %d",
                $taskId
            )
        );

        return null === $capacity ? null : (int) $capacity;
    }

    /**
     * Cancels a person's slot, keeping the row for the reputation history
     * rather than deleting it. Which cancellation it is depends on how much
     * notice was given: inside `$noticeHours` of the task's start it is a
     * `late_cancel` (a replacement is now hard to find); earlier than that it
     * is a plain `cancelled` with no penalty. `setStatus()` stamps
     * `status_changed_at`, which is the "when" a later reputation score needs.
     *
     * @return string The status recorded, or '' when there was no live slot to
     *                cancel.
     */
    public function cancel(int $taskId, int $personId, int $noticeHours): string
    {
        $assignment = $this->findFor($taskId, $personId);

        if (! $assignment instanceof Assignment || ! $assignment->isOccupying()) {
            return '';
        }

        $status = $this->cancellationStatus($taskId, $noticeHours);
        $this->setStatus($assignment->id, $status);

        return $status;
    }

    /**
     * `late_cancel` when now is within the notice window of the task's start,
     * else `cancelled`. An untimed task, or one whose start cannot be read,
     * defaults to the no-penalty `cancelled`.
     */
    private function cancellationStatus(int $taskId, int $noticeHours): string
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT starts_at, task_date FROM {$this->tasksTable()} WHERE id = %d",
                $taskId
            ),
            ARRAY_A
        );

        if (! is_array($row)) {
            return AssignmentStatus::CANCELLED;
        }

        $when = (string) ($row['starts_at'] ?? '');

        if ('' === $when) {
            $when = (string) ($row['task_date'] ?? '') . ' 00:00:00';
        }

        $start = strtotime($when);
        $now = strtotime((string) current_time('mysql'));

        if (false === $start || false === $now) {
            return AssignmentStatus::CANCELLED;
        }

        return ($start - $now) < $noticeHours * HOUR_IN_SECONDS
            ? AssignmentStatus::LATE_CANCEL
            : AssignmentStatus::CANCELLED;
    }

    /**
     * Hard-deletes a slot. Retained for the rare case a row must truly vanish
     * (e.g. a mistake), but the bot cancels rather than deletes so the history
     * survives - see cancel().
     */
    public function leave(int $taskId, int $personId): bool
    {
        global $wpdb;

        return 1 === $wpdb->delete(
            $this->table(),
            ['task_id' => $taskId, 'person_id' => $personId]
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

    public function findFor(int $taskId, int $personId): ?Assignment
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE task_id = %d AND person_id = %d",
                $taskId,
                $personId
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
    public function forTask(int $taskId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE task_id = %d ORDER BY signed_up_at ASC, id ASC",
                $taskId
            ),
            ARRAY_A
        );

        return $this->hydrate($rows);
    }

    /**
     * @return array<int, Assignment>
     */
    public function forPerson(int $personId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE person_id = %d ORDER BY id DESC",
                $personId
            ),
            ARRAY_A
        );

        return $this->hydrate($rows);
    }

    /**
     * A person's assignments with each one's task date attached, which is
     * what the reputation weighting needs to know how long ago something
     * happened without a second query per row.
     *
     * @return array<int, array{assignment: Assignment, task_date: string}>
     */
    public function historyFor(int $personId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.*, s.task_date
                FROM {$this->table()} a
                INNER JOIN {$this->tasksTable()} s ON s.id = a.task_id
                WHERE a.person_id = %d
                ORDER BY s.task_date DESC",
                $personId
            ),
            ARRAY_A
        );

        $history = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $history[] = [
                'assignment' => Assignment::fromRow($row),
                'task_date' => (string) ($row['task_date'] ?? ''),
            ];
        }

        return $history;
    }

    /**
     * Person ids already holding a slot on a given date. The 48h open-task
     * call excludes these people - nagging someone who has already signed up is
     * the fastest way to lose their consent.
     *
     * @return array<int, int>
     */
    public function personIdsAssignedOn(string $date): array
    {
        global $wpdb;

        $statuses = AssignmentStatus::occupying();
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT a.person_id
                FROM {$this->table()} a
                INNER JOIN {$this->tasksTable()} s ON s.id = a.task_id
                WHERE s.task_date = %s AND a.status IN ({$statusPlaceholders})",
                $date,
                ...$statuses
            )
        );

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    public function countCompletedFor(int $personId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table()} WHERE person_id = %d AND status = %s",
                $personId,
                AssignmentStatus::COMPLETED
            )
        );
    }

    /**
     * Whether a person already holds a slot in any task overlapping the
     * given one, used to stop someone signing up for two jobs at once.
     */
    public function hasOverlapping(int $personId, int $taskId): bool
    {
        global $wpdb;

        $statuses = AssignmentStatus::occupying();
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));

        // Tasks with no times recorded cannot be proven to overlap, so they
        // are treated as not overlapping rather than blocking a legitimate
        // second signup on the same day.
        //
        // There is deliberately no task_date equality here any more. While
        // starts_at and ends_at were bare times, comparing them was only
        // meaningful within one day, so the date had to match; now that they
        // are absolute datetimes the comparison stands on its own - and
        // requiring equal dates would have missed the case this exists to
        // catch, someone signed up for a Saturday task ending 01:00 Sunday
        // and a Sunday task starting 00:30.
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                FROM {$this->table()} a
                INNER JOIN {$this->tasksTable()} s ON s.id = a.task_id
                INNER JOIN {$this->tasksTable()} target ON target.id = %d
                WHERE a.person_id = %d
                  AND a.task_id <> target.id
                  AND a.status IN ({$statusPlaceholders})
                  AND s.starts_at IS NOT NULL AND s.ends_at IS NOT NULL
                  AND target.starts_at IS NOT NULL AND target.ends_at IS NOT NULL
                  AND s.starts_at < target.ends_at
                  AND target.starts_at < s.ends_at",
                $taskId,
                $personId,
                ...$statuses
            )
        );

        return $count > 0;
    }

    public function deleteForPerson(int $personId): void
    {
        global $wpdb;

        $wpdb->delete($this->table(), ['person_id' => $personId]);
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
