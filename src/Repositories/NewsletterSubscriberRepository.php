<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;

/**
 * All reads and writes of the newsletter subscribers table: one row per email
 * address, with its status (pending, confirmed or unsubscribed) and the dates
 * it signed up and confirmed. The table holds no name.
 */
final class NewsletterSubscriberRepository
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    /** The email column's width; a longer address is refused. */
    public const MAX_EMAIL_LENGTH = 191;

    private function table(): string
    {
        return Schema::table(Schema::NEWSLETTER_SUBSCRIBERS);
    }

    /**
     * The row with this id, or null.
     *
     * @return array{id: int, email: string, status: string, created_at: string, confirmed_at: ?string}|null
     */
    public function find(int $id): ?array
    {
        global $wpdb;

        if ($id <= 0) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id),
            ARRAY_A
        );

        return is_array($row) ? self::shape($row) : null;
    }

    /**
     * The row for this address (compared in lower case), or null.
     *
     * @return array{id: int, email: string, status: string, created_at: string, confirmed_at: ?string}|null
     */
    public function findByEmail(string $email): ?array
    {
        global $wpdb;

        $email = self::normalize($email);

        if ('' === $email) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table()} WHERE email = %s", $email),
            ARRAY_A
        );

        return is_array($row) ? self::shape($row) : null;
    }

    /**
     * Records a sign-up and returns the row's id, or 0 when nothing could be
     * written.
     *
     * A new address gets a pending row. An address that left the newsletter
     * (or whose earlier sign-up is still pending) is set back to pending with
     * a new created_at, so the 7-day expiry counts from this sign-up. A
     * confirmed address is left as it is.
     */
    public function addPending(string $email): int
    {
        global $wpdb;

        $email = self::normalize($email);

        if ('' === $email || strlen($email) > self::MAX_EMAIL_LENGTH) {
            return 0;
        }

        $existing = $this->findByEmail($email);
        $now = current_time('mysql');

        if (null !== $existing) {
            if (self::STATUS_CONFIRMED === $existing['status']) {
                return $existing['id'];
            }

            $wpdb->update(
                $this->table(),
                ['status' => self::STATUS_PENDING, 'created_at' => $now, 'confirmed_at' => null],
                ['id' => $existing['id']],
                ['%s', '%s', '%s'],
                ['%d']
            );

            return $existing['id'];
        }

        $inserted = $wpdb->insert(
            $this->table(),
            ['email' => $email, 'status' => self::STATUS_PENDING, 'created_at' => $now],
            ['%s', '%s', '%s']
        );

        return false !== $inserted && (int) $inserted > 0 ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Turns a pending row into a confirmed subscriber. Returns true when the
     * row is confirmed after the call (also when it already was).
     */
    public function confirm(int $id): bool
    {
        global $wpdb;

        $row = $this->find($id);

        if (null === $row) {
            return false;
        }

        if (self::STATUS_CONFIRMED === $row['status']) {
            return true;
        }

        if (self::STATUS_PENDING !== $row['status']) {
            return false;
        }

        $updated = $wpdb->update(
            $this->table(),
            ['status' => self::STATUS_CONFIRMED, 'confirmed_at' => current_time('mysql')],
            ['id' => $id, 'status' => self::STATUS_PENDING],
            ['%s', '%s'],
            ['%d', '%s']
        );

        return false !== $updated;
    }

    /**
     * Marks the row as unsubscribed. Returns false only when there is no such
     * row.
     */
    public function unsubscribe(int $id): bool
    {
        global $wpdb;

        $row = $this->find($id);

        if (null === $row) {
            return false;
        }

        if (self::STATUS_UNSUBSCRIBED === $row['status']) {
            return true;
        }

        $updated = $wpdb->update(
            $this->table(),
            ['status' => self::STATUS_UNSUBSCRIBED],
            ['id' => $id],
            ['%s'],
            ['%d']
        );

        return false !== $updated;
    }

    /**
     * The next confirmed subscribers after $afterId, in id order: the batch
     * the newsletter sends to next.
     *
     * @return array<int, array{id: int, email: string}>
     */
    public function confirmedAfter(int $afterId, int $limit): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, email FROM {$this->table()} WHERE status = %s AND id > %d ORDER BY id ASC LIMIT %d",
                self::STATUS_CONFIRMED,
                $afterId,
                max(1, $limit)
            ),
            ARRAY_A
        );

        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = ['id' => (int) ($row['id'] ?? 0), 'email' => (string) ($row['email'] ?? '')];
        }

        return $out;
    }

    /**
     * Every confirmed subscriber's address, in lower case.
     *
     * @return array<int, string>
     */
    public function confirmedEmails(): array
    {
        global $wpdb;

        $emails = $wpdb->get_col(
            $wpdb->prepare("SELECT email FROM {$this->table()} WHERE status = %s", self::STATUS_CONFIRMED)
        );

        return array_values(array_map('strval', is_array($emails) ? $emails : []));
    }

    public function countConfirmed(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$this->table()} WHERE status = %s", self::STATUS_CONFIRMED)
        );
    }

    /**
     * Deletes the pending sign-ups created before $before (a MySQL datetime)
     * and returns how many rows went.
     */
    public function purgePending(string $before): int
    {
        global $wpdb;

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table()} WHERE status = %s AND created_at < %s",
                self::STATUS_PENDING,
                $before
            )
        );

        return is_int($deleted) ? $deleted : 0;
    }

    /** The address as it is stored: trimmed and in lower case. */
    public static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, email: string, status: string, created_at: string, confirmed_at: ?string}
     */
    private static function shape(array $row): array
    {
        $confirmed = $row['confirmed_at'] ?? null;

        return [
            'id' => (int) ($row['id'] ?? 0),
            'email' => (string) ($row['email'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'confirmed_at' => null === $confirmed || '' === $confirmed ? null : (string) $confirmed,
        ];
    }
}
