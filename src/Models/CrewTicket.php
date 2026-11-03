<?php

declare(strict_types=1);

namespace EventCrew\Models;

/**
 * A crew member's ticket for one night, as the ticket email (Support\TicketMail)
 * draws it: either a sign-up for a task (KIND_CREW, $id is the assignment id)
 * or a free-entry credit spent on the night (KIND_CREDIT, $id is the
 * redemption id). It is not stored: it is built from the person and the row
 * when the email is sent.
 */
final class CrewTicket
{
    public const KIND_CREW = 'crew';
    public const KIND_CREDIT = 'credit';

    public function __construct(
        public readonly string $kind,
        public readonly int $id,
        public readonly string $eventDate,
        public readonly string $name,
        public readonly string $email
    ) {
    }

    public function isCredit(): bool
    {
        return self::KIND_CREDIT === $this->kind;
    }
}
