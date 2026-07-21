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
        global $wpdb;

        return null !== $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->table()} WHERE kind = %s AND person_id = %d AND task_date = %s",
                $kind,
                $personId,
                $date
            )
        );
    }

    public function recordSent(string $kind, int $personId, string $date, ?int $eventPostId = null): void
    {
        global $wpdb;

        $wpdb->insert(
            $this->table(),
            [
                'kind' => $kind,
                'person_id' => $personId,
                'task_date' => $date,
                'event_post_id' => $eventPostId,
                'sent_at' => current_time('mysql'),
            ]
        );
    }
}
