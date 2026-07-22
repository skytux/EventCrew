<?php

declare(strict_types=1);

namespace EventCrew\Models;

/**
 * One credit spent for free entry to an event.
 *
 * redeemedFor is the event date the credit buys entry to - what the door list
 * reads - as opposed to redeemedAt, the moment the organizer recorded it.
 */
final class Redemption
{
    public function __construct(
        public readonly int $id,
        public readonly int $personId,
        public readonly ?string $redeemedFor = null,
        public readonly ?int $eventPostId = null,
        public readonly string $eventLabel = '',
        public readonly string $redeemedAt = '',
        public readonly string $note = ''
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            (int) ($row['person_id'] ?? 0),
            self::nullableString($row['redeemed_for'] ?? null),
            self::nullableInt($row['event_post_id'] ?? null),
            (string) ($row['event_label'] ?? ''),
            (string) ($row['redeemed_at'] ?? ''),
            (string) ($row['note'] ?? '')
        );
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
