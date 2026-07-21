<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;

/**
 * All reads and writes of the auth_tokens table: single-use, hashed magic-link
 * tokens for confirming an email address.
 *
 * Only the SHA-256 hash of a token is ever stored, so a database leak cannot
 * be replayed as a login. The raw token exists just long enough to be put in
 * the link that is emailed, and is never persisted.
 */
final class AuthTokenRepository
{
    private function table(): string
    {
        return Schema::table(Schema::AUTH_TOKENS);
    }

    /**
     * Issues a token for a person and returns the raw value for the caller to
     * put in a link. What lands in the database is only its hash.
     */
    public function issue(int $personId, string $purpose, int $ttlSeconds): string
    {
        global $wpdb;

        $raw = bin2hex(random_bytes(32));

        $wpdb->insert(
            $this->table(),
            [
                'person_id' => $personId,
                'token_hash' => $this->hash($raw),
                'purpose' => $purpose,
                'expires_at' => $this->expiryFromNow($ttlSeconds),
                'created_at' => current_time('mysql'),
            ]
        );

        return $raw;
    }

    /**
     * Consumes a token, returning the person id it was issued for - or null
     * when it is unknown, expired, already used, or issued for a different
     * purpose. A valid token is stamped used in the same call, so it works
     * exactly once.
     */
    public function consume(string $rawToken, string $purpose): ?int
    {
        global $wpdb;

        $rawToken = trim($rawToken);

        if ('' === $rawToken) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, person_id, expires_at, used_at
                FROM {$this->table()}
                WHERE token_hash = %s AND purpose = %s",
                $this->hash($rawToken),
                $purpose
            ),
            ARRAY_A
        );

        if (! is_array($row) || null !== $row['used_at']) {
            return null;
        }

        if ((string) $row['expires_at'] < current_time('mysql')) {
            return null;
        }

        $wpdb->update(
            $this->table(),
            ['used_at' => current_time('mysql')],
            ['id' => (int) $row['id']]
        );

        return (int) $row['person_id'];
    }

    private function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /**
     * Adds the TTL to the current wall-clock time as a naive local string, the
     * same shape every other datetime column in this plugin holds. Paired with
     * strtotime under WordPress's UTC default timezone so the string moves by
     * exactly the interval and is not reread in another zone.
     */
    private function expiryFromNow(int $ttlSeconds): string
    {
        $now = strtotime((string) current_time('mysql'));

        if (false === $now) {
            $now = time();
        }

        return gmdate('Y-m-d H:i:s', $now + $ttlSeconds);
    }
}
