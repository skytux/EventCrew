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

    /** scan() answers. */
    public const SCAN_OK = 'ok';
    public const SCAN_OTHER_NIGHT = 'other_night';
    public const SCAN_DISABLED = 'disabled';
    public const SCAN_NOT_ON_LIST = 'not_on_list';
    public const SCAN_NOT_OURS = 'not_ours';

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
        private readonly ?ClaimNotifier $notifier = null
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
     * is not valid or the person has since been deleted. 'open' says whether
     * the session still works right now: the person is read fresh on every
     * request, so an organizer keeps the night until 06:00 while a crew member
     * on a sales-desk role loses it when the event ends. Whether they are
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
     * The whole page state for one night: the five sections, the counter, and
     * a version hash the page compares to decide whether to redraw. A guest
     * counts as expected with all the people they are allowed to bring, since
     * those may still come; the "in" side counts only who did. The people
     * counted in without a name ('anonymous') are added to both sides.
     *
     * @return array{
     *     date: string, day: string, in: int, expected: int, anonymous: int, version: string,
     *     sections: array<int, array{key: string, title: string, rows: array<int, array{
     *         key: string, kind: string, ref: int, name: string, detail: string, plus: int, max: int, in: bool
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
     * is stored. The email is only shown on the list: nothing is sent to it.
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
     * free-entry credit. On SCAN_OK, key is the row on tonight's list
     * ("crew:8", "credit:8"), for the page to show with its +1 counter and
     * "Mark arrived" button; nothing is marked here. The other answers carry
     * the ticket holder's name and the ticket's night when they are known.
     *
     * @return array{code: string, key: string, name: string, date: string}
     */
    public function scan(string $date, string $text): array
    {
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
        }

        return self::scanAnswer(self::SCAN_NOT_OURS);
    }

    /**
     * The line the desk shows for one of scan()'s answers other than SCAN_OK.
     */
    public function scanText(string $code, string $name, string $date): string
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
            self::SCAN_OK => '',
            default => __('This QR code is not one of our tickets.', 'eventcrew'),
        };
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
     * @return array{code: string, key: string, name: string, date: string}
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

        if (! $assignment->isOccupying()) {
            return self::scanAnswer(self::SCAN_DISABLED, '', $name, $date);
        }

        return $this->scanPerson($date, $assignment->personId, $name);
    }

    /**
     * @return array{code: string, key: string, name: string, date: string}
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

        return $this->scanPerson($date, $redemption->personId, $name);
    }

    /**
     * The person's row on tonight's list: crew when they work tonight,
     * otherwise their free-entry credit, the same one-row rule as the list.
     *
     * @return array{code: string, key: string, name: string, date: string}
     */
    private function scanPerson(string $date, int $personId, string $name): array
    {
        foreach (array_merge(...$this->entrants($date)) as $row) {
            if ($row['ref'] === $personId) {
                return self::scanAnswer(self::SCAN_OK, $row['kind'] . ':' . $row['ref'], $row['name'], $date);
            }
        }

        return self::scanAnswer(self::SCAN_NOT_ON_LIST, '', $name, $date);
    }

    /**
     * @return array{code: string, key: string, name: string, date: string}
     */
    private static function scanAnswer(string $code, string $key = '', string $name = '', string $date = ''): array
    {
        return ['code' => $code, 'key' => $key, 'name' => $name, 'date' => $date];
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
     * people yet: the door crew counts those in as they come. Returns false
     * for an empty name or a failed write.
     */
    public function addGuest(string $date, int $doorPersonId, string $name, int $allowed): bool
    {
        $name = trim($name);

        if ('' === $name) {
            return false;
        }

        $id = $this->guests->add($date, $name, max(0, $allowed), '', $doorPersonId);

        if ($id <= 0) {
            return false;
        }

        $this->checkins->mark($date, CheckinRepository::KIND_GUEST, $id, 0, $doorPersonId);

        return true;
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
            $onList[$row['ref']] = [CheckinRepository::KIND_CREDIT, __('Free-entry credit', 'eventcrew')];
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

            $this->redemptions->cancel($redemption->id);
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
     * @return array{0: array<int, array{kind: string, ref: int, name: string, detail: string, plus: int, max: int}>,
     *     1: array<int, array{kind: string, ref: int, name: string, detail: string, plus: int, max: int}>}
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
                $allowed
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
     * @return array<int, array{kind: string, ref: int, name: string, detail: string, plus: int, max: int}>
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
                $guest->plusOnes
            );
        }

        return $this->sorted($rows);
    }

    /**
     * Tonight's Holvi buyers and door sales, as two lists.
     *
     * @return array{0: array<int, array{kind: string, ref: int, name: string, detail: string, plus: int, max: int}>,
     *     1: array<int, array{kind: string, ref: int, name: string, detail: string, plus: int, max: int}>}
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
            $holvi[] = $this->row(CheckinRepository::KIND_BUYER, $buyer->id, $buyer->name, $detail);
        }

        return [$holvi, $door];
    }

    /**
     * A list row. $max is how many extra people the entry may bring: a
     * guest's allowance, the sum of a crew person's tasks' "+1 allowed",
     * none for a free-entry credit (the credit covers one person), the general
     * cap for a ticket.
     *
     * @return array{kind: string, ref: int, name: string, detail: string, plus: int, max: int}
     */
    private function row(string $kind, int $ref, string $name, string $detail, ?int $max = null): array
    {
        $alone = [CheckinRepository::KIND_CREW, CheckinRepository::KIND_CREDIT];
        $max ??= in_array($kind, $alone, true) ? 0 : CheckinRepository::MAX_PLUS_ONES;

        return ['kind' => $kind, 'ref' => $ref, 'name' => $name, 'detail' => $detail, 'plus' => 0, 'max' => $max];
    }

    /**
     * Adds each row's check-in to it. A checked-in row shows the plus-ones
     * written at the door, which may differ from the list's default.
     *
     * @param array<int, array{kind: string, ref: int, name: string, detail: string, plus: int, max: int}> $rows
     * @param array<string, int> $checkins
     * @return array{key: string, title: string, rows: array<int, array{
     *     key: string, kind: string, ref: int, name: string, detail: string, plus: int, max: int, in: bool
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
                'max' => $row['max'],
                'in' => $in,
            ];
        }

        return ['key' => $key, 'title' => $title, 'rows' => $out];
    }

    /**
     * The entry's row on tonight's list, or null when it is not on it.
     *
     * @return array{kind: string, ref: int, name: string, detail: string, plus: int, max: int}|null
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
     * @param array<int, array{kind: string, ref: int, name: string, detail: string, plus: int, max: int}> $rows
     * @return array<int, array{kind: string, ref: int, name: string, detail: string, plus: int, max: int}>
     */
    private function sorted(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $rows;
    }
}
