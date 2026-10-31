<?php

declare(strict_types=1);

namespace EventCrew\Models;

/**
 * One ticket for one night: a buyer from the Holvi shop, or a ticket sold at
 * the door. Just the name (a door ticket may have none), which is all the door
 * needs to tick them off.
 */
final class TicketBuyer
{
    /** The source of a ticket sold at the door. Imports never use it. */
    public const SOURCE_DOOR = 'door';

    public function __construct(
        public readonly int $id,
        public readonly string $eventDate,
        public readonly string $name,
        public readonly string $source = 'paste',
        public readonly string $batch = ''
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
            (string) ($row['batch'] ?? '')
        );
    }

    /** Whether this ticket was sold at the door rather than imported. */
    public function isDoorSale(): bool
    {
        return self::SOURCE_DOOR === $this->source;
    }
}
