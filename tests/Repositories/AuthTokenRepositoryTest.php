<?php

declare(strict_types=1);

namespace EventCrew\Tests\Repositories;

use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Tests\TestCase;

final class AuthTokenRepositoryTest extends TestCase
{
    private function repo(): AuthTokenRepository
    {
        return new AuthTokenRepository();
    }

    public function testIssueStoresOnlyTheHashOfTheReturnedToken(): void
    {
        $raw = $this->repo()->issue(7, 'email_verify', 3600);

        self::assertNotSame('', $raw);
        self::assertCount(1, $this->wpdb->inserts);

        $stored = $this->wpdb->inserts[0]['data'];
        self::assertSame(7, $stored['person_id']);
        self::assertSame('email_verify', $stored['purpose']);
        // The raw token is never persisted - only its hash is.
        self::assertSame(hash('sha256', $raw), $stored['token_hash']);
        self::assertStringNotContainsString($raw, (string) json_encode($stored));
    }

    public function testConsumeReturnsThePersonIdAndBurnsAValidToken(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 3,
            'person_id' => 7,
            'expires_at' => '2999-01-01 00:00:00',
            'used_at' => null,
        ];

        $personId = $this->repo()->consume('rawtoken', 'email_verify');

        self::assertSame(7, $personId);
        // Burned in the same call: used_at stamped on row 3.
        self::assertCount(1, $this->wpdb->updates);
        self::assertSame(['id' => 3], $this->wpdb->updates[0]['where']);
        self::assertArrayHasKey('used_at', $this->wpdb->updates[0]['data']);
    }

    public function testConsumeRejectsAnAlreadyUsedToken(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 3,
            'person_id' => 7,
            'expires_at' => '2999-01-01 00:00:00',
            'used_at' => '2026-07-20 11:00:00',
        ];

        self::assertNull($this->repo()->consume('rawtoken', 'email_verify'));
        self::assertCount(0, $this->wpdb->updates);
    }

    public function testConsumeRejectsAnExpiredToken(): void
    {
        // current_time('mysql') is stubbed to 2026-07-20 12:00:00.
        $this->wpdb->nextRows[] = [
            'id' => 3,
            'person_id' => 7,
            'expires_at' => '2026-07-20 11:59:59',
            'used_at' => null,
        ];

        self::assertNull($this->repo()->consume('rawtoken', 'email_verify'));
        self::assertCount(0, $this->wpdb->updates);
    }

    public function testConsumeRejectsAnUnknownToken(): void
    {
        // No row queued: get_row returns null.
        self::assertNull($this->repo()->consume('rawtoken', 'email_verify'));
    }

    public function testConsumeRejectsAnEmptyToken(): void
    {
        self::assertNull($this->repo()->consume('   ', 'email_verify'));
        // Never even queried the database.
        self::assertSame([], $this->wpdb->queries);
    }
}
