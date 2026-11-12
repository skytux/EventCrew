<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;

/**
 * All reads and writes of the push subscriptions table: the devices that
 * turned on app notifications (Web Push), several per person.
 *
 * A row is one browser on one device, found by its endpoint (the push
 * service's address for it). The same browser signing in as someone else
 * moves the row to that person, so a shared phone never gets the previous
 * person's notifications.
 */
final class PushSubscriptionRepository
{
    /** Devices kept per person; the oldest go when a person adds more. */
    public const MAX_PER_PERSON = 10;

    private function table(): string
    {
        return Schema::table(Schema::PUSH_SUBSCRIPTIONS);
    }

    /**
     * The person's devices, newest first.
     *
     * @return list<array{id: int, endpoint: string, p256dh: string, auth: string, device: string}>
     */
    public function forPerson(int $personId): array
    {
        global $wpdb;

        if ($personId <= 0) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, endpoint, p256dh, auth, device FROM {$this->table()}
                WHERE person_id = %d ORDER BY id DESC",
                $personId
            ),
            ARRAY_A
        );

        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'endpoint' => (string) $row['endpoint'],
                'p256dh' => (string) $row['p256dh'],
                'auth' => (string) $row['auth'],
                'device' => (string) ($row['device'] ?? ''),
            ];
        }

        return $out;
    }

    /** Whether the person has at least one device with app notifications on. */
    public function hasAny(int $personId): bool
    {
        global $wpdb;

        if ($personId <= 0) {
            return false;
        }

        $id = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$this->table()} WHERE person_id = %d LIMIT 1", $personId)
        );

        return null !== $id && (int) $id > 0;
    }

    /**
     * Adds a device, or brings an endpoint already stored up to date (new
     * keys, or another person signed in on that browser). Then the person's
     * oldest devices past MAX_PER_PERSON go. Returns whether it was stored.
     */
    public function save(int $personId, string $endpoint, string $p256dh, string $auth, string $device): bool
    {
        global $wpdb;

        if ($personId <= 0 || '' === $endpoint) {
            return false;
        }

        $now = (string) current_time('mysql');
        $ok = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$this->table()}
                    (person_id, endpoint, endpoint_hash, p256dh, auth, device, created_at)
                VALUES (%d, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE person_id = VALUES(person_id), endpoint = VALUES(endpoint),
                    p256dh = VALUES(p256dh), auth = VALUES(auth), device = VALUES(device)",
                $personId,
                $endpoint,
                self::hash($endpoint),
                $p256dh,
                $auth,
                $device,
                $now
            )
        );

        if (false === $ok) {
            return false;
        }

        $this->keepNewest($personId);

        return true;
    }

    /** Marks a send the push service accepted. */
    public function markOk(int $id): void
    {
        global $wpdb;

        $wpdb->update($this->table(), ['last_ok_at' => current_time('mysql')], ['id' => $id]);
    }

    /** Deletes one device by its row id (the push service said it is gone). */
    public function delete(int $id): void
    {
        global $wpdb;

        $wpdb->delete($this->table(), ['id' => $id]);
    }

    /**
     * Deletes one device by its endpoint - only the person's own when
     * $personId is given (Turn off on the crew page).
     */
    public function deleteEndpoint(string $endpoint, int $personId = 0): bool
    {
        global $wpdb;

        $where = ['endpoint_hash' => self::hash($endpoint)];

        if ($personId > 0) {
            $where['person_id'] = $personId;
        }

        return (int) $wpdb->delete($this->table(), $where) > 0;
    }

    /** Every device of a person, for erasure. Returns how many went. */
    public function deleteForPerson(int $personId): int
    {
        global $wpdb;

        if ($personId <= 0) {
            return 0;
        }

        return (int) $wpdb->delete($this->table(), ['person_id' => $personId]);
    }

    public static function hash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    private function keepNewest(int $personId): void
    {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$this->table()} WHERE person_id = %d ORDER BY id DESC LIMIT 1000 OFFSET %d",
                $personId,
                self::MAX_PER_PERSON
            )
        );

        foreach (is_array($ids) ? $ids : [] as $id) {
            $wpdb->delete($this->table(), ['id' => (int) $id]);
        }
    }
}
