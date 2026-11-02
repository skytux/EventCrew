<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TicketBuyerRepository;

/**
 * Enforces the retention period the privacy notice publishes, by anonymising
 * records that have sat untouched past it.
 *
 * A retention period nobody enforces is a sentence on a web page. This is the
 * part that makes it true - and, because it is the one scheduled job here that
 * destroys something, it is built to be dull:
 *
 *  - Off unless the organizer switched it on. An update must never start
 *    erasing contact details on a site whose owner did not ask for it.
 *  - Bounded per run, so a first run against years of history is a series of
 *    small writes on a shared host rather than one that times out halfway.
 *  - Anonymising, not deleting: the attendance rows stay, so the organizer's
 *    "how many people worked last winter" survives a purge that removes every
 *    means of telling who they were.
 *  - Idempotent, via anonymized_at, so an hourly heartbeat re-running over a
 *    tidy database does nothing at all.
 *
 * A person who wants to be gone entirely does not wait for this - they press
 * Delete on their own page, which is PersonEraser::erase() and takes the
 * history with it.
 */
final class RetentionPurge
{
    /**
     * Records stripped per run. Small on purpose: this runs inside the same
     * hourly tick as the sends, and there is no hurry whatsoever about a record
     * that has already been dormant for years.
     */
    private const BATCH = 25;

    public function __construct(
        private readonly PersonRepository $people,
        private readonly PersonEraser $eraser,
        private readonly TicketBuyerRepository $buyers = new TicketBuyerRepository(),
        private readonly GuestRepository $guests = new GuestRepository()
    ) {
    }

    /**
     * Deletes the email addresses of guests from a mailed guest list once the
     * event is more than PrivacyPolicy::BUYER_EMAIL_DAYS days ago, the same
     * period as ticket buyers. The guest's name stays on the guest list.
     * Always on, like purgeBuyers(). Returns how many rows changed.
     */
    public function purgeGuestEmails(): int
    {
        $cutoff = $this->buyerEmailCutoff();

        if ('' === $cutoff) {
            return 0;
        }

        return $this->guests->anonymizeEmailsBefore($cutoff);
    }

    /**
     * Anonymises everything past the retention period, up to the batch size.
     * Returns how many records were stripped, so a caller can tell a run that
     * did nothing from one that worked through a batch.
     */
    public function run(): int
    {
        if (! PrivacyPolicy::purgeEnabled()) {
            return 0;
        }

        $cutoff = $this->cutoff();

        if ('' === $cutoff) {
            return 0;
        }

        $ids = $this->people->idsInactiveSince($cutoff, self::BATCH);

        foreach ($ids as $id) {
            $this->eraser->anonymize($id);
        }

        return count($ids);
    }

    /**
     * Deletes ticket buyers' email addresses, names and ticket codes once the
     * event is more than PrivacyPolicy::BUYER_EMAIL_DAYS days ago, except the
     * rows of the addresses that buyerEmailsToKeep() returns. Returns how many
     * rows changed.
     *
     * Not switched off by the purge setting above, unlike run(): the public
     * ticket buyer notice promises this deletion, so it always happens. The
     * rows stay, without anything that names a person.
     */
    public function purgeBuyers(): int
    {
        $cutoff = $this->buyerEmailCutoff();

        if ('' === $cutoff) {
            return 0;
        }

        return $this->buyers->anonymizeBefore($cutoff, $this->buyerEmailsToKeep());
    }

    /**
     * The first event date whose buyers still keep their email: today minus
     * BUYER_EMAIL_DAYS, in the site's own time, as Y-m-d. An event on this
     * date or later is kept; an earlier one is purged.
     */
    public function buyerEmailCutoff(): string
    {
        $now = strtotime((string) current_time('mysql'));

        if (false === $now) {
            return '';
        }

        $cutoff = strtotime('-' . PrivacyPolicy::BUYER_EMAIL_DAYS . ' days', $now);

        return false === $cutoff ? '' : gmdate('Y-m-d', $cutoff);
    }

    /**
     * Buyer email addresses that must not be deleted: the people who joined
     * the newsletter. There is no newsletter yet, so this returns none and
     * every expired address is deleted. The newsletter release fills this in.
     *
     * @return array<int, string>
     */
    public function buyerEmailsToKeep(): array
    {
        return [];
    }

    /**
     * The datetime before which a record counts as expired.
     *
     * Built by subtracting the retention period from the site's own current
     * time, in the same naive local string every datetime column here holds -
     * comparing a local column against a UTC bound would move the cutoff by the
     * site's offset, which on a destructive job is not a rounding error.
     */
    public function cutoff(): string
    {
        $now = strtotime((string) current_time('mysql'));

        if (false === $now) {
            return '';
        }

        $cutoff = strtotime('-' . PrivacyPolicy::retentionYears() . ' years', $now);

        return false === $cutoff ? '' : gmdate('Y-m-d H:i:s', $cutoff);
    }
}
