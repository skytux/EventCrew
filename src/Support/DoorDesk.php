<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CheckinRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Telegram\TicketRedemptionService;

/**
 * The door page's logic: who is on tonight's list, who has come in, and the
 * three things the door crew can do - mark someone in or out, change how many
 * people they brought, and give a member a ticket from their credit.
 *
 * Everything here takes plain values and returns plain arrays, so it is
 * testable without a request; Web\DoorController is the thin HTTP layer on top.
 */
final class DoorDesk
{
    /** Most members the "Give ticket" search lists. */
    private const SEARCH_LIMIT = 20;

    /** Shortest search the "Give ticket" box answers. */
    public const SEARCH_MIN = 2;

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
        private readonly SalesDeskGate $gate
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
                'This link was already used — ask an organizer for a new one (/sales in Telegram).',
                'eventcrew'
            ),
        ];

        if (! DoorSession::isDate($date) || ! DoorSession::verifyLink($rawToken, $date, $signature)) {
            return $used;
        }

        $personId = $this->tokens->consume($rawToken, DoorSession::TOKEN_PURPOSE);
        $person = null === $personId ? null : $this->people->find($personId);

        if (null === $person) {
            return $used;
        }

        $seconds = $this->gate->secondsLeft($person, $date);

        if ($seconds <= 0) {
            return ['cookie' => null, 'seconds' => 0, 'message' => $this->gate->message(SalesDeskGate::CLOSED)];
        }

        return ['cookie' => DoorSession::mint($person->id, $date), 'seconds' => $seconds, 'message' => ''];
    }

    /**
     * The person and night a door cookie stands for, or null when the cookie
     * is not valid or the person has since been deleted. 'open' says whether
     * the session still works right now: the person is read fresh on every
     * request, so an organizer keeps the night until 06:00 while a leader or
     * Welcome crew member loses it when the event ends. Whether they are
     * still on the night's crew is not re-checked: that was decided when the
     * link was given, and re-checking it on every poll would cost a query per
     * task.
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

        if (null === $person) {
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
     * The whole page state for one night: the four sections, the counter, and
     * a version hash the page compares to decide whether to redraw.
     *
     * @return array{
     *     date: string, day: string, in: int, expected: int, version: string,
     *     sections: array<int, array{key: string, title: string, rows: array<int, array{
     *         key: string, kind: string, ref: int, name: string, detail: string, plus: int, in: bool
     *     }>}>
     * }
     */
    public function state(string $date): array
    {
        $checkins = $this->checkins->forDate($date);
        [$crew, $credit] = $this->entrants($date);

        $sections = [
            $this->section('crew', __('Crew working', 'eventcrew'), $crew, $checkins),
            $this->section('credit', __('Free-entry credit', 'eventcrew'), $credit, $checkins),
            $this->section('guest', __('Guests', 'eventcrew'), $this->guestRows($date), $checkins),
            $this->section('buyer', __('Holvi tickets', 'eventcrew'), $this->buyerRows($date), $checkins),
        ];

        $in = 0;
        $expected = 0;

        foreach ($sections as $section) {
            foreach ($section['rows'] as $row) {
                $expected += 1 + $row['plus'];
                $in += $row['in'] ? 1 + $row['plus'] : 0;
            }
        }

        $state = [
            'date' => $date,
            'day' => Dates::dayLabel($date),
            'in' => $in,
            'expected' => $expected,
            'sections' => $sections,
        ];

        $state['version'] = substr(hash('sha256', (string) wp_json_encode($state)), 0, 16);

        return $state;
    }

    /**
     * Marks one entry in or out. Marking a crew member in also turns their
     * signed-up assignments that night into "arrived", recorded as changed by
     * the person holding the door session; marking them out again does not
     * undo that, since attendance is its own record. Returns false when the
     * entry is not on tonight's list.
     */
    public function mark(string $date, int $doorPersonId, string $kind, int $refId, bool $in, int $plusOnes): bool
    {
        if (! $this->isOnList($date, $kind, $refId)) {
            return false;
        }

        if (! $in) {
            $this->checkins->unmark($date, $kind, $refId);

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
        if (! $this->isOnList($date, $kind, $refId)) {
            return false;
        }

        $this->checkins->setPlusOnes($date, $kind, $refId, $plusOnes);

        return true;
    }

    /**
     * Members matching $query by name or email who have a credit to spend and
     * are not already free tonight - the "Give ticket" pick-list.
     *
     * @return array<int, array{id: int, name: string, credits: int}>
     */
    public function searchMembers(string $date, string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < self::SEARCH_MIN) {
            return [];
        }

        [$crew, $credit] = $this->entrants($date);
        $alreadyFree = [];

        foreach (array_merge($crew, $credit) as $row) {
            $alreadyFree[$row['ref']] = true;
        }

        $matches = [];

        foreach ($this->people->all(['search' => $query, 'per_page' => self::SEARCH_LIMIT]) as $person) {
            if (isset($alreadyFree[$person->id]) || $person->isAnonymized()) {
                continue;
            }

            $balance = $this->standing->for($person->id)->creditBalance;

            if ($balance > 0) {
                $matches[] = ['id' => $person->id, 'name' => $person->name(), 'credits' => $balance];
            }
        }

        return $matches;
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
     * @return array{0: array<int, array{kind: string, ref: int, name: string, detail: string, plus: int}>,
     *     1: array<int, array{kind: string, ref: int, name: string, detail: string, plus: int}>}
     */
    private function entrants(string $date): array
    {
        $roles = [];

        foreach ($this->tasks->forDate($date) as $task) {
            foreach ($this->assignments->forTask($task->id) as $assignment) {
                if ($assignment->isOccupying()) {
                    $roles[$assignment->personId][] = $task->roleLabel();
                }
            }
        }

        $crew = [];

        foreach ($roles as $personId => $labels) {
            $crew[] = $this->row(
                CheckinRepository::KIND_CREW,
                $personId,
                $this->nameOf($personId),
                implode(', ', array_unique($labels))
            );
        }

        $credit = [];

        foreach ($this->redemptions->forDate($date) as $redemption) {
            if (isset($roles[$redemption->personId])) {
                continue;
            }

            $credit[] = $this->row(
                CheckinRepository::KIND_CREDIT,
                $redemption->personId,
                $this->nameOf($redemption->personId),
                __('Credit', 'eventcrew')
            );
        }

        return [$this->sorted($crew), $this->sorted($credit)];
    }

    /**
     * @return array<int, array{kind: string, ref: int, name: string, detail: string, plus: int}>
     */
    private function guestRows(string $date): array
    {
        $rows = [];

        foreach ($this->guests->forDate($date) as $guest) {
            $rows[] = $this->row(
                CheckinRepository::KIND_GUEST,
                $guest->id,
                $guest->name,
                $guest->note,
                $guest->plusOnes
            );
        }

        return $this->sorted($rows);
    }

    /**
     * @return array<int, array{kind: string, ref: int, name: string, detail: string, plus: int}>
     */
    private function buyerRows(string $date): array
    {
        $rows = [];

        foreach ($this->buyers->forDate($date) as $buyer) {
            $rows[] = $this->row(CheckinRepository::KIND_BUYER, $buyer->id, $buyer->name, __('Holvi', 'eventcrew'));
        }

        return $rows;
    }

    /**
     * @return array{kind: string, ref: int, name: string, detail: string, plus: int}
     */
    private function row(string $kind, int $ref, string $name, string $detail, int $plus = 0): array
    {
        return ['kind' => $kind, 'ref' => $ref, 'name' => $name, 'detail' => $detail, 'plus' => $plus];
    }

    /**
     * Adds each row's check-in to it. A checked-in row shows the plus-ones
     * written at the door, which may differ from the list's default.
     *
     * @param array<int, array{kind: string, ref: int, name: string, detail: string, plus: int}> $rows
     * @param array<string, int> $checkins
     * @return array{key: string, title: string, rows: array<int, array{
     *     key: string, kind: string, ref: int, name: string, detail: string, plus: int, in: bool
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
                'detail' => $row['detail'],
                'plus' => $in ? $checkins[$rowKey] : $row['plus'],
                'in' => $in,
            ];
        }

        return ['key' => $key, 'title' => $title, 'rows' => $out];
    }

    private function isOnList(string $date, string $kind, int $refId): bool
    {
        if (! in_array($kind, CheckinRepository::kinds(), true) || $refId <= 0) {
            return false;
        }

        $rows = match ($kind) {
            CheckinRepository::KIND_GUEST => $this->guestRows($date),
            CheckinRepository::KIND_BUYER => $this->buyerRows($date),
            default => array_merge(...$this->entrants($date)),
        };

        foreach ($rows as $row) {
            if ($row['kind'] === $kind && $row['ref'] === $refId) {
                return true;
            }
        }

        return false;
    }

    private function markCrewArrived(string $date, int $personId, int $doorPersonId): void
    {
        foreach ($this->tasks->forDate($date) as $task) {
            $assignment = $this->assignments->findFor($task->id, $personId);

            if (null !== $assignment && AssignmentStatus::SIGNED_UP === $assignment->status) {
                $this->assignments->setStatus($assignment->id, AssignmentStatus::ARRIVED, $doorPersonId);
            }
        }
    }

    private function nameOf(int $personId): string
    {
        $person = $this->people->find($personId);

        return null === $person ? __('(deleted person)', 'eventcrew') : $person->name();
    }

    /**
     * @param array<int, array{kind: string, ref: int, name: string, detail: string, plus: int}> $rows
     * @return array<int, array{kind: string, ref: int, name: string, detail: string, plus: int}>
     */
    private function sorted(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $rows;
    }
}
