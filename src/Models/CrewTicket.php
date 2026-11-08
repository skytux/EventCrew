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
 * email. A person with several tasks on one night has one working ticket:
 * its code and link are made from $personId and $eventDate
 * (TicketMail::workCode(), workUrl()), so they stay the same as tasks come
 * and go. $id is a sign-up of the night, used only when $personId is 0.
 *
 * $language is the language the person reads: their stored language, or
 * English when they have not chosen one (HybridPolicy::personLanguage()).
 * The email is in that language alone when the ticket email's Hybrid tick is
 * off. Null means not known, and then the main language is used.
 *
 * $personId is the ticket holder, for the short code under the QR code
 * (ShortCode::forPerson()); 0 when not known, and then there is none.
 *
 * KIND_TRANSFER is a free-entry ticket someone gave away ($id is the
 * TicketTransfer id): $from is the giver's name, $personId the receiver
 * when they are a member (0 for someone reached by email).
 */
final class CrewTicket
{
    public const KIND_CREW = 'crew';
    public const KIND_CREDIT = 'credit';
    public const KIND_TRANSFER = 'transfer';

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
        public readonly ?string $language = null,
        public readonly int $personId = 0,
        public readonly string $from = ''
    ) {
    }

    public function isCredit(): bool
    {
        return self::KIND_CREDIT === $this->kind;
    }

    public function isTransfer(): bool
    {
        return self::KIND_TRANSFER === $this->kind;
    }
}
