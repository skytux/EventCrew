<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;

/**
 * The send-once ledger. Its unique key `(kind, person_id, task_date)` is what
 * actually prevents a double-send: a run that dies partway through a batch, or
 * simply gets triggered twice, resumes without re-mailing the people it already
 * reached.
 */
final class NotificationsRepository
{
    private function table(): string
    {
        return Schema::table(Schema::NOTIFICATIONS);
    }

    public function hasSent(string $kind, int $personId, string $date): bool
    {
        return null !== $this->sentAt($kind, $personId, $date);
    }

    /**
     * When this person was last sent this kind for this date, or null if never.
     *
     * The timestamp, not just the fact, because "have they been told about this
     * date" is not the same question as "have they been told about everything
     * that is now on it" - see OpenTaskCall, which compares this against when
     * each task was created.
     */
    public function sentAt(string $kind, int $personId, string $date): ?string
    {
        global $wpdb;

        $sentAt = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT sent_at FROM {$this->table()} WHERE kind = %s AND person_id = %d AND task_date = %s",
                $kind,
                $personId,
                $date
            )
        );

        return null === $sentAt ? null : (string) $sentAt;
    }

    /**
     * Whether this person has had any notification whose kind starts with
     * $prefix on the given day. This is what a per-day cap is asked, so one
     * person cannot collect a separate email for every date that happens to
     * fall due at once.
     */
    public function sentOnDay(string $prefix, int $personId, string $day): bool
    {
        global $wpdb;

        return null !== $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->table()}
                WHERE person_id = %d AND kind LIKE %s AND DATE(sent_at) = %s
                LIMIT 1",
                $personId,
                $wpdb->esc_like($prefix) . '%',
                $day
            )
        );
    }

    /**
     * Records a send, refreshing the timestamp when there is already a row for
     * this (kind, person, date). An update rather than an ignored duplicate,
     * because a re-send means the person has now been told about whatever was
     * added since - and the next "is there anything newer than the last send"
     * check has to measure from this send, not the first one.
     */
    public function recordSent(string $kind, int $personId, string $date, ?int $eventPostId = null): void
    {
        global $wpdb;

        // The event column stays genuinely NULL when there is no event, rather
        // than becoming a 0 that prepare()'s %d would produce for null.
        $eventValue = null === $eventPostId ? 'NULL' : '%d';
        $arguments = [$kind, $personId, $date];

        if (null !== $eventPostId) {
            $arguments[] = $eventPostId;
        }

        $arguments[] = current_time('mysql');

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$this->table()} (kind, person_id, task_date, event_post_id, sent_at)
                VALUES (%s, %d, %s, {$eventValue}, %s)
                ON DUPLICATE KEY UPDATE sent_at = VALUES(sent_at), event_post_id = VALUES(event_post_id)",
                $arguments
            )
        );
    }
}
