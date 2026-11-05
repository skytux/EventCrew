<?php

declare(strict_types=1);

namespace EventCrew\Models;

/**
 * A crew member's ticket for one night, as the ticket email (Support\TicketMail)
 * draws it: either a sign-up for a task (KIND_CREW, $id is the assignment id)
 * or a free-entry credit spent on the night (KIND_CREDIT, $id is the
 * redemption id). It is not stored: it is built from the person and the row
 * when the email is sent.
 *
 * $tasks are the tasks the person holds on the night, listed in a KIND_CREW
 * email. A person with several tasks on one night has one ticket: $id is the
 * sign-up whose ticket was emailed first, so its code stays the same.
 *
 * $language is the language the person reads: their stored language, or
 * English when they have not chosen one (HybridPolicy::personLanguage()).
 * The email is in that language alone when the ticket email's Hybrid tick is
 * off. Null means not known, and then the main language is used.
 */
final class CrewTicket
{
    public const KIND_CREW = 'crew';
    public const KIND_CREDIT = 'credit';

    /**
     * @param list<Task> $tasks
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $id,
        public readonly string $eventDate,
        public readonly string $name,
        public readonly string $email,
        public readonly array $tasks = [],
        public readonly ?string $language = null
    ) {
    }

    public function isCredit(): bool
    {
        return self::KIND_CREDIT === $this->kind;
    }
}
