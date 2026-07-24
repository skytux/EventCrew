<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;

/**
 * All reads and writes of the credit-grants table - one row per bonus credit an
 * organizer hands someone by hand, outside the earn-one-per-two-completed rule.
 *
 * Like the redemptions half, the grant total is not a stored balance: it is the
 * sum of these rows, folded into Support\Credits alongside earned and redeemed,
 * so there is no running total to drift out of sync.
 */
final class CreditGrantRepository
{
    private function table(): string
    {
        return Schema::table(Schema::CREDIT_GRANTS);
    }

    /**
     * How many credits this person has been granted by hand, all-time. Added to
     * their earned-minus-redeemed balance.
     */
    public function sumFor(int $personId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(credits), 0) FROM {$this->table()} WHERE person_id = %d",
                $personId
            )
        );
    }

    /**
     * Records a bonus credit (or several) for a person, with an optional note
     * and the organizer's user id for the record.
     */
    public function record(int $personId, int $credits, string $note, int $grantedBy): void
    {
        global $wpdb;

        $wpdb->insert(
            $this->table(),
            [
                'person_id' => $personId,
                'credits' => max(1, $credits),
                'note' => $note,
                'granted_by' => 0 === $grantedBy ? null : $grantedBy,
                'granted_at' => current_time('mysql'),
            ]
        );
    }

    public function deleteForPerson(int $personId): void
    {
        global $wpdb;

        $wpdb->delete($this->table(), ['person_id' => $personId]);
    }
}
