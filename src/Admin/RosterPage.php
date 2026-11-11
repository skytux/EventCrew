<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\MailImport\MailedListImport;
use EventCrew\Models\TicketBuyer;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\BuyerImport;
use EventCrew\Support\DoorDesk;
use EventCrew\Support\DoorAddedBy;
use EventCrew\Support\DoorList;
use EventCrew\Support\FollowupMail;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\MarkedBy;
use EventCrew\Support\RosterAssembler;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\TicketMail;
use EventCrew\Telegram\TicketRedemptionService;
use EventCrew\Web\CrewAdminRoster;

/**
 * Attendance: who turned up for a given day's tasks, and marking how it went.
 *
 * The read side is the shared RosterAssembler; this page adds the date picker
 * and the two write actions - a per-person status change and a per-task "mark
 * the whole crew arrived/done" shortcut - both recording which organizer made
 * the change.
 */
final class RosterPage
{
    public const PAGE_SLUG = 'eventcrew-roster';
    private const NONCE_ACTION = 'eventcrew_attendance';

    public function __construct(
        private readonly View $view,
        private readonly RosterAssembler $assembler,
        private readonly AssignmentRepository $assignments,
        private readonly TaskRepository $tasks,
        private readonly DoorList $doorList,
        private readonly RedemptionRepository $redemptions,
        private readonly StandingCalculator $standing,
        private readonly FreeEntryGate $freeEntry,
        private readonly TicketRedemptionService $tickets,
        private readonly GuestRepository $guests,
        private readonly TicketBuyerRepository $ticketBuyers,
        private readonly BuyerImport $buyerImport,
        private readonly ?TicketMail $ticketMail = null,
        private readonly ?FollowupMail $followup = null,
        private readonly ?DoorDesk $desk = null,
        private readonly ?DoorAddedBy $addedBy = null
    ) {
    }

    public function render(): void
    {
        $dates = $this->tasks->datesWithTasks(); // most recent first
        $today = current_time('Y-m-d');

        // Upcoming nearest-first, past most-recent-first - and the roster
        // defaults to the nearest upcoming date, since that's the crew you're
        // about to run; past dates sit below a separator for marking after.
        $upcoming = array_reverse(array_values(array_filter($dates, static fn (string $d): bool => $d >= $today)));
        $past = array_values(array_filter($dates, static fn (string $d): bool => $d < $today));

        $selected = $this->selectedDate($upcoming, $past);

        $door = '' === $selected
            ? ['entrants' => [], 'candidates' => []]
            : $this->doorList->forDate($selected);
        $guests = '' === $selected ? [] : $this->guests->forDate($selected);

        $this->view->render(
            'roster',
            [
                'upcoming_dates' => $upcoming,
                'past_dates' => $past,
                'selected_date' => $selected,
                'roster' => '' === $selected ? [] : $this->assembler->forDate($selected),
                'door' => $door,
                'guests' => $guests,
                // "added by Lou · 9 Nov 14:02" under a guest someone added, by guest id.
                'guest_added_by' => null === $this->addedBy ? [] : $this->addedBy->forGuests($guests),
                'ticket_buyers' => '' === $selected ? [] : array_values(array_filter(
                    $this->ticketBuyers->forDate($selected),
                    static fn (TicketBuyer $buyer): bool => ! $buyer->isDoorSale()
                )),
                // Names and emails noted at the door for given-away tickets and +1s.
                'door_notes' => '' === $selected || null === $this->desk ? [] : $this->desk->notesList($selected),
                'import_mailbox' => MailedListImport::mailbox(),
                'ticket_mail_buyers' => '' === $selected || null === $this->ticketMail ? '' : TicketMail::statusLine(
                    $this->ticketMail->counts($selected, TicketMail::KIND_BUYERS)
                ),
                'ticket_mail_guests' => '' === $selected || null === $this->ticketMail ? '' : TicketMail::statusLine(
                    $this->ticketMail->counts($selected, TicketMail::KIND_GUESTS)
                ),
                'ticket_mail_pending' => $this->pendingLine(),
                'followup_mail' => '' === $selected || null === $this->followup ? '' : FollowupMail::statusLine(
                    $this->followup->counts($selected),
                    $this->followup->nightStatus($selected),
                    $selected
                ),
                'followup_can_stop' => '' !== $selected && null !== $this->followup
                    && $this->followup->canStop($selected),
                'free_entry_closed' => '' !== $selected && $this->freeEntry->isClosedByHand($selected),
                'free_entry_event_reason' => '' === $selected ? '' : $this->freeEntry->eventReason($selected),
                'statuses' => $this->statusChoices(),
                'nonce_action' => self::NONCE_ACTION,
                'page_slug' => self::PAGE_SLUG,
            ]
        );
    }

    /**
     * Records a credit spent for free entry on the roster's date. The balance
     * is re-checked here, not trusted from the rendered pick-list, so a stale
     * page or a double submit cannot spend a credit that is not there.
     */
    public function redeemCredit(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $personId = isset($_POST['person_id']) ? (int) $_POST['person_id'] : 0;
        $date = isset($_POST['roster_date']) ? sanitize_text_field(wp_unslash($_POST['roster_date'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ($personId <= 0 || ! $this->isValidDate($date)) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('That credit could not be redeemed.', 'eventcrew'),
                'error',
                $this->dateArg($date)
            );
        }

        if ($this->freeEntry->isClosed($date)) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('Free entry is closed for this date.', 'eventcrew'),
                'error',
                $this->dateArg($date)
            );
        }

        if ($this->standing->for($personId)->creditBalance < 1) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('They have no credit left to redeem.', 'eventcrew'),
                'error',
                $this->dateArg($date)
            );
        }

        [$eventPostId, $eventLabel] = $this->eventContext($date);
        $redemptionId = $this->redemptions->record($personId, $date, $eventPostId, $eventLabel);

        // 0 means they were already down for this date - two people working the
        // door, or one double-click. Say so rather than reporting a second
        // redemption that was not made, and send no second ticket.
        if (0 === $redemptionId) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('They already have free entry for this date.', 'eventcrew'),
                'error',
                $this->dateArg($date)
            );
        }

        // Transactional: hand the person their door-ticket link on both channels,
        // the same as the self-service 🎟 ticket flow does.
        $this->tickets->notifyRedemption($personId, $date, $redemptionId, $eventPostId);

        Admin::redirectTo(
            self::PAGE_SLUG,
            __('Credit redeemed — they’re on the door list.', 'eventcrew'),
            'success',
            $this->dateArg($date)
        );
    }

    /**
     * Undoes a redemption - a mistake at a busy door - handing the credit back.
     */
    public function removeRedemption(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $redemptionId = isset($_POST['redemption_id']) ? (int) $_POST['redemption_id'] : 0;
        $date = isset($_POST['roster_date']) ? sanitize_text_field(wp_unslash($_POST['roster_date'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $transferred = false;

        if ($redemptionId > 0) {
            // A ticket that was given away: the receiver's ticket is
            // cancelled, and the giver's credit stays spent.
            $transferred = (bool) $this->redemptions->find($redemptionId)?->isTransferred();
            // Also emails "Your ticket is cancelled" when the ticket was emailed.
            $this->tickets->cancelRedemption($redemptionId);
        }

        Admin::redirectTo(
            self::PAGE_SLUG,
            $transferred ? __('Transferred ticket cancelled.', 'eventcrew') : __('Credit handed back.', 'eventcrew'),
            'success',
            $this->dateArg($date)
        );
    }

    /**
     * Closes or reopens free entry for the roster's date - a sold-out night, or
     * a special event that credits can't be spent on. While closed, neither this
     * page's "Redeem a credit" nor the self-service 🎟 ticket flow will spend a
     * credit for that date.
     */
    public function toggleTicketClosed(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $date = isset($_POST['roster_date']) ? sanitize_text_field(wp_unslash($_POST['roster_date'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if (! $this->isValidDate($date)) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('That change could not be applied.', 'eventcrew'),
                'error'
            );
        }

        if ($this->freeEntry->isClosedByHand($date)) {
            $this->freeEntry->open($date);
            $message = __('Free entry reopened for this date.', 'eventcrew');
        } else {
            $this->freeEntry->close($date);
            $message = __('Free entry closed for this date.', 'eventcrew');
        }

        Admin::redirectTo(self::PAGE_SLUG, $message, 'success', $this->dateArg($date));
    }

    /**
     * The event a redemption on $date is recorded against: the first task's
     * event that day, so the record carries which event the credit bought.
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
     * The roster's single "Update" action: every person's status picker posts
     * together as status[<assignment_id>], so the organizer sets the whole
     * night in one submit rather than a row at a time. Each row also posts
     * shown[<assignment_id>], the status the page showed when it was built.
     * Only rows the organizer changed (status differs from shown) are written.
     * A changed row whose stored status is no longer the shown one was changed
     * by someone else after the page was opened: it is not written, and the
     * notice counts it. An unknown status or a stale id is skipped, never
     * failing the batch.
     */
    public function saveRoster(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $statuses = isset($_POST['status']) && is_array($_POST['status'])
            ? wp_unslash($_POST['status'])
            : [];
        $shown = isset($_POST['shown']) && is_array($_POST['shown'])
            ? wp_unslash($_POST['shown'])
            : [];
        $date = isset($_POST['roster_date']) ? sanitize_text_field(wp_unslash($_POST['roster_date'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $changedBy = get_current_user_id();
        $updated = 0;
        $stale = 0;

        foreach ($statuses as $assignmentId => $status) {
            $assignmentId = (int) $assignmentId;
            $status = sanitize_key((string) $status);
            $was = is_scalar($shown[$assignmentId] ?? null) ? sanitize_key((string) $shown[$assignmentId]) : '';

            if ($assignmentId <= 0 || ! AssignmentStatus::isValid($status) || $status === $was) {
                continue;
            }

            $assignment = $this->assignments->find($assignmentId);

            if (null === $assignment) {
                continue;
            }

            // No shown status (a page built before this field existed) cannot
            // prove the row is current, so it counts as changed meanwhile.
            if ($assignment->status !== $was) {
                ++$stale;
                continue;
            }

            $this->assignments->setStatus($assignmentId, $status, $changedBy, MarkedBy::WP_USER);
            ++$updated;
        }

        Admin::redirectTo(
            self::PAGE_SLUG,
            self::rosterSavedMessage($updated, $stale),
            0 === $stale ? 'success' : 'error',
            $this->dateArg($date)
        );
    }

    /**
     * The notice after saving the roster: how many people were updated, and
     * how many marks were not saved because they changed after the page was
     * opened. Shared with the crew app's roster screen.
     */
    public static function rosterSavedMessage(int $updated, int $stale): string
    {
        $parts = [];

        if ($updated > 0 || 0 === $stale) {
            $parts[] = 0 === $updated
                ? __('No changes to save.', 'eventcrew')
                : sprintf(
                    /* translators: %d: number of people updated */
                    _n('%d person updated.', '%d people updated.', $updated, 'eventcrew'),
                    $updated
                );
        }

        if ($stale > 0) {
            $parts[] = sprintf(
                /* translators: %d: number of roster marks that were not saved */
                _n(
                    '%d mark was not saved because it changed after you opened the page; reload to see it.',
                    '%d marks were not saved because they changed after you opened the page; reload to see them.',
                    $stale,
                    'eventcrew'
                ),
                $stale
            );
        }

        return implode(' ', $parts);
    }

    public function markAllForTask(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $taskId = isset($_POST['task_id']) ? (int) $_POST['task_id'] : 0;
        $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : '';
        $date = isset($_POST['roster_date']) ? sanitize_text_field(wp_unslash($_POST['roster_date'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        // Bulk marking only ever sets a whole crew arrived or completed - the
        // outcomes that apply to everyone. Every other status is per-person.
        if ($taskId <= 0 || ! in_array($status, [AssignmentStatus::ARRIVED, AssignmentStatus::COMPLETED], true)) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('That bulk update could not be applied.', 'eventcrew'),
                'error',
                $this->dateArg($date)
            );
        }

        $changedBy = get_current_user_id();
        $updated = 0;

        foreach ($this->assignments->forTask($taskId) as $assignment) {
            // Skip anyone already cancelled or marked absent - bulk "arrived"
            // is about the crew who turned up, not overwriting a recorded
            // absence.
            if (in_array($assignment->status, [AssignmentStatus::SIGNED_UP, AssignmentStatus::ARRIVED], true)) {
                $this->assignments->setStatus($assignment->id, $status, $changedBy, MarkedBy::WP_USER);
                ++$updated;
            }
        }

        Admin::redirectTo(
            self::PAGE_SLUG,
            sprintf(
                /* translators: %d: number of people updated */
                _n('%d person updated.', '%d people updated.', $updated, 'eventcrew'),
                $updated
            ),
            'success',
            $this->dateArg($date)
        );
    }

    /**
     * The requested date when it's a real date with tasks, otherwise the
     * default: the nearest upcoming date, or the most recent past one when
     * nothing is upcoming.
     *
     * @param array<int, string> $upcoming Nearest-first.
     * @param array<int, string> $past     Most-recent-first.
     */
    private function selectedDate(array $upcoming, array $past): string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $requested = isset($_GET['roster_date'])
            ? sanitize_text_field(wp_unslash($_GET['roster_date']))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if (in_array($requested, $upcoming, true) || in_array($requested, $past, true)) {
            return $requested;
        }

        return $upcoming[0] ?? ($past[0] ?? '');
    }

    /**
     * @return array<string, string>
     */
    private function statusChoices(): array
    {
        $choices = [];

        foreach (AssignmentStatus::all() as $status) {
            $choices[$status] = AssignmentStatus::label($status);
        }

        return $choices;
    }

    /**
     * Adds a guest to the roster's date (guest_id 0) or saves the changes to
     * an existing one - the same list the bot's /door keeps.
     */
    public function saveGuest(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $guestId = isset($_POST['guest_id']) ? (int) $_POST['guest_id'] : 0;
        $date = isset($_POST['roster_date']) ? sanitize_text_field(wp_unslash($_POST['roster_date'])) : '';
        $name = isset($_POST['guest_name']) ? sanitize_text_field(wp_unslash($_POST['guest_name'])) : '';
        $plusOnes = isset($_POST['guest_plus_ones']) ? (int) $_POST['guest_plus_ones'] : 0;
        $note = isset($_POST['guest_note']) ? sanitize_text_field(wp_unslash($_POST['guest_note'])) : '';
        // Null when the form had no email field: the address stays.
        $email = isset($_POST['guest_email']) ? trim(sanitize_text_field(wp_unslash($_POST['guest_email']))) : null;
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ('' === $name || ! $this->isValidDate($date)) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('A guest needs a name.', 'eventcrew'),
                'error',
                $this->dateArg($date)
            );
        }

        // The email field is optional.
        if (null !== $email && '' !== $email && ! is_email($email)) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('That email address is not valid. Correct it or leave it empty.', 'eventcrew'),
                'error',
                $this->dateArg($date)
            );
        }

        if (0 === $guestId) {
            $this->guests->add($date, $name, $plusOnes, $note, null, (string) $email);
            $message = __('Guest added.', 'eventcrew');

            // The ticket goes with the next run of the ticket email's batch.
            if (null !== $email && '' !== $email) {
                TicketMail::schedule();
            }
        } else {
            $guest = $this->guests->find($guestId);

            if (null === $guest) {
                Admin::redirectTo(
                    self::PAGE_SLUG,
                    CrewAdminRoster::noticeText('admin_change_failed'),
                    'error',
                    $this->dateArg($date)
                );
            }

            $this->guests->update($guestId, $name, $plusOnes, $note);
            $code = null === $email
                ? 'admin_guest_saved'
                : CrewAdminRoster::changeGuestEmail($this->guests, $guest, $email, $this->ticketMail);
            $message = CrewAdminRoster::noticeText($code);
        }

        Admin::redirectTo(self::PAGE_SLUG, $message, 'success', $this->dateArg($date));
    }

    public function removeGuest(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $guestId = isset($_POST['guest_id']) ? (int) $_POST['guest_id'] : 0;
        $date = isset($_POST['roster_date']) ? sanitize_text_field(wp_unslash($_POST['roster_date'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $guest = $guestId > 0 ? $this->guests->find($guestId) : null;

        if (null !== $guest) {
            $this->guests->delete($guest->id);
            // "Your ticket is cancelled", when the guest was emailed a ticket.
            $this->ticketMail?->guestCancelled($guest);
        }

        Admin::redirectTo(
            self::PAGE_SLUG,
            __('Guest removed.', 'eventcrew'),
            'success',
            $this->dateArg($date)
        );
    }

    /**
     * Imports a pasted list of Holvi ticket buyers to the roster's date. The
     * list is parsed by BuyerListParser; a name already on the date is skipped.
     */
    public function importBuyers(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $date = isset($_POST['roster_date']) ? sanitize_text_field(wp_unslash($_POST['roster_date'])) : '';
        // The textarea variant, since sanitize_text_field would fold the lines
        // into one and the list is one name per line.
        $text = isset($_POST['buyer_names']) ? sanitize_textarea_field(wp_unslash($_POST['buyer_names'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if (! $this->isValidDate($date)) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('That list could not be imported.', 'eventcrew'),
                'error'
            );
        }

        $result = $this->buyerImport->import($date, $text, 'paste');

        Admin::redirectTo(
            self::PAGE_SLUG,
            sprintf(
                /* translators: 1: number of names added, 2: number of names skipped */
                __('%1$d ticket buyers added, %2$d skipped (already on the list).', 'eventcrew'),
                $result['added'],
                $result['skipped']
            ),
            'success',
            $this->dateArg($date)
        );
    }

    /**
     * Deletes every imported ticket buyer on the roster's date, pasted or
     * mailed, so a corrected list can be imported from scratch.
     */
    public function resetBuyers(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $date = isset($_POST['roster_date']) ? sanitize_text_field(wp_unslash($_POST['roster_date'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $deleted = $this->isValidDate($date) ? $this->ticketBuyers->clearForDate($date) : 0;

        Admin::redirectTo(
            self::PAGE_SLUG,
            sprintf(
                /* translators: %d: number of ticket buyers deleted */
                _n('%d ticket buyer deleted.', '%d ticket buyers deleted.', $deleted, 'eventcrew'),
                $deleted
            ),
            'success',
            $this->dateArg($date)
        );
    }

    /**
     * "Send a test to me": one ticket email, built from the night's first
     * ticket, to the signed-in organizer's own address. No status changes.
     */
    public function sendTicketTest(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $date = isset($_POST['roster_date']) ? sanitize_text_field(wp_unslash($_POST['roster_date'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $to = (string) wp_get_current_user()->user_email;
        $result = $this->isValidDate($date) && null !== $this->ticketMail
            ? $this->ticketMail->sendTest($date, $to)
            : TicketMail::TEST_NONE;

        Admin::redirectTo(
            self::PAGE_SLUG,
            self::ticketTestMessage($result, $to),
            TicketMail::TEST_SENT === $result ? 'success' : 'error',
            $this->dateArg($date)
        );
    }

    /**
     * "Send the rest now": starts a send, TicketMail::DELAY seconds from now,
     * of the night's ticket emails that have not gone out, failed ones
     * included. ticket_kind is "buyers" or "guests". While a send waits,
     * pressing again joins it instead of starting a second one.
     */
    public function sendTicketsNow(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $date = isset($_POST['roster_date']) ? sanitize_text_field(wp_unslash($_POST['roster_date'])) : '';
        $kind = isset($_POST['ticket_kind']) ? sanitize_key(wp_unslash($_POST['ticket_kind'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if (! $this->isValidDate($date) || null === $this->ticketMail) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('That change could not be applied.', 'eventcrew'),
                'error',
                $this->dateArg($date)
            );
        }

        $send = TicketMail::scheduleRest($date, $kind);

        Admin::redirectTo(
            self::PAGE_SLUG,
            self::ticketsScheduledMessage($send['at'], $send['started']),
            'success',
            $this->dateArg($date)
        );
    }

    /**
     * Cancel on the waiting ticket send: none of it goes out, and no ticket's
     * status changes. The same capability and nonce as "Send the rest now".
     */
    public function cancelTicketSend(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $date = isset($_POST['roster_date']) ? sanitize_text_field(wp_unslash($_POST['roster_date'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $cancelled = TicketMail::cancel();

        Admin::redirectTo(
            self::PAGE_SLUG,
            self::ticketsCancelMessage($cancelled),
            $cancelled ? 'success' : 'error',
            $this->dateArg($date)
        );
    }

    /**
     * The post-event email's "Send a test to me": both versions, with a
     * sample name, to the signed-in organizer's own address.
     */
    public function sendFollowupTest(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $date = isset($_POST['roster_date']) ? sanitize_text_field(wp_unslash($_POST['roster_date'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $to = (string) wp_get_current_user()->user_email;
        $result = $this->isValidDate($date) && null !== $this->followup
            ? $this->followup->sendTest($date, $to)
            : FollowupMail::TEST_FAILED;

        Admin::redirectTo(
            self::PAGE_SLUG,
            self::followupTestMessage($result, $to),
            FollowupMail::TEST_SENT === $result ? 'success' : 'error',
            $this->dateArg($date)
        );
    }

    /** Stops the shown night's post-event email. */
    public function stopFollowup(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $date = isset($_POST['roster_date']) ? sanitize_text_field(wp_unslash($_POST['roster_date'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $result = $this->isValidDate($date) && null !== $this->followup
            ? $this->followup->stop($date)
            : FollowupMail::TOO_LATE;

        Admin::redirectTo(
            self::PAGE_SLUG,
            self::followupStopMessage($result),
            FollowupMail::TOO_LATE === $result ? 'error' : 'success',
            $this->dateArg($date)
        );
    }

    /** The notice after the post-event email's test. Shared with the crew app. */
    public static function followupTestMessage(string $result, string $to): string
    {
        return FollowupMail::TEST_SENT === $result
            ? sprintf(
                /* translators: %s: the organizer's own email address */
                __('Both post-event emails were sent to %s as a test.', 'eventcrew'),
                $to
            )
            : __('The test email could not be sent. Check the site’s email setup.', 'eventcrew');
    }

    /** The notice after stopping the post-event email. Shared with the crew app. */
    public static function followupStopMessage(string $result): string
    {
        return match ($result) {
            FollowupMail::STOPPED => __('The post-event email is stopped. It will not be sent.', 'eventcrew'),
            FollowupMail::ALREADY => __('The post-event email was already stopped.', 'eventcrew'),
            default => __('Too late: the post-event email has already gone out.', 'eventcrew'),
        };
    }

    /** The notice after "Send a test to me". Shared with the crew app. */
    public static function ticketTestMessage(string $result, string $to): string
    {
        return match ($result) {
            TicketMail::TEST_SENT => sprintf(
                /* translators: %s: the organizer's own email address */
                __('A test ticket email was sent to %s.', 'eventcrew'),
                $to
            ),
            TicketMail::TEST_FAILED => __(
                'The test email could not be sent. Check the site’s email setup.',
                'eventcrew'
            ),
            default => __('This night has no ticket with an email address to make a test from.', 'eventcrew'),
        };
    }

    /** The notice after "Send the rest now". Shared with the crew app. */
    public static function ticketsScheduledMessage(int $at, bool $started): string
    {
        return $started
            ? sprintf(
                /* translators: %s: time of day, e.g. 21:40 */
                __('Ticket emails go out at %s. Until then you can cancel them.', 'eventcrew'),
                TicketMail::clock($at)
            )
            : sprintf(
                /* translators: %s: time of day, e.g. 21:40 */
                __('A send is already waiting. It goes out at %s.', 'eventcrew'),
                TicketMail::clock($at)
            );
    }

    /** The notice after Cancel on the waiting ticket send. Shared with the crew app. */
    public static function ticketsCancelMessage(bool $cancelled): string
    {
        return $cancelled
            ? __('Sending cancelled. No ticket email was sent.', 'eventcrew')
            : __('No send was waiting. It may have gone out already.', 'eventcrew');
    }

    /** The waiting ticket send's line, or '' when nothing waits. */
    private function pendingLine(): string
    {
        $summary = $this->ticketMail?->pendingSummary();

        return null === $summary ? '' : TicketMail::pendingLine($summary);
    }

    /**
     * @return array<string, string>
     */
    private function dateArg(string $date): array
    {
        return $this->isValidDate($date) ? ['roster_date' => $date] : [];
    }

    private function isValidDate(string $date): bool
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return checkdate($month, $day, $year);
    }
}
