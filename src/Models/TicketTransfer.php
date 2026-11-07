<?php

declare(strict_types=1);

namespace EventCrew\Models;

/**
 * A free-entry ticket given by one person to another. The giver's credit
 * ticket (a Redemption) is the original; once the transfer is issued the
 * original is cancelled and links here (Redemption::$transferredTo), and this
 * row is the receiver's ticket: the Transferred category at the door.
 *
 * A gift to a member (receiverPersonId set) is issued at once. A gift to
 * someone else waits as pending for them to accept from the email link; it
 * lapses at door opening (TicketTransferService::lapseAt()) and the giver may
 * cancel it until then. name and email are the receiver's.
 */
final class TicketTransfer
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ISSUED = 'issued';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_LAPSED = 'lapsed';

    public function __construct(
        public readonly int $id,
        public readonly int $redemptionId,
        public readonly string $eventDate,
        public readonly ?int $eventPostId,
        public readonly int $giverPersonId,
        public readonly ?int $receiverPersonId,
        public readonly string $name,
        public readonly string $email,
        public readonly string $language,
        public readonly string $status,
        public readonly string $createdAt,
        public readonly ?string $issuedAt = null,
        public readonly ?string $endedAt = null,
        public readonly ?string $ticketMailedAt = null
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $receiver = $row['receiver_person_id'] ?? null;
        $post = $row['event_post_id'] ?? null;

        return new self(
            (int) ($row['id'] ?? 0),
            (int) ($row['redemption_id'] ?? 0),
            (string) ($row['event_date'] ?? ''),
            null === $post || '' === $post || 0 === (int) $post ? null : (int) $post,
            (int) ($row['giver_person_id'] ?? 0),
            null === $receiver || '' === $receiver || 0 === (int) $receiver ? null : (int) $receiver,
            (string) ($row['name'] ?? ''),
            (string) ($row['email'] ?? ''),
            (string) ($row['language'] ?? ''),
            (string) ($row['status'] ?? self::STATUS_PENDING),
            (string) ($row['created_at'] ?? ''),
            self::nullableString($row['issued_at'] ?? null),
            self::nullableString($row['ended_at'] ?? null),
            self::nullableString($row['ticket_mailed_at'] ?? null)
        );
    }

    public function isPending(): bool
    {
        return self::STATUS_PENDING === $this->status;
    }

    public function isIssued(): bool
    {
        return self::STATUS_ISSUED === $this->status;
    }

    /** Whether the receiver is a member (a gift by name), not someone reached by email. */
    public function toMember(): bool
    {
        return null !== $this->receiverPersonId;
    }

    private static function nullableString(mixed $value): ?string
    {
        return null === $value || '' === $value ? null : (string) $value;
    }
}
