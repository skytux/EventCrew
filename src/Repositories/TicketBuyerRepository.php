<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;
use EventCrew\Models\TicketBuyer;

/**
 * All reads and writes of the ticket buyers table: the names imported from the
 * Holvi web shop for one night's door list, and the tickets sold at the door.
 */
final class TicketBuyerRepository
{
    /** The name column's width; a longer name is cut to fit. */
    public const MAX_NAME_LENGTH = 191;

    public const SOURCE_DOOR = TicketBuyer::SOURCE_DOOR;

    public const SOURCE_DESK = TicketBuyer::SOURCE_DESK;

    private function table(): string
    {
        return Schema::table(Schema::TICKET_BUYERS);
    }

    /**
     * Every buyer on $date, alphabetical - the door looks a name up, it does
     * not read the list in import order.
     *
     * @return array<int, TicketBuyer>
     */
    public function forDate(string $date): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE event_date = %s ORDER BY name ASC, id ASC",
                $date
            ),
            ARRAY_A
        );

        return array_map(
            static fn (array $row): TicketBuyer => TicketBuyer::fromRow($row),
            is_array($rows) ? $rows : []
        );
    }

    /**
     * Adds the names to $date and returns how many were added.
     *
     * A name already on that date is skipped, compared case-insensitively and
     * with runs of whitespace counted as one space, so importing an updated
     * list from the shop adds only the new buyers. An empty name is skipped too.
     *
     * @param array<int, string> $names
     */
    public function addMany(string $date, array $names, string $source, string $batch): int
    {
        global $wpdb;

        $seen = [];

        // Door sales are not compared: a name sold at the door can still be
        // a Holvi buyer, and the import must not drop it.
        foreach ($this->forDate($date) as $buyer) {
            if (! $buyer->isDoorSale()) {
                $seen[self::key($buyer->name)] = true;
            }
        }

        $createdAt = current_time('mysql');
        $added = 0;

        foreach ($names as $name) {
            $name = self::clean((string) $name);
            $key = self::key($name);

            if ('' === $name || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $inserted = $wpdb->insert(
                $this->table(),
                [
                    'event_date' => $date,
                    'name' => $name,
                    'source' => $source,
                    'batch' => $batch,
                    'created_at' => $createdAt,
                ],
                ['%s', '%s', '%s', '%s', '%s']
            );

            if (false !== $inserted && (int) $inserted > 0) {
                ++$added;
            }
        }

        return $added;
    }

    /**
     * Adds the tickets read from a ticket file to $date, one row per ticket,
     * and returns how many were added.
     *
     * Two tickets with the same name and ticket code are both kept: a Holvi
     * order of two tickets is two rows. Importing an updated file again adds
     * only what is new: a ticket is skipped while $date already holds as
     * many imported tickets with the same ticket code, name and email as the
     * file has so far. A ticket with neither a name nor a code is skipped.
     *
     * @param array<int, array{name: string, email: string, code: string}> $tickets
     */
    public function addTickets(string $date, array $tickets, string $source, string $batch): int
    {
        global $wpdb;

        $stored = [];

        foreach ($this->forDate($date) as $buyer) {
            if (! $buyer->isDoorSale()) {
                $key = self::ticketKey($buyer->ticketCode, $buyer->name, $buyer->email);
                $stored[$key] = ($stored[$key] ?? 0) + 1;
            }
        }

        $createdAt = current_time('mysql');
        $added = 0;

        foreach ($tickets as $ticket) {
            $name = self::clean((string) ($ticket['name'] ?? ''));
            $email = mb_substr(trim((string) ($ticket['email'] ?? '')), 0, self::MAX_NAME_LENGTH);
            $code = mb_substr(trim((string) ($ticket['code'] ?? '')), 0, self::MAX_NAME_LENGTH);

            if ('' === $name && '' === $code) {
                continue;
            }

            $key = self::ticketKey($code, $name, $email);

            if (($stored[$key] ?? 0) > 0) {
                --$stored[$key];
                continue;
            }

            $inserted = $wpdb->insert(
                $this->table(),
                [
                    'event_date' => $date,
                    'name' => $name,
                    'email' => '' === $email ? null : $email,
                    'ticket_code' => '' === $code ? null : $code,
                    'source' => $source,
                    'batch' => $batch,
                    'created_at' => $createdAt,
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );

            if (false !== $inserted && (int) $inserted > 0) {
                ++$added;
            }
        }

        return $added;
    }

    /** The form two imported tickets are compared in. */
    private static function ticketKey(string $code, string $name, string $email): string
    {
        $email = trim($email);

        return trim($code) . "\n" . self::key($name) . "\n"
            . (function_exists('mb_strtolower') ? mb_strtolower($email) : strtolower($email));
    }

    /**
     * Records one ticket sold at the door on $date and returns its id, or 0
     * when the write failed. The name may be empty, and two tickets may carry
     * the same name: each one is a person paying at the door.
     */
    public function addDoorSale(string $date, string $name): int
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            $this->table(),
            [
                'event_date' => $date,
                'name' => self::clean($name),
                'source' => self::SOURCE_DOOR,
                'batch' => '',
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );

        return false !== $inserted && (int) $inserted > 0 ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Records one Holvi buyer who was missing from the imported list, added by
     * hand at the sales desk, and returns its id, or 0 when the write failed.
     * An empty email is stored as NULL. Nothing is ever sent to the email.
     */
    public function addDeskBuyer(string $date, string $name, string $email): int
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            $this->table(),
            [
                'event_date' => $date,
                'name' => self::clean($name),
                'email' => '' === $email ? null : mb_substr($email, 0, self::MAX_NAME_LENGTH),
                'source' => self::SOURCE_DESK,
                'batch' => '',
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );

        return false !== $inserted && (int) $inserted > 0 ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Whether a mail with this batch id has already been imported for $date.
     */
    public function hasBatch(string $date, string $batch): bool
    {
        global $wpdb;

        if ('' === $batch) {
            return false;
        }

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table()} WHERE event_date = %s AND batch = %s",
                $date,
                $batch
            )
        );

        return (int) $count > 0;
    }

    /**
     * Deletes every imported buyer on $date - the "Reset import" button - and
     * returns how many rows went. Tickets sold at the door stay, and so do
     * Holvi buyers added by hand at the sales desk: no import added them.
     */
    public function clearForDate(string $date): int
    {
        global $wpdb;

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table()} WHERE event_date = %s AND source NOT IN (%s, %s)",
                $date,
                self::SOURCE_DOOR,
                self::SOURCE_DESK
            )
        );

        return false === $deleted ? 0 : (int) $deleted;
    }

    public function countForDate(string $date): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table()} WHERE event_date = %s",
                $date
            )
        );
    }

    /**
     * The name as stored: trimmed, inner whitespace collapsed, cut to the
     * column's width.
     */
    private static function clean(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return mb_substr($name, 0, self::MAX_NAME_LENGTH);
    }

    /** The form two names are compared in. */
    private static function key(string $name): string
    {
        $name = self::clean($name);

        return function_exists('mb_strtolower') ? mb_strtolower($name) : strtolower($name);
    }
}
