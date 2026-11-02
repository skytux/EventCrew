<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CreditGrantRepository;
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
        self::assertNotContains('wp_eventcrew_assignments', $deletedTables);
        self::assertNotContains('wp_eventcrew_redemptions', $deletedTables);
        self::assertNotContains('wp_eventcrew_credit_grants', $deletedTables);
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

        $purge->purgeBuyers();
        self::assertCount(1, $this->wpdb->queries);
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
     * There is no newsletter yet, so no address is kept and the query has no
     * exception list.
     */
    public function testNoBuyerEmailIsKeptUntilTheNewsletterExists(): void
    {
        $this->withOptions([]);

        self::assertSame([], $this->purge()->buyerEmailsToKeep());

        $this->purge()->purgeBuyers();
        self::assertStringNotContainsString('NOT IN', $this->wpdb->lastQuery());
    }
}
