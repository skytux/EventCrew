<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;
use EventCrew\Models\TicketBuyer;

/**
 * Reads of the Holvi ticket buyers table. The door page only needs one night's
 * names; the import that fills the table lives elsewhere.
 */
final class TicketBuyerRepository
{
    private function table(): string
    {
        return Schema::table(Schema::TICKET_BUYERS);
    }

    /**
     * Every buyer on $date, ordered by name so the door can scan the list.
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
}
