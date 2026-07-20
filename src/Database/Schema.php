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
    public const DB_VERSION = '1';

    public const VERSION_OPTION = 'eventcrew_db_version';

    public const PEOPLE = 'eventcrew_people';
    public const TASKS = 'eventcrew_tasks';
    public const ASSIGNMENTS = 'eventcrew_assignments';
    public const REDEMPTIONS = 'eventcrew_redemptions';
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

        update_option(self::VERSION_OPTION, self::DB_VERSION);
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
                email_opt_in_at datetime DEFAULT NULL,
                email_opt_in_source varchar(20) NOT NULL DEFAULT '',
                notes text NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY email (email),
                UNIQUE KEY telegram_user_id (telegram_user_id)
            ) {$charsetCollate};",

            // event_post_id points at an eventmesh_event post when EventMesh
            // is installed; event_label carries a hand-typed name when it is
            // not, so EventCrew stands alone.
            "CREATE TABLE {$tasks} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                event_post_id bigint(20) unsigned DEFAULT NULL,
                event_label varchar(191) NOT NULL DEFAULT '',
                task_date date NOT NULL,
                starts_at time DEFAULT NULL,
                ends_at time DEFAULT NULL,
                role_slug varchar(32) NOT NULL,
                capacity smallint(5) unsigned NOT NULL DEFAULT 1,
                notes text NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY task_date (task_date),
                KEY event_post_id (event_post_id)
            ) {$charsetCollate};",

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
            ) {$charsetCollate};",

            "CREATE TABLE {$redemptions} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                person_id bigint(20) unsigned NOT NULL,
                event_post_id bigint(20) unsigned DEFAULT NULL,
                event_label varchar(191) NOT NULL DEFAULT '',
                redeemed_at datetime NOT NULL,
                note varchar(191) NOT NULL DEFAULT '',
                PRIMARY KEY  (id),
                KEY person_id (person_id)
            ) {$charsetCollate};",

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
            ) {$charsetCollate};",

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
            ) {$charsetCollate};",
        ];
    }
}
