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
     * purpose. The token is stamped used by one conditional UPDATE
     * that only matches a row that is still unused and unexpired. Two requests
     * spending the same token at the same moment cannot both get the person:
     * only the one whose UPDATE changed the row does. Used for the sales
     * desk's short-lived links shown as a QR code on a screen, the emailed
     * sign-in links, the door desk links, the email verification links and
     * the email change confirmation links.
     */
    public function consumeOnce(string $rawToken, string $purpose): ?int
    {
        global $wpdb;

        $rawToken = trim($rawToken);

        if ('' === $rawToken) {
            return null;
        }

        $now = (string) current_time('mysql');
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

        if (! is_array($row) || null !== $row['used_at'] || (string) $row['expires_at'] < $now) {
            return null;
        }

        $changed = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table()} SET used_at = %s
                WHERE id = %d AND used_at IS NULL AND expires_at >= %s",
                $now,
                (int) $row['id'],
                $now
            )
        );

        return 1 === $changed ? (int) $row['person_id'] : null;
    }

    /**
     * The person id of a token that was already spent and has not expired yet,
     * or null for any other token (unknown, expired, still unused, or issued
     * for a different purpose). Read-only: it never spends or changes a token.
     * Lets a link opened a second time - by the person after a mail scanner, or
     * a second click - say what already happened instead of "invalid".
     */
    public function usedBy(string $rawToken, string $purpose): ?int
    {
        global $wpdb;

        $rawToken = trim($rawToken);

        if ('' === $rawToken) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT person_id, expires_at, used_at
                FROM {$this->table()}
                WHERE token_hash = %s AND purpose = %s",
                $this->hash($rawToken),
                $purpose
            ),
            ARRAY_A
        );

        $now = (string) current_time('mysql');

        if (! is_array($row) || null === $row['used_at'] || (string) $row['expires_at'] < $now) {
            return null;
        }

        return (int) $row['person_id'];
    }

    /**
     * Deletes tokens past their expiry, used or not, so the table stays small
     * now that a fresh sign-in token is issued for every email's account-link
     * footer. A used token is kept until it expires: usedBy() needs it so a
     * link opened a second time says "already confirmed" instead of "invalid".
     * It cannot be used again meanwhile, since consumeOnce() refuses it.
     * Called from the hourly Scheduler heartbeat. Returns the number of rows
     * removed.
     */
    public function purgeExpired(): int
    {
        global $wpdb;

        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table()} WHERE expires_at < %s",
                current_time('mysql')
            )
        );
    }

    /**
     * Drops every token belonging to one person, spent or not.
     *
     * Erasing someone has to take their live sign-in links with it: an
     * unexpired token in an email they still have would otherwise recreate a
     * person row on next use, which is a deletion that undoes itself.
     */
    public function deleteForPerson(int $personId): void
    {
        global $wpdb;

        $wpdb->delete($this->table(), ['person_id' => $personId]);
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
