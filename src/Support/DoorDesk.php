<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CheckinRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Telegram\TicketRedemptionService;

/**
 * The door page's logic: who is on tonight's list, who has come in, and what
 * the door crew can do - mark someone in or out, change how many people they
 * brought, and "Add": a ticket sold at the door, a Holvi buyer missing from
 * the imported list, a guest, or a member, who
 * either spends a credit or is signed up for one of tonight's tasks. A member
 * who already spent a credit on tonight can still take a task: their ticket
 * is then cancelled and the credit comes back. The "+1" count adds people let
 * in without a name, such as the organizers themselves.
 *
 * Everything here takes plain values and returns plain arrays, so it is
 * testable without a request; Web\DoorController is the thin HTTP layer on top.
 */
final class DoorDesk
{
    /** Most members the member search lists. */
    private const SEARCH_LIMIT = 20;

    /** Shortest search the member search answers. */
    public const SEARCH_MIN = 2;

    /** workTonight() answers. */
    public const WORK_OK = 'ok';
    public const WORK_SWAPPED = 'swapped';
    public const WORK_FULL = 'full';
    public const WORK_UNAVAILABLE = 'unavailable';
    public const WORK_CREDIT_QUESTION = 'credit_question';

    /** addHolviBuyer() answers. */
    public const HOLVI_OK = 'ok';
    public const HOLVI_BAD_EMAIL = 'bad_email';
    public const HOLVI_FAILED = 'failed';

    /** addGuest() answers. */
    public const GUEST_OK = 'ok';
    public const GUEST_NO_NAME = 'no_name';
    public const GUEST_BAD_EMAIL = 'bad_email';
    public const GUEST_FAILED = 'failed';

    /** scan() answers. */
    public const SCAN_OK = 'ok';
    public const SCAN_OTHER_NIGHT = 'other_night';
    public const SCAN_DISABLED = 'disabled';
    public const SCAN_NOT_ON_LIST = 'not_on_list';
    public const SCAN_NOT_OURS = 'not_ours';
    public const SCAN_TOO_EARLY = 'too_early';
    public const SCAN_CLOSED = 'closed';

    /** Most ticket codes one scan may look up (one per ticket format). */
    public const TICKET_CODES_MAX = 10;

    /**
     * The option that holds one night's count of people let in without a
     * name (the "+1" button), followed by the date (YYYY-MM-DD).
     */
    public const ANONYMOUS_OPTION_PREFIX = 'eventcrew_door_anonymous_';

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly PersonRepository $people,
        private readonly RedemptionRepository $redemptions,
        private readonly GuestRepository $guests,
        private readonly TicketBuyerRepository $buyers,
        private readonly CheckinRepository $checkins,
        private readonly AuthTokenRepository $tokens,
        private readonly StandingCalculator $standing,
        private readonly TicketRedemptionService $tickets,
        private readonly SalesDeskGate $gate,
        private readonly CreditGrantRepository $grants,
        private readonly ?ClaimNotifier $notifier = null,
        private readonly ?TicketMail $ticketMail = null
    ) {
    }

    /**
     * Spends a door login link. Answers the cookie value it buys and how many
     * seconds that cookie should live, or a null cookie and the sentence to
     * show instead: the link is used, expired or forged, or the night has
     * closed for this person. The signature is checked before the token is
     * touched, so a link with a swapped date cannot burn the real one.
     *
     * @return array{cookie: ?string, seconds: int, message: string}
     */
    public function login(string $rawToken, string $date, string $signature): array
    {
        $used = [
            'cookie' => null,
            'seconds' => 0,
            'message' => __(
                'This link was already used — ask an organizer for a new one (/door in Telegram, then 💳 Sales desk).',
                'eventcrew'
            ),
        ];

        if (! DoorSession::isDate($date) || ! DoorSession::verifyLink($rawToken, $date, $signature)) {
            return $used;
        }

        // Spent by one conditional UPDATE, so the link opened twice at the same
        // moment buys only one door cookie.
        $personId = $this->tokens->consumeOnce($rawToken, DoorSession::TOKEN_PURPOSE);
        $handoff = false;

        // Not an ordinary door link: maybe one made on the sales desk page
        // ("Other device"), which is spent by one atomic write.
        if (null === $personId) {
            $personId = $this->tokens->consumeOnce($rawToken, DoorSession::HANDOFF_PURPOSE);
            $handoff = null !== $personId;
        }

        $person = null === $personId ? null : $this->people->find($personId);

        if (null === $person) {
            return $used;
        }

        // A link made on the page logs in only someone who may run the door
        // right now, asked again at the moment it is spent.
        $code = $handoff ? $this->gate->check($person, $date) : SalesDeskGate::OK;

        if (SalesDeskGate::OK !== $code) {
            return ['cookie' => null, 'seconds' => 0, 'message' => SalesDeskGate::message($code)];
        }

        $seconds = $this->gate->secondsLeft($person, $date);

        if ($seconds <= 0) {
            return ['cookie' => null, 'seconds' => 0, 'message' => $this->gate->message(SalesDeskGate::CLOSED)];
        }

        return ['cookie' => DoorSession::mint($person->id, $date), 'seconds' => $seconds, 'message' => ''];
    }

    /**
     * The person and night a door cookie stands for, or null when the cookie
     * is not valid, or the person has since been deleted, blocked or erased,
     * or is no longer on the night's crew (SalesDeskGate::stillOnDoor()).
     * The person is read fresh on every request the page makes, so any of
     * these ends the session at once. 'open' says whether the session still
     * works right now: an organizer keeps the night until 06:00 while a crew
     * member on a sales-desk role loses it when the event ends.
     *
     * @return array{person: Person, date: string, open: bool}|null
     */
    public function session(string $cookie): ?array
    {
        $read = DoorSession::read($cookie);

        if (null === $read) {
            return null;
        }

        $person = $this->people->find($read['person_id']);

        if (null === $person || $person->isBanned() || $person->isAnonymized()) {
            return null;
        }

        if (! $this->gate->stillOnDoor($person, $read['date'])) {
            return null;
        }

        return [
            'person' => $person,
            'date' => $read['date'],
            'open' => $this->gate->sessionOpen($person, $read['date']),
        ];
    }

    /** The sentence the page shows once a session's night has closed. */
    public function closedText(): string
    {
        return $this->gate->message(SalesDeskGate::CLOSED);
    }

    /**
     * The whole page state for one night: the five sections, the counter, and
     * a version hash the page compares to decide whether to redraw. A guest
     * counts as expected with all the people they are allowed to bring, since
     * those may still come; the "in" side counts only who did. The people
     * counted in without a name ('anonymous') are added to both sides.
     *
     * @return array{
     *     date: string, day: string, in: int, expected: int, anonymous: int, max: int, over_allowed: bool,
     *     version: string,
     *     sections: array<int, array{key: string, title: string, rows: array<int, array{
     *         key: string, kind: string, ref: int, name: string, first: string, detail: string, short: string,
     *         plus: int, max: int, in: bool, at: string
     *     }>}>
     * }
     */
    public function state(string $date): array
    {
        $checkins = $this->checkins->forDate($date);
        [$crew, $credit] = $this->entrants($date);
        $guests = $this->guestRows($date);
        [$holvi, $door] = $this->ticketRows($date);

        $sections = [
            $this->section('crew', __('Crew working', 'eventcrew'), $crew, $checkins),
            $this->section('credit', __('Free-entry credit', 'eventcrew'), $credit, $checkins),
            $this->section('guest', __('Guests', 'eventcrew'), $guests, $checkins),
            $this->section('buyer', __('Holvi tickets', 'eventcrew'), $holvi, $checkins),
            $this->section('door', __('Door tickets', 'eventcrew'), $door, $checkins),
        ];

        $in = 0;
        $expected = 0;

        foreach ($sections as $section) {
            foreach ($section['rows'] as $row) {
                $allowed = CheckinRepository::KIND_GUEST === $row['kind'] ? $row['max'] : 0;
                $expected += 1 + max($row['plus'], $allowed);
                $in += $row['in'] ? 1 + $row['plus'] : 0;
            }
        }

        $anonymous = $this->anonymousCount($date);

        $state = [
            'date' => $date,
            'day' => Dates::dayLabel($date),
            'in' => $in + $anonymous,
            'expected' => $expected + $anonymous,
            'anonymous' => $anonymous,
            // The most people allowed in (0: no max) and whether the desk may
            // go over it with a warning (DoorQuota).
            'max' => DoorQuota::max(),
            'over_allowed' => DoorQuota::allowsOver(),
            'sections' => $sections,
            // "Ticket emails go out at 21:40 to 1 guest." while the ticket
            // email's 10-minute send waits, else ''. The page shows it with
            // "Cancel sending".
            'sending' => $this->sendingLine(),
        ];

        $state['version'] = substr(hash('sha256', (string) wp_json_encode($state)), 0, 16);

        return $state;
    }

    /**
     * Marks one entry in or out. Marking a crew member in also turns their
     * signed-up assignments that night into "arrived", recorded as changed by
     * the person holding the door session; marking them out again does not
     * undo that, since attendance is its own record. Returns false when the
     * entry is not on tonight's list. The plus-ones are capped at what the
     * entry may bring.
     */
    public function mark(string $date, int $doorPersonId, string $kind, int $refId, bool $in, int $plusOnes): bool
    {
        $row = $this->findRow($date, $kind, $refId);

        if (null === $row) {
            return false;
        }

        $plusOnes = min($plusOnes, $row['max']);

        if (! $in) {
            $this->checkins->unmark($date, $kind, $refId);

            if (CheckinRepository::KIND_CREW === $kind) {
                $this->markCrewSignedOut($date, $refId, $doorPersonId);
            }

            return true;
        }

        $this->checkins->mark($date, $kind, $refId, $plusOnes, $doorPersonId);

        if (CheckinRepository::KIND_CREW === $kind) {
            $this->markCrewArrived($date, $refId, $doorPersonId);
        }

        return true;
    }

    /**
     * Changes the plus-ones of an entry already marked in. An entry not in
     * keeps its number on the page until it is marked, which sends it along.
     */
    public function setPlusOnes(string $date, string $kind, int $refId, int $plusOnes): bool
    {
        $row = $this->findRow($date, $kind, $refId);

        if (null === $row) {
            return false;
        }

        $this->checkins->setPlusOnes($date, $kind, $refId, min($plusOnes, $row['max']));

        return true;
    }

    /**
     * Records a ticket sold at the door, already marked in. The name may be
     * empty. Returns false when the write failed.
     */
    public function addDoorSale(string $date, int $doorPersonId, string $name): bool
    {
        $id = $this->buyers->addDoorSale($date, $name);

        if ($id <= 0) {
            return false;
        }

        $this->checkins->mark($date, CheckinRepository::KIND_BUYER, $id, 0, $doorPersonId);

        return true;
    }

    /**
     * Records a Holvi buyer who is missing from the imported list, already
     * marked in. It is listed with the Holvi buyers and is not a door sale.
     * The name and the email are both optional; with no name, a placeholder
     * is stored. With an email address, the buyer gets a ticket code
     * (TicketMail::deskBuyerCode()) and is emailed their ticket by the next
     * run of the ticket email's batch, like an imported buyer.
     * Answers a HOLVI_* code.
     */
    public function addHolviBuyer(string $date, int $doorPersonId, string $name, string $email): string
    {
        $email = trim($email);

        if ('' !== $email) {
            if (! is_email($email)) {
                return self::HOLVI_BAD_EMAIL;
            }

            $email = sanitize_email($email);
        }

        $name = trim($name);

        if ('' === $name) {
            $name = __('Holvi buyer (no name)', 'eventcrew');
        }

        $id = $this->buyers->addDeskBuyer($date, $name, $email);

        if ($id <= 0) {
            return self::HOLVI_FAILED;
        }

        $this->checkins->mark($date, CheckinRepository::KIND_BUYER, $id, 0, $doorPersonId);

        if ('' !== $email) {
            $this->buyers->setTicketCode($id, TicketMail::deskBuyerCode($id));
            TicketMail::schedule();
        }

        return self::HOLVI_OK;
    }

    /**
     * A plain sentence for an addHolviBuyer() answer.
     */
    public function holviBuyerText(string $code): string
    {
        return match ($code) {
            self::HOLVI_OK => __('Holvi buyer added and checked in.', 'eventcrew'),
            self::HOLVI_BAD_EMAIL => __('That email address is not valid. Correct it or leave it empty.', 'eventcrew'),
            default => __('The Holvi buyer could not be added. Try again.', 'eventcrew'),
        };
    }

    /**
     * Reads what the camera found in a QR code at the desk. A ticket is the
     * signed ticket link (or its bare token) of a sign-up or of a spent
     * free-entry credit, or a guest's emailed ticket: the short signed code in
     * its QR, or its ticket page link. A crew member's or a desk buyer's
     * emailed ticket holds a short signed code too (TicketMail::crewCode(),
     * creditCode(), deskBuyerCode()). On SCAN_OK, key is the row on tonight's list
     * ("crew:8", "credit:8"), for the page to show with its +1 counter and
     * "Mark arrived" button; nothing is marked here. The other answers carry
     * the ticket holder's name and the ticket's night when they are known.
     *
     * @return array{code: string, key: string, name: string, date: string, at: string}
     */
    public function scan(string $date, string $text): array
    {
        // A guest's emailed ticket holds a short signed code (TicketMail)
        // with the guest's ticketRev in it.
        $parts = TicketMail::guestCodeParts($text);

        if (null !== $parts) {
            return $this->scanGuest($date, $parts['id'], $parts['rev']);
        }

        // A crew member's emailed ticket: a sign-up or a spent credit. Either
        // way the person is checked in by their row on tonight's list, crew
        // when they work tonight, otherwise credit.
        $assignmentId = TicketMail::crewIdFromCode($text);

        if (null !== $assignmentId) {
            return $this->scanAssignment($date, $assignmentId);
        }

        $redemptionId = TicketMail::creditIdFromCode($text);

        if (null !== $redemptionId) {
            return $this->scanRedemption($date, $redemptionId);
        }

        $buyerId = TicketMail::deskBuyerIdFromCode($text);

        if (null !== $buyerId) {
            return $this->scanDeskBuyer($date, $buyerId, trim($text));
        }

        $token = self::tokenFrom($text);

        if ('' !== $token) {
            $assignmentId = SignedLink::verify('ticket', $token);

            if (null !== $assignmentId) {
                return $this->scanAssignment($date, $assignmentId);
            }

            $redemptionId = SignedLink::verify('credit_ticket', $token);

            if (null !== $redemptionId) {
                return $this->scanRedemption($date, $redemptionId);
            }

            $parts = TicketMail::guestTokenParts($token);

            if (null !== $parts) {
                return $this->scanGuest($date, $parts['id'], $parts['rev']);
            }
        }

        return self::scanAnswer(self::SCAN_NOT_OURS);
    }

    /**
     * A guest's emailed ticket (TicketMail): the guest's row on the guest
     * list. A guest who was removed from the list, or whose email address was
     * deleted, no longer has a ticket. Nor does a code from before an
     * organizer changed the guest's address: $rev, the ticketRev it was
     * signed with, is older than the guest's.
     *
     * @return array{code: string, key: string, name: string, date: string, at: string}
     */
    private function scanGuest(string $date, int $guestId, int $rev): array
    {
        $guest = $this->guests->find($guestId);

        if (null === $guest || '' === $guest->email || $rev !== $guest->ticketRev) {
            return self::scanAnswer(self::SCAN_DISABLED, '', null === $guest ? '' : $guest->name);
        }

        if ($guest->eventDate !== $date) {
            return self::scanAnswer(self::SCAN_OTHER_NIGHT, '', $guest->name, $guest->eventDate);
        }

        return $this->inScanWindow($date)
            ?? self::scanAnswer(self::SCAN_OK, CheckinRepository::KIND_GUEST . ':' . $guest->id, $guest->name, $date);
    }

    /**
     * A desk buyer's emailed ticket (TicketMail::deskBuyerCode()): the buyer's
     * row on the Holvi list. The row must still carry this code; a row that
     * was deleted or lost its code no longer has a ticket.
     *
     * @return array{code: string, key: string, name: string, date: string, at: string}
     */
    private function scanDeskBuyer(string $date, int $buyerId, string $code): array
    {
        $buyer = $this->buyers->find($buyerId);

        if (null === $buyer || $buyer->ticketCode !== $code) {
            return self::scanAnswer(self::SCAN_DISABLED, '', null === $buyer ? '' : $buyer->name);
        }

        if ($buyer->eventDate !== $date) {
            return self::scanAnswer(self::SCAN_OTHER_NIGHT, '', $buyer->name, $buyer->eventDate);
        }

        return $this->inScanWindow($date)
            ?? self::scanAnswer(self::SCAN_OK, CheckinRepository::KIND_BUYER . ':' . $buyer->id, $buyer->name, $date);
    }

    /**
     * The line the desk shows for one of scan()'s answers other than SCAN_OK.
     */
    public function scanText(string $code, string $name, string $date, string $at = ''): string
    {
        $who = '' === $name ? __('This ticket', 'eventcrew') : $name;

        return match ($code) {
            self::SCAN_OTHER_NIGHT => '' === $date
                ? sprintf(
                    /* translators: %s: the ticket holder's name */
                    __('%s: this ticket is not for tonight.', 'eventcrew'),
                    $who
                )
                : sprintf(
                    /* translators: 1: the ticket holder's name, 2: the day the ticket is for */
                    __('%1$s: this ticket is for %2$s, not tonight.', 'eventcrew'),
                    $who,
                    Dates::dayLabel($date)
                ),
            self::SCAN_DISABLED => sprintf(
                /* translators: %s: the ticket holder's name */
                __('%s: this ticket is cancelled.', 'eventcrew'),
                $who
            ),
            self::SCAN_NOT_ON_LIST => sprintf(
                /* translators: %s: the ticket holder's name */
                __('%s is not on tonight’s list.', 'eventcrew'),
                $who
            ),
            self::SCAN_TOO_EARLY => sprintf(
                /* translators: %s: a clock time, e.g. 19:00 */
                __('Ticket scanning opens at %s.', 'eventcrew'),
                substr($at, 11, 5)
            ),
            self::SCAN_CLOSED => sprintf(
                /* translators: %s: a clock time, e.g. 02:00 */
                __('Ticket scanning closed at %s.', 'eventcrew'),
                substr($at, 11, 5)
            ),
            self::SCAN_OK => '',
            default => __('This QR code is not one of our tickets.', 'eventcrew'),
        };
    }

    /**
     * The tickets on tonight's list that carry one of these ticket codes:
     * read from a QR code the desk scanned and decoded on the page. A Holvi
     * code belongs to one ticket; a ticket file of another format may repeat
     * a code, so this can answer several. Each answer is the row's key on
     * tonight's list ("buyer:12"), the name, whether that ticket is
     * already checked in, and at what time ('HH:MM', '' when not in).
     * Nothing is marked here: the desk checks the tickets in one at a time
     * through mark().
     *
     * @param array<int, string> $codes
     * @return array<int, array{key: string, kind: string, ref: int, name: string, first: string, short: string, in: bool, at: string}>
     */
    public function ticketsByCode(string $date, array $codes): array
    {
        $tickets = [];
        $checkins = null;

        foreach (array_slice(array_values($codes), 0, self::TICKET_CODES_MAX) as $code) {
            foreach ($this->buyers->findByTicketCode($date, (string) $code) as $buyer) {
                $key = CheckinRepository::KIND_BUYER . ':' . $buyer->id;

                if (isset($tickets[$key])) {
                    continue;
                }

                $checkins ??= $this->checkins->forDate($date);
                $tickets[$key] = [
                    'key' => $key,
                    'kind' => CheckinRepository::KIND_BUYER,
                    'ref' => $buyer->id,
                    'name' => '' === $buyer->name ? __('No name', 'eventcrew') : $buyer->name,
                    'first' => self::firstName($buyer->name),
                    'short' => ShortCode::forBuyer($buyer->id),
                    'in' => array_key_exists($key, $checkins),
                    'at' => self::inTime($checkins[$key]['at'] ?? ''),
                ];
            }
        }

        return array_values($tickets);
    }

    /**
     * The first word of a name, for the desk's "Welcome" line: the list keeps
     * the full name, the greeting uses only the first name. '' for no name.
     */
    public static function firstName(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), 2);

        return is_array($words) ? (string) $words[0] : '';
    }

    /** The line the desk shows when a scanned ticket code matches no ticket tonight. */
    public function noTicketCodeText(): string
    {
        return __('No ticket on tonight’s list has this code.', 'eventcrew');
    }

    /**
     * The ticket code decoders the door page runs on a scanned QR code that
     * is not one of our own tickets: the decoder_js of each ticket format, as
     * written by an organizer. An empty string stands for a format without a
     * decoder, whose ticket code is the scanned text itself. Each one is
     * listed once.
     *
     * @return array<int, string>
     */
    public static function ticketDecoders(): array
    {
        $decoders = [];

        foreach (TicketFormats::all() as $format) {
            $decoder = trim((string) ($format['decoder_js'] ?? ''));

            if (! in_array($decoder, $decoders, true)) {
                $decoders[] = $decoder;
            }
        }

        return $decoders;
    }

    /**
     * The token in a scanned ticket link, or the scanned text itself when it
     * is not a link with a query string.
     */
    private static function tokenFrom(string $text): string
    {
        $text = trim($text);
        $query = parse_url($text, PHP_URL_QUERY);

        if (is_string($query) && '' !== $query) {
            parse_str($query, $args);

            return isset($args['token']) && is_string($args['token']) ? trim($args['token']) : '';
        }

        return $text;
    }

    /**
     * @return array{code: string, key: string, name: string, date: string, at: string}
     */
    private function scanAssignment(string $date, int $assignmentId): array
    {
        $assignment = $this->assignments->find($assignmentId);
        $task = null === $assignment ? null : $this->tasks->find($assignment->taskId);

        // The sign-up or its task is gone: the ticket no longer stands for
        // anything.
        if (null === $assignment || null === $task) {
            return self::scanAnswer(self::SCAN_DISABLED);
        }

        $name = $this->nameOf($assignment->personId);

        if ($task->taskDate !== $date) {
            return self::scanAnswer(self::SCAN_OTHER_NIGHT, '', $name, $task->taskDate);
        }

        // One ticket per person per night: the code of a task they no longer
        // hold still gets them in while they hold another task that night
        // (TicketMail sends the same code again with the new task list).
        if (! $assignment->isOccupying() && ! $this->assignments->holdsTaskOn($assignment->personId, $date)) {
            return self::scanAnswer(self::SCAN_DISABLED, '', $name, $date);
        }

        return $this->scanPerson($date, $assignment->personId, $name, CheckinRepository::KIND_CREW);
    }

    /**
     * @return array{code: string, key: string, name: string, date: string, at: string}
     */
    private function scanRedemption(string $date, int $redemptionId): array
    {
        $redemption = $this->redemptions->find($redemptionId);

        if (null === $redemption || null !== $redemption->cancelledAt) {
            return self::scanAnswer(
                self::SCAN_DISABLED,
                '',
                null === $redemption ? '' : $this->nameOf($redemption->personId)
            );
        }

        $name = $this->nameOf($redemption->personId);

        if ($redemption->redeemedFor !== $date) {
            return self::scanAnswer(self::SCAN_OTHER_NIGHT, '', $name, (string) $redemption->redeemedFor);
        }

        return $this->scanPerson($date, $redemption->personId, $name, CheckinRepository::KIND_CREDIT);
    }

    /**
     * The person's $kind row on tonight's list: a task's ticket checks in the
     * crew row, a free-entry ticket the credit row.
     *
     * @return array{code: string, key: string, name: string, date: string, at: string}
     */
    private function scanPerson(string $date, int $personId, string $name, string $kind): array
    {
        foreach (array_merge(...$this->entrants($date)) as $row) {
            if ($row['ref'] === $personId && $row['kind'] === $kind) {
                return $this->inScanWindow($date)
                    ?? self::scanAnswer(self::SCAN_OK, $row['kind'] . ':' . $row['ref'], $row['name'], $date);
            }
        }

        return self::scanAnswer(self::SCAN_NOT_ON_LIST, '', $name, $date);
    }

    /**
     * @return array{code: string, key: string, name: string, date: string, at: string}
     */
    private static function scanAnswer(
        string $code,
        string $key = '',
        string $name = '',
        string $date = '',
        string $at = ''
    ): array {
        return ['code' => $code, 'key' => $key, 'name' => $name, 'date' => $date, 'at' => $at];
    }

    /**
     * Null while ticket scans are taken on $date; otherwise the answer that
     * refuses the scan, with the time scanning opens or closed in 'at'.
     *
     * @return array{code: string, key: string, name: string, date: string, at: string}|null
     */
    private function inScanWindow(string $date): ?array
    {
        $now = (string) current_time('mysql');
        $window = $this->gate->scanWindow($date);

        if ('' !== $window['opens'] && $now < $window['opens']) {
            return self::scanAnswer(self::SCAN_TOO_EARLY, '', '', $date, $window['opens']);
        }

        if ($now >= $window['closes']) {
            return self::scanAnswer(self::SCAN_CLOSED, '', '', $date, $window['closes']);
        }

        return null;
    }

    /**
     * How many people were counted in on $date without a name.
     */
    public function anonymousCount(string $date): int
    {
        return max(0, (int) get_option(self::ANONYMOUS_OPTION_PREFIX . $date, 0));
    }

    /**
     * Adds one to the count of people let in without a name on $date, or
     * takes one off when $step is negative. The count never goes below 0.
     * Answers the new count.
     *
     * The change is one SQL statement that adds to the stored number inside
     * the database. Reading the number in PHP and writing it back would lose
     * a press when two phones press at the same moment: both would read 4
     * and both would write 5.
     */
    public function countAnonymous(string $date, int $step): int
    {
        global $wpdb;

        $name = self::ANONYMOUS_OPTION_PREFIX . $date;
        $delta = $step < 0 ? -1 : 1;

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %d, 'no')"
                . ' ON DUPLICATE KEY UPDATE option_value = GREATEST(CAST(option_value AS SIGNED) + %d, 0)',
                $name,
                max(0, $delta),
                $delta
            )
        );

        // The value was written behind WordPress's back, so drop the cached copy.
        wp_cache_delete($name, 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete('alloptions', 'options');

        return $this->anonymousCount($date);
    }

    /**
     * Adds a guest at the door, already marked in with none of their extra
     * people yet: the door crew counts those in as they come. The email is
     * optional, as on the other guest forms; with one, the guest is emailed
     * their ticket by the ticket email's 10-minute batch. Answers a GUEST_*
     * code.
     */
    public function addGuest(string $date, int $doorPersonId, string $name, int $allowed, string $email = ''): string
    {
        $name = trim($name);
        $email = trim($email);

        if ('' === $name) {
            return self::GUEST_NO_NAME;
        }

        if ('' !== $email) {
            if (! is_email($email)) {
                return self::GUEST_BAD_EMAIL;
            }

            $email = sanitize_email($email);
        }

        $id = $this->guests->add($date, $name, max(0, $allowed), '', $doorPersonId, $email);

        if ($id <= 0) {
            return self::GUEST_FAILED;
        }

        $this->checkins->mark($date, CheckinRepository::KIND_GUEST, $id, 0, $doorPersonId);

        if ('' !== $email) {
            TicketMail::schedule();
        }

        return self::GUEST_OK;
    }

    /** The waiting ticket send as one line (TicketMail::pendingLine()), or ''. */
    private function sendingLine(): string
    {
        $summary = $this->ticketMail?->pendingSummary();

        return null === $summary ? '' : TicketMail::pendingLine($summary);
    }

    /**
     * "Cancel sending" at the desk: the waiting ticket send is cancelled and
     * none of it goes out, the same as the organizers' "Cancel sending".
     * Answers what the desk says.
     */
    public function cancelTicketSend(): string
    {
        return TicketMail::cancel()
            ? __('Sending cancelled. No ticket email was sent.', 'eventcrew')
            : __('No send was waiting. It may have gone out already.', 'eventcrew');
    }

    /** What the desk says for an addGuest() answer. */
    public function guestText(string $code): string
    {
        return match ($code) {
            self::GUEST_OK => __('Guest added and checked in.', 'eventcrew'),
            self::GUEST_NO_NAME => __('Give the guest’s name.', 'eventcrew'),
            self::GUEST_BAD_EMAIL => __('That email address is not valid. Correct it or leave it empty.', 'eventcrew'),
            default => __('The guest could not be added. Try again.', 'eventcrew'),
        };
    }

    /**
     * Members matching $query by name or email, with their credit balance -
     * the "Add" member search. A member with no credit is listed too: they can
     * still work tonight. A member already on tonight's crew or credit list is
     * listed with that list's name in 'list' and its kind in 'kind', so the
     * door sees they are there rather than getting no match. A credit-list
     * member can still be signed up for a task (workTonight swaps the ticket).
     *
     * @return array<int, array{id: int, name: string, credits: int, list: string, kind: string}>
     */
    public function searchMembers(string $date, string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < self::SEARCH_MIN) {
            return [];
        }

        [$crew, $credit] = $this->entrants($date);
        $onList = [];

        foreach ($crew as $row) {
            $onList[$row['ref']] = [CheckinRepository::KIND_CREW, __('Crew working', 'eventcrew')];
        }

        foreach ($credit as $row) {
            $onList[$row['ref']] ??= [CheckinRepository::KIND_CREDIT, __('Free-entry credit', 'eventcrew')];
        }

        $matches = [];

        foreach ($this->people->all(['search' => $query, 'per_page' => self::SEARCH_LIMIT]) as $person) {
            if ($person->isAnonymized()) {
                continue;
            }

            [$kind, $list] = $onList[$person->id] ?? ['', ''];
            $matches[] = [
                'id' => $person->id,
                'name' => $person->name(),
                'credits' => '' === $kind ? max(0, $this->standing->for($person->id)->creditBalance) : 0,
                'list' => $list,
                'kind' => $kind,
            ];
        }

        return $matches;
    }

    /**
     * One member as the member search lists them, or null when there is no
     * such member.
     *
     * @return array{id: int, name: string, credits: int}|null
     */
    public function member(int $personId): ?array
    {
        $person = $this->people->find($personId);

        if (null === $person || $person->isAnonymized()) {
            return null;
        }

        return [
            'id' => $person->id,
            'name' => $person->name(),
            'credits' => max(0, $this->standing->for($person->id)->creditBalance),
        ];
    }

    /**
     * Tonight's tasks this member could be signed up for at the door: those
     * with a free place, leaving out restricted ones (Organize, DJ, ...) whose
     * role they do not hold. When the person at the desk ($doorPersonId) is an
     * organizer, restricted tasks are listed whatever roles the member holds:
     * an organizer can sign anyone up to any task. A task with no limit has
     * free null: it is never full. ask_credit is true for a restricted task,
     * where the desk asks whether it counts toward free-entry credit.
     *
     * @return array<int, array{id: int, label: string, free: int|null, mine: bool, ask_credit: bool}>
     */
    public function openTasks(string $date, int $personId, int $doorPersonId = 0): array
    {
        $person = $this->people->find($personId);

        if (null === $person) {
            return [];
        }

        $tasks = $this->tasks->forDate($date);
        $taken = $this->tasks->occupancyFor(array_map(static fn (Task $task): int => $task->id, $tasks));
        $mine = $this->heldTaskIds($personId);
        $bypass = $this->isOrganizer($doorPersonId);
        $open = [];

        foreach ($tasks as $task) {
            if (! $bypass && SignupService::blocksRestrictedSlot($task, $person)) {
                continue;
            }

            $free = $task->hasNoLimit() ? null : $task->capacity - ($taken[$task->id] ?? 0);
            $held = isset($mine[$task->id]);

            // A task they already hold stays on the list, marked, even when
            // it is full, so the desk sees everything they are signed up for.
            if (! $held && null !== $free && $free <= 0) {
                continue;
            }

            $time = $task->timeRange();
            $open[] = [
                'id' => $task->id,
                'label' => '' === $time ? $task->roleLabel() : $task->roleLabel() . ' · ' . $time,
                'free' => $free,
                'mine' => $held,
                // The desk asks "Count toward free-entry credit?" before it
                // signs someone up for a restricted task.
                'ask_credit' => $task->isRestricted(),
            ];
        }

        return $open;
    }

    /** Whether the person running the desk is an organizer. */
    private function isOrganizer(int $doorPersonId): bool
    {
        $doorPerson = $doorPersonId > 0 ? $this->people->find($doorPersonId) : null;

        return null !== $doorPerson && $doorPerson->isOrganizer;
    }

    /**
     * The ids of the tasks $personId holds a place on, as keys.
     *
     * @return array<int, true>
     */
    private function heldTaskIds(int $personId): array
    {
        $held = [];

        foreach ($this->assignments->forPerson($personId) as $assignment) {
            if ($assignment->isOccupying()) {
                $held[$assignment->taskId] = true;
            }
        }

        return $held;
    }

    /**
     * Signs a member up for one of tonight's tasks from the door and marks
     * them in, which also marks the task arrived. The standing gate is not
     * asked: the person signing them up is at the door with them. The place
     * limit still holds. A restricted task needs its role, unless the person
     * at the desk is an organizer. Answers a WORK_* code.
     *
     * Someone who already spent a credit on tonight is counted once, as crew:
     * their ticket is cancelled, which gives the credit back, and the grant
     * log gets a line saying why (WORK_SWAPPED).
     */
    public function workTonight(
        string $date,
        int $doorPersonId,
        int $personId,
        int $taskId,
        ?bool $countsCredit = null
    ): string {
        $person = $this->people->find($personId);
        $task = $this->tasks->find($taskId);

        if (null === $person || $person->isAnonymized() || null === $task || $task->taskDate !== $date) {
            return self::WORK_UNAVAILABLE;
        }

        // Worded like a task that is not there: someone without the task's
        // role is never offered it, so there is nothing more to say about it.
        if (SignupService::blocksRestrictedSlot($task, $person) && ! $this->isOrganizer($doorPersonId)) {
            return self::WORK_UNAVAILABLE;
        }

        // A restricted task needs the answer to "Count toward free-entry
        // credit?"; every other task always counts.
        if ($task->isRestricted() && null === $countsCredit) {
            return self::WORK_CREDIT_QUESTION;
        }

        $outcome = $this->assignments->join($taskId, $personId, $task->isRestricted() ? (bool) $countsCredit : true);

        if (AssignmentRepository::JOIN_FULL === $outcome) {
            return self::WORK_FULL;
        }

        $joined = [
            AssignmentRepository::JOIN_OK,
            AssignmentRepository::JOIN_REJOINED,
            AssignmentRepository::JOIN_DUPLICATE,
        ];

        if (! in_array($outcome, $joined, true)) {
            return self::WORK_UNAVAILABLE;
        }

        $this->checkins->mark($date, CheckinRepository::KIND_CREW, $personId, 0, $doorPersonId);
        $this->markCrewArrived($date, $personId, $doorPersonId);

        // Someone signed up at the desk gets the same confirmation, with the
        // ticket, as a signup on the web or in the bot. A signup they already
        // held already sent one.
        if (AssignmentRepository::JOIN_DUPLICATE !== $outcome) {
            $this->notifier?->confirmSignup($person, $taskId);
        }

        return $this->returnTicket($date, $personId, $doorPersonId) ? self::WORK_SWAPPED : self::WORK_OK;
    }

    /**
     * Cancels $personId's free-entry ticket for $date, if they hold one, and
     * logs why. Cancelling the redemption is what gives the credit back; its
     * ticket link stays and shows DISABLED.
     */
    private function returnTicket(string $date, int $personId, int $doorPersonId): bool
    {
        $returned = false;

        foreach ($this->redemptions->forDate($date) as $redemption) {
            if ($redemption->personId !== $personId) {
                continue;
            }

            // Also emails "Your ticket is cancelled" when the ticket was emailed.
            $this->tickets->cancelRedemption($redemption->id);
            $returned = true;
        }

        if ($returned) {
            $this->grants->recordNote(
                $personId,
                __('Ticket exchanged against taking a task', 'eventcrew'),
                0 === $doorPersonId ? null : $doorPersonId
            );
        }

        return $returned;
    }

    /**
     * A plain sentence for a workTonight() answer.
     */
    public function workTonightText(string $code): string
    {
        return match ($code) {
            self::WORK_OK => __('Signed up and checked in. They are on the crew list now.', 'eventcrew'),
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            self::WORK_SWAPPED => __('Signed up and checked in. Their free-entry ticket is cancelled and the credit is given back.', 'eventcrew'),
            self::WORK_FULL => __('That task has just filled up. Pick another one.', 'eventcrew'),
            self::WORK_CREDIT_QUESTION => __('Choose whether this task counts toward free-entry credit.', 'eventcrew'),
            default => __('They could not be signed up for that task.', 'eventcrew'),
        };
    }

    /**
     * Spends one of a member's credits on tonight, from the door. The answer
     * is one of TicketRedemptionService's codes.
     */
    public function giveTicket(string $date, int $personId): string
    {
        if (null === $this->people->find($personId)) {
            return TicketRedemptionService::NOT_ELIGIBLE;
        }

        return $this->tickets->redeemAtDoor($personId, $date)['code'];
    }

    /**
     * A plain sentence for a give-ticket answer.
     */
    public function giveTicketText(string $code): string
    {
        return match ($code) {
            TicketRedemptionService::TICKET_READY => __('Ticket given. They are on the list now.', 'eventcrew'),
            TicketRedemptionService::NO_CREDIT => __('They have no credit left.', 'eventcrew'),
            TicketRedemptionService::ALREADY_REDEEMED => __('They already have free entry tonight.', 'eventcrew'),
            default => __('That ticket could not be given.', 'eventcrew'),
        };
    }

    /**
     * Tonight's crew and credit rows. A worker who also holds a redemption is
     * listed once, as crew - the same rule as DoorList. Built here rather than
     * through DoorList::entrantsFor() because that one also works out every
     * entrant's standing, which the door page does not show and which would
     * be a dozen queries per person on every 4-second poll.
     *
     * @return array{0: array<int, array{kind: string, ref: int, name: string, detail: string, plus: int, max: int, short: string}>,
     *     1: array<int, array{kind: string, ref: int, name: string, detail: string, plus: int, max: int, short: string}>}
     */
    private function entrants(string $date): array
    {
        $roles = [];
        $plusOnes = [];

        foreach ($this->tasks->forDate($date) as $task) {
            foreach ($this->assignments->forTask($task->id) as $assignment) {
                if ($assignment->isOccupying()) {
                    $roles[$assignment->personId][] = $task->roleLabel();
                    // Each task's "+1 allowed", added up over all of the
                    // person's tasks tonight.
                    $plusOnes[$assignment->personId] = ($plusOnes[$assignment->personId] ?? 0) + $task->plusOnes;
                }
            }
        }

        $crew = [];

        foreach ($roles as $personId => $labels) {
            $allowed = min(CheckinRepository::MAX_PLUS_ONES, $plusOnes[$personId] ?? 0);
            $detail = implode(', ', array_unique($labels));

            if ($allowed > 0) {
                /* translators: %d: how many extra people this guest may bring */
                $detail .= ' · ' . sprintf(__('allowed +%d', 'eventcrew'), $allowed);
            }

            $crew[] = $this->row(
                CheckinRepository::KIND_CREW,
                $personId,
                $this->nameOf($personId),
                $detail,
                $allowed,
                ShortCode::forPerson($personId, $date)
            );
        }

        $credit = [];

        // Someone who works tonight and also holds a free-entry ticket has
        // two rows: they may give the ticket to someone else (Owner 2026-11-07).
        foreach ($this->redemptions->forDate($date) as $redemption) {
            $credit[] = $this->row(
                CheckinRepository::KIND_CREDIT,
                $redemption->personId,
                $this->nameOf($redemption->personId),
                __('Credit', 'eventcrew'),
                null,
                ShortCode::forCredit($redemption->personId, $date)
            );
        }

        return [$this->sorted($crew), $this->sorted($credit)];
    }

    /**
     * @return array<int, array{kind: string, ref: int, name: string, detail: string, plus: int, max: int, short: string}>
     */
    private function guestRows(string $date): array
    {
        $rows = [];

        // A guest's count starts at none: the list says how many they may
        // bring, and the door counts the ones who come.
        foreach ($this->guests->forDate($date) as $guest) {
            /* translators: %d: how many extra people this guest may bring */
            $allowed = sprintf(__('allowed +%d', 'eventcrew'), $guest->plusOnes);

            $rows[] = $this->row(
                CheckinRepository::KIND_GUEST,
                $guest->id,
                $guest->name,
                '' === $guest->note ? $allowed : $guest->note . ' · ' . $allowed,
                $guest->plusOnes,
                // Only a guest with an email address has a ticket.
                '' === $guest->email ? '' : ShortCode::forGuest($guest->id)
            );
        }

        return $this->sorted($rows);
    }

    /**
     * Tonight's Holvi buyers and door sales, as two lists.
     *
     * @return array{0: array<int, array{kind: string, ref: int, name: string, detail: string, plus: int, max: int, short: string}>,
     *     1: array<int, array{kind: string, ref: int, name: string, detail: string, plus: int, max: int, short: string}>}
     */
    private function ticketRows(string $date): array
    {
        $holvi = [];
        $door = [];

        foreach ($this->buyers->forDate($date) as $buyer) {
            if ($buyer->isDoorSale()) {
                $name = '' === $buyer->name ? __('No name', 'eventcrew') : $buyer->name;
                $door[] = $this->row(CheckinRepository::KIND_BUYER, $buyer->id, $name, __('Door', 'eventcrew'));
                continue;
            }

            // The page writes the detail with textContent, so the email is
            // shown as plain text.
            $detail = __('Holvi', 'eventcrew');
            $detail = '' === $buyer->email ? $detail : $detail . ' · ' . $buyer->email;
            // A row whose name was blanked 60 days after the event
            // (RetentionPurge::purgeBuyers) still shows on an old night's list.
            $name = '' === $buyer->name ? __('No name', 'eventcrew') : $buyer->name;
            // A Holvi ticket admits one person: the desk shows no +1 for it.
            // Only a row with a ticket code has a ticket with a QR code.
            $short = '' === $buyer->ticketCode ? '' : ShortCode::forBuyer($buyer->id);
            $holvi[] = $this->row(CheckinRepository::KIND_BUYER, $buyer->id, $name, $detail, 0, $short);
        }

        return [$holvi, $door];
    }

    /**
     * A list row. $max is how many extra people the entry may bring: a
     * guest's allowance, the sum of a crew person's tasks' "+1 allowed",
     * none for a free-entry credit (the credit covers one person) or a Holvi
     * ticket, the general cap for a ticket sold at the door. $short is the
     * ticket's short code (ShortCode), '' for a row with no ticket.
     *
     * @return array{kind: string, ref: int, name: string, detail: string, plus: int, max: int, short: string}
     */
    private function row(
        string $kind,
        int $ref,
        string $name,
        string $detail,
        ?int $max = null,
        string $short = ''
    ): array {
        $alone = [CheckinRepository::KIND_CREW, CheckinRepository::KIND_CREDIT];
        $max ??= in_array($kind, $alone, true) ? 0 : CheckinRepository::MAX_PLUS_ONES;

        return [
            'kind' => $kind,
            'ref' => $ref,
            'name' => $name,
            'detail' => $detail,
            'plus' => 0,
            'max' => $max,
            'short' => $short,
        ];
    }

    /**
     * Adds each row's check-in to it. A checked-in row shows the plus-ones
     * written at the door, which may differ from the list's default, and
     * the time it was marked in.
     *
     * @param array<int, array{kind: string, ref: int, name: string, detail: string, plus: int, max: int, short: string}> $rows
     * @param array<string, array{plus: int, at: string}> $checkins
     * @return array{key: string, title: string, rows: array<int, array{
     *     key: string, kind: string, ref: int, name: string, first: string, detail: string, short: string,
     *     plus: int, max: int, in: bool, at: string
     * }>}
     */
    private function section(string $key, string $title, array $rows, array $checkins): array
    {
        $out = [];

        foreach ($rows as $row) {
            $rowKey = $row['kind'] . ':' . $row['ref'];
            $in = array_key_exists($rowKey, $checkins);

            $out[] = [
                'key' => $rowKey,
                'kind' => $row['kind'],
                'ref' => $row['ref'],
                'name' => $row['name'],
                'first' => self::firstName($row['name']),
                'detail' => $row['detail'],
                // The short code under the ticket's QR code, for the door
                // crew to compare when they tick a row by hand.
                'short' => $row['short'],
                'plus' => $in ? $checkins[$rowKey]['plus'] : $row['plus'],
                'max' => $row['max'],
                'in' => $in,
                'at' => $in ? self::inTime($checkins[$rowKey]['at']) : '',
            ];
        }

        return ['key' => $key, 'title' => $title, 'rows' => $out];
    }

    /**
     * The time a row was marked in, like "21:05", for the desk's orange
     * "already used" line. The stored time is site time already; '' when
     * it is not known.
     */
    private static function inTime(string $checkedInAt): string
    {
        return '' === $checkedInAt ? '' : Dates::wallClock($checkedInAt, 'H:i');
    }

    /**
     * The entry's row on tonight's list, or null when it is not on it.
     *
     * @return array{kind: string, ref: int, name: string, detail: string, plus: int, max: int, short: string}|null
     */
    private function findRow(string $date, string $kind, int $refId): ?array
    {
        if (! in_array($kind, CheckinRepository::kinds(), true) || $refId <= 0) {
            return null;
        }

        $rows = match ($kind) {
            CheckinRepository::KIND_GUEST => $this->guestRows($date),
            CheckinRepository::KIND_BUYER => array_merge(...$this->ticketRows($date)),
            default => array_merge(...$this->entrants($date)),
        };

        foreach ($rows as $row) {
            if ($row['kind'] === $kind && $row['ref'] === $refId) {
                return $row;
            }
        }

        return null;
    }

    private function markCrewArrived(string $date, int $personId, int $doorPersonId): void
    {
        foreach ($this->tasks->forDate($date) as $task) {
            $assignment = $this->assignments->findFor($task->id, $personId);

            if (null !== $assignment && AssignmentStatus::SIGNED_UP === $assignment->status) {
                $this->assignments->setStatus(
                    $assignment->id,
                    AssignmentStatus::ARRIVED,
                    $doorPersonId,
                    MarkedBy::PERSON
                );
            }
        }
    }

    /**
     * Undoes markCrewArrived when the desk marks a crew member out: their
     * arrived tasks that night go back to signed up (expected), recording who
     * at the desk did it, so the roster shows "signed out by X at HH:MM".
     * Only `arrived`: a late or completed mark was set on the roster itself.
     */
    private function markCrewSignedOut(string $date, int $personId, int $doorPersonId): void
    {
        foreach ($this->tasks->forDate($date) as $task) {
            $assignment = $this->assignments->findFor($task->id, $personId);

            if (null !== $assignment && AssignmentStatus::ARRIVED === $assignment->status) {
                $this->assignments->setStatus(
                    $assignment->id,
                    AssignmentStatus::SIGNED_UP,
                    $doorPersonId,
                    MarkedBy::PERSON
                );
            }
        }
    }

    private function nameOf(int $personId): string
    {
        $person = $this->people->find($personId);

        return null === $person ? __('(deleted person)', 'eventcrew') : $person->name();
    }

    /**
     * @param array<int, array{kind: string, ref: int, name: string, detail: string, plus: int, max: int, short: string}> $rows
     * @return array<int, array{kind: string, ref: int, name: string, detail: string, plus: int, max: int, short: string}>
     */
    private function sorted(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $rows;
    }
}
