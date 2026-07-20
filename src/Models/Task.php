<?php

declare(strict_types=1);

namespace EventCrew\Models;

use EventCrew\Support\Roles;

/**
 * One slot of work on one date - a role, a capacity, and optionally the
 * event it belongs to.
 */
final class Task
{
    public function __construct(
        public readonly int $id,
        public readonly string $taskDate,
        public readonly string $roleSlug,
        public readonly int $capacity,
        public readonly ?int $eventPostId = null,
        public readonly string $eventLabel = '',
        public readonly ?string $startsAt = null,
        public readonly ?string $endsAt = null,
        public readonly string $notes = '',
        public readonly string $createdAt = ''
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['task_date'] ?? ''),
            (string) ($row['role_slug'] ?? ''),
            (int) ($row['capacity'] ?? 1),
            self::nullableInt($row['event_post_id'] ?? null),
            (string) ($row['event_label'] ?? ''),
            self::nullableString($row['starts_at'] ?? null),
            self::nullableString($row['ends_at'] ?? null),
            (string) ($row['notes'] ?? ''),
            (string) ($row['created_at'] ?? '')
        );
    }

    public function roleLabel(): string
    {
        return Roles::label($this->roleSlug);
    }

    public function roleDisplay(): string
    {
        return Roles::display($this->roleSlug);
    }

    /**
     * Prefers the linked EventMesh post's title, falling back to the label
     * typed by hand, then to the date - so this never renders as empty.
     */
    public function eventName(): string
    {
        if (null !== $this->eventPostId) {
            $title = get_the_title($this->eventPostId);

            if (is_string($title) && '' !== $title) {
                return $title;
            }
        }

        return '' !== $this->eventLabel ? $this->eventLabel : $this->taskDate;
    }

    public function timeRange(): string
    {
        if (null === $this->startsAt) {
            return '';
        }

        $start = substr($this->startsAt, 0, 5);

        if (null === $this->endsAt) {
            return $start;
        }

        return $start . '–' . substr($this->endsAt, 0, 5);
    }

    public function isPast(): bool
    {
        return $this->taskDate < current_time('Y-m-d');
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
