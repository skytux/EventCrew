<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;
use EventCrew\Support\History;

/**
 * The reads behind the organizers' monthly report (Support\MonthlyReport):
 * counts and person ids, never an address; only adminActions() reads names,
 * for the "Done for others" list. Each method is one query over a time range
 * the caller gives: $from inclusive, $to exclusive,
 * as Y-m-d H:i:s (site time, except the bot log, which keeps UTC) or Y-m-d
 * for the event-day ranges.
 */
final class MonthlyReportRepository
{
    /** People rows created in the range. Erased people are gone, so not counted. */
    public function newPeople(string $from, string $to): int
    {
        global $wpdb;

        $people = Schema::table(Schema::PEOPLE);

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$people} WHERE created_at >= %s AND created_at < %s",
                $from,
                $to
            )
        );
    }

    /**
     * The people (linked to Telegram) with a row in the bot's command log in
     * the UTC range: a command, a button or a helper action.
     *
     * @return list<int>
     */
    public function botPeople(string $fromUtc, string $toUtc): array
    {
        global $wpdb;

        $log = Schema::table(Schema::BOT_LOG);

        return self::ids($wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT person_id FROM {$log}
                WHERE person_id IS NOT NULL AND created_at >= %s AND created_at < %s",
                $fromUtc,
                $toUtc
            )
        ));
    }

    /**
     * The people who did something themselves on $channel ('web' or 'bot')
     * in the range, from the history of actions: a sign-up, a cancellation,
     * a ticket given away...
     *
     * @return list<int>
     */
    public function actingPeople(string $channel, string $from, string $to): array
    {
        global $wpdb;

        $history = Schema::table(Schema::HISTORY);

        return self::ids($wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT actor_person_id FROM {$history}
                WHERE actor_person_id IS NOT NULL AND channel = %s AND created_at >= %s AND created_at < %s",
                $channel,
                $from,
                $to
            )
        ));
    }

    /**
     * History rows of the given kinds in the range, per channel (bot, web,
     * admin, system): where each action was done.
     *
     * @param list<string> $kinds
     * @return array<string, int> channel => rows
     */
    public function historyByChannel(array $kinds, string $from, string $to): array
    {
        global $wpdb;

        if ([] === $kinds) {
            return [];
        }

        $history = Schema::table(Schema::HISTORY);
        $in = implode(', ', array_fill(0, count($kinds), '%s'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is a list of %s placeholders.
                "SELECT channel, COUNT(*) AS n FROM {$history}
                WHERE kind IN ({$in}) AND created_at >= %s AND created_at < %s
                GROUP BY channel",
                array_merge($kinds, [$from, $to])
            ),
            ARRAY_A
        );

        $channels = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $channels[(string) $row['channel']] = (int) $row['n'];
        }

        return $channels;
    }

    /**
     * Per person, how many sign-ups they have on tasks dated in the day range
     * (Y-m-d, $to exclusive), and how many of those have one of $done: the
     * rows of the funnel and of the done and no-show counts. Deleted tasks
     * are left out.
     *
     * @param list<string> $statuses The statuses counted in the second figure.
     * @return array<int, array{all: int, matched: int}> person id => counts
     */
    public function taskPeople(array $statuses, string $from, string $to): array
    {
        global $wpdb;

        $assignments = Schema::table(Schema::ASSIGNMENTS);
        $tasks = Schema::table(Schema::TASKS);
        $in = [] === $statuses ? "''" : implode(', ', array_fill(0, count($statuses), '%s'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is a list of %s placeholders.
                "SELECT a.person_id, COUNT(*) AS n_all, SUM(a.status IN ({$in})) AS n_matched
                FROM {$assignments} a INNER JOIN {$tasks} t ON t.id = a.task_id
                WHERE t.deleted_at IS NULL AND t.task_date >= %s AND t.task_date < %s
                GROUP BY a.person_id",
                array_merge($statuses, [$from, $to])
            ),
            ARRAY_A
        );

        $people = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $people[(int) $row['person_id']] = ['all' => (int) $row['n_all'], 'matched' => (int) $row['n_matched']];
        }

        return $people;
    }

    /**
     * Sign-ups with one of $statuses on tasks dated in the day range.
     *
     * @param list<string> $statuses
     */
    public function assignmentCount(array $statuses, string $from, string $to): int
    {
        global $wpdb;

        if ([] === $statuses) {
            return 0;
        }

        $assignments = Schema::table(Schema::ASSIGNMENTS);
        $tasks = Schema::table(Schema::TASKS);
        $in = implode(', ', array_fill(0, count($statuses), '%s'));

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is a list of %s placeholders.
                "SELECT COUNT(*) FROM {$assignments} a INNER JOIN {$tasks} t ON t.id = a.task_id
                WHERE t.deleted_at IS NULL AND a.status IN ({$in}) AND t.task_date >= %s AND t.task_date < %s",
                array_merge($statuses, [$from, $to])
            )
        );
    }

    /**
     * Door check-ins of the given kinds on nights in the day range.
     *
     * @param list<string> $kinds
     */
    public function checkinCount(array $kinds, string $from, string $to): int
    {
        global $wpdb;

        if ([] === $kinds) {
            return 0;
        }

        $checkins = Schema::table(Schema::CHECKINS);
        $in = implode(', ', array_fill(0, count($kinds), '%s'));

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is a list of %s placeholders.
                "SELECT COUNT(*) FROM {$checkins} WHERE kind IN ({$in}) AND event_date >= %s AND event_date < %s",
                array_merge($kinds, [$from, $to])
            )
        );
    }

    /**
     * Ticket buyer rows per night in the day range, oldest night first. The
     * rows stay after the 60-day purge blanks their names, so old nights
     * still count.
     *
     * @return array<string, int> Y-m-d => buyers
     */
    public function buyersPerNight(string $from, string $to): array
    {
        global $wpdb;

        $buyers = Schema::table(Schema::TICKET_BUYERS);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT event_date, COUNT(*) AS n FROM {$buyers}
                WHERE event_date >= %s AND event_date < %s
                GROUP BY event_date ORDER BY event_date ASC",
                $from,
                $to
            ),
            ARRAY_A
        );

        $nights = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $nights[(string) $row['event_date']] = (int) $row['n'];
        }

        return $nights;
    }

    /**
     * The page views and visitors the Burst Statistics plugin counted from
     * Unix time $from (inclusive) to $to (exclusive), read only from its own
     * table ({prefix}burst_statistics: one row per page view, `time` a Unix
     * time, `uid_id` the visitor, 0 when unknown), as Burst counts them.
     * Null when Burst's table is not there.
     *
     * @return array{views: int, visitors: int}|null
     */
    public function burstMonth(int $from, int $to): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'burst_statistics';

        if ($table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)))) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS views, COUNT(DISTINCT NULLIF(uid_id, 0)) AS visitors FROM {$table}
                WHERE time >= %d AND time < %d",
                $from,
                $to
            ),
            ARRAY_A
        );

        return is_array($row) ? ['views' => (int) $row['views'], 'visitors' => (int) $row['visitors']] : null;
    }

    /**
     * The history rows in the range where someone acted for somebody else:
     * an organizer acting for another person (the bot's Act as included) or
     * a WordPress user in wp-admin, settings and task list changes included.
     * Not anyone's own actions, not the system's, not rows made from older
     * data. Oldest first, with the names Support\HistoryText needs: the
     * person acted on, the other person, the actor, and the task's role and
     * time. Never an email address.
     *
     * @return list<array<string, mixed>>
     */
    public function adminActions(string $from, string $to): array
    {
        global $wpdb;

        $history = Schema::table(Schema::HISTORY);
        $people = Schema::table(Schema::PEOPLE);
        $tasks = Schema::table(Schema::TASKS);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT h.id, h.created_at, h.actor_kind, h.actor_person_id, h.actor_wp_user_id, h.channel, h.kind,
                    h.person_id, h.other_person_id, h.task_id, h.event_date, h.details,
                    p.display_name AS person_name, o.display_name AS other_name, a.display_name AS actor_name,
                    t.role_slug AS task_role_slug, t.starts_at AS task_starts_at
                FROM {$history} AS h
                LEFT JOIN {$people} AS p ON p.id = h.person_id
                LEFT JOIN {$people} AS o ON o.id = h.other_person_id
                LEFT JOIN {$people} AS a ON a.id = h.actor_person_id
                LEFT JOIN {$tasks} AS t ON t.id = h.task_id
                WHERE h.actor_kind IN (%s, %s) AND h.source <> %s
                    AND h.created_at >= %s AND h.created_at < %s
                ORDER BY h.created_at ASC, h.id ASC",
                History::ACTOR_ORGANIZER,
                History::ACTOR_WP_USER,
                History::SOURCE_BACKFILL,
                $from,
                $to
            ),
            ARRAY_A
        );

        return array_values(is_array($rows) ? $rows : []);
    }

    /**
     * The role_notices records (JSON, as stored) of the people with a Crew
     * app entry in them (AppInstallPrompt's "app:" keys), for AppStats.
     * Only that column: no names, no addresses.
     *
     * @return list<string>
     */
    public function appNotices(): array
    {
        global $wpdb;

        $people = Schema::table(Schema::PEOPLE);
        $column = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT role_notices FROM {$people} WHERE role_notices LIKE %s",
                '%' . $wpdb->esc_like('"app:') . '%'
            )
        );

        return array_values(array_map('strval', is_array($column) ? $column : []));
    }

    /**
     * The devices with app notifications turned on: all of them, and those
     * a notification reached (last_ok_at) at or after $since (site time).
     *
     * @return array{recent: int, total: int}
     */
    public function pushDevices(string $since): array
    {
        global $wpdb;

        $table = Schema::table(Schema::PUSH_SUBSCRIPTIONS);
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS total, COALESCE(SUM(last_ok_at >= %s), 0) AS recent FROM {$table}",
                $since
            ),
            ARRAY_A
        );

        return is_array($row)
            ? ['recent' => (int) ($row['recent'] ?? 0), 'total' => (int) ($row['total'] ?? 0)]
            : ['recent' => 0, 'total' => 0];
    }

    /**
     * @param mixed $column
     * @return list<int>
     */
    private static function ids(mixed $column): array
    {
        return array_values(array_filter(
            array_map('intval', is_array($column) ? $column : []),
            static fn (int $id): bool => $id > 0
        ));
    }
}
