<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;
use EventCrew\Models\Redemption;

/**
 * All reads and writes of the redemptions table - one row per credit spent for
 * free entry. The credit balance itself is not stored: it is completions earned
 * (AssignmentRepository::countCompletedFor) minus rows counted here, computed by
 * Support\Credits, so there is no running total to drift out of sync.
 */
final class RedemptionRepository
{
    private function table(): string
    {
        return Schema::table(Schema::REDEMPTIONS);
    }

    /**
     * How many credits this person has spent, all-time. Paired with their
     * completed-task count to give the current balance.
     */
    public function countFor(int $personId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table()} WHERE person_id = %d",
                $personId
            )
        );
    }

    /**
     * Records a credit spent for entry to the event on $date. The caller
     * (the door list) re-checks the balance first; this is the write only.
     */
    public function record(
        int $personId,
        string $date,
        ?int $eventPostId = null,
        string $eventLabel = '',
        string $note = ''
    ): void {
        global $wpdb;

        $wpdb->insert(
            $this->table(),
            [
                'person_id' => $personId,
                'redeemed_for' => $date,
                'event_post_id' => $eventPostId,
                'event_label' => $eventLabel,
                'redeemed_at' => current_time('mysql'),
                'note' => $note,
            ]
        );
    }

    /**
     * Every credit redeemed for the event on $date - the credit half of that
     * night's door list. Full rows, so each carries the id its Remove button
     * needs.
     *
     * @return array<int, Redemption>
     */
    public function forDate(string $date): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE redeemed_for = %s ORDER BY id ASC",
                $date
            ),
            ARRAY_A
        );

        return array_map(
            static fn (array $row): Redemption => Redemption::fromRow($row),
            is_array($rows) ? $rows : []
        );
    }

    /**
     * @return array<int, Redemption>
     */
    public function forPerson(int $personId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE person_id = %d ORDER BY redeemed_at DESC",
                $personId
            ),
            ARRAY_A
        );

        return array_map(
            static fn (array $row): Redemption => Redemption::fromRow($row),
            is_array($rows) ? $rows : []
        );
    }

    public function delete(int $id): void
    {
        global $wpdb;

        $wpdb->delete($this->table(), ['id' => $id]);
    }

    public function deleteForPerson(int $personId): void
    {
        global $wpdb;

        $wpdb->delete($this->table(), ['person_id' => $personId]);
    }
}
