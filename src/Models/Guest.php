<?php

declare(strict_types=1);

namespace EventCrew\Models;

/**
 * One name on a night's guest list: someone let in free who is not a crew
 * member, with the number of people they bring along. A guest from a mailed
 * guest list may carry an email address, which is emailed their ticket once;
 * mailStatus is '' (not sent), 'sent' or 'failed'. ticketRev goes up by one
 * each time an organizer changes the address; it is signed into the
 * ticket's code, so the old code stops working (TicketMail::guestCode()).
 */
final class Guest
{
    public function __construct(
        public readonly int $id,
        public readonly string $eventDate,
        public readonly string $name,
        public readonly int $plusOnes = 0,
        public readonly string $note = '',
        public readonly ?int $addedBy = null,
        public readonly string $email = '',
        public readonly string $mailStatus = '',
        public readonly string $mailedAt = '',
        public readonly int $ticketRev = 0
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
            null === $addedBy || '' === $addedBy ? null : (int) $addedBy,
            (string) ($row['email'] ?? ''),
            (string) ($row['ticket_mail_status'] ?? ''),
            (string) ($row['ticket_mailed_at'] ?? ''),
            (int) ($row['ticket_rev'] ?? 0)
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
