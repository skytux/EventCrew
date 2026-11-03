<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;
use EventCrew\Models\Takeover;

/**
 * All reads and writes of the takeover requests table.
 */
final class TakeoverRepository
{
    private function table(): string
    {
        return Schema::table(Schema::TAKEOVERS);
    }

    /**
     * Stores a new pending request and returns its id, or null when the task
     * already has a pending request.
     *
     * The "no pending request on this task" check and the insert are one
     * statement, for the same reason as AssignmentRepository::join(): two
     * covers tapping at the same moment must not both get a request in. The
     * subquery is wrapped in a derived table because MySQL does not let an
     * INSERT name its own table in a plain subquery.
     */
    public function create(int $assignmentId, int $taskId, int $holderId, int $coverId, string $deadlineAt): ?int
    {
        global $wpdb;

        $table = $this->table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (assignment_id, task_id, holder_id, cover_id, status, deadline_at, created_at)
                SELECT %d, %d, %d, %d, %s, %s, %s
                FROM (SELECT 1) AS placeholder
                WHERE NOT EXISTS (
                    SELECT 1 FROM (SELECT task_id, status FROM {$table}) AS existing
                    WHERE existing.task_id = %d AND existing.status = %s
                )",
                $assignmentId,
                $taskId,
                $holderId,
                $coverId,
                Takeover::PENDING,
                $deadlineAt,
                current_time('mysql'),
                $taskId,
                Takeover::PENDING
            )
        );

        return 1 === $inserted ? (int) $wpdb->insert_id : null;
    }

    public function find(int $id): ?Takeover
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id),
            ARRAY_A
        );

        return is_array($row) ? Takeover::fromRow($row) : null;
    }

    public function pendingForTask(int $taskId): ?Takeover
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE task_id = %d AND status = %s ORDER BY id DESC LIMIT 1",
                $taskId,
                Takeover::PENDING
            ),
            ARRAY_A
        );

        return is_array($row) ? Takeover::fromRow($row) : null;
    }

    public function pendingForAssignment(int $assignmentId): ?Takeover
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE assignment_id = %d AND status = %s ORDER BY id DESC LIMIT 1",
                $assignmentId,
                Takeover::PENDING
            ),
            ARRAY_A
        );

        return is_array($row) ? Takeover::fromRow($row) : null;
    }

    /**
     * The pending requests, oldest deadline first.
     *
     * @return array<int, Takeover>
     */
    public function pending(int $limit): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE status = %s ORDER BY deadline_at ASC, id ASC LIMIT %d",
                Takeover::PENDING,
                max(1, $limit)
            ),
            ARRAY_A
        );

        return array_map(
            static fn (array $row): Takeover => Takeover::fromRow($row),
            is_array($rows) ? array_values(array_filter($rows, 'is_array')) : []
        );
    }

    /**
     * Closes a pending request with $status. Only a pending row changes, in
     * one statement, so a holder's answer and the expiry sweep running at the
     * same moment cannot both act: the one that gets false does nothing.
     */
    public function resolve(int $id, string $status): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $changed = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table()} SET status = %s, answered_at = %s WHERE id = %d AND status = %s",
                $status,
                current_time('mysql'),
                $id,
                Takeover::PENDING
            )
        );

        return 1 === $changed;
    }

    /**
     * Sets a status whatever the current one is. Only for undoing an accepted
     * request whose move failed.
     */
    public function setStatus(int $id, string $status): void
    {
        global $wpdb;

        $wpdb->update($this->table(), ['status' => $status], ['id' => $id]);
    }

    /**
     * Deletes every request the person is part of, as the holder and as the
     * one who asked. Used by PersonEraser.
     */
    public function deleteForPerson(int $personId): void
    {
        global $wpdb;

        $wpdb->delete($this->table(), ['holder_id' => $personId]);
        $wpdb->delete($this->table(), ['cover_id' => $personId]);
    }
}
