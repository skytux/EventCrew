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
        self::assertStringContainsString('can_lead = 0', $sql);
        self::assertStringContainsString('2023-07-20 12:00:00', $sql);
    }
}
