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
    public const DB_VERSION = '24';

    public const VERSION_OPTION = 'eventcrew_db_version';

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

        update_option(self::VERSION_OPTION, self::DB_VERSION);
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
            "CREATE TABLE {$redemptions} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                person_id bigint(20) unsigned NOT NULL,
                redeemed_for date DEFAULT NULL,
                event_post_id bigint(20) unsigned DEFAULT NULL,
                event_label varchar(191) NOT NULL DEFAULT '',
                redeemed_at datetime NOT NULL,
                note varchar(191) NOT NULL DEFAULT '',
                cancelled_at datetime DEFAULT NULL,
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
            // order code). Several tickets of one order share it, so it is
            // not unique. NULL for names imported without a file.
            //
            // ticket_mail_status is '' (not sent), 'sent' or 'failed' (what
            // wp_mail answered), and ticket_mailed_at is when it was tried.
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
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY event_date (event_date),
                KEY ticket_code (ticket_code)
            ) ENGINE=InnoDB {$charsetCollate};",
            // The newsletter's subscribers: an email address and nothing that
            // names a person. status is 'pending' (signed up, not confirmed
            // yet), 'confirmed' (clicked the link in the confirmation email;
            // only these get the newsletter) or 'unsubscribed'. The address is
            // stored in lower case and is unique, so a second sign-up reuses
            // the row. A pending row older than Newsletter::PENDING_DAYS is
            // deleted.
            "CREATE TABLE {$newsletterSubscribers} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                email varchar(191) NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'pending',
                created_at datetime NOT NULL,
                confirmed_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY email (email),
                KEY status (status)
            ) ENGINE=InnoDB {$charsetCollate};",
        ];
    }
}
