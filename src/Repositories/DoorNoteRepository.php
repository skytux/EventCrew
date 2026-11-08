<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;

/**
 * All reads and writes of the door notes table: the name and email the door
 * crew noted for someone who came in on a given-away ticket (role
 * 'receiver') or as an entry's +1 (role 'plus'). kind and ref_id are the
 * entry on that night's door list, the same pair the check-ins table uses.
 *
 * newsletter records that the person asked the door crew for the
 * newsletter. Nothing here sends anything or subscribes anyone: the door
 * desk passes such an address to the newsletter's own double opt-in.
 */
final class DoorNoteRepository
{
    public const ROLE_RECEIVER = 'receiver';
    public const ROLE_PLUS = 'plus';

    private function table(): string
    {
        return Schema::table(Schema::DOOR_NOTES);
    }

    /**
     * Every note on $date, keyed "kind:ref_id", oldest first within an entry.
     *
     * @return array<string, array<int, array{id: int, role: string, name: string, email: string, newsletter: bool}>>
     */
    public function forDate(string $date): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, kind, ref_id, role, name, email, newsletter FROM {$this->table()}
                WHERE event_date = %s ORDER BY id ASC",
                $date
            ),
            ARRAY_A
        );

        $notes = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $notes[(string) $row['kind'] . ':' . (int) $row['ref_id']][] = [
                'id' => (int) $row['id'],
                'role' => (string) $row['role'],
                'name' => (string) $row['name'],
                'email' => (string) ($row['email'] ?? ''),
                'newsletter' => (int) ($row['newsletter'] ?? 0) > 0,
            ];
        }

        return $notes;
    }

    /**
     * Adds one note. $email is '' for none; $newsletter records that they
     * asked for the newsletter. Returns the new row id, 0 when the write
     * failed.
     */
    public function add(
        string $date,
        string $kind,
        int $refId,
        string $role,
        string $name,
        string $email,
        int $addedBy,
        bool $newsletter = false
    ): int {
        global $wpdb;

        $ok = $wpdb->insert(
            $this->table(),
            [
                'event_date' => $date,
                'kind' => $kind,
                'ref_id' => $refId,
                'role' => $role,
                'name' => $name,
                'email' => '' === $email ? null : $email,
                'newsletter' => $newsletter && '' !== $email ? 1 : 0,
                'added_by' => $addedBy > 0 ? $addedBy : null,
                'created_at' => current_time('mysql'),
            ]
        );

        return false === $ok ? 0 : (int) $wpdb->insert_id;
    }

    /**
     * Replaces the name, email and newsletter answer of one note.
     */
    public function change(int $id, string $name, string $email, bool $newsletter = false): bool
    {
        global $wpdb;

        return false !== $wpdb->update(
            $this->table(),
            [
                'name' => $name,
                'email' => '' === $email ? null : $email,
                'newsletter' => $newsletter && '' !== $email ? 1 : 0,
            ],
            ['id' => $id]
        );
    }

    /**
     * Deletes one note of the entry $kind:$refId on $date. The entry is part
     * of the condition, so a note id from another night or entry deletes
     * nothing. Returns true when a row went.
     */
    public function delete(int $id, string $date, string $kind, int $refId): bool
    {
        global $wpdb;

        return (int) $wpdb->delete(
            $this->table(),
            ['id' => $id, 'event_date' => $date, 'kind' => $kind, 'ref_id' => $refId]
        ) > 0;
    }

    /**
     * Notes under this email address, for a personal data export.
     *
     * @return array<int, array{event_date: string, role: string, name: string, email: string, newsletter: bool, created_at: string}>
     */
    public function rowsForEmail(string $email): array
    {
        global $wpdb;

        if ('' === $email) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT event_date, role, name, email, newsletter, created_at FROM {$this->table()}
                WHERE email = %s ORDER BY event_date ASC, id ASC",
                $email
            ),
            ARRAY_A
        );

        return array_map(
            static fn (array $row): array => [
                'event_date' => (string) ($row['event_date'] ?? ''),
                'role' => (string) ($row['role'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'newsletter' => (int) ($row['newsletter'] ?? 0) > 0,
                'created_at' => (string) ($row['created_at'] ?? ''),
            ],
            is_array($rows) ? $rows : []
        );
    }

    /**
     * Deletes every note under this email address, name and all, for a
     * privacy erasure. A note is only a name and an address, so nothing is
     * left to keep. Returns how many rows went.
     */
    public function eraseEmail(string $email): int
    {
        global $wpdb;

        if ('' === $email) {
            return 0;
        }

        $deleted = $wpdb->query(
            $wpdb->prepare("DELETE FROM {$this->table()} WHERE email = %s", $email)
        );

        return false === $deleted ? 0 : (int) $deleted;
    }

    /**
     * Blanks the email of every note from a night before $cutoffDate (Y-m-d),
     * the same period as the guests' addresses; a note left with neither a
     * name nor an email is deleted. Returns how many rows changed.
     */
    public function anonymizeEmailsBefore(string $cutoffDate): int
    {
        global $wpdb;

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table()} WHERE event_date < %s AND name = ''",
                $cutoffDate
            )
        );
        $changed = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table()} SET email = NULL WHERE event_date < %s AND email IS NOT NULL",
                $cutoffDate
            )
        );

        return (false === $deleted ? 0 : (int) $deleted) + (false === $changed ? 0 : (int) $changed);
    }
}
