<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;
use EventCrew\Models\Guest;

/**
 * All reads and writes of the guest list table.
 */
final class GuestRepository
{
    /** Most plus-ones one guest may bring - a typo guard, not a policy. */
    public const MAX_PLUS_ONES = 20;

    private function table(): string
    {
        return Schema::table(Schema::GUESTS);
    }

    /**
     * Every guest on $date, in the order they were added - so the numbers the
     * bot shows stay put while the list grows.
     *
     * @return array<int, Guest>
     */
    public function forDate(string $date): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE event_date = %s ORDER BY id ASC",
                $date
            ),
            ARRAY_A
        );

        return array_map(static fn (array $row): Guest => Guest::fromRow($row), is_array($rows) ? $rows : []);
    }

    public function find(int $id): ?Guest
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id),
            ARRAY_A
        );

        return is_array($row) ? Guest::fromRow($row) : null;
    }

    /**
     * Adds a guest and returns the new row's id (0 on failure). $email is
     * only given by a mailed guest list; such a guest is emailed a ticket.
     */
    public function add(
        string $date,
        string $name,
        int $plusOnes,
        string $note,
        ?int $addedBy,
        string $email = ''
    ): int {
        global $wpdb;

        $row = [
            'event_date' => $date,
            'name' => $name,
            'plus_ones' => self::clampPlusOnes($plusOnes),
            'note' => $note,
            'created_at' => current_time('mysql'),
        ];
        $formats = ['%s', '%s', '%d', '%s', '%s'];

        // Left out rather than written as 0 when unknown, so the column stays NULL.
        if (null !== $addedBy) {
            $row['added_by'] = $addedBy;
            $formats[] = '%d';
        }

        if ('' !== $email) {
            $row['email'] = $email;
            $formats[] = '%s';
        }

        $wpdb->insert($this->table(), $row, $formats);

        return (int) $wpdb->insert_id;
    }

    public function update(int $id, string $name, int $plusOnes, string $note): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table(),
            ['name' => $name, 'plus_ones' => self::clampPlusOnes($plusOnes), 'note' => $note],
            ['id' => $id],
            ['%s', '%d', '%s'],
            ['%d']
        );
    }

    public function delete(int $id): void
    {
        global $wpdb;

        $wpdb->delete($this->table(), ['id' => $id], ['%d']);
    }

    /**
     * Guests with an email address whose ticket has not been tried yet, on
     * $fromDate or later, oldest event first. A 'failed' row is not among
     * them; only an organizer's "Send the rest now" tries it again.
     *
     * @return array<int, Guest>
     */
    public function unsentFrom(string $fromDate, int $limit): array
    {
        return $this->mailRows(
            "event_date >= %s AND ticket_mail_status = ''",
            [$fromDate],
            'event_date ASC, id ASC',
            $limit
        );
    }

    /**
     * Guests on $date with an email address whose ticket has not gone out:
     * not tried yet first, then the failed ones.
     *
     * @return array<int, Guest>
     */
    public function notSentOn(string $date, int $limit): array
    {
        return $this->mailRows(
            'event_date = %s AND ticket_mail_status <> %s',
            [$date, 'sent'],
            "(ticket_mail_status = 'failed') ASC, id ASC",
            $limit
        );
    }

    /** The first guest on $date with an email address, whatever its status. */
    public function firstMailableOn(string $date): ?Guest
    {
        return $this->mailRows('event_date = %s', [$date], 'id ASC', 1)[0] ?? null;
    }

    /**
     * How many tickets to $date's guests with an email address were sent,
     * failed and are not sent yet.
     *
     * @return array{sent: int, failed: int, unsent: int}
     */
    public function mailCounts(string $date): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ticket_mail_status AS status, COUNT(*) AS n FROM {$this->table()}"
                    . " WHERE event_date = %s AND email IS NOT NULL AND email <> ''"
                    . ' GROUP BY ticket_mail_status',
                $date
            ),
            ARRAY_A
        );

        return TicketBuyerRepository::countsFrom(is_array($rows) ? $rows : []);
    }

    /** Records what happened to a guest's ticket email: 'sent' or 'failed', and now. */
    public function setMailStatus(int $id, string $status): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table(),
            ['ticket_mail_status' => $status, 'ticket_mailed_at' => current_time('mysql')],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );
    }

    /**
     * Every guest row with this email address, for the privacy export.
     *
     * @return array<int, Guest>
     */
    public function rowsForEmail(string $email): array
    {
        global $wpdb;

        if ('' === $email) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE email = %s ORDER BY event_date ASC, id ASC",
                $email
            ),
            ARRAY_A
        );

        return array_map(static fn (array $row): Guest => Guest::fromRow($row), is_array($rows) ? $rows : []);
    }

    /**
     * Blanks the email address and the name on every guest row with this
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
                "UPDATE {$this->table()} SET email = NULL, name = '' WHERE email = %s",
                $email
            )
        );

        return false === $changed ? 0 : (int) $changed;
    }

    /**
     * Blanks the email address of every guest whose night was before
     * $cutoffDate (Y-m-d). The name stays: the guest list keeps its names, as
     * it did before guests had addresses. Returns how many rows were changed.
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
     * @param array<int, string> $args The values of $where's placeholders.
     * @return array<int, Guest>
     */
    private function mailRows(string $where, array $args, string $order, int $limit): array
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- placeholders only; the table name comes from Schema.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE {$where} AND email IS NOT NULL AND email <> ''"
                    . " ORDER BY {$order} LIMIT %d",
                ...array_merge($args, [max(1, $limit)])
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        return array_map(static fn (array $row): Guest => Guest::fromRow($row), is_array($rows) ? $rows : []);
    }

    private static function clampPlusOnes(int $plusOnes): int
    {
        return max(0, min(self::MAX_PLUS_ONES, $plusOnes));
    }
}
