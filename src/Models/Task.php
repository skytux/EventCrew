<?php

declare(strict_types=1);

namespace EventCrew\Models;

use EventCrew\Support\Roles;

/**
 * One slot of work on one date - a role, a capacity, and optionally the
 * event it belongs to.
 *
 * taskDate is the day the task is filed under; startsAt and endsAt are full
 * datetimes and may fall on the following day. A clean-up at 01:00 on Sunday
 * after a Saturday event has a Saturday taskDate, because that is the board
 * and the reminder it belongs to.
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
        public readonly string $createdAt = '',
        public readonly int $plusOnes = 0
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
            (string) ($row['created_at'] ?? ''),
            (int) ($row['plus_ones'] ?? 0)
        );
    }

    /**
     * How many guests a person working this task may bring in (0 to 20, the
     * same cap as a guest-list entry).
     */
    public static function clampPlusOnes(int $plusOnes): int
    {
        return max(0, min(20, $plusOnes));
    }

    public function roleLabel(): string
    {
        return Roles::label($this->roleSlug);
    }

    public function roleDisplay(): string
    {
        return Roles::display($this->roleSlug);
    }

    public function roleDescription(): string
    {
        return Roles::description($this->roleSlug);
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

    /**
     * The times as an organizer reads them, with a marker on any part that
     * lands on a day other than the one the task is filed under.
     *
     * Without that marker "22:00–01:00" is ambiguous about which 01:00 is
     * meant, and the cleaning task - the one that always crosses midnight -
     * is precisely the one this has to get right.
     */
    public function timeRange(): string
    {
        if (null === $this->startsAt) {
            return '';
        }

        $start = $this->clockTime($this->startsAt);

        if (null === $this->endsAt) {
            return $start;
        }

        return $start . '–' . $this->clockTime($this->endsAt);
    }

    /**
     * HH:MM, suffixed with the day offset from taskDate when they differ:
     * "01:00 (+1)" for the small hours of the next morning.
     */
    private function clockTime(string $dateTime): string
    {
        $clock = substr($dateTime, 11, 5);

        // A value still stored as a bare time, which is what rows written
        // before the datetime widening look like.
        if ('' === $clock) {
            return substr($dateTime, 0, 5);
        }

        $dayOffset = $this->dayOffset(substr($dateTime, 0, 10));

        if (0 === $dayOffset) {
            return $clock;
        }

        return sprintf('%s (%+d)', $clock, $dayOffset);
    }

    private function dayOffset(string $date): int
    {
        if ('' === $this->taskDate || $date === $this->taskDate) {
            return 0;
        }

        $from = strtotime($this->taskDate . ' 00:00:00');
        $to = strtotime($date . ' 00:00:00');

        if (false === $from || false === $to) {
            return 0;
        }

        return (int) round(($to - $from) / 86400);
    }

    /** Whether only organizers may see this task and sign up for it. */
    public function isOrganizersOnly(): bool
    {
        return Roles::ORGANIZE_SLUG === $this->roleSlug;
    }

    /**
     * Whether only people holding this task's role may see it and sign up:
     * Organize (organizers) and every role set to "Only people with this
     * role" (DJ, VJ, ...). See Person::hasRole().
     */
    public function isRestricted(): bool
    {
        return Roles::isRestricted($this->roleSlug);
    }

    /**
     * Whether any number of people may take this task. Such a task is never
     * full, and it never counts as a place that still needs people. True for
     * every restricted task.
     */
    public function hasNoLimit(): bool
    {
        return $this->isRestricted();
    }

    public function isPast(): bool
    {
        return $this->taskDate < current_time('Y-m-d');
    }

    /**
     * True once the task is under way: its start time has passed, or - for a
     * task with no start time - its date is over. startsAt is site-local wall
     * clock, like current_time('mysql'), so the two compare as strings.
     */
    public function hasStarted(): bool
    {
        if (null === $this->startsAt) {
            return $this->isPast();
        }

        return $this->startsAt <= (string) current_time('mysql');
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
