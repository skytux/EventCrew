<?php

declare(strict_types=1);

namespace EventCrew\Models;

use EventCrew\Support\AssignmentStatus;

/**
 * A volunteer's commitment to one shift, and how it turned out.
 */
final class Assignment
{
    public function __construct(
        public readonly int $id,
        public readonly int $shiftId,
        public readonly int $volunteerId,
        public readonly string $status,
        public readonly string $signedUpAt = '',
        public readonly ?string $statusChangedAt = null,
        public readonly ?int $changedBy = null,
        public readonly ?string $remindedAt = null
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            (int) ($row['shift_id'] ?? 0),
            (int) ($row['volunteer_id'] ?? 0),
            (string) ($row['status'] ?? AssignmentStatus::SIGNED_UP),
            (string) ($row['signed_up_at'] ?? ''),
            self::nullableString($row['status_changed_at'] ?? null),
            self::nullableInt($row['changed_by'] ?? null),
            self::nullableString($row['reminded_at'] ?? null)
        );
    }

    public function isCompleted(): bool
    {
        return AssignmentStatus::COMPLETED === $this->status;
    }

    /**
     * Whether this row still holds a slot in its shift. A no-show keeps its
     * row for the reputation history but has stopped occupying the shift.
     */
    public function isOccupying(): bool
    {
        return in_array($this->status, AssignmentStatus::occupying(), true);
    }

    public function statusLabel(): string
    {
        return AssignmentStatus::label($this->status);
    }

    private static function nullableString(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return (string) $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return (int) $value;
    }
}
