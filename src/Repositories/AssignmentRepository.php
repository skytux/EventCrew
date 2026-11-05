<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;
use EventCrew\Models\Assignment;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\MarkedBy;
use EventCrew\Support\Roles;

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

    /**
     * Refused by cancel(): the task starts within the sign-out lock (or has
     * started), so the person can no longer sign themselves out. Nothing is
     * written. An organizer can still take them off.
     */
    public const CANCEL_LOCKED = 'locked';

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
     * $countsCredit false records that this task should not count toward the
     * person's free-entry credit or standing (asked for restricted-role
     * tasks only); a rejoin overwrites it with the new answer.
     *
     * @return self::JOIN_*
     */
    public function join(int $taskId, int $personId, bool $countsCredit = true): string
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
                : $this->reactivate($existing->id, $taskId, $countsCredit);
        }

        $statuses = AssignmentStatus::occupying();
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));

        // The occupancy subquery reads the same table this statement inserts
        // into. MySQL rejects that when the subquery is uncorrelated and
        // directly names the target, so it is wrapped in a derived table -
        // which also forces materialisation, giving a stable count.
        // A task with no limit (any restricted role's task, Organize included)
        // skips the count: the second condition lets the insert through
        // whatever the first one says.
        $restricted = Roles::restrictedSlugs();
        $rolePlaceholders = implode(',', array_fill(0, count($restricted), '%s'));

        $sql = "INSERT INTO {$this->table()} (task_id, person_id, status, signed_up_at, counts_credit)
            SELECT %d, %d, %s, %s, %d
            FROM (SELECT 1) AS placeholder
            WHERE (
                SELECT COUNT(*)
                FROM (SELECT task_id, status FROM {$this->table()}) AS existing
                WHERE existing.task_id = %d
                  AND existing.status IN ({$statusPlaceholders})
            ) < COALESCE((SELECT capacity FROM {$this->tasksTable()} WHERE id = %d AND deleted_at IS NULL), 0)
            OR EXISTS (SELECT 1 FROM {$this->tasksTable()}
                WHERE id = %d AND deleted_at IS NULL AND role_slug IN ({$rolePlaceholders}))";

        $params = array_merge(
            [$taskId, $personId, AssignmentStatus::SIGNED_UP, current_time('mysql'), $countsCredit ? 1 : 0, $taskId],
            $statuses,
            [$taskId, $taskId],
            $restricted
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
    private function reactivate(int $assignmentId, int $taskId, bool $countsCredit = true): string
    {
        global $wpdb;

        $restricted = Roles::restrictedSlugs();
        $rolePlaceholders = implode(',', array_fill(0, count($restricted), '%s'));

        $statuses = AssignmentStatus::occupying();
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));
        $now = current_time('mysql');

        $sql = "UPDATE {$this->table()} AS target
            SET target.status = %s, target.signed_up_at = %s, target.status_changed_at = %s,
                target.counts_credit = %d
            WHERE target.id = %d
              AND (
                (
                  SELECT COUNT(*)
                  FROM (SELECT task_id, status FROM {$this->table()}) AS existing
                  WHERE existing.task_id = %d
                    AND existing.status IN ({$statusPlaceholders})
                ) < COALESCE((SELECT capacity FROM {$this->tasksTable()} WHERE id = %d AND deleted_at IS NULL), 0)
                OR EXISTS (SELECT 1 FROM {$this->tasksTable()}
                    WHERE id = %d AND deleted_at IS NULL AND role_slug IN ({$rolePlaceholders}))
              )";

        $params = array_merge(
            [AssignmentStatus::SIGNED_UP, $now, $now, $countsCredit ? 1 : 0, $assignmentId, $taskId],
            $statuses,
            [$taskId, $taskId],
            $restricted
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
     * With `$lockMinutes` above 0, a task with a start time that starts in
     * less than that many minutes, or has started, is not cancelled at all:
     * CANCEL_LOCKED comes back and nothing is written. A task without a start
     * time is never locked.
     *
     * With `$free`, the cancel is a plain `cancelled` however close the task
     * is: the person took it inside the second thoughts window.
     *
     * @return string The status recorded, CANCEL_LOCKED, or '' when there was
     *                no live slot to cancel.
     */
    public function cancel(int $taskId, int $personId, int $noticeHours, int $lockMinutes = 0, bool $free = false): string
    {
        $assignment = $this->findFor($taskId, $personId);

        if (! $assignment instanceof Assignment || ! $assignment->isOccupying()) {
            return '';
        }

        $start = $this->secondsToStart($taskId);

        $locked = $lockMinutes > 0 && null !== $start && $start['timed']
            && $start['left'] < $lockMinutes * MINUTE_IN_SECONDS;

        if ($locked) {
            return self::CANCEL_LOCKED;
        }

        $status = $free ? AssignmentStatus::CANCELLED : $this->cancellationStatus($start, $noticeHours);
        $this->setStatus($assignment->id, $status);

        return $status;
    }

    /**
     * `late_cancel` when now is within the notice window of the task's start,
     * else `cancelled`. An untimed task counts from 00:00 of its date; one
     * whose start cannot be read defaults to the no-penalty `cancelled`.
     *
     * @param array{left: int, timed: bool}|null $start From secondsToStart().
     */
    private function cancellationStatus(?array $start, int $noticeHours): string
    {
        if (null === $start) {
            return AssignmentStatus::CANCELLED;
        }

        return $start['left'] < $noticeHours * HOUR_IN_SECONDS
            ? AssignmentStatus::LATE_CANCEL
            : AssignmentStatus::CANCELLED;
    }

    /**
     * Seconds from now until the task starts (negative once it has started),
     * and whether the task has its own start time. An untimed task counts
     * from 00:00 of its date. Null when the task or its date cannot be read.
     *
     * @return array{left: int, timed: bool}|null
     */
    private function secondsToStart(int $taskId): ?array
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
            return null;
        }

        $when = (string) ($row['starts_at'] ?? '');
        $timed = '' !== $when;

        if (! $timed) {
            $when = (string) ($row['task_date'] ?? '') . ' 00:00:00';
        }

        $start = strtotime($when);
        $now = strtotime((string) current_time('mysql'));

        if (false === $start || false === $now) {
            return null;
        }

        return ['left' => $start - $now, 'timed' => $timed];
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

    /**
     * @param ?string $changedByKind Which id $changedBy is: MarkedBy::PERSON or
     *                               MarkedBy::WP_USER. Stored as NULL (not
     *                               known) when there is no $changedBy or the
     *                               kind is not one of the two.
     */
    public function setStatus(int $id, string $status, ?int $changedBy = null, ?string $changedByKind = null): void
    {
        global $wpdb;

        $changedByKind = null !== $changedBy && MarkedBy::isKind($changedByKind) ? $changedByKind : null;

        $wpdb->update(
            $this->table(),
            [
                'status' => $status,
                'status_changed_at' => current_time('mysql'),
                'changed_by' => $changedBy,
                'changed_by_kind' => $changedByKind,
            ],
            ['id' => $id]
        );

        $turnedUp = [AssignmentStatus::ARRIVED, AssignmentStatus::ARRIVED_LATE, AssignmentStatus::COMPLETED];

        if (in_array($status, $turnedUp, true)) {
            $this->clearLateCancelsSameNight($id);
        }

        // Every status write in the plugin comes through here - the wp-admin
        // roster, the bot's /roster buttons, the door page - so this one hook
        // is where something that reacts to a status change (the door link for
        // a Welcome crew member who just arrived) can listen to all of them.
        do_action('eventcrew/assignment_status_changed', $id, $status, $changedBy, $changedByKind);
    }

    /**
     * Someone who cancelled late and then turned up for another task on the
     * same date did come and work that night, so the late cancellation is
     * changed to a plain `cancelled` (no penalty). Only on turning up, not on
     * signing up, so signing up and cancelling again cannot wash it out.
     */
    private function clearLateCancelsSameNight(int $assignmentId): void
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table()} AS other
                    INNER JOIN {$this->tasksTable()} AS other_task ON other_task.id = other.task_id
                    INNER JOIN {$this->table()} AS this_one ON this_one.person_id = other.person_id
                    INNER JOIN {$this->tasksTable()} AS this_task ON this_task.id = this_one.task_id
                SET other.status = %s, other.status_changed_at = %s
                WHERE this_one.id = %d
                  AND other.id <> this_one.id
                  AND other.status = %s
                  AND other_task.task_date = this_task.task_date",
                AssignmentStatus::CANCELLED,
                current_time('mysql'),
                $assignmentId,
                AssignmentStatus::LATE_CANCEL
            )
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

    /** Records that the "Your ticket" email (TicketMail) was sent for this sign-up, now. */
    public function markTicketMailed(int $id): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table(),
            ['ticket_mailed_at' => current_time('mysql')],
            ['id' => $id],
            ['%s'],
            ['%d']
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

    /**
     * Every sign-up of one person for tasks on $date, whatever its status,
     * oldest first. The crew ticket email (TicketMail) reads from it which of
     * the night's tickets was emailed and which tasks the person still holds.
     *
     * @return array<int, Assignment>
     */
    public function forPersonOn(int $personId, string $date): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.*
                FROM {$this->table()} a
                INNER JOIN {$this->tasksTable()} s ON s.id = a.task_id
                WHERE a.person_id = %d AND s.task_date = %s
                ORDER BY a.id ASC",
                $personId,
                $date
            ),
            ARRAY_A
        );

        return $this->hydrate(is_array($rows) ? $rows : []);
    }

    /**
     * Whether the person still holds a task on $date. A crew ticket is one per
     * person per night, so its code stays valid while this is true.
     */
    public function holdsTaskOn(int $personId, string $date): bool
    {
        foreach ($this->forPersonOn($personId, $date) as $assignment) {
            if ($assignment->isOccupying()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Assignments an admin marked no-show or late-cancel at least as long ago
     * as $cutoff, that have not yet had a standing notice recorded in the
     * ledger under $kind. The NOT EXISTS guard is what lets the caller take a
     * bounded batch each run without the already-notified backlog starving out
     * newer rows - the same job reminders solve with reminded_at.
     *
     * @return array<int, array{assignment: Assignment, task_date: string}>
     */
    public function needingStandingNotice(string $cutoff, string $kind, int $limit): array
    {
        global $wpdb;

        $notifications = Schema::table(Schema::NOTIFICATIONS);
        $statuses = [AssignmentStatus::NO_SHOW, AssignmentStatus::LATE_CANCEL];
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));

        $sql = "SELECT a.*, s.task_date
            FROM {$this->table()} a
            INNER JOIN {$this->tasksTable()} s ON s.id = a.task_id
            WHERE a.status IN ({$statusPlaceholders})
              AND a.status_changed_at IS NOT NULL
              AND a.status_changed_at <= %s
              AND NOT EXISTS (
                SELECT 1 FROM {$notifications} n
                WHERE n.kind = %s AND n.person_id = a.person_id AND n.task_date = s.task_date
              )
            ORDER BY a.status_changed_at ASC
            LIMIT %d";

        $params = array_merge($statuses, [$cutoff, $kind, $limit]);

        // Assembled from table-name constants and generated placeholders, then
        // bound through prepare(); the sniff cannot follow a query in a variable.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = [
                'assignment' => Assignment::fromRow($row),
                'task_date' => (string) ($row['task_date'] ?? ''),
            ];
        }

        return $out;
    }

    public function countCompletedFor(int $personId): int
    {
        global $wpdb;

        $statuses = AssignmentStatus::credited();
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table()}
                WHERE person_id = %d AND counts_credit = 1 AND status IN ({$statusPlaceholders})",
                array_merge([$personId], $statuses)
            )
        );
    }

    /**
     * Completed tasks per role for one person - what a role's conditions are
     * checked against (see RoleConditions). Keyed by role slug. Every completed
     * task counts, including one marked as not counting toward free-entry
     * credit: the conditions ask what someone has done, not what they earned.
     *
     * @return array<string, int>
     */
    public function completedByRole(int $personId): array
    {
        global $wpdb;

        $statuses = AssignmentStatus::credited();
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.role_slug AS role_slug, COUNT(*) AS n
                FROM {$this->table()} a
                INNER JOIN {$this->tasksTable()} s ON s.id = a.task_id
                WHERE a.person_id = %d AND s.deleted_at IS NULL AND a.status IN ({$statusPlaceholders})
                GROUP BY s.role_slug",
                array_merge([$personId], $statuses)
            ),
            ARRAY_A
        );

        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[(string) $row['role_slug']] = (int) $row['n'];
        }

        return $out;
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
