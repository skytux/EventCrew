<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;
use EventCrew\Support\Language;

/**
 * All reads and writes of the newsletter subscribers table: one row per email
 * address, with its status (pending, confirmed or unsubscribed), the dates it
 * signed up and confirmed, the optional name the person wants to be greeted
 * with, and the language their browser preferred ('fi', 'en' or '').
 */
final class NewsletterSubscriberRepository
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    /** The email column's width; a longer address is refused. */
    public const MAX_EMAIL_LENGTH = 191;

    /** The name column's width; a longer name is cut to this many characters. */
    public const MAX_NAME_LENGTH = 100;

    private function table(): string
    {
        return Schema::table(Schema::NEWSLETTER_SUBSCRIBERS);
    }

    /**
     * The row with this id, or null.
     *
     * @return array{id: int, email: string, status: string, created_at: string, confirmed_at: ?string,
     *     name: ?string, language: string}|null
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
     * @return array{id: int, email: string, status: string, created_at: string, confirmed_at: ?string,
     *     name: ?string, language: string}|null
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
     *
     * $language ('fi', 'en' or '') is stored on a new row, and on an existing
     * row only when that row has no language yet - a choice made on the
     * manage page is not overwritten by a browser.
     */
    public function addPending(string $email, string $language = ''): int
    {
        global $wpdb;

        $email = self::normalize($email);
        $language = Language::normalize($language) ?? '';

        if ('' === $email || strlen($email) > self::MAX_EMAIL_LENGTH) {
            return 0;
        }

        $existing = $this->findByEmail($email);
        $now = current_time('mysql');

        if (null !== $existing) {
            if (self::STATUS_CONFIRMED === $existing['status']) {
                return $existing['id'];
            }

            $data = ['status' => self::STATUS_PENDING, 'created_at' => $now, 'confirmed_at' => null];

            if ('' !== $language && '' === $existing['language']) {
                $data['language'] = $language;
            }

            $wpdb->update(
                $this->table(),
                $data,
                ['id' => $existing['id']],
                array_fill(0, count($data), '%s'),
                ['%d']
            );

            return $existing['id'];
        }

        $data = ['email' => $email, 'status' => self::STATUS_PENDING, 'created_at' => $now];

        if ('' !== $language) {
            $data['language'] = $language;
        }

        $inserted = $wpdb->insert($this->table(), $data, array_fill(0, count($data), '%s'));

        return false !== $inserted && (int) $inserted > 0 ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Subscribes a ticket holder who pressed the subscribe button in their
     * thank-you (or sorry) email. Returns the row's id, or 0 when nothing could
     * be written.
     *
     * The click came from the mailbox the email was sent to, which is the
     * proof a confirmation email would ask for, so the row is confirmed
     * straight away: a new address gets a confirmed row, a pending one is
     * confirmed, and one that had left is subscribed again - the button press
     * is an explicit request to join. A confirmed row stays as it is.
     *
     * The first name and the language only fill a row's empty fields: a name
     * the person typed in themselves is never replaced by the one on a ticket.
     */
    public function addFromTicket(string $email, string $firstName, string $language = ''): int
    {
        global $wpdb;

        $email = self::normalize($email);
        $name = self::cleanName($firstName);
        $language = Language::normalize($language) ?? '';

        if ('' === $email || strlen($email) > self::MAX_EMAIL_LENGTH) {
            return 0;
        }

        $existing = $this->findByEmail($email);
        $now = current_time('mysql');

        if (null === $existing) {
            $data = [
                'email' => $email,
                'status' => self::STATUS_CONFIRMED,
                'created_at' => $now,
                'confirmed_at' => $now,
            ];

            if (null !== $name) {
                $data['name'] = $name;
            }

            if ('' !== $language) {
                $data['language'] = $language;
            }

            $inserted = $wpdb->insert($this->table(), $data, array_fill(0, count($data), '%s'));

            return false !== $inserted && (int) $inserted > 0 ? (int) $wpdb->insert_id : 0;
        }

        $data = [];

        if (self::STATUS_CONFIRMED !== $existing['status']) {
            $data['status'] = self::STATUS_CONFIRMED;
            $data['confirmed_at'] = $now;
        }

        if (null !== $name && null === $existing['name']) {
            $data['name'] = $name;
        }

        if ('' !== $language && '' === $existing['language']) {
            $data['language'] = $language;
        }

        if ([] !== $data) {
            $wpdb->update(
                $this->table(),
                $data,
                ['id' => $existing['id']],
                array_fill(0, count($data), '%s'),
                ['%d']
            );
        }

        return $existing['id'];
    }

    /**
     * Adds a crew member's verified address as a confirmed subscriber, with no
     * email sent. Returns true only when a new row was written.
     *
     * An address that already has a row, in any state, is left as it is: a
     * crew member who left the newsletter stays out, and a pending or
     * confirmed sign-up is not touched.
     */
    public function addCrewMember(string $email): bool
    {
        global $wpdb;

        $email = self::normalize($email);

        if ('' === $email || strlen($email) > self::MAX_EMAIL_LENGTH) {
            return false;
        }

        if (null !== $this->findByEmail($email)) {
            return false;
        }

        $now = current_time('mysql');

        $inserted = $wpdb->insert(
            $this->table(),
            [
                'email' => $email,
                'status' => self::STATUS_CONFIRMED,
                'created_at' => $now,
                'confirmed_at' => $now,
            ],
            ['%s', '%s', '%s', '%s']
        );

        return false !== $inserted && (int) $inserted > 0;
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
     * Marks the row as unsubscribed and forgets the name: someone who left is
     * never greeted again, so there is no reason to keep it. Returns false
     * only when there is no such row.
     */
    public function unsubscribe(int $id): bool
    {
        global $wpdb;

        $row = $this->find($id);

        if (null === $row) {
            return false;
        }

        if (self::STATUS_UNSUBSCRIBED === $row['status'] && null === $row['name']) {
            return true;
        }

        $updated = $wpdb->update(
            $this->table(),
            ['status' => self::STATUS_UNSUBSCRIBED, 'name' => null],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );

        return false !== $updated;
    }

    /**
     * Sets the name the row is greeted with. An empty name (after trimming)
     * clears it. Returns false when the write failed.
     */
    public function setName(int $id, string $name): bool
    {
        global $wpdb;

        if ($id <= 0) {
            return false;
        }

        $updated = $wpdb->update(
            $this->table(),
            ['name' => self::cleanName($name)],
            ['id' => $id],
            ['%s'],
            ['%d']
        );

        return false !== $updated;
    }

    /**
     * Sets the row's language: 'fi', 'en', or '' for not known. Anything else
     * is refused.
     */
    public function setLanguage(int $id, string $language): bool
    {
        global $wpdb;

        $normalized = '' === trim($language) ? '' : Language::normalize($language);

        if ($id <= 0 || null === $normalized) {
            return false;
        }

        $updated = $wpdb->update(
            $this->table(),
            ['language' => $normalized],
            ['id' => $id],
            ['%s'],
            ['%d']
        );

        return false !== $updated;
    }

    /**
     * Deletes the row for this address, whatever its status. Returns how many
     * rows went (0 or 1). Used by the personal-data eraser.
     */
    public function deleteByEmail(string $email): int
    {
        global $wpdb;

        $email = self::normalize($email);

        if ('' === $email) {
            return 0;
        }

        $deleted = $wpdb->query(
            $wpdb->prepare("DELETE FROM {$this->table()} WHERE email = %s", $email)
        );

        return is_int($deleted) ? $deleted : 0;
    }

    /**
     * The next confirmed subscribers after $afterId, in id order: the batch
     * the newsletter sends to next.
     *
     * @return array<int, array{id: int, email: string, name: ?string, language: string}>
     */
    public function confirmedAfter(int $afterId, int $limit): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, email, name, language FROM {$this->table()}"
                    . ' WHERE status = %s AND id > %d ORDER BY id ASC LIMIT %d',
                self::STATUS_CONFIRMED,
                $afterId,
                max(1, $limit)
            ),
            ARRAY_A
        );

        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $name = $row['name'] ?? null;

            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'email' => (string) ($row['email'] ?? ''),
                'name' => null === $name || '' === $name ? null : (string) $name,
                'language' => (string) ($row['language'] ?? ''),
            ];
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

    /**
     * How many confirmed subscribers get the announcement in each language:
     * 'fi' and 'en', and '' for those whose language is not known (they get
     * both languages).
     *
     * @return array{fi: int, en: int, '': int}
     */
    public function countConfirmedByLanguage(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT language, COUNT(*) AS n FROM {$this->table()} WHERE status = %s GROUP BY language",
                self::STATUS_CONFIRMED
            ),
            ARRAY_A
        );
        $counts = ['fi' => 0, 'en' => 0, '' => 0];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $language = (string) ($row['language'] ?? '');
            $counts[in_array($language, ['fi', 'en'], true) ? $language : ''] += (int) ($row['n'] ?? 0);
        }

        return $counts;
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
     * A name as it is stored: no tags or control characters, runs of white
     * space made one space, cut to MAX_NAME_LENGTH characters. Null when
     * nothing is left.
     */
    public static function cleanName(string $name): ?string
    {
        $name = strip_tags($name);
        $name = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ('' === $name) {
            return null;
        }

        return trim(mb_substr($name, 0, self::MAX_NAME_LENGTH));
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, email: string, status: string, created_at: string, confirmed_at: ?string,
     *     name: ?string, language: string}
     */
    private static function shape(array $row): array
    {
        $confirmed = $row['confirmed_at'] ?? null;
        $name = $row['name'] ?? null;

        return [
            'id' => (int) ($row['id'] ?? 0),
            'email' => (string) ($row['email'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'confirmed_at' => null === $confirmed || '' === $confirmed ? null : (string) $confirmed,
            'name' => null === $name || '' === $name ? null : (string) $name,
            'language' => (string) ($row['language'] ?? ''),
        ];
    }
}
