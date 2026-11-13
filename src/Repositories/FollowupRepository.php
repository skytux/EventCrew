<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;
use EventCrew\Models\Person;
use EventCrew\Support\AssignmentStatus;

/**
 * The reads and writes of the post-event email (Support\FollowupMail): its
 * three columns (followup_mail_status, followup_mailed_at,
 * followup_clicked_at) on the ticket buyers, guests, assignments and
 * redemptions tables, and the night's check-ins that tell who came.
 *
 * A row is one attendee of one night with an email address:
 *
 * - KIND_BUYER and KIND_GUEST: a ticket holder, with the email on the row,
 *   the same words the check-ins table uses.
 * - KIND_ASSIGNMENT: a crew member's assignment on a task filed under the
 *   night, with the person's email. Only a task that is not deleted, and
 *   only an assignment marked as come (arrived, arrived late, completed) or
 *   as a no-show: one still just signed up was not marked either way, and a
 *   cancelled or replaced one is not coming.
 * - KIND_REDEMPTION: a free-entry ticket (credit) for the night that is not
 *   cancelled (a ticket given away is cancelled too), with the person's
 *   email.
 *
 * A person's email is used only when it is a real one (not a placeholder),
 * confirmed by the person (email_verified_at) and the person is not erased
 * (anonymized_at). The person's notification switches play no part: the
 * post-event email has no switch of its own, and a person who switched
 * every email off still gets it (Owner, 2026-11-13). The do-not-send list
 * is what keeps an address out.
 */
final class FollowupRepository
{
    public const KIND_BUYER = CheckinRepository::KIND_BUYER;
    public const KIND_GUEST = CheckinRepository::KIND_GUEST;
    public const KIND_ASSIGNMENT = 'assignment';
    public const KIND_REDEMPTION = 'redemption';

    /** Every kind, in the order recipients() returns them. */
    public const KINDS = [self::KIND_BUYER, self::KIND_GUEST, self::KIND_ASSIGNMENT, self::KIND_REDEMPTION];

    /** The assignment statuses that make a crew member a recipient: marked as come, or as a no-show. */
    public const ASSIGNMENT_STATUSES = [
        AssignmentStatus::ARRIVED,
        AssignmentStatus::ARRIVED_LATE,
        AssignmentStatus::COMPLETED,
        AssignmentStatus::NO_SHOW,
    ];

    /** The assignment statuses that count as came. */
    public const CAME_STATUSES = [AssignmentStatus::ARRIVED, AssignmentStatus::ARRIVED_LATE, AssignmentStatus::COMPLETED];

    /**
     * Every attendee on $date with an email address: buyers, guests, crew
     * assignments and free-entry tickets, each kind in the order they were
     * added. A row whose email was deleted (by the retention purge or an
     * erasure request) is not among them. person_id is the person of an
     * assignment or a free-entry ticket (0 for the others); marked is true
     * for an assignment marked as come.
     *
     * @return array<int, array{kind: string, id: int, name: string, email: string, status: string, person_id: int, marked: bool}>
     */
    public function recipients(string $date): array
    {
        $rows = [];

        foreach (self::KINDS as $kind) {
            $person = self::isPersonKind($kind);

            foreach ($this->rowsOf($kind, $date) as $row) {
                $email = trim((string) ($row['email'] ?? ''));

                if ($person && Person::isPlaceholderEmail($email)) {
                    continue;
                }

                $rows[] = [
                    'kind' => $kind,
                    'id' => (int) ($row['id'] ?? 0),
                    'name' => (string) ($row['name'] ?? ''),
                    'email' => $email,
                    'status' => (string) ($row['followup_mail_status'] ?? ''),
                    'person_id' => $person ? (int) ($row['person_id'] ?? 0) : 0,
                    'marked' => self::KIND_ASSIGNMENT === $kind
                        && in_array((string) ($row['status'] ?? ''), self::CAME_STATUSES, true),
                ];
            }
        }

        return $rows;
    }

    /**
     * Whether a recipients() row came on the night, by $checkedIn
     * (checkedIn()): a ticket holder checked in on their own row; a crew
     * member marked as come on the roster or checked in at the door; a
     * free-entry ticket checked in at the door (the person's credit row, or
     * this ticket's own row when it was their second of the night).
     *
     * @param array{kind: string, id: int, person_id?: int, marked?: bool} $row
     * @param array<string, true> $checkedIn
     */
    public static function came(array $row, array $checkedIn): bool
    {
        $kind = $row['kind'];
        $personId = (int) ($row['person_id'] ?? 0);

        if (! self::isPersonKind($kind)) {
            return isset($checkedIn[$kind . ':' . $row['id']]);
        }

        if (! empty($row['marked'])) {
            return true;
        }

        foreach ([CheckinRepository::KIND_CREW, CheckinRepository::KIND_CREDIT] as $entry) {
            if (isset($checkedIn[$entry . ':' . $personId])) {
                return true;
            }
        }

        return self::KIND_REDEMPTION === $kind
            && isset($checkedIn[CheckinRepository::KIND_CREDIT_EXTRA . ':' . $row['id']]);
    }

    /**
     * The nights from $from to $to (Y-m-d, both included) that have at least
     * one attendee with an email address, earliest first. A crew member
     * counts while still just signed up: the night's preview goes out the
     * day before, when nobody is marked yet.
     *
     * @return array<int, string>
     */
    public function datesWithRecipients(string $from, string $to): array
    {
        global $wpdb;

        $buyers = Schema::table(Schema::TICKET_BUYERS);
        $guests = Schema::table(Schema::GUESTS);
        $assignments = Schema::table(Schema::ASSIGNMENTS);
        $tasks = Schema::table(Schema::TASKS);
        $redemptions = Schema::table(Schema::REDEMPTIONS);
        $people = Schema::table(Schema::PEOPLE);
        $usable = self::usableEmail();
        $statuses = self::inList(array_merge([AssignmentStatus::SIGNED_UP], self::ASSIGNMENT_STATUSES));

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- placeholders only; the table names come from Schema.
        $dates = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT event_date FROM {$buyers}"
                    . " WHERE event_date BETWEEN %s AND %s AND email IS NOT NULL AND email <> ''"
                    . " UNION SELECT event_date FROM {$guests}"
                    . " WHERE event_date BETWEEN %s AND %s AND email IS NOT NULL AND email <> ''"
                    . " UNION SELECT t.task_date FROM {$assignments} a"
                    . " INNER JOIN {$tasks} t ON t.id = a.task_id INNER JOIN {$people} p ON p.id = a.person_id"
                    . " WHERE t.task_date BETWEEN %s AND %s AND t.deleted_at IS NULL"
                    . " AND a.status IN ({$statuses}) AND {$usable}"
                    . " UNION SELECT r.redeemed_for FROM {$redemptions} r INNER JOIN {$people} p ON p.id = r.person_id"
                    . " WHERE r.redeemed_for BETWEEN %s AND %s AND r.cancelled_at IS NULL AND {$usable}",
                $from,
                $to,
                $from,
                $to,
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
     * Who was checked in at the door on $date, as "kind:ref_id" keys of the
     * check-ins table: a buyer or guest row id, the person id of crew and
     * credit, a redemption id for a second free-entry ticket (came()).
     *
     * @return array<string, true>
     */
    public function checkedIn(string $date): array
    {
        global $wpdb;

        $table = Schema::table(Schema::CHECKINS);
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT kind, ref_id FROM {$table} WHERE event_date = %s", $date),
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

        foreach (self::KINDS as $kind) {
            $table = self::tableOf($kind);

            if (self::KIND_ASSIGNMENT === $kind) {
                $tasks = Schema::table(Schema::TASKS);
                $statuses = self::inList(self::ASSIGNMENT_STATUSES);
                $sql = $wpdb->prepare(
                    "UPDATE {$table} a INNER JOIN {$tasks} t ON t.id = a.task_id SET a.followup_mail_status = %s"
                        . " WHERE t.task_date = %s AND t.deleted_at IS NULL AND a.status IN ({$statuses})"
                        . " AND a.followup_mail_status = ''",
                    $status,
                    $date
                );
            } elseif (self::KIND_REDEMPTION === $kind) {
                $sql = $wpdb->prepare(
                    "UPDATE {$table} SET followup_mail_status = %s"
                        . " WHERE redeemed_for = %s AND cancelled_at IS NULL AND followup_mail_status = ''",
                    $status,
                    $date
                );
            } else {
                $sql = $wpdb->prepare(
                    "UPDATE {$table} SET followup_mail_status = %s WHERE event_date = %s AND followup_mail_status = ''",
                    $status,
                    $date
                );
            }

            $result = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
            $changed += false === $result ? 0 : (int) $result;
        }

        return $changed;
    }

    /**
     * One row's name and email address, for the subscribe button and the
     * "Don't send me these" link in its post-event email. Null when there is
     * no such row, or its email was deleted (by the retention purge or an
     * erasure request), or, for a person, is a placeholder.
     *
     * @return array{name: string, email: string}|null
     */
    public function holder(string $kind, int $id): ?array
    {
        global $wpdb;

        if ($id <= 0 || ! in_array($kind, self::KINDS, true)) {
            return null;
        }

        $table = self::tableOf($kind);

        if (self::isPersonKind($kind)) {
            $people = Schema::table(Schema::PEOPLE);
            $alias = self::KIND_ASSIGNMENT === $kind ? 'a' : 'r';
            $usable = self::usableEmail();
            $sql = $wpdb->prepare(
                "SELECT p.display_name AS name, p.email FROM {$table} {$alias}"
                    . " INNER JOIN {$people} p ON p.id = {$alias}.person_id WHERE {$alias}.id = %d AND {$usable}",
                $id
            );
        } else {
            $sql = $wpdb->prepare("SELECT name, email FROM {$table} WHERE id = %d", $id);
        }

        $row = $wpdb->get_row($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.

        if (! is_array($row)) {
            return null;
        }

        $email = trim((string) ($row['email'] ?? ''));

        if ('' === $email || (self::isPersonKind($kind) && Person::isPlaceholderEmail($email))) {
            return null;
        }

        return ['name' => (string) ($row['name'] ?? ''), 'email' => $email];
    }

    /**
     * Records the first click on a link in one row's post-event email. Only
     * the time is stored, and only once: a second click changes nothing.
     * Returns whether this was the first.
     */
    public function recordClick(string $kind, int $id): bool
    {
        global $wpdb;

        if ($id <= 0 || ! in_array($kind, self::KINDS, true)) {
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

        foreach (self::KINDS as $kind) {
            [$from, $where] = self::nightRows($kind);
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT {$where['alias']}followup_mail_status AS status, COUNT(*) AS n,"
                        . " SUM({$where['alias']}followup_clicked_at IS NOT NULL) AS clicked"
                        . " FROM {$from} WHERE {$where['night']}"
                        . " AND ({$where['alias']}followup_mail_status <> '' OR ({$where['email']}))"
                        . " GROUP BY {$where['alias']}followup_mail_status",
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

        [$from, $where] = self::nightRows($kind);
        $alias = $where['alias'];
        $columns = self::isPersonKind($kind)
            ? "{$alias}id, {$alias}person_id, {$alias}followup_mail_status, p.display_name AS name, p.email"
                . (self::KIND_ASSIGNMENT === $kind ? ', a.status' : '')
            : 'id, name, email, followup_mail_status';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT {$columns} FROM {$from} WHERE {$where['night']} AND {$where['email']} ORDER BY {$alias}id ASC",
                $date
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * The FROM part and the WHERE parts that pick one kind's rows of a night
     * (one %s: the date): 'night' the night's attendees of the kind, 'email'
     * those with a usable address, and 'alias' the prefix of the kind's own
     * columns ('a.', 'r.' or '').
     *
     * @return array{0: string, 1: array{night: string, email: string, alias: string}}
     */
    private static function nightRows(string $kind): array
    {
        $table = self::tableOf($kind);
        $people = Schema::table(Schema::PEOPLE);

        if (self::KIND_ASSIGNMENT === $kind) {
            $tasks = Schema::table(Schema::TASKS);

            return [
                "{$table} a INNER JOIN {$tasks} t ON t.id = a.task_id INNER JOIN {$people} p ON p.id = a.person_id",
                [
                    'night' => 't.task_date = %s AND t.deleted_at IS NULL'
                        . ' AND a.status IN (' . self::inList(self::ASSIGNMENT_STATUSES) . ')',
                    'email' => self::usableEmail(),
                    'alias' => 'a.',
                ],
            ];
        }

        if (self::KIND_REDEMPTION === $kind) {
            return [
                "{$table} r INNER JOIN {$people} p ON p.id = r.person_id",
                [
                    'night' => 'r.redeemed_for = %s AND r.cancelled_at IS NULL',
                    'email' => self::usableEmail(),
                    'alias' => 'r.',
                ],
            ];
        }

        return [$table, ['night' => 'event_date = %s', 'email' => "email IS NOT NULL AND email <> ''", 'alias' => '']];
    }

    /**
     * The SQL condition that a person (alias p) has an address to write to:
     * not erased, confirmed by the person, and not a placeholder.
     */
    private static function usableEmail(): string
    {
        return "p.anonymized_at IS NULL AND p.email_verified_at IS NOT NULL AND p.email <> ''"
            . " AND RIGHT(p.email, 8) <> '.invalid'";
    }

    /**
     * Fixed status words as a quoted SQL list. Only ever this class's own
     * constants, never input.
     *
     * @param array<int, string> $values
     */
    private static function inList(array $values): string
    {
        return implode(', ', array_map(static fn (string $value): string => "'" . $value . "'", $values));
    }

    private static function isPersonKind(string $kind): bool
    {
        return self::KIND_ASSIGNMENT === $kind || self::KIND_REDEMPTION === $kind;
    }

    private static function tableOf(string $kind): string
    {
        return Schema::table(match ($kind) {
            self::KIND_GUEST => Schema::GUESTS,
            self::KIND_ASSIGNMENT => Schema::ASSIGNMENTS,
            self::KIND_REDEMPTION => Schema::REDEMPTIONS,
            default => Schema::TICKET_BUYERS,
        });
    }
}
