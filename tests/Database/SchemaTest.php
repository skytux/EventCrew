<?php

declare(strict_types=1);

namespace EventCrew\Tests\Database;

use Brain\Monkey\Functions;
use EventCrew\Database\Schema;
use EventCrew\Tests\TestCase;
use ReflectionMethod;

/**
 * The schema cannot be exercised against a real MySQL server here, so these
 * tests stand in for the build step: they assert the CREATE TABLE strings say
 * what the rest of the plugin assumes they say. A missing unique key would
 * otherwise only surface as duplicate rows in production.
 */
final class SchemaTest extends TestCase
{
    public function testPrefixesEveryTableWithTheSiteTablePrefix(): void
    {
        self::assertSame('wp_eventcrew_people', Schema::table(Schema::PEOPLE));
        self::assertSame('wp_eventcrew_assignments', Schema::table(Schema::ASSIGNMENTS));
    }

    public function testKnowsEveryTableItCreates(): void
    {
        $statements = $this->statements();

        self::assertCount(count(Schema::tableNames()), $statements);

        foreach (Schema::tableNames() as $name) {
            $table = Schema::table($name);

            self::assertNotEmpty(
                array_filter(
                    $statements,
                    static fn (string $sql): bool => str_contains($sql, "CREATE TABLE {$table} ")
                ),
                sprintf('No CREATE TABLE statement for %s.', $table)
            );
        }
    }

    /**
     * This index is what actually stops the same person holding two slots
     * in one task when they double-tap the group button.
     */
    public function testAssignmentsAreUniquePerTaskAndPerson(): void
    {
        self::assertStringContainsString(
            'UNIQUE KEY task_person (task_id,person_id)',
            $this->statementFor(Schema::ASSIGNMENTS)
        );
    }

    /**
     * The send-once guard for both notification kinds. Without it a cron tick
     * that dies mid-batch re-mails everyone it already reached.
     */
    public function testNotificationsAreUniquePerKindPersonAndDate(): void
    {
        self::assertStringContainsString(
            'UNIQUE KEY kind_person_date (kind,person_id,task_date)',
            $this->statementFor(Schema::NOTIFICATIONS)
        );
    }

    public function testPersonEmailAndTelegramIdAreUnique(): void
    {
        $sql = $this->statementFor(Schema::PEOPLE);

        self::assertStringContainsString('UNIQUE KEY email (email)', $sql);
        self::assertStringContainsString('UNIQUE KEY telegram_user_id (telegram_user_id)', $sql);
    }

    /**
     * Two spaces after PRIMARY KEY is not a typo - dbDelta parses these
     * strings with regular expressions and silently fails to detect the key
     * with one space, leaving tables it cannot later alter.
     */
    public function testEveryStatementUsesTheSpacingDbDeltaRequires(): void
    {
        foreach ($this->statements() as $sql) {
            self::assertStringContainsString('PRIMARY KEY  (id)', $sql);
            self::assertStringNotContainsString('INDEX ', $sql);
        }
    }

    public function testEmailColumnFitsInAUtf8mb4UniqueIndex(): void
    {
        // 255 chars of utf8mb4 exceeds the 767-byte index limit on MySQL 5.7,
        // which shared hosts still run; 191 is the largest safe width.
        self::assertStringContainsString(
            'email varchar(191) NOT NULL',
            $this->statementFor(Schema::PEOPLE)
        );
    }

    public function testAnAddressStartsUnverified(): void
    {
        self::assertStringContainsString(
            'email_verified_at datetime DEFAULT NULL',
            $this->statementFor(Schema::PEOPLE)
        );
    }

    /**
     * The open-task opt-in was superseded by per-type opt-outs in v0.6 and
     * nothing has written these since. They are gone from the table, and the
     * migration drops them from installs that still carry them - a column that
     * reads as consent evidence but is always empty is worse than none at all
     * now the plugin publishes a privacy notice describing the real model.
     */
    public function testTheRetiredOptInColumnsAreGone(): void
    {
        $sql = $this->statementFor(Schema::PEOPLE);

        self::assertStringNotContainsString('email_opt_in_at', $sql);
        self::assertStringNotContainsString('email_opt_in_source', $sql);
    }

    /**
     * The retention purge's high-water mark. It must default to NULL, because
     * "not yet stripped" is the state every existing row has to migrate into -
     * a default of anything else would mark the whole table as already done and
     * the purge would never touch any of it.
     */
    public function testTheAnonymisedStampDefaultsToNull(): void
    {
        self::assertStringContainsString(
            'anonymized_at datetime DEFAULT NULL',
            $this->statementFor(Schema::PEOPLE)
        );
    }

    /**
     * The first real install came up MyISAM, because dbDelta never states an
     * engine and the host's default was not InnoDB. Declaring it explicitly
     * is what stops that happening on the next install; ensureInnoDb() repairs
     * the ones already made.
     *
     * Without InnoDB there is no crash recovery for signup history and no
     * transaction to make v0.5's "spend a credit, write the redemption" atomic.
     */
    public function testEveryTableDeclaresInnoDb(): void
    {
        foreach ($this->statements() as $sql) {
            self::assertStringContainsString('ENGINE=InnoDB', $sql);
        }
    }

    /**
     * Bare times could not express a task that runs past midnight, which is
     * every clean-up after an evening event. task_date deliberately stays a
     * DATE: it is the day the task is filed under, not the day it starts.
     */
    public function testTaskTimesAreDatetimesWhileTheTaskDateStaysADate(): void
    {
        $sql = $this->statementFor(Schema::TASKS);

        self::assertStringContainsString('task_date date NOT NULL', $sql);
        self::assertStringContainsString('starts_at datetime DEFAULT NULL', $sql);
        self::assertStringContainsString('ends_at datetime DEFAULT NULL', $sql);
    }

    /**
     * The door list finds a night's credit-redeemers by the event date the
     * credit buys entry to, which is why the column exists and is indexed -
     * without it a redemption could only be matched by when it was recorded.
     */
    public function testRedemptionsCarryTheDateTheCreditBuysEntryTo(): void
    {
        $sql = $this->statementFor(Schema::REDEMPTIONS);

        self::assertStringContainsString('redeemed_for date DEFAULT NULL', $sql);
        self::assertStringContainsString('KEY redeemed_for (redeemed_for)', $sql);
    }

    public function testSkipsMigrationWhenTheStoredVersionIsCurrent(): void
    {
        $reads = [];
        Functions\when('get_option')->alias(
            static function (string $name, mixed $default = false) use (&$reads): mixed {
                $reads[] = [$name, $default];

                return Schema::DB_VERSION;
            }
        );

        // A migration would have to call update_option; never stubbing it
        // means this test fails loudly if maybeMigrate() runs anyway.
        Schema::maybeMigrate();

        self::assertSame([[Schema::VERSION_OPTION, '']], $reads);
    }

    public function testThePeopleTableHasTheRoleNoticesColumn(): void
    {
        self::assertStringContainsString('role_notices text', $this->statementFor(Schema::PEOPLE));
    }

    /**
     * Schema 20: everyone with the old crew-leader permission is given the
     * role "leader" before can_lead is dropped, without doubling it for
     * someone who already holds it.
     */
    public function testCanLeadIsCopiedIntoTheLeaderRoleWhileTheColumnExists(): void
    {
        $this->wpdb->nextVars[] = 'can_lead';

        $this->moveCanLeadToRole();

        $sql = $this->wpdb->lastQuery();
        self::assertStringStartsWith('UPDATE wp_eventcrew_people', trim($sql));
        self::assertStringContainsString("CONCAT(role_slugs, ',', 'leader')", $sql);
        self::assertStringContainsString('WHERE can_lead = 1', $sql);
        self::assertStringContainsString("FIND_IN_SET('leader', role_slugs) = 0", $sql);
    }

    public function testNothingIsCopiedOnceTheCanLeadColumnIsGone(): void
    {
        $this->wpdb->nextVars[] = null;

        $this->moveCanLeadToRole();

        self::assertCount(1, $this->wpdb->queries);
        self::assertStringContainsString('information_schema.COLUMNS', $this->wpdb->lastQuery());
    }

    public function testCanLeadAndItsNoticeColumnAreRetired(): void
    {
        $retired = (new \ReflectionClassConstant(Schema::class, 'RETIRED_PEOPLE_COLUMNS'))->getValue();

        self::assertContains('can_lead', $retired);
        self::assertContains('leader_eligible_notified_at', $retired);
        self::assertGreaterThanOrEqual(21, (int) Schema::DB_VERSION);
    }

    /**
     * Schema 29: a subscriber keeps the name they are greeted with and the
     * language their browser preferred.
     */
    public function testSubscribersHaveANameAndALanguage(): void
    {
        $sql = $this->statementFor(Schema::NEWSLETTER_SUBSCRIBERS);

        self::assertStringContainsString('name varchar(100) DEFAULT NULL,', $sql);
        self::assertStringContainsString("language varchar(2) NOT NULL DEFAULT '',", $sql);
        self::assertGreaterThanOrEqual(29, (int) Schema::DB_VERSION);
    }

    /**
     * Schema 30: the newsletter history. Joins and leaves are a time and a
     * kind only; each sent version keeps its counts and its copy.
     */
    public function testTheNewsletterHistoryTablesHoldNoAddress(): void
    {
        $changes = $this->statementFor(Schema::NEWSLETTER_CHANGES);
        $sends = $this->statementFor(Schema::NEWSLETTER_SENDS);

        self::assertStringContainsString('created_at datetime NOT NULL,', $changes);
        self::assertStringContainsString("kind varchar(12) NOT NULL DEFAULT '',", $changes);
        self::assertStringNotContainsString('email', $changes);
        self::assertStringNotContainsString('subscriber', $changes);
        self::assertStringContainsString('html longtext NOT NULL,', $sends);
        self::assertStringContainsString('KEY sent_at (sent_at)', $sends);
        self::assertStringNotContainsString('email', $sends);
        self::assertGreaterThanOrEqual(30, (int) Schema::DB_VERSION);
    }

    /**
     * Schema 31: every confirmed_at older than the history gets a join. One
     * that already has a join from that second up to ten seconds later is
     * skipped (the plugin recorded it), each recorded join answers for one
     * subscriber only, and a time that is not valid is left out.
     */
    public function testOnlyTheMissingJoinsAreBackfilled(): void
    {
        $confirmed = [
            '2025-03-01 10:00:00',
            '2026-11-04 09:00:00', // on the update day, before the update: no join recorded
            '2026-11-04 15:00:00',
            '2026-11-05 12:00:00',
            '2026-11-05 12:00:00', // two in the same second, one join recorded
            '2026-11-06 08:00:00',
            '0000-00-00 00:00:00',
            '2024-12-24 18:30:00',
        ];
        $recorded = [
            '2026-11-04 15:00:01',
            '2026-11-05 12:00:00',
            '2026-11-06 08:00:11', // too late to be this subscriber's join
        ];

        $missing = Schema::joinsToBackfill($confirmed, $recorded);

        self::assertSame(
            [
                '2024-12-24 18:30:00',
                '2025-03-01 10:00:00',
                '2026-11-04 09:00:00',
                '2026-11-05 12:00:00',
                '2026-11-06 08:00:00',
            ],
            $missing
        );

        // Run again with those added, nothing more is missing.
        self::assertSame([], Schema::joinsToBackfill($confirmed, array_merge($recorded, $missing)));
    }

    public function testTheBackfillWritesOneJoinPerMissingConfirmation(): void
    {
        $this->wpdb->nextCols[] = ['2025-03-01 10:00:00', '2026-11-04 15:00:00'];
        $this->wpdb->nextCols[] = ['2026-11-04 15:00:02'];

        self::assertSame(1, $this->backfillNewsletterJoins('30'));
        self::assertStringContainsString(
            'SELECT confirmed_at FROM wp_eventcrew_newsletter_subscribers WHERE confirmed_at IS NOT NULL',
            $this->wpdb->queries[0]
        );
        self::assertStringContainsString(
            "FROM wp_eventcrew_newsletter_changes WHERE kind = 'subscribed'",
            $this->wpdb->queries[1]
        );
        self::assertSame(
            [[
                'table' => 'wp_eventcrew_newsletter_changes',
                'data' => ['created_at' => '2025-03-01 10:00:00', 'kind' => 'subscribed'],
            ]],
            $this->wpdb->inserts
        );
        self::assertGreaterThanOrEqual(31, (int) Schema::DB_VERSION);
    }

    /** Once the stored schema is 31 or later, the backfill does not even read. */
    public function testTheBackfillRunsOnlyBeforeSchema31(): void
    {
        self::assertSame(0, $this->backfillNewsletterJoins('31'));
        self::assertSame(0, $this->backfillNewsletterJoins('32'));
        self::assertSame([], $this->wpdb->queries);

        // A fresh install (nothing stored) reads, and finds nothing to add.
        self::assertSame(0, $this->backfillNewsletterJoins(''));
        self::assertCount(2, $this->wpdb->queries);
        self::assertSame([], $this->wpdb->inserts);
    }

    private function backfillNewsletterJoins(string $stored): int
    {
        $method = new ReflectionMethod(Schema::class, 'backfillNewsletterJoins');
        $method->setAccessible(true);

        return $method->invoke(null, $stored);
    }

    /** A guest's ticketRev: bumped when an organizer changes the address. */
    public function testGuestsHaveATicketRev(): void
    {
        $guests = $this->statementFor(Schema::GUESTS);

        self::assertStringContainsString('ticket_rev smallint(5) unsigned NOT NULL DEFAULT 0,', $guests);
        self::assertGreaterThanOrEqual(32, (int) Schema::DB_VERSION);
    }

    private function moveCanLeadToRole(): void
    {
        $method = new ReflectionMethod(Schema::class, 'moveCanLeadToRole');
        $method->setAccessible(true);
        $method->invoke(null);
    }

    private function statementFor(string $table): string
    {
        $needle = 'CREATE TABLE ' . Schema::table($table) . ' ';

        foreach ($this->statements() as $sql) {
            if (str_contains($sql, $needle)) {
                return $sql;
            }
        }

        self::fail(sprintf('No CREATE TABLE statement for %s.', $table));
    }

    /**
     * @return array<int, string>
     */
    private function statements(): array
    {
        $method = new ReflectionMethod(Schema::class, 'statements');
        $method->setAccessible(true);

        return $method->invoke(null, 'DEFAULT CHARACTER SET utf8mb4');
    }

    /**
     * Schema 38: the app channel written into every stored person's
     * preferences, on only where Telegram and email are both on.
     */
    public function testTheAppSwitchIsSeededFromTheTelegramAndEmailSwitches(): void
    {
        $this->wpdb->nextResults[] = [
            // Credit: Telegram on, email off; the rest nothing stored (both on).
            ['id' => 4, 'is_organizer' => 0, 'notify_muted' => 0, 'notify_prefs' => '{"credit":{"dm":true,"email":false}}'],
            // Already has the app switch everywhere: left alone.
            ['id' => 9, 'is_organizer' => 0, 'notify_muted' => 0, 'notify_prefs' => (string) json_encode(array_fill_keys(
                \EventCrew\Support\NotificationPreferences::types(),
                ['push' => false]
            ))],
        ];
        $this->wpdb->nextResults[] = [];

        self::assertSame(1, Schema::seedPushPrefs('37'));

        self::assertStringContainsString('FROM wp_eventcrew_people', $this->wpdb->queries[0]);
        self::assertStringContainsString('anonymized_at IS NULL', $this->wpdb->queries[0]);
        self::assertCount(1, $this->wpdb->updates);
        self::assertSame(['id' => 4], $this->wpdb->updates[0]['where']);
        $saved = json_decode((string) $this->wpdb->updates[0]['data']['notify_prefs'], true);
        self::assertSame(['dm' => true, 'email' => false, 'push' => false], $saved['credit']);
        self::assertSame(['push' => true], $saved['standing']);
        self::assertSame(['push' => true], $saved['newcomer']);
    }

    public function testTheAppSwitchIsSeededOnlyWhenUpdatingFromBeforeSchema38(): void
    {
        self::assertSame(-1, Schema::seedPushPrefs(''));
        self::assertSame(-1, Schema::seedPushPrefs('38'));
        self::assertSame(-1, Schema::seedPushPrefs('39'));
        self::assertSame([], $this->wpdb->queries);
        self::assertGreaterThanOrEqual(38, (int) Schema::DB_VERSION);
    }

    public function testDevicesAreUniquePerEndpoint(): void
    {
        $sql = implode("\n", $this->statements());

        self::assertStringContainsString('CREATE TABLE wp_eventcrew_push_subscriptions', $sql);
        self::assertStringContainsString('UNIQUE KEY endpoint_hash (endpoint_hash)', $sql);
    }

    public function testGroupMessagesAreUniquePerChatAndMessage(): void
    {
        $sql = implode("\n", $this->statements());

        self::assertGreaterThanOrEqual(39, (int) Schema::DB_VERSION);
        self::assertStringContainsString('CREATE TABLE wp_eventcrew_group_messages', $sql);
        self::assertStringContainsString('UNIQUE KEY chat_message (chat_id,message_id)', $sql);
        self::assertStringContainsString('KEY created_at (created_at)', $sql);
    }
}
