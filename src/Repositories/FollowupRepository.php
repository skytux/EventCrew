<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;

/**
 * The reads and writes of the post-event email (Support\FollowupMail): its
 * three columns on the ticket buyers table and on the guests table, and the
 * night's check-ins that tell who came.
 *
 * A row is one ticket holder with an email address on one night. kind is
 * KIND_BUYER or KIND_GUEST, the same words the check-ins table uses.
 */
final class FollowupRepository
{
    public const KIND_BUYER = CheckinRepository::KIND_BUYER;
    public const KIND_GUEST = CheckinRepository::KIND_GUEST;

    /**
     * Every ticket holder on $date with an email address: buyers first, then
     * guests, each in the order they were added. A row whose email was
     * deleted (by the retention purge or an erasure request) is not among
     * them.
     *
     * @return array<int, array{kind: string, id: int, name: string, email: string, status: string}>
     */
    public function recipients(string $date): array
    {
        $rows = [];

        foreach ([self::KIND_BUYER, self::KIND_GUEST] as $kind) {
            foreach ($this->rowsOf($kind, $date) as $row) {
                $rows[] = [
                    'kind' => $kind,
                    'id' => (int) ($row['id'] ?? 0),
                    'name' => (string) ($row['name'] ?? ''),
                    'email' => trim((string) ($row['email'] ?? '')),
                    'status' => (string) ($row['followup_mail_status'] ?? ''),
                ];
            }
        }

        return $rows;
    }

    /**
     * The nights from $from to $to (Y-m-d, both included) that have at least
     * one ticket holder with an email address, earliest first.
     *
     * @return array<int, string>
     */
    public function datesWithRecipients(string $from, string $to): array
    {
        global $wpdb;

        $buyers = Schema::table(Schema::TICKET_BUYERS);
        $guests = Schema::table(Schema::GUESTS);

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- placeholders only; the table names come from Schema.
        $dates = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT event_date FROM {$buyers}"
                    . " WHERE event_date BETWEEN %s AND %s AND email IS NOT NULL AND email <> ''"
                    . " UNION SELECT event_date FROM {$guests}"
                    . " WHERE event_date BETWEEN %s AND %s AND email IS NOT NULL AND email <> ''",
                $from,
                $to,
                $from,
                $to
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        $dates = array_values(array_unique(array_map('strval', is_array($dates) ? $dates : [])));
        sort($dates);

        return $dates;
    }

    /**
     * Which ticket holders on $date were checked in at the door, as
     * "kind:id" keys.
     *
     * @return array<string, true>
     */
    public function checkedIn(string $date): array
    {
        global $wpdb;

        $table = Schema::table(Schema::CHECKINS);
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT kind, ref_id FROM {$table} WHERE event_date = %s AND kind IN (%s, %s)",
                $date,
                self::KIND_BUYER,
                self::KIND_GUEST
            ),
            ARRAY_A
        );
        $keys = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $keys[(string) ($row['kind'] ?? '') . ':' . (int) ($row['ref_id'] ?? 0)] = true;
        }

        return $keys;
    }

    /** Records what happened to one row's post-event email, and when. */
    public function setStatus(string $kind, int $id, string $status): void
    {
        global $wpdb;

        $wpdb->update(
            self::tableOf($kind),
            ['followup_mail_status' => $status, 'followup_mailed_at' => current_time('mysql')],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );
    }

    /**
     * Marks every row on $date that has not had the email as $status (used
     * for 'stopped'). Returns how many rows changed.
     */
    public function markUnsent(string $date, string $status): int
    {
        global $wpdb;

        $changed = 0;

        foreach ([self::KIND_BUYER, self::KIND_GUEST] as $kind) {
            $table = self::tableOf($kind);
            $result = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET followup_mail_status = %s WHERE event_date = %s AND followup_mail_status = ''",
                    $status,
                    $date
                )
            );
            $changed += false === $result ? 0 : (int) $result;
        }

        return $changed;
    }

    /**
     * One ticket holder's name and email address, for the subscribe button
     * in their post-event email. Null when there is no such row, or its email
     * was deleted (by the retention purge or an erasure request).
     *
     * @return array{name: string, email: string}|null
     */
    public function holder(string $kind, int $id): ?array
    {
        global $wpdb;

        if ($id <= 0 || ! in_array($kind, [self::KIND_BUYER, self::KIND_GUEST], true)) {
            return null;
        }

        $table = self::tableOf($kind);
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT name, email FROM {$table} WHERE id = %d", $id),
            ARRAY_A
        );

        if (! is_array($row)) {
            return null;
        }

        $email = trim((string) ($row['email'] ?? ''));

        return '' === $email ? null : ['name' => (string) ($row['name'] ?? ''), 'email' => $email];
    }

    /**
     * Records the first click on a link in one row's post-event email. Only
     * the time is stored, and only once: a second click changes nothing.
     * Returns whether this was the first.
     */
    public function recordClick(string $kind, int $id): bool
    {
        global $wpdb;

        if ($id <= 0 || ! in_array($kind, [self::KIND_BUYER, self::KIND_GUEST], true)) {
            return false;
        }

        $table = self::tableOf($kind);
        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET followup_clicked_at = %s WHERE id = %d AND followup_clicked_at IS NULL",
                current_time('mysql'),
                $id
            )
        );

        return is_int($result) && $result > 0;
    }

    /**
     * How many of $date's post-event emails were sent, failed, stopped and
     * are not sent yet, and how many of the sent ones had a link opened.
     * A row that shared its address with another row of the night, and so
     * got no email of its own ('duplicate'), is not counted.
     *
     * @return array{sent: int, failed: int, stopped: int, unsent: int, clicked: int}
     */
    public function counts(string $date): array
    {
        global $wpdb;

        $counts = ['sent' => 0, 'failed' => 0, 'stopped' => 0, 'unsent' => 0, 'clicked' => 0];

        foreach ([self::KIND_BUYER, self::KIND_GUEST] as $kind) {
            $table = self::tableOf($kind);
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT followup_mail_status AS status, COUNT(*) AS n,'
                        . ' SUM(followup_clicked_at IS NOT NULL) AS clicked'
                        . " FROM {$table} WHERE event_date = %s"
                        . " AND (followup_mail_status <> '' OR (email IS NOT NULL AND email <> ''))"
                        . ' GROUP BY followup_mail_status',
                    $date
                ),
                ARRAY_A
            );

            foreach (is_array($rows) ? $rows : [] as $row) {
                $status = (string) ($row['status'] ?? '');
                $n = (int) ($row['n'] ?? 0);
                $counts['clicked'] += (int) ($row['clicked'] ?? 0);

                if (isset($counts[$status]) && 'unsent' !== $status && 'clicked' !== $status) {
                    $counts[$status] += $n;
                } elseif ('' === $status) {
                    $counts['unsent'] += $n;
                }
            }
        }

        return $counts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rowsOf(string $kind, string $date): array
    {
        global $wpdb;

        $table = self::tableOf($kind);
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, email, followup_mail_status FROM {$table}"
                    . " WHERE event_date = %s AND email IS NOT NULL AND email <> '' ORDER BY id ASC",
                $date
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    private static function tableOf(string $kind): string
    {
        return Schema::table(self::KIND_GUEST === $kind ? Schema::GUESTS : Schema::TICKET_BUYERS);
    }
}
