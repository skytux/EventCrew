<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;

/**
 * The one place a person stops being personal data, in the two ways that can
 * happen: erased outright, or anonymised because their record aged out.
 *
 * There were two hand-rolled copies of the delete before this - one on the
 * self-service manage page, one on the organizer's People screen - and they
 * agreed with each other only by luck. Both cleared assignments, redemptions
 * and grants; neither cleared the sign-in tokens or the notification ledger, so
 * an "erased" person left behind a live magic link and a record of every notice
 * they had been sent. Now there is one routine and four callers: those two, the
 * WordPress eraser, and the retention purge.
 *
 * Ordering is deliberate throughout - children before the parent, tokens first
 * of all. A failure partway through then leaves a person row whose satellites
 * are gone, which the next run finishes off; the reverse would leave orphans
 * pointing at a person id that no longer exists.
 */
final class PersonEraser
{
    public function __construct(
        private readonly PersonRepository $people,
        private readonly AssignmentRepository $assignments,
        private readonly RedemptionRepository $redemptions,
        private readonly CreditGrantRepository $grants,
        private readonly AuthTokenRepository $tokens,
        private readonly NotificationsRepository $notifications
    ) {
    }

    /**
     * Erases a person and everything attached to them, for good.
     *
     * What a person means when they press Delete: not "hide me", not "keep the
     * numbers", gone. The attendance history goes with them, which does cost
     * the organizer a little history - that is the trade the right to erasure
     * makes, and the retention purge below exists for the case where keeping
     * the anonymous numbers is what is wanted instead.
     */
    public function erase(int $personId): void
    {
        if ($personId <= 0) {
            return;
        }

        $this->tokens->deleteForPerson($personId);
        $this->notifications->deleteForPerson($personId);
        $this->assignments->deleteForPerson($personId);
        $this->redemptions->deleteForPerson($personId);
        $this->grants->deleteForPerson($personId);
        $this->people->delete($personId);
    }

    /**
     * Strips a person's record of everything that identifies them while leaving
     * their assignments and redemptions standing.
     *
     * This is what the retention purge does when a record ages out, and it is a
     * different answer from erase(): the organizer keeps "nine people worked
     * that night" while nobody can tell who they were. Once the email, name,
     * notes and Telegram link are gone, the remaining rows are not information
     * about an identifiable person, which is exactly the line the GDPR draws.
     *
     * The email column is unique and NOT NULL, so it cannot simply be emptied -
     * every anonymised row needs a distinct value. The person's own id supplies
     * it, under .invalid, a TLD reserved by RFC 2606 precisely so it can never
     * belong to anyone or accept mail.
     *
     * Irreversible, and stamped so it happens exactly once.
     */
    public function anonymize(int $personId): void
    {
        if ($personId <= 0) {
            return;
        }

        $this->tokens->deleteForPerson($personId);
        $this->notifications->deleteForPerson($personId);

        $this->people->update($personId, [
            'email' => 'anonymised-' . $personId . '@removed.invalid',
            'display_name' => '',
            'email_verified_at' => null,
            'telegram_user_id' => null,
            'telegram_chat_id' => null,
            'notes' => '',
            'notify_prefs' => null,
            'is_organizer' => 0,
            'can_lead' => 0,
            'at_risk_pass' => 0,
            'anonymized_at' => current_time('mysql'),
            // The row survives anonymising, so a live session cookie would go
            // on resolving to it - leaving whoever holds it signed in and
            // looking at the history of a record that no longer has a name.
            // erase() needs no equivalent: it deletes the row, and the cookie
            // then resolves to nobody.
            'sessions_valid_from' => current_time('mysql'),
        ]);
    }
}
