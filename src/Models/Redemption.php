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
        public readonly string $note = '',
        public readonly ?string $cancelledAt = null,
        /** when the "Your ticket" email was sent for this credit; null when none was */
        public readonly ?string $ticketMailedAt = null
    ) {
    }

    /** Whether this redemption was undone; its ticket then shows DISABLED. */
    public function isCancelled(): bool
    {
        return null !== $this->cancelledAt;
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
            (string) ($row['note'] ?? ''),
            self::nullableString($row['cancelled_at'] ?? null),
            self::nullableString($row['ticket_mailed_at'] ?? null)
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
