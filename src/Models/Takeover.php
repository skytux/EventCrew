<?php

declare(strict_types=1);

namespace EventCrew\Models;

/**
 * A request from one member (the cover) to take over another member's task.
 * The task only moves when the holder agrees; see Support\TakeoverService.
 */
final class Takeover
{
    /** Asked, not answered yet. */
    public const PENDING = 'pending';

    /** The holder agreed and the task moved to the cover. */
    public const ACCEPTED = 'accepted';

    /** The holder kept the task. */
    public const REFUSED = 'refused';

    /** Nobody answered before the deadline, so the holder kept the task. */
    public const EXPIRED = 'expired';

    /** The task or the holder's place on it went away before an answer. */
    public const CANCELLED = 'cancelled';

    public function __construct(
        public readonly int $id,
        public readonly int $assignmentId,
        public readonly int $taskId,
        public readonly int $holderId,
        public readonly int $coverId,
        public readonly string $status,
        /** Site-local wall clock, like current_time('mysql'). */
        public readonly string $deadlineAt,
        public readonly string $createdAt = '',
        public readonly ?string $answeredAt = null
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $answeredAt = $row['answered_at'] ?? null;

        return new self(
            (int) ($row['id'] ?? 0),
            (int) ($row['assignment_id'] ?? 0),
            (int) ($row['task_id'] ?? 0),
            (int) ($row['holder_id'] ?? 0),
            (int) ($row['cover_id'] ?? 0),
            (string) ($row['status'] ?? self::PENDING),
            (string) ($row['deadline_at'] ?? ''),
            (string) ($row['created_at'] ?? ''),
            null === $answeredAt || '' === $answeredAt ? null : (string) $answeredAt
        );
    }

    public function isPending(): bool
    {
        return self::PENDING === $this->status;
    }
}
