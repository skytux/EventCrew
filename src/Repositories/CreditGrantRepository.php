<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\History;

/**
 * All reads and writes of the credit-grants table - one row per bonus credit an
 * organizer hands someone by hand, outside the earn-one-per-two-completed rule.
 *
 * Like the redemptions half, the grant total is not a stored balance: it is the
 * sum of these rows, folded into Support\Credits alongside earned and redeemed,
 * so there is no running total to drift out of sync.
 */
final class CreditGrantRepository
{
    /** The source of a credit for an extra task on the same night. */
    public const SOURCE_EXTRA_TASK = 'extra_task';

    private function table(): string
    {
        return Schema::table(Schema::CREDIT_GRANTS);
    }

    /**
     * How many credits this person has been granted by hand, all-time. Added to
     * their earned-minus-redeemed balance.
     */
    public function sumFor(int $personId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(credits), 0) FROM {$this->table()} WHERE person_id = %d",
                $personId
            )
        );
    }

    /**
     * sumFor() for many people in one query, keyed by person id; 0 for
     * anyone with none.
     *
     * @param array<int, int> $personIds
     * @return array<int, int>
     */
    public function sumsFor(array $personIds): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $personIds))));
        $sums = array_fill_keys($ids, 0);

        if ([] === $ids) {
            return $sums;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT person_id, COALESCE(SUM(credits), 0) AS n FROM {$this->table()}
                WHERE person_id IN ({$placeholders}) GROUP BY person_id",
                ...$ids
            ),
            ARRAY_A
        );

        foreach (is_array($rows) ? $rows : [] as $row) {
            $sums[(int) $row['person_id']] = (int) $row['n'];
        }

        return $sums;
    }

    /**
     * Records a bonus credit (or several) for a person, with an optional note
     * and the organizer's user id for the record.
     */
    public function record(
        int $personId,
        int $credits,
        string $note,
        int $grantedBy,
        ?int $grantedByPersonId = null
    ): void {
        global $wpdb;

        $wpdb->insert(
            $this->table(),
            [
                'person_id' => $personId,
                'credits' => max(1, $credits),
                'note' => $note,
                'granted_by' => 0 === $grantedBy ? null : $grantedBy,
                'granted_by_person_id' => $grantedByPersonId,
                'granted_at' => current_time('mysql'),
            ]
        );

        History::record(History::CREDIT_GRANTED, [
            'person_id' => $personId,
            'details' => ['credits' => max(1, $credits)],
            'actor_person_id' => $grantedByPersonId,
            'actor_wp_user_id' => null === $grantedByPersonId && $grantedBy > 0 ? $grantedBy : null,
        ]);
    }

    /**
     * A grant-log line that gives no credit of its own: the reason a spent
     * credit came back some other way - the door cancelling a free-entry
     * ticket when its holder took a task instead. Zero credits, so the
     * balance (which sums this column) is not counted twice.
     */
    public function recordNote(int $personId, string $note, ?int $grantedByPersonId = null): void
    {
        global $wpdb;

        $wpdb->insert(
            $this->table(),
            [
                'person_id' => $personId,
                'credits' => 0,
                'note' => $note,
                'granted_by' => null,
                'granted_by_person_id' => $grantedByPersonId,
                'granted_at' => current_time('mysql'),
            ]
        );
    }

    /**
     * The credit for an extra task on the same night (ExtraTaskCredits): one
     * row per task, source 'extra_task'. INSERT IGNORE on the unique
     * assignment_id key, so a second run for the same task adds nothing.
     * Returns whether a row was added.
     */
    public function recordExtraTask(int $personId, int $assignmentId, string $note): bool
    {
        global $wpdb;

        $result = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single SQL statement; wrapping it just adds noise.
                "INSERT IGNORE INTO {$this->table()} (person_id, credits, note, granted_by, granted_by_person_id, granted_at, source, assignment_id) VALUES (%d, 1, %s, NULL, NULL, %s, %s, %d)",
                $personId,
                $note,
                (string) current_time('mysql'),
                self::SOURCE_EXTRA_TASK,
                $assignmentId
            )
        );

        $added = is_int($result) && $result > 0;

        if ($added) {
            History::record(History::CREDIT_GRANTED, [
                'assignment_id' => $assignmentId,
                'person_id' => $personId,
                'details' => ['credits' => 1, 'extra_task' => 1],
            ]);
        }

        return $added;
    }

    /**
     * The pending credits for extra tasks: for each of the person's nights
     * from $from (to $to when given), the tasks they hold that night minus
     * one, minus the extra-task credits already added for that night. One
     * number, summed over the nights. Only tasks that count toward credit.
     */
    public function pendingExtraTasks(int $personId, string $from, ?string $to = null): int
    {
        global $wpdb;

        $assignments = Schema::table(Schema::ASSIGNMENTS);
        $tasks = Schema::table(Schema::TASKS);
        $statuses = AssignmentStatus::occupying();
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $toClause = null === $to ? '' : 'AND s.task_date <= %s';

        $sql = "SELECT COALESCE(SUM(GREATEST(0, n.held - 1 - n.added)), 0) FROM (
                SELECT COUNT(*) AS held, (
                    SELECT COUNT(*) FROM {$this->table()} g
                    INNER JOIN {$assignments} a2 ON a2.id = g.assignment_id
                    INNER JOIN {$tasks} s2 ON s2.id = a2.task_id
                    WHERE g.person_id = a.person_id AND g.source = %s AND s2.task_date = s.task_date
                ) AS added
                FROM {$assignments} a
                INNER JOIN {$tasks} s ON s.id = a.task_id
                WHERE a.person_id = %d AND s.deleted_at IS NULL AND a.counts_credit = 1
                  AND a.status IN ({$placeholders})
                  AND s.task_date >= %s {$toClause}
                GROUP BY s.task_date
                HAVING held > 1
            ) n";

        $params = array_merge(
            [self::SOURCE_EXTRA_TASK, $personId],
            $statuses,
            [$from],
            null === $to ? [] : [$to]
        );

        // Assembled from table-name constants and generated placeholders, then
        // bound through prepare(); the sniff cannot follow a query in a variable.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return max(0, (int) $wpdb->get_var($wpdb->prepare($sql, ...$params)));
    }

    /**
     * Nights whose extra-task credits are still to add after their thank-you
     * went out: a completed task marked at least a day ago ($cutoff), not the
     * first completed task of the person's night, with no credit row yet,
     * and the thank-you ($thanksKind) for that night already in the ledger.
     * A task marked completed after the thank-you is caught here.
     *
     * @return array<int, array{person_id: int, task_date: string}>
     */
    public function extraTasksToAdd(string $from, string $cutoff, string $thanksKind, int $limit): array
    {
        global $wpdb;

        $assignments = Schema::table(Schema::ASSIGNMENTS);
        $tasks = Schema::table(Schema::TASKS);
        $notifications = Schema::table(Schema::NOTIFICATIONS);
        $statuses = AssignmentStatus::credited();
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        $sql = "SELECT DISTINCT a.person_id, s.task_date
            FROM {$assignments} a
            INNER JOIN {$tasks} s ON s.id = a.task_id
            WHERE a.status IN ({$placeholders})
              AND a.counts_credit = 1
              AND s.deleted_at IS NULL
              AND s.task_date >= %s
              AND a.status_changed_at IS NOT NULL
              AND a.status_changed_at <= %s
              AND NOT EXISTS (SELECT 1 FROM {$this->table()} g WHERE g.assignment_id = a.id)
              AND EXISTS (
                SELECT 1 FROM {$notifications} n
                WHERE n.kind = %s AND n.person_id = a.person_id AND n.task_date = s.task_date
              )
              AND a.id > (
                SELECT MIN(a2.id) FROM {$assignments} a2
                INNER JOIN {$tasks} s2 ON s2.id = a2.task_id
                WHERE a2.person_id = a.person_id AND s2.task_date = s.task_date
                  AND s2.deleted_at IS NULL AND a2.counts_credit = 1 AND a2.status IN ({$placeholders})
              )
            LIMIT %d";

        $params = array_merge($statuses, [$from, $cutoff, $thanksKind], $statuses, [max(1, $limit)]);

        // Assembled from table-name constants and generated placeholders, then
        // bound through prepare(); the sniff cannot follow a query in a variable.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        return array_map(
            static fn (array $row): array => [
                'person_id' => (int) $row['person_id'],
                'task_date' => (string) $row['task_date'],
            ],
            is_array($rows) ? $rows : []
        );
    }

    /**
     * The most recent grants across everyone, newest first - the credit-grant
     * audit log the People screen shows. Raw rows; the caller resolves the person
     * and granter names for display.
     *
     * @return array<int, array{person_id: int, credits: int, note: string, granted_by: ?int, granted_by_person_id: ?int, granted_at: string}>
     */
    public function recent(int $limit = 20): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single SQL statement; wrapping it just adds noise.
                "SELECT person_id, credits, note, granted_by, granted_by_person_id, granted_at FROM {$this->table()} ORDER BY id DESC LIMIT %d",
                max(1, $limit)
            ),
            ARRAY_A
        );

        return array_map(
            static fn (array $row): array => [
                'person_id' => (int) $row['person_id'],
                'credits' => (int) $row['credits'],
                'note' => (string) $row['note'],
                'granted_by' => null === $row['granted_by'] ? null : (int) $row['granted_by'],
                'granted_by_person_id' => null === $row['granted_by_person_id']
                    ? null
                    : (int) $row['granted_by_person_id'],
                'granted_at' => (string) $row['granted_at'],
            ],
            is_array($rows) ? $rows : []
        );
    }

    /**
     * One person's most recent grant-log lines, newest first - the credit log
     * the crew page's Admin tab shows for that person. Raw rows, as recent().
     *
     * @return array<int, array{credits: int, note: string, granted_by: ?int,
     *     granted_by_person_id: ?int, granted_at: string}>
     */
    public function forPerson(int $personId, int $limit = 20): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single SQL statement; wrapping it just adds noise.
                "SELECT credits, note, granted_by, granted_by_person_id, granted_at FROM {$this->table()} WHERE person_id = %d ORDER BY id DESC LIMIT %d",
                $personId,
                max(1, $limit)
            ),
            ARRAY_A
        );

        return array_map(
            static fn (array $row): array => [
                'credits' => (int) $row['credits'],
                'note' => (string) $row['note'],
                'granted_by' => null === ($row['granted_by'] ?? null) ? null : (int) $row['granted_by'],
                'granted_by_person_id' => null === ($row['granted_by_person_id'] ?? null)
                    ? null
                    : (int) $row['granted_by_person_id'],
                'granted_at' => (string) $row['granted_at'],
            ],
            is_array($rows) ? $rows : []
        );
    }

    /**
     * The assignment ids that have an extra-task credit, among the person's
     * tasks on one night.
     *
     * @return array<int, int>
     */
    public function extraTaskAssignmentsOn(int $personId, string $date): array
    {
        global $wpdb;

        $assignments = Schema::table(Schema::ASSIGNMENTS);
        $tasks = Schema::table(Schema::TASKS);

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT g.assignment_id FROM {$this->table()} g
                INNER JOIN {$assignments} a ON a.id = g.assignment_id
                INNER JOIN {$tasks} s ON s.id = a.task_id
                WHERE g.person_id = %d AND g.source = %s AND s.task_date = %s
                ORDER BY g.assignment_id ASC",
                $personId,
                self::SOURCE_EXTRA_TASK,
                $date
            )
        );

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    /**
     * Takes back the extra-task credit added for one assignment. True when
     * there was one.
     */
    public function deleteExtraTask(int $personId, int $assignmentId): bool
    {
        global $wpdb;

        $result = $wpdb->delete(
            $this->table(),
            [
                'person_id' => $personId,
                'source' => self::SOURCE_EXTRA_TASK,
                'assignment_id' => $assignmentId,
            ],
            ['%d', '%s', '%d']
        );

        $removed = is_int($result) && $result > 0;

        if ($removed) {
            History::record(History::CREDIT_REMOVED, [
                'assignment_id' => $assignmentId,
                'person_id' => $personId,
                'details' => ['credits' => 1, 'extra_task' => 1],
            ]);
        }

        return $removed;
    }

    public function deleteForPerson(int $personId): void
    {
        global $wpdb;

        $wpdb->delete($this->table(), ['person_id' => $personId]);
    }
}
