<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;
use EventCrew\Models\TicketTransfer;

/**
 * All reads and writes of the ticket transfer table (TicketTransfer).
 */
final class TicketTransferRepository
{
    private function table(): string
    {
        return Schema::table(Schema::TICKET_TRANSFERS);
    }

    /**
     * Adds a transfer and returns its id (0 on failure).
     *
     * @param array{redemption_id: int, event_date: string, event_post_id: ?int, giver_person_id: int,
     *     receiver_person_id: ?int, name: string, email: ?string, language: ?string, status: string} $fields
     */
    public function create(array $fields): int
    {
        global $wpdb;

        $row = [
            'redemption_id' => $fields['redemption_id'],
            'event_date' => $fields['event_date'],
            'event_post_id' => $fields['event_post_id'],
            'giver_person_id' => $fields['giver_person_id'],
            'receiver_person_id' => $fields['receiver_person_id'],
            'name' => $fields['name'],
            'email' => null === $fields['email'] || '' === $fields['email'] ? null : $fields['email'],
            'language' => $fields['language'],
            'status' => $fields['status'],
            'created_at' => current_time('mysql'),
            'issued_at' => TicketTransfer::STATUS_ISSUED === $fields['status'] ? current_time('mysql') : null,
        ];

        $ok = $wpdb->insert($this->table(), $row);

        return false === $ok ? 0 : (int) $wpdb->insert_id;
    }

    public function find(int $id): ?TicketTransfer
    {
        global $wpdb;

        if ($id <= 0) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id),
            ARRAY_A
        );

        return is_array($row) ? TicketTransfer::fromRow($row) : null;
    }

    /** The pending offer on this credit ticket, if any (there is at most one). */
    public function pendingForRedemption(int $redemptionId): ?TicketTransfer
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE redemption_id = %d AND status = %s ORDER BY id DESC LIMIT 1",
                $redemptionId,
                TicketTransfer::STATUS_PENDING
            ),
            ARRAY_A
        );

        return is_array($row) ? TicketTransfer::fromRow($row) : null;
    }

    /**
     * Issued transfers of $date: the Transferred tickets at the door.
     *
     * @return array<int, TicketTransfer>
     */
    public function issuedForDate(string $date): array
    {
        return $this->rows(
            'event_date = %s AND status = %s',
            [$date, TicketTransfer::STATUS_ISSUED]
        );
    }

    /**
     * Issued transfers this member received, upcoming or not.
     *
     * @return array<int, TicketTransfer>
     */
    public function heldBy(int $personId): array
    {
        if ($personId <= 0) {
            return [];
        }

        return $this->rows(
            'receiver_person_id = %d AND status = %s',
            [$personId, TicketTransfer::STATUS_ISSUED]
        );
    }

    /**
     * Pending offers this person made to an email address (not to a member).
     *
     * @return array<int, TicketTransfer>
     */
    public function pendingToEmailsBy(int $giverPersonId): array
    {
        return $this->rows(
            'giver_person_id = %d AND status = %s AND receiver_person_id IS NULL',
            [$giverPersonId, TicketTransfer::STATUS_PENDING]
        );
    }

    /**
     * Moves a pending transfer to issued, with the receiver's name. Guarded on
     * pending, so an accept after a cancel or a lapse changes nothing.
     */
    public function markIssued(int $id, string $name): bool
    {
        global $wpdb;

        $changed = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table()} SET status = %s, name = %s, issued_at = %s WHERE id = %d AND status = %s",
                TicketTransfer::STATUS_ISSUED,
                $name,
                current_time('mysql'),
                $id,
                TicketTransfer::STATUS_PENDING
            )
        );

        return is_int($changed) && $changed > 0;
    }

    /**
     * Ends a transfer (cancelled or lapsed), only from status $from. Returns
     * whether it changed, so two taps cannot both act.
     */
    public function end(int $id, string $status, string $from): bool
    {
        global $wpdb;

        $changed = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table()} SET status = %s, ended_at = %s WHERE id = %d AND status = %s",
                $status,
                current_time('mysql'),
                $id,
                $from
            )
        );

        return is_int($changed) && $changed > 0;
    }

    public function markTicketMailed(int $id): void
    {
        global $wpdb;

        $wpdb->update($this->table(), ['ticket_mailed_at' => current_time('mysql')], ['id' => $id]);
    }

    /**
     * Every transfer to this email address, for a privacy export.
     *
     * @return array<int, TicketTransfer>
     */
    public function rowsForEmail(string $email): array
    {
        if ('' === $email) {
            return [];
        }

        return $this->rows('email = %s', [$email]);
    }

    /**
     * Blanks the receiver's email address and name on every transfer to this
     * email, for a privacy erasure. The row stays, so the night's head count
     * does not change. Returns how many rows were changed.
     */
    public function eraseEmail(string $email): int
    {
        global $wpdb;

        if ('' === $email) {
            return 0;
        }

        $changed = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table()} SET email = NULL, name = '',"
                . " status = IF(status = %s, %s, status) WHERE email = %s",
                TicketTransfer::STATUS_PENDING,
                TicketTransfer::STATUS_CANCELLED,
                $email
            )
        );

        return false === $changed ? 0 : (int) $changed;
    }

    /**
     * Deletes the tickets given to member $personId, when the member is
     * erased: like their own credit tickets, they go with them.
     */
    public function deleteForReceiver(int $personId): void
    {
        global $wpdb;

        if ($personId > 0) {
            $wpdb->delete($this->table(), ['receiver_person_id' => $personId], ['%d']);
        }
    }

    /**
     * Blanks the receiver's email address on every transfer whose night was
     * before $cutoffDate (Y-m-d), like the guest list. Returns how many rows
     * were changed.
     */
    public function anonymizeEmailsBefore(string $cutoffDate): int
    {
        global $wpdb;

        $changed = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table()} SET email = NULL WHERE event_date < %s AND email IS NOT NULL",
                $cutoffDate
            )
        );

        return false === $changed ? 0 : (int) $changed;
    }

    /**
     * @param array<int, int|string> $args
     * @return array<int, TicketTransfer>
     */
    private function rows(string $where, array $args): array
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- placeholders only; the table name comes from Schema.
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$this->table()} WHERE {$where} ORDER BY id ASC", ...$args),
            ARRAY_A
        );
        // phpcs:enable

        return array_map(
            static fn (array $row): TicketTransfer => TicketTransfer::fromRow($row),
            is_array($rows) ? $rows : []
        );
    }
}
