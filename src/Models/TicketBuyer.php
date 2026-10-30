<?php

declare(strict_types=1);

namespace EventCrew\Models;

/**
 * One ticket bought in the Holvi shop for one night: just the buyer's name,
 * which is all the door needs to tick them off.
 */
final class TicketBuyer
{
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
}
