<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Repositories\TicketBuyerRepository;

/**
 * Imports a list of Holvi ticket buyers onto a night's door list - shared by
 * the wp-admin paste box and the import-by-email endpoint.
 */
final class BuyerImport
{
    public function __construct(
        private readonly TicketBuyerRepository $buyers,
        private readonly DoorAccess $access
    ) {
    }

    /**
     * The night a mailed list goes to: the next event date, today included.
     * '' when no event is coming up - DoorAccess falls back to the most recent
     * past night, which is right for reading a door list but never for adding
     * buyers to one.
     */
    public function nextDate(): string
    {
        $date = $this->access->defaultDate();

        if ('' === $date || $date < current_time('Y-m-d')) {
            return '';
        }

        return $date;
    }

    /**
     * Adds the names in $text to $date. A batch already imported for that date
     * adds nothing, so the same mail delivered twice is harmless.
     *
     * @return array{added: int, skipped: int, duplicate: bool}
     */
    public function import(string $date, string $text, string $source, string $batch = ''): array
    {
        $names = BuyerListParser::names($text);

        if ($this->buyers->hasBatch($date, $batch)) {
            return ['added' => 0, 'skipped' => count($names), 'duplicate' => true];
        }

        $added = $this->buyers->addMany($date, $names, $source, $batch);

        return ['added' => $added, 'skipped' => count($names) - $added, 'duplicate' => false];
    }

    /**
     * Adds the tickets of a ticket file (.xlsx or .csv) to $date, one row per
     * ticket. A batch already imported for that date adds nothing.
     *
     * @return array{added: int, skipped: int, duplicate: bool}
     * @throws TicketFileException When the file cannot be read or matches no format.
     */
    public function importFile(string $date, string $filename, string $bytes, string $source, string $batch = ''): array
    {
        $tickets = TicketFormats::tickets(TicketFile::rows($filename, $bytes))['tickets'];

        if ($this->buyers->hasBatch($date, $batch)) {
            return ['added' => 0, 'skipped' => count($tickets), 'duplicate' => true];
        }

        $added = $this->buyers->addTickets($date, $tickets, $source, $batch);

        return ['added' => $added, 'skipped' => count($tickets) - $added, 'duplicate' => false];
    }

    /**
     * The batch id of a mail: a short hash of its Message-ID, or of its text
     * when it has none.
     */
    public static function batchFor(string $messageId, string $text): string
    {
        $basis = '' !== trim($messageId) ? trim($messageId) : $text;

        return substr(hash('sha256', $basis), 0, 16);
    }
}
