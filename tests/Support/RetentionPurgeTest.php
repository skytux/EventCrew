<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\DoorNoteRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Support\PersonEraser;
use EventCrew\Support\PrivacyPolicy;
use EventCrew\Support\RetentionPurge;
use EventCrew\Tests\TestCase;

final class RetentionPurgeTest extends TestCase
{
    private function purge(): RetentionPurge
    {
        $people = new PersonRepository();

        return new RetentionPurge(
            $people,
            new PersonEraser(
                $people,
                new AssignmentRepository(),
                new RedemptionRepository(),
                new CreditGrantRepository(),
                new AuthTokenRepository(),
                new NotificationsRepository()
            )
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function withOptions(array $options): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => $options[$name] ?? $default
        );
    }

    /**
     * The single most important property of this job: an install that never
     * asked for automatic erasure must never get it, and must not so much as
     * run the query that finds candidates.
     */
    public function testItDoesNothingWhenTheOrganizerHasNotSwitchedItOn(): void
    {
        $this->withOptions([]);

        self::assertSame(0, $this->purge()->run());
        self::assertSame([], $this->wpdb->queries);
        self::assertSame([], $this->wpdb->updates);
    }

    /**
     * The cutoff is the retention period back from the site's own clock, in the
     * naive local format every datetime column here holds.
     */
    public function testTheCutoffIsTheRetentionPeriodBeforeNow(): void
    {
        $this->withOptions([
            PrivacyPolicy::PURGE_OPTION => '1',
            PrivacyPolicy::RETENTION_OPTION => 3,
        ]);

        // TestCase pins current_time('mysql') to 2026-07-20 12:00:00.
        self::assertSame('2023-07-20 12:00:00', $this->purge()->cutoff());
    }

    /**
     * An expired record is stripped of everything identifying while its
     * assignments stay put, and it is stamped so the next hourly tick leaves it
     * alone.
     */
    public function testItAnonymisesExpiredRecordsWithoutDeletingTheirHistory(): void
    {
        $this->withOptions([
            PrivacyPolicy::PURGE_OPTION => '1',
            PrivacyPolicy::RETENTION_OPTION => 3,
        ]);

        $this->wpdb->nextCols[] = [4, 9];

        self::assertSame(2, $this->purge()->run());

        // Two people, each stripped by one UPDATE - never a DELETE of the row.
        self::assertCount(2, $this->wpdb->updates);

        $first = $this->wpdb->updates[0]['data'];

        self::assertSame('anonymised-4@removed.invalid', $first['email']);
        self::assertSame('', $first['display_name']);
        self::assertNull($first['telegram_user_id']);
        self::assertNull($first['telegram_chat_id']);
        self::assertSame('', $first['notes']);
        self::assertNotEmpty($first['anonymized_at']);

        // The row survives, so a live session cookie would keep resolving to it
        // and leave whoever holds it signed in to a record with no name on it.
        // Stamping the revocation line is what closes that.
        self::assertNotEmpty($first['sessions_valid_from']);

        // The satellites cleared are the ones that are pure personal data: the
        // sign-in tokens and the send ledger. Assignments, redemptions and
        // credit grants survive, which is the whole point of anonymising
        // rather than erasing.
        $deletedTables = array_column($this->wpdb->deletes, 'table');

        self::assertContains('wp_eventcrew_auth_tokens', $deletedTables);
        self::assertContains('wp_eventcrew_notifications', $deletedTables);
        $takeovers = 'wp_eventcrew_takeovers';
        self::assertContains(['table' => $takeovers, 'where' => ['holder_id' => 4]], $this->wpdb->deletes);
        self::assertContains(['table' => $takeovers, 'where' => ['cover_id' => 4]], $this->wpdb->deletes);
        self::assertNotContains('wp_eventcrew_assignments', $deletedTables);
        self::assertNotContains('wp_eventcrew_redemptions', $deletedTables);
        self::assertNotContains('wp_eventcrew_credit_grants', $deletedTables);
    }

    /**
     * What the person said when joining (JoinAnswers) is personal data:
     * anonymising removes it and keeps the rest of the newcomer record.
     */
    public function testAnonymisingRemovesTheJoinAnswers(): void
    {
        $this->withOptions([
            PrivacyPolicy::PURGE_OPTION => '1',
            PrivacyPolicy::RETENTION_OPTION => 3,
        ]);

        $this->wpdb->nextCols[] = [4];
        $this->wpdb->nextRows[] = [
            'id' => 4,
            'email' => 'sam@example.com',
            'role_notices' => '{"newcomer:asked":"2026-01-01 10:00:00","newcomer:before":"yes",'
                . '"newcomer:times":"5","newcomer:tickets":"3"}',
        ];

        $this->purge()->run();

        $notices = array_values(array_filter(
            $this->wpdb->updates,
            static fn (array $u): bool => array_key_exists('role_notices', $u['data'])
        ));
        self::assertCount(1, $notices);
        self::assertSame(['newcomer:asked' => '2026-01-01 10:00:00'], json_decode($notices[0]['data']['role_notices'], true));
    }

    public function testAnonymisingWithOnlyTheAnswersClearsTheRecord(): void
    {
        $this->withOptions([
            PrivacyPolicy::PURGE_OPTION => '1',
            PrivacyPolicy::RETENTION_OPTION => 3,
        ]);

        $this->wpdb->nextCols[] = [4];
        $this->wpdb->nextRows[] = ['id' => 4, 'role_notices' => '{"newcomer:before":"no"}'];

        $this->purge()->run();

        $notices = array_values(array_filter(
            $this->wpdb->updates,
            static fn (array $u): bool => array_key_exists('role_notices', $u['data'])
        ));
        self::assertCount(1, $notices);
        self::assertNull($notices[0]['data']['role_notices']);
    }

    /**
     * The candidate query has to hold back staff and anything already stripped,
     * or the job would anonymise the organizer holding the door list and then
     * rewrite the same rows on every tick for ever.
     */
    public function testTheCandidateQuerySkipsStaffAndAlreadyStrippedRecords(): void
    {
        $this->withOptions([
            PrivacyPolicy::PURGE_OPTION => '1',
            PrivacyPolicy::RETENTION_OPTION => 3,
        ]);

        $this->wpdb->nextCols[] = [];
        $this->purge()->run();

        $sql = $this->wpdb->lastQuery();

        self::assertStringContainsString('anonymized_at IS NULL', $sql);
        self::assertStringContainsString('is_organizer = 0', $sql);
        self::assertStringNotContainsString('can_lead', $sql);
        self::assertStringContainsString('2023-07-20 12:00:00', $sql);
    }

    /**
     * TestCase pins today to 2026-07-20. 60 days before is 2026-05-21: an
     * event on that day or later keeps its emails, an earlier one loses them.
     */
    public function testBuyerEmailsGoOnlyWhenTheEventIsMoreThanSixtyDaysAgo(): void
    {
        $this->withOptions([]);

        $cutoff = $this->purge()->buyerEmailCutoff();
        self::assertSame('2026-05-21', $cutoff);

        $daysAgo = static fn (int $days): string => gmdate('Y-m-d', (int) strtotime("2026-07-20 -{$days} days"));
        $purged = static fn (string $eventDate): bool => $eventDate < $cutoff;

        self::assertFalse($purged($daysAgo(59)));
        self::assertFalse($purged($daysAgo(60)));
        self::assertTrue($purged($daysAgo(61)));

        $this->wpdb->nextQueryResults[] = 3;

        self::assertSame(3, $this->purge()->purgeBuyers());
        self::assertStringContainsString("event_date < '2026-05-21'", $this->wpdb->lastQuery());
    }

    /**
     * Q057: the email, the name and the ticket code are blanked; rows with
     * none of them left are not touched, and no row is deleted.
     */
    public function testTheBuyerPurgeBlanksEmailNameAndTicketCodeButKeepsTheRow(): void
    {
        $this->withOptions([]);

        $this->purge()->purgeBuyers();

        $sql = $this->wpdb->lastQuery();

        self::assertStringContainsString(
            "UPDATE wp_eventcrew_ticket_buyers SET email = NULL, name = '', ticket_code = NULL WHERE",
            $sql
        );
        self::assertStringContainsString(
            "AND ((email IS NOT NULL AND email <> '') OR name <> '' OR ticket_code IS NOT NULL)",
            $sql
        );
        self::assertStringNotContainsString('DELETE', $sql);
        self::assertSame([], $this->wpdb->deletes);
        self::assertSame([], $this->wpdb->updates);
    }

    /**
     * The public notice promises the deletion, so it runs even when the crew
     * purge setting is off. The crew purge itself still does nothing.
     */
    public function testTheBuyerPurgeRunsWithTheCrewPurgeSwitchedOff(): void
    {
        $this->withOptions([PrivacyPolicy::PURGE_OPTION => '0']);

        $purge = $this->purge();
        self::assertSame(0, $purge->run());
        self::assertSame([], $this->wpdb->queries);

        // Two queries: the confirmed newsletter subscribers to keep, then the purge.
        $purge->purgeBuyers();
        self::assertCount(2, $this->wpdb->queries);
        self::assertStringContainsString('UPDATE wp_eventcrew_ticket_buyers', $this->wpdb->lastQuery());
    }

    /**
     * A guest's email address goes after the same period as a buyer's; the
     * guest's name stays on the list and no row is deleted.
     */
    public function testGuestEmailsGoAfterTheSamePeriodAndTheNameStays(): void
    {
        $this->withOptions([PrivacyPolicy::PURGE_OPTION => '0']);
        $this->wpdb->nextQueryResults[] = 2;

        self::assertSame(2, $this->purge()->purgeGuestEmails());

        $sql = $this->wpdb->lastQuery();
        self::assertStringContainsString('UPDATE wp_eventcrew_guests SET email = NULL', $sql);
        self::assertStringContainsString("event_date < '2026-05-21'", $sql);
        self::assertStringNotContainsString('name', $sql);
        self::assertSame([], $this->wpdb->deletes);
    }

    /**
     * With no confirmed newsletter subscriber, no address is kept and the
     * query has no exception list.
     */
    public function testNoBuyerEmailIsKeptWithoutConfirmedSubscribers(): void
    {
        $this->withOptions([]);
        $this->wpdb->nextCols = [[], []];

        self::assertSame([], $this->purge()->buyerEmailsToKeep());

        $this->purge()->purgeBuyers();
        self::assertStringNotContainsString('NOT IN', $this->wpdb->lastQuery());
    }

    /**
     * A confirmed newsletter subscriber's address is kept: the buyer purge
     * skips it, and the subscriber query asks for confirmed rows only.
     */
    public function testConfirmedSubscribersKeepTheirBuyerEmail(): void
    {
        $this->withOptions([]);
        $this->wpdb->nextCols = [['fan@example.test']];

        $this->purge()->purgeBuyers();

        $subscriberQuery = $this->wpdb->queries[0];
        self::assertStringContainsString('wp_eventcrew_newsletter_subscribers', $subscriberQuery);
        self::assertStringContainsString("status = 'confirmed'", $subscriberQuery);

        $sql = $this->wpdb->lastQuery();
        self::assertStringContainsString("email NOT IN ('fan@example.test')", $sql);
    }

    /**
     * An in-memory SQLite copy of the ticket buyer columns and the check-ins
     * table, so the purge's own SQL can be run against real rows.
     *
     * Today is pinned to 2026-07-20, so the cutoff is 2026-05-21.
     */
    private function buyerDatabase(): \PDO
    {
        if (! extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec(
            "CREATE TABLE wp_eventcrew_ticket_buyers (
                id INTEGER PRIMARY KEY, event_date TEXT NOT NULL, name TEXT NOT NULL,
                email TEXT, source TEXT NOT NULL DEFAULT 'paste', batch TEXT NOT NULL DEFAULT '',
                ticket_code TEXT, ticket_mail_status TEXT NOT NULL DEFAULT '',
                ticket_mailed_at TEXT, followup_mail_status TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL DEFAULT ''
            )"
        );
        $db->exec(
            'CREATE TABLE wp_eventcrew_checkins (id INTEGER PRIMARY KEY, event_date TEXT, kind TEXT, ref_id INTEGER)'
        );

        $rows = [
            // 61 days ago: an imported Holvi ticket, mailed and checked in.
            [1, '2026-05-20', 'Ada Lovelace', 'ada@example.test', 'email', 'AB12', 'sent'],
            // Exactly 60 days ago: kept.
            [2, '2026-05-21', 'Bea Virtanen', 'bea@example.test', 'email', 'CD34', 'sent'],
            // Last month: kept.
            [3, '2026-07-01', 'Cy Korhonen', 'cy@example.test', 'email', 'EF56', ''],
            // Long ago: a buyer added by hand at the desk, with no ticket code yet.
            [4, '2026-01-10', 'Dan Niemi', 'dan@example.test', 'desk', null, 'failed'],
            // Long ago: a pasted name only.
            [5, '2026-01-10', 'Eve Laine', null, 'paste', null, ''],
            // Long ago: a door sale with no name, nothing personal to blank.
            [6, '2026-01-10', '', null, 'door', null, ''],
        ];
        $insert = $db->prepare(
            'INSERT INTO wp_eventcrew_ticket_buyers'
            . ' (id, event_date, name, email, source, ticket_code, ticket_mail_status)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($rows as $row) {
            $insert->execute($row);
        }

        $db->exec(
            "INSERT INTO wp_eventcrew_checkins VALUES (1, '2026-05-20', 'buyer', 1), (2, '2026-01-10', 'buyer', 6)"
        );

        return $db;
    }

    /**
     * Runs purgeBuyers() and then its UPDATE against $db. Returns how many
     * rows the UPDATE changed there.
     */
    private function purgeBuyersInto(\PDO $db): int
    {
        $this->wpdb->queries = [];
        $this->purge()->purgeBuyers();

        $updates = array_values(array_filter(
            $this->wpdb->queries,
            static fn (string $sql): bool => str_starts_with($sql, 'UPDATE ')
        ));
        self::assertCount(1, $updates);

        return (int) $db->exec($updates[0]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buyerRows(\PDO $db): array
    {
        $rows = $db->query('SELECT * FROM wp_eventcrew_ticket_buyers ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);

        return array_column($rows, null, 'id');
    }

    /**
     * Q057: a row whose event is more than 60 days ago loses its name, email
     * and ticket code; a row of 60 days or less is untouched. The rows, their
     * date, source, email status and check-ins all stay, so the counts of
     * tickets sold and used for each night do not change.
     */
    public function testBuyerRowsOlderThanSixtyDaysAreBlankedAndTheCountsStay(): void
    {
        $this->withOptions([]);
        $db = $this->buyerDatabase();

        self::assertSame(3, $this->purgeBuyersInto($db));

        $rows = $this->buyerRows($db);
        self::assertCount(6, $rows);

        foreach ([1, 4, 5] as $id) {
            self::assertSame('', $rows[$id]['name'], "row {$id} name");
            self::assertNull($rows[$id]['email'], "row {$id} email");
            self::assertNull($rows[$id]['ticket_code'], "row {$id} ticket code");
        }

        // Kept on the blanked rows: date, source, the sent flag.
        self::assertSame('2026-05-20', $rows[1]['event_date']);
        self::assertSame('email', $rows[1]['source']);
        self::assertSame('sent', $rows[1]['ticket_mail_status']);
        self::assertSame('desk', $rows[4]['source']);
        self::assertSame('failed', $rows[4]['ticket_mail_status']);

        // 60 days or less: untouched.
        self::assertSame('Bea Virtanen', $rows[2]['name']);
        self::assertSame('bea@example.test', $rows[2]['email']);
        self::assertSame('CD34', $rows[2]['ticket_code']);
        self::assertSame('Cy Korhonen', $rows[3]['name']);
        self::assertSame('EF56', $rows[3]['ticket_code']);

        // The check-ins are not touched, so "N of M came in" stays right.
        self::assertSame(2, (int) $db->query('SELECT COUNT(*) FROM wp_eventcrew_checkins')->fetchColumn());
        $sold = $db->query("SELECT COUNT(*) FROM wp_eventcrew_ticket_buyers WHERE event_date = '2026-01-10'");
        self::assertSame(3, (int) $sold->fetchColumn());
    }

    /**
     * The job runs every hour: a second run finds nothing left to blank and
     * changes nothing.
     */
    public function testRunningTheBuyerPurgeTwiceChangesNothingTheSecondTime(): void
    {
        $this->withOptions([]);
        $db = $this->buyerDatabase();

        self::assertSame(3, $this->purgeBuyersInto($db));
        $after = $this->buyerRows($db);

        self::assertSame(0, $this->purgeBuyersInto($db));
        self::assertSame($after, $this->buyerRows($db));
    }

    public function testDoorNoteEmailsGoAfterTheSamePeriodAsGuests(): void
    {
        $this->withOptions([PrivacyPolicy::PURGE_OPTION => '0']);
        $people = new PersonRepository();
        $purge = new RetentionPurge(
            $people,
            new PersonEraser(
                $people,
                new AssignmentRepository(),
                new RedemptionRepository(),
                new CreditGrantRepository(),
                new AuthTokenRepository(),
                new NotificationsRepository()
            ),
            doorNotes: new DoorNoteRepository()
        );
        $this->wpdb->nextQueryResults[] = 2; // guests
        $this->wpdb->nextQueryResults[] = 1; // door notes with no name, deleted
        $this->wpdb->nextQueryResults[] = 3; // door notes left with a name

        self::assertSame(6, $purge->purgeGuestEmails());

        $sql = implode("\n", $this->wpdb->queries);
        self::assertStringContainsString("DELETE FROM wp_eventcrew_door_notes WHERE event_date < '2026-05-21' AND name = ''", $sql);
        self::assertStringContainsString(
            "UPDATE wp_eventcrew_door_notes SET email = NULL WHERE event_date < '2026-05-21' AND email IS NOT NULL",
            $sql
        );
    }
}
