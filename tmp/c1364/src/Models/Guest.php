<?php

declare(strict_types=1);

namespace EventCrew\Models;

/**
 * One name on a night's guest list: someone let in free who is not a crew
 * member, with the number of people they bring along.
 */
final class Guest
{
    public function __construct(
        public readonly int $id,
        public readonly string $eventDate,
        public readonly string $name,
        public readonly int $plusOnes = 0,
        public readonly string $note = '',
        public readonly ?int $addedBy = null
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $addedBy = $row['added_by'] ?? null;

        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['event_date'] ?? ''),
            (string) ($row['name'] ?? ''),
            (int) ($row['plus_ones'] ?? 0),
            (string) ($row['note'] ?? ''),
            null === $addedBy || '' === $addedBy ? null : (int) $addedBy
        );
    }

    /** The guest and everyone they bring. */
    public function headcount(): int
    {
        return 1 + $this->plusOnes;
    }

    /**
     * "Anna +2 (DJ's partner)" - the one-line form the bot and the door list show.
     */
    public function line(): string
    {
        $line = $this->name;

        if ($this->plusOnes > 0) {
            $line .= ' +' . $this->plusOnes;
        }

        if ('' !== $this->note) {
            $line .= ' (' . $this->note . ')';
        }

        return $line;
    }
}
