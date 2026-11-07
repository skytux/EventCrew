<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Telegram\TelegramClient;

/**
 * The signup and cancellation confirmation messages, in one place so both the
 * Telegram bot and the web signup page send exactly the same thing. Claiming a
 * slot is the same act on either channel, so its confirmation must not drift -
 * the same reasoning that put the claim/drop rules in SignupService.
 *
 * Each confirmation goes on both channels a person has: a Telegram DM (so the
 * chat itself becomes a record they can scroll back through) and an email. Both
 * are about a commitment they just made, so they are locked on - a signup or
 * cancellation always confirms, even for someone who has muted the toggleable
 * notifications.
 */
final class ClaimNotifier
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly Mailer $mailer,
        private readonly TelegramClient $telegram,
        private readonly StandingCalculator $standing,
        private readonly ?TicketMail $ticketMail = null,
        private readonly ?PersonRepository $people = null,
        private readonly ?SlotFreedNotice $slots = null
    ) {
    }

    public function boot(): void
    {
        add_action(SecondThoughts::HOOK, [$this, 'afterSecondThoughts'], 10, 3);
    }

    /**
     * A person took a task themselves (the board, the bot, the web page). The
     * confirmation waits for the second thoughts window (SecondThoughts), and
     * is sent now only when the window is off.
     */
    public function signedUp(Person $person, int $taskId): void
    {
        if (! SecondThoughts::hold($person->id, $taskId, false)) {
            $this->confirmSignup($person, $taskId);
        }
    }

    /**
     * A person cancelled a task themselves. Like signedUp(): the confirmation,
     * the "a spot opened" call and the ticket email wait for the window.
     */
    public function cancelled(Person $person, int $taskId, string $status): void
    {
        if (! SecondThoughts::hold($person->id, $taskId, true)) {
            $this->confirmCancellation($person, $taskId, $status);
        }
    }

    /**
     * The WP-Cron event at the end of a second thoughts window. Sends only what
     * is still true: nothing when the person is back where they started (took
     * and cancelled, or cancelled and took again), else the confirmation of
     * where they are now.
     */
    public function afterSecondThoughts(int $personId, int $taskId, int $heldBefore): void
    {
        $person = ($this->people ?? new PersonRepository())->find($personId);
        $assignment = $this->assignments->findFor($taskId, $personId);

        if (null === $person || null === $assignment || $assignment->isOccupying() === (1 === $heldBefore)) {
            return;
        }

        if ($assignment->isOccupying()) {
            $this->confirmSignup($person, $taskId);

            return;
        }

        // Only a cancellation of their own; anything an organizer set since
        // has its own messages.
        if (in_array($assignment->status, [AssignmentStatus::CANCELLED, AssignmentStatus::LATE_CANCEL], true)) {
            $this->confirmCancellation($person, $taskId, $assignment->status);
        }
    }

    /**
     * Confirms a successful sign-up, with the door ticket link.
     */
    public function confirmSignup(Person $person, int $taskId): void
    {
        Language::forPerson($person, fn () => $this->sendSignup($person, $taskId));
    }

    private function sendSignup(Person $person, int $taskId): void
    {
        $task = $this->tasks->find($taskId);
        $assignment = $this->assignments->findFor($taskId, $person->id);

        if (null === $task || null === $assignment) {
            return;
        }

        $about = RoleGuide::aboutLine($task);
        // The texts are the Messages page templates (MessageTemplates).
        $context = [
            'person' => $person,
            'task' => $task,
            'standing' => $this->standingLine($person),
            'free_cancel' => self::freeCancelLine(),
            'about' => '' === $about ? '' : "\n\n" . $about,
            'assignment_id' => $assignment->id,
        ];

        $this->dm(
            $person,
            MessageTemplates::body('signup_confirmed_dm', $context),
            MessageActions::replyMarkup(MessageActions::of('signup_confirmed_dm', $context, $this->mailer))
        );

        $this->mailer->toPerson(
            $person->id,
            $person->email,
            MessageTemplates::subject('signup_confirmed', $context),
            MessageTemplates::body('signup_confirmed', $context),
            // The ticket first: it is the thing this email exists to hand over.
            // Then the calendar hold, cancel and the crew guide: the same
            // actions as the DM (MessageTemplates::actions()).
            MessageActions::email(MessageActions::of('signup_confirmed', $context, $this->mailer))
        );

        // The "Your ticket" email, the same one ticket buyers get, after the
        // task email. It carries the QR code the door desk scans.
        $this->ticketMail?->sendCrewTicket($person, $assignment->id, $task->taskDate);
    }

    /**
     * " You can cancel without it affecting your standing up to N hours
     * before the task.", added after the cancel instructions of the sign-up
     * DM and email. N is the late-cancellation notice (Settings), the same
     * number SignupService uses to tell a cancellation from a late one. ''
     * when that notice is 0: then every cancellation is free.
     */
    private static function freeCancelLine(): string
    {
        $hours = max(0, (int) get_option(SignupService::NOTICE_HOURS_OPTION, 48));

        if (0 === $hours) {
            return '';
        }

        return ' ' . sprintf(
            /* translators: %d: hours before the task's start */
            _n(
                'You can cancel without it affecting your standing up to %d hour before the task.',
                'You can cancel without it affecting your standing up to %d hours before the task.',
                $hours,
                'eventcrew'
            ),
            $hours
        );
    }

    /**
     * Confirms a cancellation, with a standing note that depends on how much
     * notice it gave. The caller passes the status the drop resolved to.
     */
    public function confirmCancellation(Person $person, int $taskId, string $status): void
    {
        Language::forPerson($person, fn () => $this->sendCancellation($person, $taskId, $status));
    }

    private function sendCancellation(Person $person, int $taskId, string $status): void
    {
        $task = $this->tasks->find($taskId);

        if (null === $task) {
            return;
        }

        // A late cancellation frees a slot close to the event, when it most needs
        // filling; a listener queues a notice to the crew, which the hourly
        // run sends inside the send window. Fired here, at
        // the one place both channels' cancellations converge.
        if (AssignmentStatus::LATE_CANCEL === $status) {
            do_action('eventcrew/slot_freed', $taskId, $person->id);
        }

        // A person with no Telegram chat cannot use 🔁 Take over a task, which
        // is only in the bot (CrewPageHelp); they get no DM, so the note is
        // only in their email.
        $lateNote = CrewPageHelp::webOnly($person)
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            ? __('This was a late cancellation, which counts against your standing. More notice keeps it clear next time.', 'eventcrew')
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            : __("This was a late cancellation, which counts against your standing. Next time, more notice keeps it clear, and so does a replacement: they send /me to the bot and tap 🔁 Take over a task, and you accept.", 'eventcrew');
        // A late cancel can be undone with the Sign up again button under this
        // message; an early one says why it costs nothing (Q351).
        $note = AssignmentStatus::LATE_CANCEL === $status
            ? $lateNote . ' ' . CancelNote::undo()
            : __('Thanks for the notice.', 'eventcrew') . ' ' . CancelNote::early($task);

        // The texts are the Messages page templates (MessageTemplates).
        $context = [
            'person' => $person,
            'task' => $task,
            'cancelled' => AssignmentStatus::LATE_CANCEL === $status
                ? __('⚠️ Late cancellation', 'eventcrew')
                : __('❌ Cancelled', 'eventcrew'),
            'note' => $note,
            // The other open tasks of the event, to pick another one.
            'other_rows' => null === $this->slots ? [] : $this->slots->otherOpen($task),
            // Only a late cancellation changes the standing; else the line goes.
            'standing' => AssignmentStatus::LATE_CANCEL === $status ? $this->standingLine($person) : '',
        ];

        $this->dm(
            $person,
            MessageTemplates::body('cancel_confirmed_dm', $context),
            MessageActions::replyMarkup(MessageActions::of('cancel_confirmed_dm', $context, $this->mailer))
        );

        $this->mailer->toPerson(
            $person->id,
            $person->email,
            MessageTemplates::subject('cancel_confirmed', $context),
            MessageTemplates::body('cancel_confirmed', $context),
            // A cancellation is the moment someone is most likely to pick up a
            // different slot, so the board is one tap away.
            MessageActions::email(MessageActions::of('cancel_confirmed', $context, $this->mailer))
        );

        // When the night's ticket was emailed: the ticket email again with
        // the tasks left, or the short "Your ticket is cancelled" email when
        // no task is left, in the same two languages as the ticket.
        $this->ticketTasksChanged($person, $task->taskDate);
    }

    /**
     * The person's tasks on $date changed: a task was taken away from them.
     * Their emailed ticket for the night is sent again with the tasks left,
     * or cancelled when none is left (TicketMail::crewTasksChanged()).
     */
    public function ticketTasksChanged(Person $person, string $date): void
    {
        $this->ticketMail?->crewTasksChanged($person, $date);
    }

    /**
     * Sends a confirmation to the person's Telegram DM, when they have one. A
     * no-op for a web-only person, and swallowed by TelegramClient if the send
     * fails - a confirmation that doesn't arrive must never fail the signup.
     *
     * @param array<string, mixed>|null $replyMarkup
     */
    private function dm(Person $person, string $text, ?array $replyMarkup = null): void
    {
        // Signup and cancellation are commitment confirmations - locked on, so a
        // muted account still gets word of what it just committed to (or dropped).
        if (! (new NotificationPreferences())->dmAllowed($person, NotificationPreferences::SIGNUP)) {
            return;
        }

        // A record the person keeps: a later button tap never replaces it.
        $this->telegram->record()->sendMessage($person->telegramChatId, $text, $replyMarkup);
    }

    /**
     * A one-line "where you stand" note appended to every confirmation, so a
     * person sees their reliability and free-entry credits move as they sign up
     * and cancel - the same figures the organizer sees on the People list.
     */
    private function standingLine(Person $person): string
    {
        $standing = $this->standing->for($person->id);

        return sprintf(
            /* translators: 1: standing level and score, 2: number of free-entry credits */
            __('Your standing: %1$s · %2$d free-entry credits.', 'eventcrew'),
            $standing->ratedSummary(),
            $standing->creditBalance
        );
    }
}
