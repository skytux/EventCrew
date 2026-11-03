<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;

/**
 * All reads and writes of the bot's command log (Telegram\BotLog): one row per
 * command, button tap or helper action, with no message text in it. Times are
 * stored in UTC; the callers turn a day in the site's time zone into a UTC
 * range before asking.
 */
final class BotLogRepository
{
    private function table(): string
    {
        return Schema::table(Schema::BOT_LOG);
    }

    /**
     * Adds one row and returns its id, 0 when nothing was written. The person
     * is found in the same statement from the Telegram user id, so recording
     * costs one query; NULL when that Telegram user is not linked.
     *
     * @param array{created_at: string, telegram_user_id: int, chat_kind: string, command: string,
     *     source: string, button_kind: ?string, date_arg: ?string, outcome: string} $row
     */
    public function insert(array $row): int
    {
        global $wpdb;

        $people = Schema::table(Schema::PEOPLE);

        // NULL cannot go through a placeholder, so the two optional values
        // are written as NULL or as a placeholder.
        $buttonKind = null === $row['button_kind'] ? 'NULL' : '%s';
        $dateArg = null === $row['date_arg'] ? 'NULL' : '%s';
        $args = [
            $row['created_at'],
            $row['telegram_user_id'],
            $row['telegram_user_id'],
            $row['chat_kind'],
            $row['command'],
            $row['source'],
        ];

        if (null !== $row['button_kind']) {
            $args[] = $row['button_kind'];
        }

        if (null !== $row['date_arg']) {
            $args[] = $row['date_arg'];
        }

        $args[] = $row['outcome'];

        $written = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "INSERT INTO {$this->table()}"
                . ' (created_at, person_id, telegram_user_id, chat_kind, command, source,'
                . ' button_kind, date_arg, outcome)'
                . " VALUES (%s, (SELECT id FROM {$people} WHERE telegram_user_id = %d LIMIT 1), NULLIF(%d, 0),"
                . " %s, %s, %s, {$buttonKind}, {$dateArg}, %s)",
                $args
            )
        );

        return is_int($written) && $written > 0 ? (int) $wpdb->insert_id : 0;
    }

    /** Changes the outcome of one row. */
    public function setOutcome(int $id, string $outcome): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update($this->table(), ['outcome' => $outcome], ['id' => $id]);
    }

    /** Deletes every row written before $cutoffUtc. Returns how many. */
    public function deleteBefore(string $cutoffUtc): int
    {
        global $wpdb;

        return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare("DELETE FROM {$this->table()} WHERE created_at < %s", $cutoffUtc)
        );
    }

    /**
     * The rows from $fromUtc up to (not including) $toUtc, newest first, with
     * the person's display name (NULL when not linked).
     *
     * @return list<array<string, mixed>>
     */
    public function between(string $fromUtc, string $toUtc, int $limit, int $offset): array
    {
        global $wpdb;

        $people = Schema::table(Schema::PEOPLE);

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT l.*, p.display_name FROM {$this->table()} l"
                . " LEFT JOIN {$people} p ON p.id = l.person_id"
                . ' WHERE l.created_at >= %s AND l.created_at < %s'
                . ' ORDER BY l.created_at DESC, l.id DESC LIMIT %d OFFSET %d',
                $fromUtc,
                $toUtc,
                $limit,
                $offset
            ),
            ARRAY_A
        );

        return is_array($rows) ? array_values($rows) : [];
    }

    /** How many rows fall from $fromUtc up to (not including) $toUtc. */
    public function countBetween(string $fromUtc, string $toUtc): int
    {
        global $wpdb;

        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table()} WHERE created_at >= %s AND created_at < %s",
                $fromUtc,
                $toUtc
            )
        );
    }

    /**
     * How many rows each person has in the range, most first. A Telegram user
     * who is not linked is one entry of their own (person_id NULL). first_id
     * is the person's earliest row in the range, which is what numbers the
     * unlinked users in the order they first appeared.
     *
     * @return list<array<string, mixed>>
     */
    public function countsPerPerson(string $fromUtc, string $toUtc): array
    {
        global $wpdb;

        $people = Schema::table(Schema::PEOPLE);

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT l.person_id, CASE WHEN l.person_id IS NULL THEN l.telegram_user_id END AS unlinked_id,'
                . ' MAX(p.display_name) AS display_name, COUNT(*) AS commands, MIN(l.id) AS first_id'
                . " FROM {$this->table()} l"
                . " LEFT JOIN {$people} p ON p.id = l.person_id"
                . ' WHERE l.created_at >= %s AND l.created_at < %s'
                . ' GROUP BY l.person_id, unlinked_id'
                . ' ORDER BY commands DESC, first_id ASC',
                $fromUtc,
                $toUtc
            ),
            ARRAY_A
        );

        return is_array($rows) ? array_values($rows) : [];
    }
}
