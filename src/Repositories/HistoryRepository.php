<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\History;

/**
 * All reads and writes of the history table (Support\History). Times are
 * site time, like the assignments and tasks they describe.
 */
final class HistoryRepository
{
    /** The columns a row is written with. */
    private const COLUMNS = [
        'created_at', 'actor_kind', 'actor_person_id', 'actor_wp_user_id', 'channel', 'kind',
        'person_id', 'other_person_id', 'task_id', 'event_date', 'details', 'source',
    ];

    private function table(): string
    {
        return Schema::table(Schema::HISTORY);
    }

    /**
     * Adds one row and returns its id, 0 when nothing was written.
     *
     * @param array<string, mixed> $row
     */
    public function insert(array $row): int
    {
        global $wpdb;

        $data = [];

        foreach (self::COLUMNS as $column) {
            if (array_key_exists($column, $row)) {
                $data[$column] = $row[$column];
            }
        }

        $ok = $wpdb->insert($this->table(), $data); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        return false === $ok ? 0 : (int) $wpdb->insert_id;
    }

    /** Deletes the rows created before $cutoff (site time). Returns how many. */
    public function deleteBefore(string $cutoff): int
    {
        global $wpdb;

        $deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare("DELETE FROM {$this->table()} WHERE created_at < %s", $cutoff)
        );

        return is_int($deleted) ? $deleted : 0;
    }

    /**
     * Empties an erased person's ids in every row, wherever they appear (the
     * person acted on, the other person, the one who acted), so their rows
     * stay as anonymous actions. Returns how many rows changed.
     */
    public function forgetPerson(int $personId): int
    {
        global $wpdb;

        if ($personId <= 0) {
            return 0;
        }

        $changed = 0;

        foreach (['person_id', 'other_person_id', 'actor_person_id'] as $column) {
            // The column is one of three fixed names.
            $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prepare("UPDATE {$this->table()} SET {$column} = NULL WHERE {$column} = %d", $personId)
            );
            $changed += is_int($result) ? $result : 0;
        }

        return $changed;
    }

    /**
     * One page of rows, newest first, with the names they need. $filter may
     * hold person (a person id: as the person acted on, the other person or
     * the one who acted), date (the event night, Y-m-d), kinds (a list) and
     * since (site time, from).
     *
     * @param array{person?: int, date?: string, kinds?: list<string>, since?: string} $filter
     * @return list<array<string, mixed>>
     */
    public function page(array $filter, int $limit, int $offset = 0): array
    {
        global $wpdb;

        [$where, $args] = $this->where($filter);
        $people = Schema::table(Schema::PEOPLE);
        $tasks = Schema::table(Schema::TASKS);

        $sql = "SELECT h.*, p.display_name AS person_name, o.display_name AS other_name,
                a.display_name AS actor_name, t.role_slug AS task_role_slug, t.task_date AS task_task_date,
                t.starts_at AS task_starts_at, t.ends_at AS task_ends_at, t.event_label AS task_event_label,
                t.event_post_id AS task_event_post_id
            FROM {$this->table()} AS h
            LEFT JOIN {$people} AS p ON p.id = h.person_id
            LEFT JOIN {$people} AS o ON o.id = h.other_person_id
            LEFT JOIN {$people} AS a ON a.id = h.actor_person_id
            LEFT JOIN {$tasks} AS t ON t.id = h.task_id
            WHERE {$where}
            ORDER BY h.created_at DESC, h.id DESC
            LIMIT %d OFFSET %d";

        // Built from table-name constants and placeholders; every value is bound.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...[...$args, max(1, $limit), max(0, $offset)]), ARRAY_A);

        return array_values(is_array($rows) ? $rows : []);
    }

    /**
     * How many rows page() pages through.
     *
     * @param array{person?: int, date?: string, kinds?: list<string>, since?: string} $filter
     */
    public function count(array $filter): int
    {
        global $wpdb;

        [$where, $args] = $this->where($filter);
        $sql = "SELECT COUNT(*) FROM {$this->table()} AS h WHERE {$where}";

        if ([] === $args) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            return (int) $wpdb->get_var($sql);
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$args));
    }

    /**
     * @param array{person?: int, date?: string, kinds?: list<string>, since?: string} $filter
     * @return array{0: string, 1: list<mixed>}
     */
    private function where(array $filter): array
    {
        $where = ['1=1'];
        $args = [];
        $person = (int) ($filter['person'] ?? 0);

        if ($person > 0) {
            $where[] = '(h.person_id = %d OR h.other_person_id = %d OR h.actor_person_id = %d)';
            array_push($args, $person, $person, $person);
        }

        $date = (string) ($filter['date'] ?? '');

        if (1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $where[] = 'h.event_date = %s';
            $args[] = $date;
        }

        $kinds = array_values(array_filter(
            array_map('strval', (array) ($filter['kinds'] ?? [])),
            static fn (string $kind): bool => '' !== $kind
        ));

        if ([] !== $kinds) {
            $where[] = 'h.kind IN (' . implode(',', array_fill(0, count($kinds), '%s')) . ')';
            array_push($args, ...$kinds);
        }

        $since = (string) ($filter['since'] ?? '');

        if ('' !== $since) {
            $where[] = 'h.created_at >= %s';
            $args[] = $since;
        }

        return [implode(' AND ', $where), $args];
    }

    /**
     * What the recorder needs of an assignment: its person, task and night.
     *
     * @return array{person_id: int, task_id: int, event_date: ?string}|null
     */
    public function assignment(int $id): ?array
    {
        global $wpdb;

        $tasks = Schema::table(Schema::TASKS);
        $assignments = Schema::table(Schema::ASSIGNMENTS);
        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT a.person_id, a.task_id, t.task_date FROM {$assignments} AS a
                LEFT JOIN {$tasks} AS t ON t.id = a.task_id WHERE a.id = %d",
                $id
            ),
            ARRAY_A
        );

        return is_array($row)
            ? [
                'person_id' => (int) $row['person_id'],
                'task_id' => (int) $row['task_id'],
                'event_date' => null === ($row['task_date'] ?? null) ? null : (string) $row['task_date'],
            ]
            : null;
    }

    /**
     * A task row as stored, for the night it is filed under and to tell
     * which fields an edit changes.
     *
     * @return array<string, mixed>|null
     */
    public function task(int $id): ?array
    {
        global $wpdb;

        $tasks = Schema::table(Schema::TASKS);
        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare("SELECT * FROM {$tasks} WHERE id = %d", $id),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * A person's organizer flag and roles as stored, to tell what a change
     * to them changes.
     *
     * @return array{is_organizer: bool, role_slugs: list<string>}|null
     */
    public function personRoles(int $id): ?array
    {
        global $wpdb;

        $people = Schema::table(Schema::PEOPLE);
        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT is_organizer, role_slugs FROM {$people} WHERE id = %d",
                $id
            ),
            ARRAY_A
        );

        if (! is_array($row)) {
            return null;
        }

        return [
            'is_organizer' => ! empty($row['is_organizer']),
            'role_slugs' => self::slugs((string) ($row['role_slugs'] ?? '')),
        ];
    }

    /**
     * The person, night and redemption of a credit ticket.
     *
     * @return array{person_id: int, event_date: ?string}|null
     */
    public function redemption(int $id): ?array
    {
        global $wpdb;

        $redemptions = Schema::table(Schema::REDEMPTIONS);
        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT person_id, redeemed_for FROM {$redemptions} WHERE id = %d",
                $id
            ),
            ARRAY_A
        );

        return is_array($row)
            ? [
                'person_id' => (int) $row['person_id'],
                'event_date' => null === ($row['redeemed_for'] ?? null) ? null : (string) $row['redeemed_for'],
            ]
            : null;
    }

    /**
     * The giver, the receiver (a member, or null) and the night of a ticket
     * given away. Never the receiver's name or email.
     *
     * @return array{person_id: int, other_person_id: ?int, event_date: string}|null
     */
    public function transfer(int $id): ?array
    {
        global $wpdb;

        $transfers = Schema::table(Schema::TICKET_TRANSFERS);
        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT giver_person_id, receiver_person_id, event_date FROM {$transfers} WHERE id = %d",
                $id
            ),
            ARRAY_A
        );

        return is_array($row)
            ? [
                'person_id' => (int) $row['giver_person_id'],
                'other_person_id' => empty($row['receiver_person_id']) ? null : (int) $row['receiver_person_id'],
                'event_date' => (string) $row['event_date'],
            ]
            : null;
    }

    /** Whether a person is an organizer now. */
    public function isOrganizer(int $personId): bool
    {
        $roles = $this->personRoles($personId);

        return null !== $roles && $roles['is_organizer'];
    }

    /** The person linked to a Telegram user, or null. */
    public function personForTelegram(int $telegramUserId): ?int
    {
        global $wpdb;

        $people = Schema::table(Schema::PEOPLE);
        $id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT id FROM {$people} WHERE telegram_user_id = %d LIMIT 1",
                $telegramUserId
            )
        );

        return null === $id ? null : (int) $id;
    }

    /**
     * Everyone with a name, for finding the people a question names: id and
     * display name only.
     *
     * @return list<array{id: int, name: string}>
     */
    public function names(): array
    {
        global $wpdb;

        $people = Schema::table(Schema::PEOPLE);
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT id, display_name FROM {$people} WHERE display_name <> '' ORDER BY display_name ASC",
            ARRAY_A
        );
        $names = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $names[] = ['id' => (int) $row['id'], 'name' => (string) $row['display_name']];
        }

        return $names;
    }

    /**
     * Schema 37: the history from before the table existed, made once from
     * the columns that say who did something and when, for the last
     * KEEP_DAYS days ($since). Every row is marked source 'backfill'. The
     * backfilled rows are deleted first, so running it again makes the same
     * rows, not a second copy. Returns how many rows were added.
     *
     * What the columns hold, and so what is recorded:
     * - assignments: the sign-up (signed_up_at; who did it is not stored)
     *   and the latest status change (status_changed_at, changed_by), as a
     *   cancellation, late cancellation, taken off, no-show, replaced or
     *   marked. An earlier change that a later one overwrote is lost, and a
     *   re-join after a cancellation moved signed_up_at, so only the re-join
     *   is there.
     * - tasks: created_at and deleted_at; past edits left no trace.
     * - check-ins (who checked in whom), credit grants (who gave them),
     *   redemptions and their cancellation (one given away is the ticket
     *   transfer instead), ticket transfers (offered, given, ended), people
     *   (added).
     */
    public function backfill(string $since): int
    {
        global $wpdb;

        $h = $this->table();
        $assignments = Schema::table(Schema::ASSIGNMENTS);
        $tasks = Schema::table(Schema::TASKS);
        $checkins = Schema::table(Schema::CHECKINS);
        $grants = Schema::table(Schema::CREDIT_GRANTS);
        $redemptions = Schema::table(Schema::REDEMPTIONS);
        $transfers = Schema::table(Schema::TICKET_TRANSFERS);
        $people = Schema::table(Schema::PEOPLE);
        $source = History::SOURCE_BACKFILL;
        $columns = 'created_at, actor_kind, actor_person_id, actor_wp_user_id, channel, kind,'
            . ' person_id, other_person_id, task_id, event_date, details, source';

        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare("DELETE FROM {$h} WHERE source = %s", $source)
        );

        $marked = [AssignmentStatus::ARRIVED, AssignmentStatus::ARRIVED_LATE, AssignmentStatus::COMPLETED];
        $actorKind = "CASE a.changed_by_kind WHEN 'person' THEN IF(a.changed_by = a.person_id, 'person', 'organizer')"
            . " WHEN 'wp_user' THEN 'wp_user' ELSE '' END";

        $statements = [
            // Sign-ups, still on the task or not.
            "INSERT INTO {$h} ({$columns})
                SELECT a.signed_up_at, '', NULL, NULL, '', 'signed_up', a.person_id, NULL, a.task_id,
                    t.task_date, '', '{$source}'
                FROM {$assignments} AS a INNER JOIN {$tasks} AS t ON t.id = a.task_id
                WHERE a.signed_up_at >= %s",
            // The latest status change of each sign-up. A cancellation by
            // someone else is "taken off"; a cancellation at the same moment
            // as the task's deletion came from deleting the task.
            "INSERT INTO {$h} ({$columns})
                SELECT a.status_changed_at, {$actorKind},
                    IF(a.changed_by_kind = 'person', a.changed_by, NULL),
                    IF(a.changed_by_kind = 'wp_user', a.changed_by, NULL), '',
                    CASE
                        WHEN a.status = 'cancelled' AND t.deleted_at IS NOT NULL
                            AND a.status_changed_at = t.deleted_at THEN 'taken_off'
                        WHEN a.status = 'cancelled' AND a.changed_by IS NOT NULL
                            AND NOT (a.changed_by_kind = 'person' AND a.changed_by = a.person_id) THEN 'taken_off'
                        WHEN a.status IN ('" . implode("','", $marked) . "') THEN 'marked'
                        ELSE a.status
                    END,
                    a.person_id,
                    IF(a.status = 'replaced' AND a.changed_by_kind = 'person', a.changed_by, NULL),
                    a.task_id, t.task_date,
                    CASE
                        WHEN a.status IN ('" . implode("','", $marked) . "')
                            THEN CONCAT('{\"status\":\"', a.status, '\"}')
                        WHEN a.status = 'cancelled' AND t.deleted_at IS NOT NULL
                            AND a.status_changed_at = t.deleted_at THEN '{\"task_deleted\":1}'
                        ELSE ''
                    END,
                    '{$source}'
                FROM {$assignments} AS a INNER JOIN {$tasks} AS t ON t.id = a.task_id
                WHERE a.status <> 'signed_up' AND a.status_changed_at IS NOT NULL AND a.status_changed_at >= %s",
            // Tasks added.
            "INSERT INTO {$h} ({$columns})
                SELECT t.created_at, '', NULL, NULL, '', 'task_created', NULL, NULL, t.id, t.task_date, '',
                    '{$source}'
                FROM {$tasks} AS t WHERE t.created_at >= %s",
            // Tasks deleted, with how many places the deletion cancelled.
            "INSERT INTO {$h} ({$columns})
                SELECT t.deleted_at, '', NULL, NULL, '', 'task_deleted', NULL, NULL, t.id, t.task_date,
                    CONCAT('{\"places\":', (SELECT COUNT(*) FROM {$assignments} AS a
                        WHERE a.task_id = t.id AND a.status = 'cancelled' AND a.status_changed_at = t.deleted_at),
                        '}'),
                    '{$source}'
                FROM {$tasks} AS t WHERE t.deleted_at IS NOT NULL AND t.deleted_at >= %s",
            // Check-ins: a person for crew and credit, a list for guests and buyers.
            "INSERT INTO {$h} ({$columns})
                SELECT c.checked_in_at, IF(c.checked_by IS NULL OR c.checked_by = 0, '', 'person'),
                    NULLIF(c.checked_by, 0), NULL, '', 'checked_in',
                    IF(c.kind IN ('crew', 'credit'), c.ref_id, NULL), NULL, NULL, c.event_date,
                    CONCAT('{\"list\":\"', c.kind, '\"}'), '{$source}'
                FROM {$checkins} AS c WHERE c.checked_in_at >= %s",
            // Credits given (not the zero-credit notes).
            "INSERT INTO {$h} ({$columns})
                SELECT g.granted_at,
                    CASE WHEN g.granted_by_person_id IS NOT NULL THEN 'organizer'
                        WHEN g.granted_by IS NOT NULL THEN 'wp_user' ELSE 'system' END,
                    g.granted_by_person_id, IF(g.granted_by_person_id IS NULL, g.granted_by, NULL), '',
                    'credit_granted', g.person_id, NULL, NULL, NULL,
                    CONCAT('{\"credits\":', g.credits, IF(g.source = 'extra_task', ',\"extra_task\":1', ''), '}'),
                    '{$source}'
                FROM {$grants} AS g WHERE g.credits > 0 AND g.granted_at >= %s",
            // Credits used for a free-entry ticket.
            "INSERT INTO {$h} ({$columns})
                SELECT r.redeemed_at, '', NULL, NULL, '', 'credit_redeemed', r.person_id, NULL, NULL,
                    r.redeemed_for, '', '{$source}'
                FROM {$redemptions} AS r WHERE r.redeemed_at >= %s",
            // Free-entry tickets cancelled (not the ones given away).
            "INSERT INTO {$h} ({$columns})
                SELECT r.cancelled_at, '', NULL, NULL, '', 'redemption_cancelled', r.person_id, NULL, NULL,
                    r.redeemed_for, '', '{$source}'
                FROM {$redemptions} AS r
                WHERE r.cancelled_at IS NOT NULL AND r.transferred_to IS NULL AND r.cancelled_at >= %s",
            // Tickets offered: every transfer starts as an offer, unless it
            // moved at once (issued at creation).
            "INSERT INTO {$h} ({$columns})
                SELECT x.created_at, 'person', x.giver_person_id, NULL, '', 'ticket_offered',
                    x.giver_person_id, x.receiver_person_id, NULL, x.event_date, '', '{$source}'
                FROM {$transfers} AS x
                WHERE x.created_at >= %s AND (x.issued_at IS NULL OR x.issued_at <> x.created_at)",
            // Tickets that moved.
            "INSERT INTO {$h} ({$columns})
                SELECT x.issued_at, '', NULL, NULL, '', 'ticket_given', x.giver_person_id,
                    x.receiver_person_id, NULL, x.event_date, '', '{$source}'
                FROM {$transfers} AS x WHERE x.issued_at IS NOT NULL AND x.issued_at >= %s",
            // Offers withdrawn or lapsed, and moved tickets cancelled.
            "INSERT INTO {$h} ({$columns})
                SELECT x.ended_at, '', NULL, NULL, '', 'ticket_offer_ended', x.giver_person_id,
                    x.receiver_person_id, NULL, x.event_date, CONCAT('{\"status\":\"', x.status, '\"}'),
                    '{$source}'
                FROM {$transfers} AS x WHERE x.ended_at IS NOT NULL AND x.ended_at >= %s",
            // People added (not the anonymised ones).
            "INSERT INTO {$h} ({$columns})
                SELECT p.created_at, '', NULL, NULL, '', 'person_added', p.id, NULL, NULL, NULL, '', '{$source}'
                FROM {$people} AS p WHERE p.anonymized_at IS NULL AND p.created_at >= %s",
        ];

        $added = 0;

        foreach ($statements as $sql) {
            // Table-name constants and fixed words only; the date is bound.
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $result = $wpdb->query($wpdb->prepare($sql, $since));
            $added += is_int($result) ? $result : 0;
        }

        return $added;
    }

    /**
     * @return list<string>
     */
    public static function slugs(string $column): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $column)), 'strlen'));
    }
}
