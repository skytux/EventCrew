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

    public function testConsumeOnceReturnsThePersonIdAndBurnsAValidToken(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 3,
            'person_id' => 7,
            'expires_at' => '2999-01-01 00:00:00',
            'used_at' => null,
        ];
        $this->wpdb->nextQueryResults[] = 1; // the conditional UPDATE changed the row

        $personId = $this->repo()->consumeOnce('rawtoken', 'email_verify');

        self::assertSame(7, $personId);
        // Burned in the same call: one conditional UPDATE on row 3 that only
        // matches a still-unused, unexpired row.
        $updates = $this->updateQueries();
        self::assertCount(1, $updates);
        self::assertStringContainsString('SET used_at =', $updates[0]);
        self::assertStringContainsString('WHERE id = 3', $updates[0]);
        self::assertStringContainsString('used_at IS NULL', $updates[0]);
        self::assertStringContainsString('expires_at >=', $updates[0]);
    }

    public function testConsumeOnceReturnsNullWhenAnotherRequestSpentTheTokenFirst(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 3,
            'person_id' => 7,
            'expires_at' => '2999-01-01 00:00:00',
            'used_at' => null,
        ];
        $this->wpdb->nextQueryResults[] = 0; // the UPDATE matched nothing: lost the race

        self::assertNull($this->repo()->consumeOnce('rawtoken', 'email_verify'));
    }

    public function testConsumeOnceRejectsAnAlreadyUsedToken(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 3,
            'person_id' => 7,
            'expires_at' => '2999-01-01 00:00:00',
            'used_at' => '2026-07-20 11:00:00',
        ];

        self::assertNull($this->repo()->consumeOnce('rawtoken', 'email_verify'));
        self::assertSame([], $this->updateQueries());
    }

    public function testConsumeOnceRejectsAnExpiredToken(): void
    {
        // current_time('mysql') is stubbed to 2026-07-20 12:00:00.
        $this->wpdb->nextRows[] = [
            'id' => 3,
            'person_id' => 7,
            'expires_at' => '2026-07-20 11:59:59',
            'used_at' => null,
        ];

        self::assertNull($this->repo()->consumeOnce('rawtoken', 'email_verify'));
        self::assertSame([], $this->updateQueries());
    }

    public function testConsumeOnceRejectsAnUnknownToken(): void
    {
        // No row queued: get_row returns null.
        self::assertNull($this->repo()->consumeOnce('rawtoken', 'email_verify'));
        self::assertSame([], $this->updateQueries());
    }

    public function testConsumeOnceRejectsAnEmptyToken(): void
    {
        self::assertNull($this->repo()->consumeOnce('   ', 'email_verify'));
        // Never even queried the database.
        self::assertSame([], $this->wpdb->queries);
    }

    /** @return list<string> The UPDATE statements sent through $wpdb->query(). */
    private function updateQueries(): array
    {
        return array_values(array_filter(
            $this->wpdb->queries,
            static fn (string $sql): bool => str_contains($sql, 'UPDATE')
        ));
    }

    public function testPurgeExpiredDeletesSpentAndExpiredTokens(): void
    {
        $this->wpdb->nextQueryResults[] = 4; // rows the DELETE reports removed

        $removed = $this->repo()->purgeExpired();

        self::assertSame(4, $removed);
        self::assertCount(1, $this->wpdb->queries);
        // A single DELETE that clears both the past-expiry and already-used rows.
        self::assertStringContainsString('DELETE FROM', $this->wpdb->queries[0]);
        self::assertStringContainsString('expires_at <', $this->wpdb->queries[0]);
        self::assertStringContainsString('used_at IS NOT NULL', $this->wpdb->queries[0]);
    }
}
