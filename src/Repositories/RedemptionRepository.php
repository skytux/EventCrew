<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;
use EventCrew\Models\Redemption;
use EventCrew\Support\History;

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
     * completed-task count to give the current balance. A ticket the person
     * gave to someone else is cancelled but still counts: its credit was
     * spent, the receiver holds the ticket.
     */
    public function countFor(int $personId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table()} WHERE person_id = %d
                AND (cancelled_at IS NULL OR transferred_to IS NOT NULL)",
                $personId
            )
        );
    }

    /**
     * Records a credit spent for entry to the event on $date and returns the new
     * row's id (which the self-service ticket signs its link with). The caller
     * re-checks the balance first; this is the write only.
     */
    /**
     * Spends a credit on one date, and refuses to spend a second on the same
     * one. Returns the new redemption's id, or 0 when there already was one.
     *
     * The check has to live in the statement, not in front of it. Every caller
     * asks "have they already redeemed this date, and do they have a credit?"
     * before calling - and between that question and this write there is a gap
     * two requests can both be inside. A double-tap on Get my ticket, or the
     * bot and the web page a moment apart, then wrote two rows and spent two
     * credits against a balance of one; the balance is derived from these rows,
     * so it simply went negative and clamped at zero, leaving somebody free
     * entry twice over and nothing to show it.
     *
     * This is the same guard the assignments table has carried since v0.5 for
     * exactly the same reason, and the same derived-table trick: MySQL will not
     * let a subquery name the table its INSERT targets, so the existing rows
     * are read through a wrapper, which also forces materialisation.
     *
     * A conditional insert rather than a unique key on (person_id,
     * redeemed_for) because the column is nullable and installs may already
     * hold duplicate rows from before this - an index added by dbDelta over
     * those would fail the migration on the sites that most need the fix.
     */
    public function record(
        int $personId,
        string $date,
        ?int $eventPostId = null,
        string $eventLabel = '',
        string $note = '',
        int $held = 0
    ): int {
        global $wpdb;

        // The event column stays genuinely NULL when there is no event, rather
        // than becoming the 0 that prepare()'s %d would produce for null.
        $eventValue = null === $eventPostId ? 'NULL' : '%d';
        $arguments = [$personId, $date];

        if (null !== $eventPostId) {
            $arguments[] = $eventPostId;
        }

        $arguments[] = $eventLabel;
        $arguments[] = current_time('mysql');
        $arguments[] = $note;
        $arguments[] = $personId;
        $arguments[] = $date;

        // With several tickets per night (SeveralTickets) the guard is the
        // number of live tickets the person held when they asked: a
        // double-tap finds one more and is refused, a deliberate second
        // ticket names the count it saw.
        if ($held > 0) {
            $arguments[] = $held;
            $guard = "WHERE (
                     SELECT COUNT(*) FROM (
                         SELECT person_id, redeemed_for FROM {$this->table()} WHERE cancelled_at IS NULL
                     ) AS existing
                     WHERE existing.person_id = %d AND existing.redeemed_for = %s
                 ) = %d";
        } else {
            $guard = "WHERE NOT EXISTS (
                     SELECT 1 FROM (
                         SELECT person_id, redeemed_for FROM {$this->table()} WHERE cancelled_at IS NULL
                     ) AS existing
                     WHERE existing.person_id = %d AND existing.redeemed_for = %s
                 )";
        }

        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$this->table()}
                    (person_id, redeemed_for, event_post_id, event_label, redeemed_at, note)
                 SELECT %d, %s, {$eventValue}, %s, %s, %s
                 FROM (SELECT 1) AS placeholder
                 {$guard}",
                $arguments
            )
        );

        $id = (int) $wpdb->insert_id;

        if (1 === $inserted && $id > 0) {
            History::record(History::CREDIT_REDEEMED, ['person_id' => $personId, 'event_date' => $date]);
        }

        return $id;
    }

    /**
     * One redemption by id, or null when it is gone - what the self-service
     * ticket page reads to know its credit is still live.
     */
    public function find(int $id): ?Redemption
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id),
            ARRAY_A
        );

        return is_array($row) ? Redemption::fromRow($row) : null;
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
                "SELECT * FROM {$this->table()} WHERE redeemed_for = %s AND cancelled_at IS NULL ORDER BY id ASC",
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
                "SELECT * FROM {$this->table()} WHERE person_id = %d AND cancelled_at IS NULL
                ORDER BY redeemed_at DESC",
                $personId
            ),
            ARRAY_A
        );

        return array_map(
            static fn (array $row): Redemption => Redemption::fromRow($row),
            is_array($rows) ? $rows : []
        );
    }

    /**
     * Every redemption of a person, cancelled ones included, for the privacy
     * export.
     *
     * @return array<int, Redemption>
     */
    public function allForPerson(int $personId): array
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

    /**
     * Undoes a redemption without deleting it: the credit is handed back and
     * the ticket link stays, showing DISABLED. True only when this call
     * cancelled the row: false when it is gone or was already cancelled, so
     * no caller reports a removal that did not happen.
     */
    public function cancel(int $id): bool
    {
        global $wpdb;

        $changed = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table()} SET cancelled_at = %s WHERE id = %d AND cancelled_at IS NULL",
                current_time('mysql'),
                $id
            )
        );

        if (is_int($changed) && $changed > 0) {
            History::record(History::REDEMPTION_CANCELLED, ['redemption_id' => $id]);
            $this->reslotCheckins($id);

            return true;
        }

        return false;
    }

    /**
     * The id of the person's first live ticket for the night (the lowest),
     * or 0 when they hold none - the ticket checked in as their credit row
     * (CheckinRepository::creditEntry()).
     */
    public function firstLiveIdFor(int $personId, string $date): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MIN(id) FROM {$this->table()}
                WHERE person_id = %d AND redeemed_for = %s AND cancelled_at IS NULL",
                $personId,
                $date
            )
        );
    }

    /**
     * The door entry [kind, ref_id] ticket $redemption is checked in as
     * (CheckinRepository::creditEntry()).
     *
     * @return array{0: string, 1: int}
     */
    public function checkinEntry(Redemption $redemption): array
    {
        if (null === $redemption->redeemedFor) {
            return [CheckinRepository::KIND_CREDIT, $redemption->personId];
        }

        return CheckinRepository::creditEntry(
            $redemption->id,
            $redemption->personId,
            $this->firstLiveIdFor($redemption->personId, $redemption->redeemedFor)
        );
    }

    /**
     * After ticket $id stopped being live: when it was the person's first
     * ticket of the night and they hold another, the door rows are swapped
     * so the new first ticket owns the credit row (person id) and the one
     * that left keeps its own (CheckinRepository::creditEntry()).
     */
    private function reslotCheckins(int $id): void
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT r.person_id, r.redeemed_for, (
                    SELECT MIN(o.id) FROM {$this->table()} o
                    WHERE o.person_id = r.person_id AND o.redeemed_for = r.redeemed_for
                    AND o.cancelled_at IS NULL
                ) AS first_live
                FROM {$this->table()} r WHERE r.id = %d",
                $id
            ),
            ARRAY_A
        );

        if (!is_array($row) || null === ($row['redeemed_for'] ?? null)) {
            return;
        }

        $first = (int) ($row['first_live'] ?? 0);

        // Only when the ticket that left came before every live one.
        if ($first <= $id) {
            return;
        }

        $personId = (int) $row['person_id'];
        $date = (string) $row['redeemed_for'];
        $checkins = new CheckinRepository();

        $checkins->move($date, CheckinRepository::KIND_CREDIT, $personId, CheckinRepository::KIND_CREDIT_EXTRA, $id);
        $checkins->move($date, CheckinRepository::KIND_CREDIT_EXTRA, $first, CheckinRepository::KIND_CREDIT, $personId);
    }

    /**
     * Cancels a ticket because it was given away: cancelled_at is set and
     * transferred_to names the transfer, so the credit stays spent
     * (countFor()). Only a ticket that is neither cancelled nor given away
     * already; false when it was, so two taps cannot give one ticket twice.
     */
    public function markTransferred(int $id, int $transferId): bool
    {
        global $wpdb;

        $changed = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table()} SET cancelled_at = %s, transferred_to = %d
                WHERE id = %d AND cancelled_at IS NULL AND transferred_to IS NULL",
                current_time('mysql'),
                $transferId,
                $id
            )
        );

        if (!is_int($changed) || $changed <= 0) {
            return false;
        }

        $this->reslotCheckins($id);

        return true;
    }

    /** Records that the "Your ticket" email (TicketMail) was sent for this credit, now. */
    public function markTicketMailed(int $id): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table(),
            ['ticket_mailed_at' => current_time('mysql')],
            ['id' => $id],
            ['%s'],
            ['%d']
        );
    }

    public function deleteForPerson(int $personId): void
    {
        global $wpdb;

        $wpdb->delete($this->table(), ['person_id' => $personId]);
    }
}
