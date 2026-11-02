<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Admin\RosterPage;
use EventCrew\MailImport\MailFetchSettings;
use EventCrew\MailImport\MailImporter;
use EventCrew\Models\Guest;
use EventCrew\Models\Person;
use EventCrew\Models\TicketBuyer;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\BuyerImport;
use EventCrew\Support\Dates;
use EventCrew\Support\DoorList;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\MarkedBy;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\TicketMail;
use EventCrew\Telegram\TicketRedemptionService;

/**
 * The Roster screen of the crew page's Admin tab, made to match the wp-admin
 * Roster page: a date picker, attendance with every status, the door list
 * with credit redemptions and the free-entry switch, the guest list, the
 * imported ticket buyers, and the mailbox check.
 *
 * CrewAdminController guards every action (session, organizer flag, CSRF,
 * POST only) before it calls a method here. The methods only check their own
 * input and answer with an 'admin_' notice code. Every write that records who
 * made it records the organizer's person id, as the bot does.
 */
final class CrewAdminRoster
{
    /** The statuses "Mark all" may set: the outcomes that apply to a whole crew. */
    private const BULK = [AssignmentStatus::ARRIVED, AssignmentStatus::COMPLETED];

    /** The rows "Mark all" changes: the same rows the quick buttons are on. */
    private const BULK_FROM = [AssignmentStatus::SIGNED_UP, AssignmentStatus::ARRIVED];

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly DoorList $doorList,
        private readonly RedemptionRepository $redemptions,
        private readonly StandingCalculator $standing,
        private readonly FreeEntryGate $freeEntry,
        private readonly TicketRedemptionService $tickets,
        private readonly GuestRepository $guests,
        private readonly TicketBuyerRepository $ticketBuyers,
        private readonly BuyerImport $buyerImport,
        private readonly MailImporter $mailImporter,
        private readonly ?TicketMail $ticketMail = null
    ) {
    }

    /**
     * The dates the picker offers and the one shown: upcoming dates nearest
     * first, past dates most recent first, as on the wp-admin Roster page. The
     * requested date is shown when it has tasks; otherwise the nearest
     * upcoming date, or the most recent past one when nothing is upcoming.
     *
     * 'prev' and 'next' are the dates with tasks just before and just after
     * the one shown, for the arrows of the date bar ('' when there is none).
     *
     * @return array{
     *     date: string,
     *     upcoming: array<string, string>,
     *     past: array<string, string>,
     *     prev: string,
     *     next: string
     * }
     */
    public function dates(string $requested): array
    {
        $dates = $this->tasks->datesWithTasks(); // most recent first
        $today = (string) current_time('Y-m-d');

        $upcoming = array_reverse(array_values(array_filter($dates, static fn (string $d): bool => $d >= $today)));
        $past = array_values(array_filter($dates, static fn (string $d): bool => $d < $today));

        $selected = in_array($requested, $dates, true) ? $requested : ($upcoming[0] ?? ($past[0] ?? ''));

        $earlier = array_filter($dates, static fn (string $d): bool => '' !== $selected && $d < $selected);
        $later = array_filter($dates, static fn (string $d): bool => '' !== $selected && $d > $selected);

        return [
            'date' => $selected,
            'upcoming' => self::labelled($upcoming),
            'past' => self::labelled($past),
            'prev' => [] === $earlier ? '' : max($earlier),
            'next' => [] === $later ? '' : min($later),
        ];
    }

    /**
     * The door list, the free-entry state, the guests and the ticket buyers
     * for $date, and the mailbox state, for the template.
     *
     * @return array{
     *     entrants: list<array{name: string, detail: string, standing: string, at_risk: bool, redemption_id: ?int}>,
     *     candidates: list<array{person_id: int, name: string, credit_balance: int}>,
     *     closed_by_hand: bool,
     *     event_reason: string,
     *     guests: list<array{id: int, name: string, plus_ones: int, note: string}>,
     *     buyers: list<string>,
     *     ticket_mail_buyers: string,
     *     ticket_mail_guests: string,
     *     mailbox: string,
     *     mail_last: string
     * }
     */
    public function view(string $date): array
    {
        $view = [
            'entrants' => [],
            'candidates' => [],
            'closed_by_hand' => false,
            'event_reason' => '',
            'guests' => [],
            'buyers' => [],
            'ticket_mail_buyers' => '',
            'ticket_mail_guests' => '',
            'mailbox' => BuyerImportController::mailbox(),
            'mail_last' => self::lastMailCheck(),
        ];

        if ('' === $date) {
            return $view;
        }

        $door = $this->doorList->forDate($date);

        foreach ($door['entrants'] as $entrant) {
            $view['entrants'][] = [
                'name' => $entrant['name'],
                'detail' => $entrant['detail'],
                'standing' => $entrant['standing']->ratedSummary(),
                'at_risk' => $entrant['standing']->isAtRisk(),
                'redemption_id' => $entrant['redemption_id'],
            ];
        }

        $view['candidates'] = array_values($door['candidates']);
        $view['closed_by_hand'] = $this->freeEntry->isClosedByHand($date);
        $view['event_reason'] = $this->freeEntry->eventReason($date);
        $view['guests'] = array_map(
            static fn (Guest $guest): array => [
                'id' => $guest->id,
                'name' => $guest->name,
                'plus_ones' => $guest->plusOnes,
                'note' => $guest->note,
            ],
            array_values($this->guests->forDate($date))
        );

        foreach ($this->ticketBuyers->forDate($date) as $buyer) {
            if ($buyer instanceof TicketBuyer && ! $buyer->isDoorSale()) {
                $view['buyers'][] = $buyer->name;
            }
        }

        if (null !== $this->ticketMail) {
            $view['ticket_mail_buyers'] = TicketMail::statusLine(
                $this->ticketMail->counts($date, TicketMail::KIND_BUYERS)
            );
            $view['ticket_mail_guests'] = TicketMail::statusLine(
                $this->ticketMail->counts($date, TicketMail::KIND_GUESTS)
            );
        }

        return $view;
    }

    /**
     * "Update attendance": every status picker of one task posts together as
     * status[<assignment_id>]. Only rows whose status changed are written, so
     * saving again does not restamp the others; an unknown status or an id
     * that is not found is skipped.
     *
     * @param array<mixed> $statuses
     */
    public function saveRoster(Person $organizer, string $date, array $statuses): string
    {
        if (! self::isValidDate($date)) {
            return 'admin_change_failed';
        }

        $updated = 0;

        foreach ($statuses as $assignmentId => $status) {
            $assignmentId = (int) $assignmentId;
            $status = is_scalar($status) ? sanitize_key((string) $status) : '';

            if ($assignmentId <= 0 || ! AssignmentStatus::isValid($status)) {
                continue;
            }

            $assignment = $this->assignments->find($assignmentId);

            if (null === $assignment || $assignment->status === $status) {
                continue;
            }

            $this->assignments->setStatus($assignmentId, $status, $organizer->id, MarkedBy::PERSON);
            ++$updated;
        }

        return 0 === $updated ? 'admin_roster_none' : 'admin_roster_updated_' . $updated;
    }

    /**
     * "Mark all arrived" / "Mark all completed" for one task: only the people
     * signed up or arrived change. A cancellation or a recorded absence stays.
     */
    public function markAll(Person $organizer, string $date, int $taskId, string $status): string
    {
        if (! self::isValidDate($date) || $taskId <= 0 || ! in_array($status, self::BULK, true)) {
            return 'admin_roster_bulk_failed';
        }

        $updated = 0;

        foreach ($this->assignments->forTask($taskId) as $assignment) {
            if (in_array($assignment->status, self::BULK_FROM, true)) {
                $this->assignments->setStatus($assignment->id, $status, $organizer->id, MarkedBy::PERSON);
                ++$updated;
            }
        }

        return 'admin_roster_updated_' . $updated;
    }

    /** "Close free entry (sold out)" / "Reopen free entry" for $date. */
    public function toggleFreeEntry(string $date): string
    {
        if (! self::isValidDate($date)) {
            return 'admin_change_failed';
        }

        if ($this->freeEntry->isClosedByHand($date)) {
            $this->freeEntry->open($date);

            return 'admin_door_reopened';
        }

        $this->freeEntry->close($date);

        return 'admin_door_closed_now';
    }

    /**
     * "Redeem a credit": spends one of the person's credits for free entry on
     * $date and sends them the door ticket, as the wp-admin Roster page does.
     * The balance is checked again here, not taken from the page.
     */
    public function redeem(string $date, int $personId): string
    {
        if ($personId <= 0 || ! self::isValidDate($date)) {
            return 'admin_door_failed';
        }

        if ($this->freeEntry->isClosed($date)) {
            return 'admin_door_closed';
        }

        if ($this->standing->for($personId)->creditBalance < 1) {
            return 'admin_door_no_credit';
        }

        [$eventPostId, $eventLabel] = $this->eventContext($date);
        $redemptionId = $this->redemptions->record($personId, $date, $eventPostId, $eventLabel);

        // 0: they were already down for this date. No second ticket.
        if (0 === $redemptionId) {
            return 'admin_door_already';
        }

        $this->tickets->notifyRedemption($personId, $date, $redemptionId);

        return 'admin_door_redeemed';
    }

    /** "Remove" on a redeemed row: the credit goes back to the person. */
    public function unredeem(int $redemptionId): string
    {
        $redemption = $redemptionId > 0 ? $this->redemptions->find($redemptionId) : null;

        if (null === $redemption) {
            return 'admin_change_failed';
        }

        $this->redemptions->cancel($redemption->id);

        return 'admin_door_returned';
    }

    /**
     * Adds a guest to $date ($guestId 0) or saves an existing guest. The added
     * guest records the organizer as the one who added them, as /door add does.
     */
    public function saveGuest(
        Person $organizer,
        string $date,
        int $guestId,
        string $name,
        int $plusOnes,
        string $note
    ): string {
        if (! self::isValidDate($date) || $guestId < 0) {
            return 'admin_change_failed';
        }

        if ('' === $name) {
            return 'admin_guest_no_name';
        }

        $plusOnes = max(0, min(GuestRepository::MAX_PLUS_ONES, $plusOnes));

        if (0 === $guestId) {
            $this->guests->add($date, $name, $plusOnes, $note, $organizer->id);

            return 'admin_guest_added';
        }

        if (null === $this->guests->find($guestId)) {
            return 'admin_change_failed';
        }

        $this->guests->update($guestId, $name, $plusOnes, $note);

        return 'admin_guest_saved';
    }

    /** "Remove" on a guest. */
    public function removeGuest(int $guestId): string
    {
        if ($guestId <= 0 || null === $this->guests->find($guestId)) {
            return 'admin_change_failed';
        }

        $this->guests->delete($guestId);

        return 'admin_guest_removed';
    }

    /** "Import": a pasted list of ticket buyers, one name per line, for $date. */
    public function importBuyers(string $date, string $text): string
    {
        if (! self::isValidDate($date)) {
            return 'admin_buyers_failed';
        }

        $result = $this->buyerImport->import($date, $text, 'paste');

        return sprintf('admin_buyers_added_%d_%d', $result['added'], $result['skipped']);
    }

    /** "Reset import": deletes every imported ticket buyer on $date. */
    public function resetBuyers(string $date): string
    {
        if (! self::isValidDate($date)) {
            return 'admin_change_failed';
        }

        return 'admin_buyers_deleted_' . $this->ticketBuyers->clearForDate($date);
    }

    /**
     * "Send a test to me": one ticket email, built from the night's first
     * ticket, to the organizer's own address. No row's status changes.
     */
    public function sendTicketTest(string $date, Person $organizer): string
    {
        if (! self::isValidDate($date) || null === $this->ticketMail) {
            return 'admin_change_failed';
        }

        return 'admin_tickets_test_' . $this->ticketMail->sendTest($date, $organizer->email);
    }

    /**
     * "Send the rest now": up to one batch of the night's ticket emails that
     * have not gone out, failed ones included. $kind is "buyers" or "guests".
     */
    public function sendTicketsRest(string $date, string $kind): string
    {
        if (! self::isValidDate($date) || null === $this->ticketMail) {
            return 'admin_change_failed';
        }

        $result = $this->ticketMail->sendRest(
            $date,
            TicketMail::KIND_GUESTS === $kind ? TicketMail::KIND_GUESTS : TicketMail::KIND_BUYERS
        );

        return sprintf('admin_tickets_sent_%d_%d', $result['sent'], $result['failed'])
            . ($result['more'] ? '_more' : '');
    }

    /**
     * "Check the mailbox now": one run of the mail import, as the button in
     * wp-admin Settings does. The text of the result is read back from the
     * stored status when the page shows the notice.
     */
    public function checkMail(): string
    {
        if (! MailFetchSettings::isOn()) {
            return 'admin_mail_off';
        }

        $status = $this->mailImporter->run();

        if (null === $status) {
            return 'admin_mail_busy';
        }

        return $status['ok'] ? 'admin_mail_checked' : 'admin_mail_failed';
    }

    /** A real calendar date written YYYY-MM-DD. */
    public static function isValidDate(string $date): bool
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return checkdate($month, $day, $year);
    }

    /**
     * Every status, value => label, for the status pickers.
     *
     * @return array<string, string>
     */
    public static function statusChoices(): array
    {
        $choices = [];

        foreach (AssignmentStatus::all() as $status) {
            $choices[$status] = AssignmentStatus::label($status);
        }

        return $choices;
    }

    /**
     * The text for one of this screen's notice codes, or '' when the code is
     * not one of them.
     */
    public static function noticeText(string $code): string
    {
        $map = [
            'admin_change_failed' => __('That change could not be applied.', 'eventcrew'),
            'admin_roster_none' => __('No changes to save.', 'eventcrew'),
            'admin_roster_bulk_failed' => __('That bulk update could not be applied.', 'eventcrew'),
            'admin_door_closed_now' => __('Free entry closed for this date.', 'eventcrew'),
            'admin_door_reopened' => __('Free entry reopened for this date.', 'eventcrew'),
            'admin_door_failed' => __('That credit could not be redeemed.', 'eventcrew'),
            'admin_door_closed' => __('Free entry is closed for this date.', 'eventcrew'),
            'admin_door_no_credit' => __('They have no credit left to redeem.', 'eventcrew'),
            'admin_door_already' => __('They already have free entry for this date.', 'eventcrew'),
            'admin_door_redeemed' => __('Credit redeemed — they’re on the door list.', 'eventcrew'),
            'admin_door_returned' => __('Credit handed back.', 'eventcrew'),
            'admin_guest_no_name' => __('A guest needs a name.', 'eventcrew'),
            'admin_guest_added' => __('Guest added.', 'eventcrew'),
            'admin_guest_saved' => __('Guest saved.', 'eventcrew'),
            'admin_guest_removed' => __('Guest removed.', 'eventcrew'),
            'admin_buyers_failed' => __('That list could not be imported.', 'eventcrew'),
            'admin_tickets_test_sent' => __('A test ticket email was sent to your own address.', 'eventcrew'),
            'admin_tickets_test_failed' => RosterPage::ticketTestMessage(TicketMail::TEST_FAILED, ''),
            'admin_tickets_test_none' => RosterPage::ticketTestMessage(TicketMail::TEST_NONE, ''),
            'admin_mail_off' => __(
                'Reading the mailbox is switched off, or the server, user name or password is missing.',
                'eventcrew'
            ),
            'admin_mail_busy' => __('Another check of the mailbox is running. Try again in a minute.', 'eventcrew'),
        ];

        if (isset($map[$code])) {
            return $map[$code];
        }

        if (1 === preg_match('/^admin_roster_updated_(\d+)$/', $code, $m)) {
            return sprintf(
                /* translators: %d: number of people updated */
                _n('%d person updated.', '%d people updated.', (int) $m[1], 'eventcrew'),
                (int) $m[1]
            );
        }

        if (1 === preg_match('/^admin_buyers_added_(\d+)_(\d+)$/', $code, $m)) {
            return sprintf(
                /* translators: 1: number of names added, 2: number of names skipped */
                __('%1$d ticket buyers added, %2$d skipped (already on the list).', 'eventcrew'),
                (int) $m[1],
                (int) $m[2]
            );
        }

        if (1 === preg_match('/^admin_tickets_sent_(\d+)_(\d+)(_more)?$/', $code, $m)) {
            return RosterPage::ticketsSentMessage((int) $m[1], (int) $m[2], isset($m[3]) && '' !== $m[3]);
        }

        if (1 === preg_match('/^admin_buyers_deleted_(\d+)$/', $code, $m)) {
            return sprintf(
                /* translators: %d: number of ticket buyers deleted */
                _n('%d ticket buyer deleted.', '%d ticket buyers deleted.', (int) $m[1], 'eventcrew'),
                (int) $m[1]
            );
        }

        if ('admin_mail_checked' === $code || 'admin_mail_failed' === $code) {
            $message = MailFetchSettings::status()['message'];

            return 'admin_mail_checked' === $code
                /* translators: %s: what the check found, e.g. "1 imported, 0 rejected, 0 left to try again" */
                ? sprintf(__('Mailbox checked: %s.', 'eventcrew'), $message)
                /* translators: %s: the error, e.g. "Could not reach mail.example.org: timed out" */
                : sprintf(__('The mailbox check failed: %s', 'eventcrew'), $message);
        }

        return '';
    }

    /**
     * The event a redemption on $date is recorded against: the first task's
     * event that day, as on the wp-admin Roster page.
     *
     * @return array{0: ?int, 1: string}
     */
    private function eventContext(string $date): array
    {
        foreach ($this->tasks->forDate($date) as $task) {
            return [$task->eventPostId, $task->eventName()];
        }

        return [null, ''];
    }

    /**
     * "Last check <when>: <what it found>", as wp-admin Settings shows it, or
     * '' when the mailbox has never been checked.
     */
    private static function lastMailCheck(): string
    {
        $status = MailFetchSettings::status();

        if (0 === $status['time']) {
            return '';
        }

        $when = function_exists('wp_date')
            ? (string) wp_date('j M Y H:i', $status['time'])
            : gmdate('j M Y H:i', $status['time']);

        return sprintf(
            /* translators: 1: date and time of the last check, 2: what it found or the error */
            __('Last check %1$s: %2$s', 'eventcrew'),
            $when,
            $status['message']
        );
    }

    /**
     * @param list<string> $dates
     * @return array<string, string> date => day label
     */
    private static function labelled(array $dates): array
    {
        $labelled = [];

        foreach ($dates as $date) {
            $labelled[$date] = Dates::dayLabel($date);
        }

        return $labelled;
    }
}
