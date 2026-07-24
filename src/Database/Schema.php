<?php

declare(strict_types=1);

namespace EventCrew\Database;

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
    public const DB_VERSION = '8';

    public const VERSION_OPTION = 'eventcrew_db_version';

    public const PEOPLE = 'eventcrew_people';
    public const TASKS = 'eventcrew_tasks';
    public const ASSIGNMENTS = 'eventcrew_assignments';
    public const REDEMPTIONS = 'eventcrew_redemptions';
    public const CREDIT_GRANTS = 'eventcrew_credit_grants';
    public const AUTH_TOKENS = 'eventcrew_auth_tokens';
    public const NOTIFICATIONS = 'eventcrew_notifications';

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

        update_option(self::VERSION_OPTION, self::DB_VERSION);
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

        return [
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
                can_lead tinyint(1) NOT NULL DEFAULT 0,
                at_risk_pass tinyint(1) NOT NULL DEFAULT 0,
                leader_eligible_notified_at datetime DEFAULT NULL,
                notify_muted tinyint(1) NOT NULL DEFAULT 0,
                notify_prefs text DEFAULT NULL,
                email_opt_in_at datetime DEFAULT NULL,
                email_opt_in_source varchar(20) NOT NULL DEFAULT '',
                disabled_at datetime DEFAULT NULL,
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
            "CREATE TABLE {$tasks} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                event_post_id bigint(20) unsigned DEFAULT NULL,
                event_label varchar(191) NOT NULL DEFAULT '',
                task_date date NOT NULL,
                starts_at datetime DEFAULT NULL,
                ends_at datetime DEFAULT NULL,
                role_slug varchar(32) NOT NULL,
                capacity smallint(5) unsigned NOT NULL DEFAULT 1,
                notes text NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY task_date (task_date),
                KEY event_post_id (event_post_id)
            ) ENGINE=InnoDB {$charsetCollate};",

            // The unique key on (task_id, person_id) is load-bearing: it
            // is what stops a duplicate join when the same person taps the
            // group button twice before the first request has committed.
            "CREATE TABLE {$assignments} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                task_id bigint(20) unsigned NOT NULL,
                person_id bigint(20) unsigned NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'signed_up',
                signed_up_at datetime NOT NULL,
                status_changed_at datetime DEFAULT NULL,
                changed_by bigint(20) unsigned DEFAULT NULL,
                reminded_at datetime DEFAULT NULL,
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
            "CREATE TABLE {$redemptions} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                person_id bigint(20) unsigned NOT NULL,
                redeemed_for date DEFAULT NULL,
                event_post_id bigint(20) unsigned DEFAULT NULL,
                event_label varchar(191) NOT NULL DEFAULT '',
                redeemed_at datetime NOT NULL,
                note varchar(191) NOT NULL DEFAULT '',
                PRIMARY KEY  (id),
                KEY person_id (person_id),
                KEY redeemed_for (redeemed_for)
            ) ENGINE=InnoDB {$charsetCollate};",

            // A manual credit an organizer hands someone - a bonus outside the
            // earn-one-per-two-completed rule (a spontaneous cleaner after a
            // paid event, say). credits is normally 1 but is a count so a larger
            // grant is one row. The balance in Support\Credits adds the sum of
            // these to earned-minus-redeemed; there is no stored running total.
            "CREATE TABLE {$creditGrants} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                person_id bigint(20) unsigned NOT NULL,
                credits smallint(5) unsigned NOT NULL DEFAULT 1,
                note varchar(191) NOT NULL DEFAULT '',
                granted_by bigint(20) unsigned DEFAULT NULL,
                granted_by_person_id bigint(20) unsigned DEFAULT NULL,
                granted_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY person_id (person_id)
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
        ];
    }
}
