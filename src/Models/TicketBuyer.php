<?php

declare(strict_types=1);

namespace EventCrew\Models;

/**
 * One ticket for one night: a buyer from the Holvi shop, or a ticket sold at
 * the door. Just the name (a door ticket may have none), which is all the door
 * needs to tick them off. A ticket imported from a ticket file also carries
 * the buyer's email address and the ticket code; a Holvi buyer added by hand
 * at the sales desk may carry an email address.
 */
final class TicketBuyer
{
    /** The source of a ticket sold at the door. Imports never use it. */
    public const SOURCE_DOOR = 'door';

    /**
     * The source of a Holvi buyer who was missing from the imported list and
     * was added by hand at the sales desk. Listed with the Holvi buyers.
     */
    public const SOURCE_DESK = 'desk';

    public function __construct(
        public readonly int $id,
        public readonly string $eventDate,
        public readonly string $name,
        public readonly string $source = 'paste',
        public readonly string $batch = '',
        public readonly string $email = '',
        public readonly string $ticketCode = ''
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['event_date'] ?? ''),
            (string) ($row['name'] ?? ''),
            (string) ($row['source'] ?? 'paste'),
            (string) ($row['batch'] ?? ''),
            (string) ($row['email'] ?? ''),
            (string) ($row['ticket_code'] ?? '')
        );
    }

    /** Whether this ticket was sold at the door rather than imported. */
    public function isDoorSale(): bool
    {
        return self::SOURCE_DOOR === $this->source;
    }
}
