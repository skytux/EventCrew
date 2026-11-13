<?php

declare(strict_types=1);

namespace EventCrew\Web;

use Closure;
use EventCrew\Models\Person;
use EventCrew\Models\TicketBuyer;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\BuyerImport;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\TicketFile;
use EventCrew\Support\TicketFileException;
use EventCrew\Support\TicketMail;

/**
 * The organizers' part of the sales desk (the door page): the night actions
 * an organizer also has on the Admin tab's Door screen, at hand at the door.
 *
 * - "Close free entry (sold out)" / "Reopen free entry": the same switch as
 *   CrewAdminRoster::toggleFreeEntry(). Closed, no credit can be spent on
 *   the night (the 🎟 ticket flow, the bot and Redeem refuse); task sign-up
 *   and everything already on the list stay as they are.
 * - The Holvi ticket file (.xlsx or .csv): its tickets go onto the night's
 *   list through BuyerImport::importFile(), the same reader as a ticket file
 *   mailed to the import mailbox. The same file twice adds nothing.
 * - The ticket emails' "Send the rest now", for buyers and for guests, as on
 *   the Door screen.
 *
 * Only organizers: the sales-desk crew never sees these, and every method
 * refuses anyone else on its own, whatever the page sent. The person is the
 * one DoorDesk::session() read fresh for this request.
 */
final class DoorOrganizer
{
    /** The largest ticket file taken, in bytes. A Holvi export is a few kB. */
    public const MAX_FILE_BYTES = 2 * 1024 * 1024;

    /** run()'s operations. */
    public const OP_INFO = 'info';
    public const OP_FREE_ENTRY = 'free_entry';
    public const OP_MAIL_BUYERS = 'mail_buyers';
    public const OP_MAIL_GUESTS = 'mail_guests';

    /** Whether a path is a file PHP received in this request's upload. */
    private readonly Closure $isUploaded;

    public function __construct(
        private readonly FreeEntryGate $freeEntry,
        private readonly BuyerImport $import,
        private readonly TicketBuyerRepository $buyers,
        private readonly ?TicketMail $ticketMail = null,
        ?Closure $isUploaded = null
    ) {
        $this->isUploaded = $isUploaded ?? static fn (string $path): bool => is_uploaded_file($path);
    }

    /**
     * What the organizer section shows for $date: whether free entry is
     * closed and why, how many Holvi tickets are on the list, and the ticket
     * emails' status lines ('' when there is nothing to email).
     *
     * @return array{
     *     closed_by_hand: bool, closed_text: string, toggle: string,
     *     buyers: string, mail_buyers: string, mail_guests: string
     * }
     */
    public function info(string $date): array
    {
        $closedByHand = $this->freeEntry->isClosedByHand($date);
        $reason = $closedByHand ? '' : $this->freeEntry->eventReason($date);
        $count = 0;

        foreach ($this->buyers->forDate($date) as $buyer) {
            if ($buyer instanceof TicketBuyer && ! $buyer->isDoorSale()) {
                ++$count;
            }
        }

        $closedText = '';

        if ($closedByHand) {
            $closedText = __('Free entry is closed for this date — no credits can be spent on it.', 'eventcrew');
        } elseif ('' !== $reason) {
            $reasons = [
                'unpublished' => __('is not published (removed from the shop)', 'eventcrew'),
                'disabled' => __('is disabled', 'eventcrew'),
                'hidden' => __('is hidden', 'eventcrew'),
                'sold_out' => __('is sold out', 'eventcrew'),
            ];
            $closedText = sprintf(
                /* translators: %s: the reason, e.g. "is sold out". */
                __('No credits can be spent on this date: the linked event %s. Task sign-up stays open.', 'eventcrew'),
                $reasons[$reason] ?? $reason
            );
        }

        return [
            'closed_by_hand' => $closedByHand,
            'closed_text' => $closedText,
            'toggle' => $closedByHand
                ? __('Reopen free entry', 'eventcrew')
                : __('Close free entry (sold out)', 'eventcrew'),
            'buyers' => sprintf(
                /* translators: %d: number of imported ticket buyers */
                _n(
                    '%d person bought a ticket in Holvi for this date.',
                    '%d people bought a ticket in Holvi for this date.',
                    $count,
                    'eventcrew'
                ),
                $count
            ),
            'mail_buyers' => null === $this->ticketMail
                ? ''
                : TicketMail::statusLine($this->ticketMail->counts($date, TicketMail::KIND_BUYERS)),
            'mail_guests' => null === $this->ticketMail
                ? ''
                : TicketMail::statusLine($this->ticketMail->counts($date, TicketMail::KIND_GUESTS)),
        ];
    }

    /**
     * One of the night actions. 'ok' false with 'refused' true: $person is
     * not an organizer, and nothing changed.
     *
     * @return array{ok: bool, refused: bool, message: string}
     */
    public function run(Person $person, string $date, string $op): array
    {
        if (! $person->isOrganizer) {
            return self::refused();
        }

        switch ($op) {
            case self::OP_INFO:
                return ['ok' => true, 'refused' => false, 'message' => ''];

            case self::OP_FREE_ENTRY:
                if ($this->freeEntry->isClosedByHand($date)) {
                    $this->freeEntry->open($date);
                    $code = 'admin_door_reopened';
                } else {
                    $this->freeEntry->close($date);
                    $code = 'admin_door_closed_now';
                }

                return ['ok' => true, 'refused' => false, 'message' => CrewAdminRoster::noticeText($code)];

            case self::OP_MAIL_BUYERS:
            case self::OP_MAIL_GUESTS:
                $send = TicketMail::scheduleRest(
                    $date,
                    self::OP_MAIL_GUESTS === $op ? TicketMail::KIND_GUESTS : TicketMail::KIND_BUYERS
                );

                return [
                    'ok' => true,
                    'refused' => false,
                    'message' => CrewAdminRoster::noticeText(
                        sprintf('admin_tickets_queued_%d_%d', $send['at'], $send['started'] ? 1 : 0)
                    ),
                ];
        }

        return ['ok' => false, 'refused' => false, 'message' => CrewAdminRoster::noticeText('admin_change_failed')];
    }

    /**
     * The Holvi ticket file an organizer picked on the page: one entry of
     * $_FILES (name, tmp_name, size, error). Its tickets go onto $date's
     * list; 'added' is how many. 'code' is the same result as a Crew app
     * Admin notice code (CrewAdminRoster::noticeText()), for the Admin Door
     * screen's upload.
     *
     * @param array<string, mixed> $file
     * @return array{ok: bool, refused: bool, added: int, message: string, code: string}
     */
    public function importUpload(Person $person, string $date, array $file): array
    {
        if (! $person->isOrganizer) {
            return self::refused() + ['added' => 0, 'code' => 'admin_buyers_file_failed'];
        }

        $name = is_string($file['name'] ?? null) ? sanitize_file_name((string) $file['name']) : '';
        $path = is_string($file['tmp_name'] ?? null) ? (string) $file['tmp_name'] : '';
        $size = is_numeric($file['size'] ?? null) ? (int) $file['size'] : 0;
        $error = is_numeric($file['error'] ?? null) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

        if (UPLOAD_ERR_NO_FILE === $error || ('' === $name && '' === $path)) {
            return self::failed(__('Pick the ticket file first.', 'eventcrew'));
        }

        if (! TicketFile::isTicketFile($name)) {
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            return self::failed(__('That is not an .xlsx or .csv file. Pick the ticket file exported from Holvi.', 'eventcrew'));
        }

        if (
            in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            || $size > self::MAX_FILE_BYTES
        ) {
            return self::failed(__('That file is too big for a ticket file.', 'eventcrew'));
        }

        if (UPLOAD_ERR_OK !== $error || '' === $path || $size <= 0 || ! ($this->isUploaded)($path)) {
            return self::failed(__('The file did not arrive. Try again.', 'eventcrew'));
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local upload, not a URL.
        return $this->importBytes($person, $date, $name, (string) file_get_contents($path));
    }

    /**
     * The ticket file's bytes added for $date, as the upload adds them: the
     * upload above, and a file sent to the bot (ParityCommands, parityB).
     *
     * @return array{ok: bool, refused: bool, added: int, message: string, code: string}
     */
    public function importBytes(Person $person, string $date, string $name, string $bytes): array
    {
        if (! $person->isOrganizer) {
            return self::refused() + ['added' => 0, 'code' => 'admin_buyers_file_failed'];
        }

        if (! TicketFile::isTicketFile($name)) {
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            return self::failed(__('That is not an .xlsx or .csv file. Pick the ticket file exported from Holvi.', 'eventcrew'));
        }

        if ('' === $bytes) {
            return self::failed(__('The file did not arrive. Try again.', 'eventcrew'));
        }

        if (strlen($bytes) > self::MAX_FILE_BYTES) {
            return self::failed(__('That file is too big for a ticket file.', 'eventcrew'));
        }

        try {
            $result = $this->import->importFile(
                $date,
                $name,
                $bytes,
                'upload',
                BuyerImport::batchFor('', $bytes)
            );
        } catch (TicketFileException $exception) {
            return self::failed($exception->getMessage());
        }

        if ($result['duplicate']) {
            return [
                'ok' => true,
                'refused' => false,
                'added' => 0,
                'message' => __('This file was already added for this night. Nothing changed.', 'eventcrew'),
                'code' => 'admin_buyers_file_same',
            ];
        }

        $code = sprintf('admin_buyers_added_%d_%d', $result['added'], $result['skipped']);

        return [
            'ok' => true,
            'refused' => false,
            'added' => $result['added'],
            'message' => CrewAdminRoster::noticeText($code),
            'code' => $code,
        ];
    }

    /** @return array{ok: bool, refused: bool, message: string} */
    private static function refused(): array
    {
        return [
            'ok' => false,
            'refused' => true,
            'message' => __('Only organizers can do that.', 'eventcrew'),
        ];
    }

    /** @return array{ok: bool, refused: bool, added: int, message: string, code: string} */
    private static function failed(string $message): array
    {
        return [
            'ok' => false,
            'refused' => false,
            'added' => 0,
            'message' => $message,
            'code' => 'admin_buyers_file_failed',
        ];
    }
}
