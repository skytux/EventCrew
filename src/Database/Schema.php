<?php

declare(strict_types=1);

namespace EventCrew\Database;

use EventCrew\Models\Person;
use EventCrew\Repositories\HistoryRepository;
use EventCrew\Repositories\NewsletterHistoryRepository;
use EventCrew\Support\History;
use EventCrew\Support\NotificationPreferences;

/**
 * Owns every EventCrew table definition and the migration that applies them.
 *
 * Static rather than a container service because it has to be callable from
 * the activation hook, which fires before the kernel has wired anything up.
 */
final class Schema
{
    /**
     * Bump whenever any CREATE TABLE below changes. The stored value in
     * EventCrew's options is compared against this on every request, so an
     * un-bumped version means an added column silently never appears.
     */
    public const DB_VERSION = '39';

    public const VERSION_OPTION = 'eventcrew_db_version';

    /**
     * The day (Y-m-d, site time) the newsletter history began to be recorded
     * (schema 30). Written once by the migration that adds the two history
     * tables; the newsletter page says the history starts on that day.
     */
    public const NEWSLETTER_HISTORY_SINCE_OPTION = 'eventcrew_newsletter_history_since';

    /** The first night the credit for extra tasks counts (ExtraTaskCredits). */
    public const EXTRA_TASK_CREDIT_SINCE_OPTION = 'eventcrew_extra_task_credit_since';

    public const PEOPLE = 'eventcrew_people';
    public const TASKS = 'eventcrew_tasks';
    public const ASSIGNMENTS = 'eventcrew_assignments';
    public const REDEMPTIONS = 'eventcrew_redemptions';
    public const CREDIT_GRANTS = 'eventcrew_credit_grants';
    public const AUTH_TOKENS = 'eventcrew_auth_tokens';
    public const NOTIFICATIONS = 'eventcrew_notifications';
    public const GUESTS = 'eventcrew_guests';
    public const CHECKINS = 'eventcrew_checkins';
    public const TICKET_BUYERS = 'eventcrew_ticket_buyers';
    public const NEWSLETTER_SUBSCRIBERS = 'eventcrew_newsletter_subscribers';
    public const TAKEOVERS = 'eventcrew_takeovers';
    public const BOT_LOG = 'eventcrew_bot_log';
    public const NEWSLETTER_CHANGES = 'eventcrew_newsletter_changes';
    public const NEWSLETTER_SENDS = 'eventcrew_newsletter_sends';
    public const TICKET_TRANSFERS = 'eventcrew_ticket_transfers';
    public const DOOR_NOTES = 'eventcrew_door_notes';
    public const HISTORY = 'eventcrew_history';
    public const PUSH_SUBSCRIPTIONS = 'eventcrew_push_subscriptions';
    public const GROUP_MESSAGES = 'eventcrew_group_messages';

    /**
     * Set by the schema 37 update when it has made the history from older
     * data (HistoryRepository::backfill()). add_option() is the claim: of two
     * requests running the update at once, only one adds it and backfills.
     */
    public const HISTORY_BACKFILLED_OPTION = 'eventcrew_history_backfilled';

    /**
     * @return array<int, string>
     */
    public static function tableNames(): array
    {
        return [
            self::PEOPLE,
            self::TASKS,
            self::ASSIGNMENTS,
            self::REDEMPTIONS,
            self::CREDIT_GRANTS,
            self::AUTH_TOKENS,
            self::NOTIFICATIONS,
            self::GUESTS,
            self::CHECKINS,
            self::TICKET_BUYERS,
            self::NEWSLETTER_SUBSCRIBERS,
            self::TAKEOVERS,
            self::BOT_LOG,
            self::NEWSLETTER_CHANGES,
            self::NEWSLETTER_SENDS,
            self::TICKET_TRANSFERS,
            self::DOOR_NOTES,
            self::HISTORY,
            self::PUSH_SUBSCRIPTIONS,
            self::GROUP_MESSAGES,
        ];
    }

    /**
     * Prefixed, site-specific name of one table.
     *
     * Everything that builds SQL goes through here rather than interpolating
     * $wpdb->prefix itself, so a multisite install or a renamed table is a
     * single-place change.
     */
    public static function table(string $name): string
    {
        global $wpdb;

        return $wpdb->prefix . $name;
    }

    /**
     * Runs on activation and, via maybeMigrate(), on any request where the
     * stored version is behind. dbDelta is idempotent, so running it against
     * an already-current schema is a no-op rather than an error.
     */
    public static function migrate(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $wpdb->get_charset_collate();

        foreach (self::statements($charsetCollate) as $statement) {
            dbDelta($statement);
        }

        self::ensureInnoDb();

        // Schema 20: Crew leader is an ordinary role. Copies can_lead into
        // role_slugs; it has to run before dropRetiredColumns() drops can_lead.
        self::moveCanLeadToRole();

        self::dropRetiredColumns();

        // Schema 30: the newsletter history starts on the day its tables
        // appear. add_option() leaves an existing value alone.
        add_option(self::NEWSLETTER_HISTORY_SINCE_OPTION, (string) current_time('Y-m-d'), '', false);

        // Schema 31: the joins from before the history began.
        self::backfillNewsletterJoins((string) get_option(self::VERSION_OPTION, ''));

        // Schema 33: the people columns approval and approval_by. dbDelta adds
        // them with their default '', so everyone already in the people list
        // is OK; only a newcomer who signs in after the update waits.

        // Schema 34: the ticket transfers table and the redemptions column
        // transferred_to. dbDelta adds both; nothing to copy.

        // Schema 35: the door notes table. dbDelta adds it; nothing to copy.

        // Schema 36: the credit_grants columns source and assignment_id. The
        // credit for extra tasks on the same night counts from the day they
        // appear, never for nights before (no migration of old nights).
        add_option(self::EXTRA_TASK_CREDIT_SINCE_OPTION, (string) current_time('Y-m-d'), '', false);

        // Schema 37: the history table. dbDelta adds it; the history of the
        // last year is made once from the older tables.
        self::backfillHistory((string) get_option(self::VERSION_OPTION, ''));

        // Schema 38: the push subscriptions table (dbDelta adds it), and the
        // app switch written into every existing person's notification
        // preferences, on the most restrictive choice they already made.
        self::seedPushPrefs((string) get_option(self::VERSION_OPTION, ''));

        // Schema 39: the group messages table (the group chat on the web).
        // dbDelta adds it; nothing to copy.

        update_option(self::VERSION_OPTION, self::DB_VERSION);
    }

    /**
     * Schema 37: fills the new history table from the older tables, once, for
     * the last History::KEEP_DAYS days (HistoryRepository::backfill()).
     * Skipped on a new install (nothing stored yet, nothing to make), once the
     * stored schema is 37 or later, and when the claim option is already
     * there. Returns how many rows were added, -1 when skipped.
     */
    public static function backfillHistory(
        string $storedVersion,
        HistoryRepository $repository = new HistoryRepository()
    ): int {
        if ('' === $storedVersion || (int) $storedVersion >= 37) {
            return -1;
        }

        if (! add_option(self::HISTORY_BACKFILLED_OPTION, (string) current_time('mysql'), '', false)) {
            return -1;
        }

        return $repository->backfill(History::cutoff((string) current_time('mysql')));
    }

    /**
     * Schema 38: writes the app channel into the notification preferences of
     * every person already stored (NotificationPreferences::withPushSeeded():
     * on only where both Telegram and email are on). Skipped on a new install
     * and once the stored schema is 38 or later; a row that already has the
     * app switch is left alone, so a second run changes nothing. Written
     * straight to the table, not through PersonRepository: it is not a
     * change anyone made, so it records no history. Returns how many rows
     * were written, -1 when skipped.
     */
    public static function seedPushPrefs(string $storedVersion): int
    {
        global $wpdb;

        if ('' === $storedVersion || (int) $storedVersion >= 38) {
            return -1;
        }

        $table = self::table(self::PEOPLE);
        $prefs = new NotificationPreferences();
        $written = 0;
        $lastId = 0;

        do {
            // Table name is a constant joined to $wpdb->prefix.
            $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prepare(
                    "SELECT id, is_organizer, notify_muted, notify_prefs FROM {$table}
                     WHERE id > %d AND anonymized_at IS NULL ORDER BY id ASC LIMIT %d",
                    $lastId,
                    self::SEED_BATCH
                ),
                ARRAY_A
            );
            $rows = is_array($rows) ? $rows : [];

            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                $person = Person::fromRow($row);
                $seeded = $prefs->withPushSeeded($person);

                if ($seeded === $person->notifyPrefs) {
                    continue;
                }

                $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    $table,
                    ['notify_prefs' => wp_json_encode($seeded)],
                    ['id' => $lastId]
                );
                ++$written;
            }
        } while (self::SEED_BATCH === count($rows));

        return $written;
    }

    /** People read per query by seedPushPrefs(). */
    private const SEED_BATCH = 500;

    /**
     * A join recorded by the plugin is written right after confirmed_at, in
     * the same request, from a second current_time() call; it can be a second
     * or two later. This is how far after a confirmed_at a recorded join
     * still counts as that subscriber's join.
     */
    private const JOIN_MATCH_SECONDS = 10;

    /**
     * Schema 31: one join (a 'subscribed' row in the changes table) for every
     * subscriber row with a confirmed_at - confirmed now or unsubscribed
     * since - at its confirmed_at, unless that join is already recorded.
     * Returns how many rows were added.
     *
     * Already recorded means: a 'subscribed' row from confirmed_at up to
     * JOIN_MATCH_SECONDS later, each row used for one subscriber only. Before
     * the history began (schema 30) there are no rows, so every older join is
     * added. From then on the plugin recorded each join as it happened, so it
     * is found and not added twice. The exception is a confirmation on the
     * schema 30 update day before the update ran: it has no row, and gets one.
     * A row this function added sits exactly at confirmed_at, so a second run
     * finds every join and adds nothing; it is also skipped once the stored
     * schema is 31 or later.
     */
    private static function backfillNewsletterJoins(string $storedVersion): int
    {
        global $wpdb;

        if ('' !== $storedVersion && (int) $storedVersion >= 31) {
            return 0;
        }

        $subscribers = self::table(self::NEWSLETTER_SUBSCRIBERS);
        $changes = self::table(self::NEWSLETTER_CHANGES);

        // Table names are constants joined to $wpdb->prefix.
        $confirmed = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT confirmed_at FROM {$subscribers} WHERE confirmed_at IS NOT NULL ORDER BY confirmed_at ASC"
        );
        $recorded = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT created_at FROM {$changes} WHERE kind = %s ORDER BY created_at ASC",
                NewsletterHistoryRepository::SUBSCRIBED
            )
        );
        $missing = self::joinsToBackfill(
            array_map('strval', is_array($confirmed) ? $confirmed : []),
            array_map('strval', is_array($recorded) ? $recorded : [])
        );

        foreach ($missing as $at) {
            $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $changes,
                ['created_at' => $at, 'kind' => NewsletterHistoryRepository::SUBSCRIBED],
                ['%s', '%s']
            );
        }

        return count($missing);
    }

    /**
     * The confirmed_at times (Y-m-d H:i:s) that have no recorded join from
     * that second up to JOIN_MATCH_SECONDS later. Each recorded join answers
     * for one confirmed_at only; going through both lists oldest first and
     * always taking the oldest join still in reach finds as many as can be
     * found. Times that are not valid are left out.
     *
     * @param list<string> $confirmedAts
     * @param list<string> $recordedJoins
     * @return list<string>
     */
    public static function joinsToBackfill(array $confirmedAts, array $recordedJoins): array
    {
        $seconds = static function (string $at): ?int {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $at, new \DateTimeZone('UTC'));

            return false === $date || $date->format('Y-m-d H:i:s') !== $at ? null : $date->getTimestamp();
        };
        $recorded = array_values(array_filter(array_map($seconds, $recordedJoins), 'is_int'));
        sort($recorded);
        sort($confirmedAts);
        $next = 0;
        $missing = [];

        foreach ($confirmedAts as $at) {
            $time = $seconds($at);

            if (null === $time) {
                continue;
            }

            while (isset($recorded[$next]) && $recorded[$next] < $time) {
                ++$next;
            }

            if (isset($recorded[$next]) && $recorded[$next] <= $time + self::JOIN_MATCH_SECONDS) {
                ++$next;
                continue;
            }

            $missing[] = $at;
        }

        return $missing;
    }

    /**
     * Columns the plugin has retired, dropped from the people table if an older
     * install still carries them.
     *
     * - disabled_at: the account "off" flag, replaced in v1.5 by the per-type
     *   notification preferences.
     * - email_opt_in_at / email_opt_in_source: the open-task mail opt-in,
     *   superseded in v0.6 by per-type opt-outs. Nothing has written them
     *   since, so the People screen was reporting consent that was never
     *   recorded - and a column that looks like consent evidence but is always
     *   empty is worse than no column at all now that the plugin publishes a
     *   privacy notice describing the real, opt-out model.
     * - can_lead / leader_eligible_notified_at: the crew-leader permission and
     *   its one-time notice, retired in schema 20 when Crew leader became an
     *   ordinary role. can_lead is copied into role_slugs first (see
     *   moveCanLeadToRole()); the notice record moved to role_notices.
     *
     * @var array<int, string>
     */
    private const RETIRED_PEOPLE_COLUMNS = [
        'disabled_at',
        'email_opt_in_at',
        'email_opt_in_source',
        'can_lead',
        'leader_eligible_notified_at',
    ];

    /** The role slug a can_lead person is given when that column is retired. */
    private const CREW_LEADER_SLUG = 'leader';

    /**
     * Gives everyone who had the old crew-leader permission (can_lead = 1) the
     * role "leader" instead, so they stay in the Crew leader role group. Runs
     * only while the can_lead column still exists, just before it is dropped,
     * and skips anyone who already holds the role, so it is safe to run twice.
     * It does not create the Crew leader role itself; that is a setting.
     */
    private static function moveCanLeadToRole(): void
    {
        global $wpdb;

        $table = self::table(self::PEOPLE);

        if (! self::hasColumn($table, 'can_lead')) {
            return;
        }

        $slug = self::CREW_LEADER_SLUG;

        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "UPDATE {$table}
                 SET role_slugs = IF(role_slugs = '', %s, CONCAT(role_slugs, ',', %s))
                 WHERE can_lead = 1 AND FIND_IN_SET(%s, role_slugs) = 0",
                $slug,
                $slug,
                $slug
            )
        );
    }

    private static function hasColumn(string $table, string $column): bool
    {
        global $wpdb;

        $present = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                $table,
                $column
            )
        );

        return null !== $present && '' !== (string) $present;
    }

    /**
     * dbDelta never drops a column - it only adds and widens - so a field the
     * plugin has retired lingers on installs that once had it. Each is dropped
     * here if still present, guarded by an information_schema check so the whole
     * thing runs once and is a no-op thereafter.
     */
    private static function dropRetiredColumns(): void
    {
        global $wpdb;

        $table = self::table(self::PEOPLE);

        foreach (self::RETIRED_PEOPLE_COLUMNS as $column) {
            if (! self::hasColumn($table, $column)) {
                continue;
            }

            // Both names are constants here - the table from $wpdb->prefix, the
            // column from the list above - and DROP takes no placeholders.
            $wpdb->query("ALTER TABLE {$table} DROP COLUMN {$column}"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        }
    }

    /**
     * Converts any table the host created as MyISAM.
     *
     * dbDelta compares columns and keys but never the storage engine, so a
     * host whose default_storage_engine is MyISAM produces tables that match
     * the declaration in every respect this plugin can otherwise see. The
     * first real install was exactly that.
     *
     * MyISAM would not break the capacity guard - its table-level write lock
     * serialises the conditional insert if anything more firmly than InnoDB's
     * row locking - but it has no crash recovery and no transactions. That
     * costs signup history on an unclean shutdown, and it puts an atomic
     * "spend a credit, write the redemption" permanently out of reach.
     *
     * Running this while the tables are empty is instantaneous. Running it
     * after a season of signups is a locking ALTER on a shared host, which is
     * why it happens now.
     */
    private static function ensureInnoDb(): void
    {
        global $wpdb;

        foreach (self::tableNames() as $name) {
            $table = self::table($name);

            $engine = (string) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT ENGINE FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                    $table
                )
            );

            if ('' === $engine || 0 === strcasecmp('InnoDB', $engine)) {
                continue;
            }

            // Table name comes from a constant joined to $wpdb->prefix, and
            // ALTER takes no placeholders.
            $wpdb->query("ALTER TABLE {$table} ENGINE=InnoDB"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        }
    }

    /**
     * The activation hook does not fire when WordPress updates a plugin
     * through the updater or when a file is dropped in over FTP, so relying
     * on it alone means a new column never lands on exactly the installs that
     * already have data in them. Comparing a stored version on every request
     * costs one autoloaded option read and closes that gap.
     */
    public static function maybeMigrate(): void
    {
        if (self::DB_VERSION === (string) get_option(self::VERSION_OPTION, '')) {
            return;
        }

        self::migrate();
    }

    public static function dropAll(): void
    {
        global $wpdb;

        foreach (self::tableNames() as $name) {
            $table = self::table($name);

            // Table name is a constant joined to $wpdb->prefix; no user input
            // can reach it, and DROP TABLE takes no placeholders.
            $wpdb->query("DROP TABLE IF EXISTS {$table}"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        }

        delete_option(self::VERSION_OPTION);
    }

    /**
     * dbDelta is famously particular: it parses these strings with regular
     * expressions rather than a SQL parser, so `PRIMARY KEY` needs two spaces
     * before the bracket, `KEY` may not be written as `INDEX`, and each field
     * must sit on its own line. Reformatting for tidiness will silently stop
     * it detecting changes.
     *
     * @return array<int, string>
     */
    private static function statements(string $charsetCollate): array
    {
        $people = self::table(self::PEOPLE);
        $tasks = self::table(self::TASKS);
        $assignments = self::table(self::ASSIGNMENTS);
        $redemptions = self::table(self::REDEMPTIONS);
        $creditGrants = self::table(self::CREDIT_GRANTS);
        $authTokens = self::table(self::AUTH_TOKENS);
        $notifications = self::table(self::NOTIFICATIONS);
        $guests = self::table(self::GUESTS);
        $checkins = self::table(self::CHECKINS);
        $ticketBuyers = self::table(self::TICKET_BUYERS);
        $newsletterSubscribers = self::table(self::NEWSLETTER_SUBSCRIBERS);
        $takeovers = self::table(self::TAKEOVERS);
        $botLog = self::table(self::BOT_LOG);
        $newsletterChanges = self::table(self::NEWSLETTER_CHANGES);
        $newsletterSends = self::table(self::NEWSLETTER_SENDS);
        $ticketTransfers = self::table(self::TICKET_TRANSFERS);
        $doorNotes = self::table(self::DOOR_NOTES);
        $history = self::table(self::HISTORY);
        $pushSubscriptions = self::table(self::PUSH_SUBSCRIPTIONS);
        $groupMessages = self::table(self::GROUP_MESSAGES);

        return [
            // anonymized_at is the retention purge's high-water mark. Without it
            // the purge would have no way to tell a record it has already
            // stripped from one that is merely old, and would rewrite the same
            // rows every hour for ever.
            //
            // sessions_valid_from is what makes a stateless session cookie
            // revocable: any cookie issued before it is refused. NULL means
            // "never revoked", which is every row until somebody presses the
            // button, so the column costs nothing until it is used.
            //
            // banned_at blocks a person: no task sign-up, no web sign-in. It is
            // set when a signed-in member sends a forged admin request from the
            // crew page, and an organizer clears it on the People page.
            // ban_reason says why, for the organizer who reads it there.
            //
            // language is the language crew-facing text reaches this person in:
            // 'en' or 'fi'. NULL means they have not chosen yet, which reads as
            // English; the bot asks at their next private command.
            //
            // role_notices is JSON: which role notices the organizers have
            // already been sent about this person ("met:<slug>" for meeting a
            // role's conditions, "given:<slug>" for being given the role), each
            // with when. NULL until the first one, so each is sent only once.
            //
            // approval is the team's OK for a newcomer (schema 33): 'waiting'
            // for a person who signed in by themselves for the first time,
            // 'ok' or 'no' once an organizer decided, and '' for everyone
            // else - every person from before schema 33, and everyone an
            // organizer added - who is OK. approval_by is the name of the
            // organizer who decided.
            //
            // email is varchar(191) rather than 255 so it still fits a unique
            // index under utf8mb4 on MySQL 5.7, which shared hosts still run.
            // telegram_user_id is uniquely indexed but nullable, which MySQL
            // permits any number of NULLs in - exactly what we want, since
            // most people have no Telegram link until they use the bot.
            "CREATE TABLE {$people} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                email varchar(191) NOT NULL,
                email_verified_at datetime DEFAULT NULL,
                display_name varchar(191) NOT NULL DEFAULT '',
                telegram_user_id bigint(20) unsigned DEFAULT NULL,
                telegram_chat_id bigint(20) DEFAULT NULL,
                is_organizer tinyint(1) NOT NULL DEFAULT 0,
                at_risk_pass tinyint(1) NOT NULL DEFAULT 0,
                notify_muted tinyint(1) NOT NULL DEFAULT 0,
                notify_prefs text DEFAULT NULL,
                anonymized_at datetime DEFAULT NULL,
                sessions_valid_from datetime DEFAULT NULL,
                banned_at datetime DEFAULT NULL,
                ban_reason text DEFAULT NULL,
                language varchar(5) DEFAULT NULL,
                role_slugs varchar(255) NOT NULL DEFAULT '',
                role_notices text DEFAULT NULL,
                approval varchar(10) NOT NULL DEFAULT '',
                approval_by varchar(191) NOT NULL DEFAULT '',
                notes text NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY email (email),
                UNIQUE KEY telegram_user_id (telegram_user_id)
            ) ENGINE=InnoDB {$charsetCollate};",

            // event_post_id points at an eventmesh_event post when EventMesh
            // is installed; event_label carries a hand-typed name when it is
            // not, so EventCrew stands alone.
            //
            // task_date is the day the task is filed under, which is not the
            // same thing as the day it starts: a clean-up running 01:00 on
            // Sunday after a Saturday event belongs to Saturday's board, its
            // reminder and its open-task call. starts_at and ends_at are
            // absolute instants and carry the real crossing of midnight, which
            // is why they are datetimes rather than the times they used to be.
            // Both stay nullable - a task created from a role template has no
            // times until someone decides them.
            //
            // deleted_at: a task is never deleted, because the tickets of the
            // people on it must keep working and show DISABLED. Deleting it in
            // wp-admin sets this, which hides it everywhere, and cancels its
            // sign-ups.
            "CREATE TABLE {$tasks} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                event_post_id bigint(20) unsigned DEFAULT NULL,
                event_label varchar(191) NOT NULL DEFAULT '',
                task_date date NOT NULL,
                starts_at datetime DEFAULT NULL,
                ends_at datetime DEFAULT NULL,
                role_slug varchar(32) NOT NULL,
                capacity smallint(5) unsigned NOT NULL DEFAULT 1,
                plus_ones tinyint(3) unsigned NOT NULL DEFAULT 0,
                notes text NOT NULL,
                created_at datetime NOT NULL,
                deleted_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY task_date (task_date),
                KEY event_post_id (event_post_id)
            ) ENGINE=InnoDB {$charsetCollate};",

            // The unique key on (task_id, person_id) is load-bearing: it
            // is what stops a duplicate join when the same person taps the
            // group button twice before the first request has committed.
            //
            // changed_by_kind says which table changed_by points into:
            // 'person' (an EventCrew person id) or 'wp_user' (a WordPress user
            // id). NULL means it is not known - every row written before
            // schema 16, and every change with nobody recorded.
            //
            // counts_credit (schema 18) is 0 when whoever signed someone up to
            // a restricted-role task said it should not count toward
            // free-entry credit; every credit and standing count skips those.
            //
            // ticket_mailed_at (schema 26) is when the "Your ticket" email
            // (TicketMail) was sent for this sign-up. NULL means none was
            // sent, so a cancellation sends no "ticket cancelled" email.
            "CREATE TABLE {$assignments} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                task_id bigint(20) unsigned NOT NULL,
                person_id bigint(20) unsigned NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'signed_up',
                signed_up_at datetime NOT NULL,
                status_changed_at datetime DEFAULT NULL,
                changed_by bigint(20) unsigned DEFAULT NULL,
                changed_by_kind varchar(10) DEFAULT NULL,
                reminded_at datetime DEFAULT NULL,
                counts_credit tinyint(1) NOT NULL DEFAULT 1,
                ticket_mailed_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY task_person (task_id,person_id),
                KEY person_id (person_id),
                KEY status (status)
            ) ENGINE=InnoDB {$charsetCollate};",

            // redeemed_for is the event date the credit buys entry to, which is
            // how the door list finds tonight's credit-redeemers. It is the day
            // the task is filed under, not redeemed_at (when the organizer
            // pressed the button), so a credit booked earlier still lands on the
            // right night. event_post_id/event_label carry the event's identity
            // for the record; the date is what the door reads.
            //
            // cancelled_at: a redemption is never deleted, because its ticket
            // link must keep working and show DISABLED. Undoing one (credit
            // handed back, or swapped for a task at the desk) sets this, and
            // a cancelled row no longer uses up a credit or lets anyone in.
            //
            // ticket_mailed_at (schema 26) is when the "Your ticket" email
            // (TicketMail) was sent for this credit, as on assignments.
            "CREATE TABLE {$redemptions} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                person_id bigint(20) unsigned NOT NULL,
                redeemed_for date DEFAULT NULL,
                event_post_id bigint(20) unsigned DEFAULT NULL,
                event_label varchar(191) NOT NULL DEFAULT '',
                redeemed_at datetime NOT NULL,
                note varchar(191) NOT NULL DEFAULT '',
                cancelled_at datetime DEFAULT NULL,
                ticket_mailed_at datetime DEFAULT NULL,
                transferred_to bigint(20) unsigned DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY person_id (person_id),
                KEY redeemed_for (redeemed_for)
            ) ENGINE=InnoDB {$charsetCollate};",

            // A free-entry ticket given to someone else (schema 34). One row
            // is first an offer and, once it moves, the new ticket itself:
            // status 'pending' (an offer to an email address, waiting for the
            // receiver to accept), 'issued' (the ticket moved: the original
            // redemption is cancelled with transferred_to naming this row),
            // 'cancelled' (the giver withdrew the offer, or an organizer
            // cancelled the moved ticket) or 'lapsed' (never accepted before
            // the doors opened). receiver_person_id is the member it went to,
            // NULL for someone who is not a member. name and email are the
            // receiver's; email is emptied after the night with the guests'
            // addresses (RetentionPurge).
            "CREATE TABLE {$ticketTransfers} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                redemption_id bigint(20) unsigned NOT NULL,
                event_date date NOT NULL,
                event_post_id bigint(20) unsigned DEFAULT NULL,
                giver_person_id bigint(20) unsigned NOT NULL,
                receiver_person_id bigint(20) unsigned DEFAULT NULL,
                name varchar(191) NOT NULL DEFAULT '',
                email varchar(191) DEFAULT NULL,
                language varchar(10) DEFAULT NULL,
                status varchar(10) NOT NULL DEFAULT 'pending',
                created_at datetime NOT NULL,
                issued_at datetime DEFAULT NULL,
                ended_at datetime DEFAULT NULL,
                ticket_mailed_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY redemption_id (redemption_id),
                KEY event_date (event_date),
                KEY giver_person_id (giver_person_id),
                KEY receiver_person_id (receiver_person_id),
                KEY email (email)
            ) ENGINE=InnoDB {$charsetCollate};",

            // The name and email the door crew noted for someone who came in on
            // a given-away ticket or as a +1 (schema 35). Both are optional;
            // a row has at least one. kind and ref_id name the entry on that
            // night's door list, as in the check-ins table; role is 'receiver'
            // (who came in on a given-away ticket, one per ticket) or 'plus'
            // (one of the entry's +1s, up to its plus-ones). newsletter is 1
            // when the person asked the door crew for the newsletter: that
            // address gets the usual confirmation email (double opt-in) and
            // nothing else is sent from here. The email is emptied after
            // the night with the guests' addresses (RetentionPurge), and
            // erased on request by address (PrivacyEraser).
            "CREATE TABLE {$doorNotes} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                event_date date NOT NULL,
                kind varchar(10) NOT NULL,
                ref_id bigint(20) unsigned NOT NULL,
                role varchar(10) NOT NULL DEFAULT 'plus',
                name varchar(191) NOT NULL DEFAULT '',
                email varchar(191) DEFAULT NULL,
                newsletter tinyint(1) unsigned NOT NULL DEFAULT 0,
                added_by bigint(20) unsigned DEFAULT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY entry (event_date,kind,ref_id),
                KEY email (email)
            ) ENGINE=InnoDB {$charsetCollate};",

            // A manual credit an organizer hands someone - a bonus outside the
            // earn-one-per-two-completed rule (a spontaneous cleaner after a
            // paid event, say). credits is normally 1 but is a count so a larger
            // grant is one row. source 'extra_task' with assignment_id marks
            // the credit for an extra task on the same night
            // (ExtraTaskCredits); the unique key lets one task give it once. The balance in Support\Credits adds the sum of
            // these to earned-minus-redeemed; there is no stored running total.
            "CREATE TABLE {$creditGrants} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                person_id bigint(20) unsigned NOT NULL,
                credits smallint(5) unsigned NOT NULL DEFAULT 1,
                note varchar(191) NOT NULL DEFAULT '',
                granted_by bigint(20) unsigned DEFAULT NULL,
                granted_by_person_id bigint(20) unsigned DEFAULT NULL,
                granted_at datetime NOT NULL,
                source varchar(32) NOT NULL DEFAULT '',
                assignment_id bigint(20) unsigned DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY person_id (person_id),
                UNIQUE KEY assignment_id (assignment_id)
            ) ENGINE=InnoDB {$charsetCollate};",

            // Only the hash is stored, never the token itself, so a database
            // leak cannot be replayed as a login.
            "CREATE TABLE {$authTokens} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                person_id bigint(20) unsigned NOT NULL,
                token_hash varchar(64) NOT NULL,
                purpose varchar(20) NOT NULL,
                expires_at datetime NOT NULL,
                used_at datetime DEFAULT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY token_hash (token_hash),
                KEY person_id (person_id)
            ) ENGINE=InnoDB {$charsetCollate};",

            // Send-once ledger shared by both notification kinds. The unique
            // key is the actual guard against a double-send when a cron tick
            // dies partway through a batch and the next one resumes.
            "CREATE TABLE {$notifications} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                kind varchar(20) NOT NULL,
                person_id bigint(20) unsigned NOT NULL,
                task_date date NOT NULL,
                event_post_id bigint(20) unsigned DEFAULT NULL,
                sent_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY kind_person_date (kind,person_id,task_date),
                KEY task_date (task_date)
            ) ENGINE=InnoDB {$charsetCollate};",

            // The guest list: people let in free on one night who are not
            // crew members - a DJ's partner, a press guest. Just a name typed
            // by an organizer, so no person row; plus_ones counts the people
            // they bring. added_by is the organizer's person id, for the record.
            //
            // email is set only for a guest from a mailed guest list whose
            // line held an email address. That guest is sent a thank-you
            // message with their ticket after the import (TicketMail);
            // ticket_mail_status is '' (not sent), 'sent'
            // or 'failed', and ticket_mailed_at is when it was tried.
            // RetentionPurge blanks the email 60 days after the event.
            //
            // ticket_rev (schema 32) counts how often an organizer changed
            // the guest's email address. It is part of what the guest's
            // ticket code and ticket link sign (TicketMail::guestCode()), so
            // each change makes a new code and the old one stops working. 0
            // signs as before schema 32, so tickets sent earlier keep working.
            //
            // followup_mail_status is the post-event email (FollowupMail): ''
            // (not sent), 'sent', 'failed' or 'stopped' (an organizer stopped
            // that night's email); followup_mailed_at is when it was tried,
            // and followup_clicked_at is the first time a link in it was
            // opened (only the time, nothing about who or from where).
            "CREATE TABLE {$guests} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                event_date date NOT NULL,
                name varchar(191) NOT NULL,
                plus_ones smallint(5) unsigned NOT NULL DEFAULT 0,
                note varchar(191) NOT NULL DEFAULT '',
                added_by bigint(20) unsigned DEFAULT NULL,
                email varchar(191) DEFAULT NULL,
                ticket_mail_status varchar(10) NOT NULL DEFAULT '',
                ticket_mailed_at datetime DEFAULT NULL,
                ticket_rev smallint(5) unsigned NOT NULL DEFAULT 0,
                followup_mail_status varchar(10) NOT NULL DEFAULT '',
                followup_mailed_at datetime DEFAULT NULL,
                followup_clicked_at datetime DEFAULT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY event_date (event_date)
            ) ENGINE=InnoDB {$charsetCollate};",

            // Who has come through the door on one night. kind says which list
            // the row belongs to (crew, credit, guest, buyer) and ref_id is the
            // id in that list: the person id for crew and credit, the guest or
            // ticket-buyer row id for the other two. The unique key is what
            // keeps two door phones tapping "In" at once down to one row.
            // Un-marking deletes the row; checked_by is the door session's
            // person id.
            "CREATE TABLE {$checkins} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                event_date date NOT NULL,
                kind varchar(10) NOT NULL,
                ref_id bigint(20) unsigned NOT NULL,
                plus_ones smallint(5) unsigned NOT NULL DEFAULT 0,
                checked_in_at datetime NOT NULL,
                checked_by bigint(20) unsigned DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY one_per_entry (event_date,kind,ref_id)
            ) ENGINE=InnoDB {$charsetCollate};",

            // Ticket buyers from the Holvi shop for one night: a name only,
            // with where it came from (source) and which import added it
            // (batch), so one import can be told apart from the next.
            //
            // email is filled from an imported ticket file, and for a Holvi
            // buyer added by hand at the sales desk because they were missing
            // from the imported list. It is shown on the door list. An
            // imported ticket with an email and a ticket code is sent a
            // thank-you message with its ticket, automatically after the
            // import (TicketMail). RetentionPurge blanks it, the
            // name and the ticket code 60 days after the event
            // (PrivacyPolicy::BUYER_EMAIL_DAYS); the row and its check-in stay.
            //
            // ticket_code is the code from the ticket file (for Holvi, the
            // Tilauskoodi column, which is different for every ticket). Not a
            // unique key: a ticket file of another format may repeat a code.
            // NULL for names imported without a file.
            //
            // ticket_mail_status is '' (not sent), 'sent' or 'failed' (what
            // wp_mail answered), and ticket_mailed_at is when it was tried.
            //
            // followup_mail_status, followup_mailed_at and followup_clicked_at
            // are the post-event email, as on the guests table. Every row with
            // an email gets it, door and desk rows too.
            "CREATE TABLE {$ticketBuyers} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                event_date date NOT NULL,
                name varchar(191) NOT NULL,
                email varchar(191) DEFAULT NULL,
                source varchar(20) NOT NULL DEFAULT 'paste',
                batch varchar(64) NOT NULL DEFAULT '',
                ticket_code varchar(191) DEFAULT NULL,
                ticket_mail_status varchar(10) NOT NULL DEFAULT '',
                ticket_mailed_at datetime DEFAULT NULL,
                followup_mail_status varchar(10) NOT NULL DEFAULT '',
                followup_mailed_at datetime DEFAULT NULL,
                followup_clicked_at datetime DEFAULT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY event_date (event_date),
                KEY ticket_code (ticket_code)
            ) ENGINE=InnoDB {$charsetCollate};",
            // The newsletter's subscribers: an email address, and optionally
            // the name the person wants to be greeted with (schema 29). status
            // is 'pending' (signed up, not confirmed yet), 'confirmed'
            // (clicked the link in the confirmation email, or the subscribe
            // button in a thank-you email; only these get the newsletter) or
            // 'unsubscribed'. The address is stored in lower case and is
            // unique, so a second sign-up reuses the row. A pending row older
            // than Newsletter::PENDING_DAYS is deleted. language (schema 29)
            // is 'fi', 'en' or '' when not known, taken from the browser at
            // sign-up and changeable on the manage page.
            "CREATE TABLE {$newsletterSubscribers} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                email varchar(191) NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'pending',
                created_at datetime NOT NULL,
                confirmed_at datetime DEFAULT NULL,
                name varchar(100) DEFAULT NULL,
                language varchar(2) NOT NULL DEFAULT '',
                PRIMARY KEY  (id),
                UNIQUE KEY email (email),
                KEY status (status)
            ) ENGINE=InnoDB {$charsetCollate};",

            // A request to take over someone's task (schema 27). The cover
            // asks, the holder (holder_id, on assignment_id) answers before
            // deadline_at, and only then does the task move. status is
            // 'pending', 'accepted', 'refused', 'expired' or 'cancelled'.
            // One pending row per task at a time; the insert checks that in
            // the same statement (TakeoverRepository::create()).
            "CREATE TABLE {$takeovers} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                assignment_id bigint(20) unsigned NOT NULL,
                task_id bigint(20) unsigned NOT NULL,
                holder_id bigint(20) unsigned NOT NULL,
                cover_id bigint(20) unsigned NOT NULL,
                status varchar(10) NOT NULL DEFAULT 'pending',
                deadline_at datetime NOT NULL,
                created_at datetime NOT NULL,
                answered_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY task_status (task_id,status),
                KEY status_deadline (status,deadline_at),
                KEY assignment_id (assignment_id)
            ) ENGINE=InnoDB {$charsetCollate};",

            // The bot's command log (schema 28, Telegram\BotLog): one row per
            // typed command, button tap or action the language-model helper
            // ran. Never the message text, its arguments or the full callback
            // data: command is the command's name, button_kind only the
            // callback data's prefix, date_arg a date the arguments named.
            // person_id is NULL when the Telegram user is not linked to a
            // person; telegram_user_id groups those rows. created_at is UTC.
            // Rows older than BotLog::KEEP_DAYS are deleted by the heartbeat.
            "CREATE TABLE {$botLog} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                created_at datetime NOT NULL,
                person_id bigint(20) unsigned DEFAULT NULL,
                telegram_user_id bigint(20) unsigned DEFAULT NULL,
                chat_kind varchar(10) NOT NULL DEFAULT '',
                command varchar(20) NOT NULL DEFAULT '',
                source varchar(10) NOT NULL DEFAULT '',
                button_kind varchar(20) DEFAULT NULL,
                date_arg date DEFAULT NULL,
                outcome varchar(10) NOT NULL DEFAULT 'ok',
                PRIMARY KEY  (id),
                KEY created_at (created_at),
                KEY person_id (person_id)
            ) ENGINE=InnoDB {$charsetCollate};",

            // The newsletter's history of joins and leaves (schema 30,
            // NewsletterHistoryRepository): one row each time an address
            // becomes a confirmed subscriber ('subscribed') or a confirmed
            // subscriber leaves ('unsubscribed'). No address, name or
            // subscriber id: only the time and the kind, so the rows are
            // counts and nothing else. created_at is site time, like the
            // subscribers table.
            "CREATE TABLE {$newsletterChanges} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                created_at datetime NOT NULL,
                kind varchar(12) NOT NULL DEFAULT '',
                PRIMARY KEY  (id),
                KEY created_at (created_at)
            ) ENGINE=InnoDB {$charsetCollate};",

            // One row per version of a sent newsletter (schema 30): 'fi',
            // 'en' or '' for both languages. html is a copy of that version
            // as the subscribers got it, made when its first email went,
            // with the greeting that has no name and with no link of any
            // subscriber, so it holds no personal data. sent and failed are
            // how many emails of this version went and failed; sent_at is
            // site time.
            "CREATE TABLE {$newsletterSends} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                plan_id bigint(20) unsigned NOT NULL DEFAULT 0,
                lang varchar(2) NOT NULL DEFAULT '',
                sent_at datetime NOT NULL,
                subject varchar(255) NOT NULL DEFAULT '',
                sent int(10) unsigned NOT NULL DEFAULT 0,
                failed int(10) unsigned NOT NULL DEFAULT 0,
                html longtext NOT NULL,
                PRIMARY KEY  (id),
                KEY sent_at (sent_at),
                KEY plan_id (plan_id)
            ) ENGINE=InnoDB {$charsetCollate};",

            // The history of actions (schema 37, Support\History): one row per
            // action - a sign-up, a cancellation, a task added, a check-in, a
            // credit given, a person's roles changed, settings saved. created_at
            // is site time, like the assignments and tasks it describes (the
            // bot log alone keeps UTC). actor_kind is 'person' (acting for
            // themselves), 'organizer' (a person acting for someone else),
            // 'wp_user' (actor_wp_user_id, in wp-admin), 'system' (WP-Cron) or
            // '' (not known: backfilled rows); channel is 'bot', 'web',
            // 'admin', 'system' or ''. person_id is the person acted on and
            // other_person_id the second person (the cover who took over, the
            // receiver of a ticket). details is short JSON of keys, ids and
            // counts only: never free text, an email or a setting's value.
            // source is 'backfill' for the rows the schema 37 update made from
            // older data. An erased person's ids are emptied (NULL). Rows older
            // than History::KEEP_DAYS are deleted by the heartbeat.
            "CREATE TABLE {$history} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                created_at datetime NOT NULL,
                actor_kind varchar(10) NOT NULL DEFAULT '',
                actor_person_id bigint(20) unsigned DEFAULT NULL,
                actor_wp_user_id bigint(20) unsigned DEFAULT NULL,
                channel varchar(10) NOT NULL DEFAULT '',
                kind varchar(24) NOT NULL DEFAULT '',
                person_id bigint(20) unsigned DEFAULT NULL,
                other_person_id bigint(20) unsigned DEFAULT NULL,
                task_id bigint(20) unsigned DEFAULT NULL,
                event_date date DEFAULT NULL,
                details varchar(191) NOT NULL DEFAULT '',
                source varchar(10) NOT NULL DEFAULT '',
                PRIMARY KEY  (id),
                KEY created_at (created_at),
                KEY person_id (person_id),
                KEY other_person_id (other_person_id),
                KEY actor_person_id (actor_person_id),
                KEY task_id (task_id),
                KEY event_date (event_date)
            ) ENGINE=InnoDB {$charsetCollate};",

            // The devices that turned on app notifications (Web Push), several
            // per person. endpoint is the push service's address for one
            // browser on one device; it is too long for a unique index under
            // utf8mb4, so its SHA-256 (endpoint_hash) is the unique key.
            // p256dh and auth are the browser's keys the payload is encrypted
            // to (RFC 8291). device is a short label from the user agent,
            // like "Android Chrome". last_ok_at is the last send the push
            // service accepted; a 404 or 410 deletes the row.
            "CREATE TABLE {$pushSubscriptions} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                person_id bigint(20) unsigned NOT NULL,
                endpoint text NOT NULL,
                endpoint_hash char(64) NOT NULL,
                p256dh varchar(255) NOT NULL,
                auth varchar(64) NOT NULL,
                device varchar(64) NOT NULL DEFAULT '',
                created_at datetime NOT NULL,
                last_ok_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY endpoint_hash (endpoint_hash),
                KEY person_id (person_id)
            ) ENGINE=InnoDB {$charsetCollate};",

            // The crew's Telegram group, mirrored for the group chat on the
            // web (schema 39, Telegram\GroupRelay): one row per message in
            // the group the bot knows (GroupChat::id()), from Telegram or sent
            // from the web through the bot. person_id is the sender when they
            // are a known person (NULL otherwise; an erased person's messages
            // are deleted); sender_name is the name shown. kind is '' for text and
            // otherwise what was sent instead ('photo', 'sticker', ...), shown
            // as a word on the web. reply_to is the message_id answered, with
            // the answered sender and a short quote (reply_name, reply_text),
            // so a quote of a message older than the stored ones still reads;
            // reply_person_id is the answered sender, so erasing them empties
            // the quote too.
            // source is 'telegram' or 'web'. hidden is an organizer's hide.
            // Rows older than GroupRelay::KEEP_DAYS are deleted by the
            // heartbeat.
            "CREATE TABLE {$groupMessages} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                chat_id bigint(20) NOT NULL,
                message_id bigint(20) NOT NULL,
                person_id bigint(20) unsigned DEFAULT NULL,
                sender_name varchar(191) NOT NULL DEFAULT '',
                text text NOT NULL,
                kind varchar(16) NOT NULL DEFAULT '',
                reply_to bigint(20) DEFAULT NULL,
                reply_person_id bigint(20) unsigned DEFAULT NULL,
                reply_name varchar(191) NOT NULL DEFAULT '',
                reply_text varchar(255) NOT NULL DEFAULT '',
                source varchar(10) NOT NULL DEFAULT 'telegram',
                hidden tinyint(1) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                edited_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY chat_message (chat_id,message_id),
                KEY created_at (created_at),
                KEY person_id (person_id),
                KEY reply_person_id (reply_person_id)
            ) ENGINE=InnoDB {$charsetCollate};",
        ];
    }
}
