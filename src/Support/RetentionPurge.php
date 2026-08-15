<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Repositories\PersonRepository;

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
        private readonly PersonEraser $eraser
    ) {
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
