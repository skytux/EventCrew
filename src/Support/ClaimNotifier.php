<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Telegram\CalendarController;
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
        private readonly ?TicketMail $ticketMail = null
    ) {
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
        ];

        $this->dm(
            $person,
            MessageTemplates::body('signup_confirmed_dm', $context),
            ['inline_keyboard' => $this->signupKeyboard($task->id, $assignment->id)]
        );

        $this->mailer->toPerson(
            $person->id,
            $person->email,
            MessageTemplates::subject('signup_confirmed', $context),
            MessageTemplates::body('signup_confirmed', $context),
            // The ticket first: it is the thing this email exists to hand over.
            // The calendar hold second, since a task in someone's own calendar,
            // with its own alarm, is the strongest nudge there is against a
            // no-show.
            // The crew guide last, for anyone who wants the full detail.
            array_values(array_filter([
                ['label' => __('Show your ticket', 'eventcrew'), 'url' => $this->mailer->ticketUrl($assignment->id)],
                ['label' => __('Add to calendar', 'eventcrew'), 'url' => CalendarController::url($task->id)],
                RoleGuide::emailAction(),
            ]))
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
     * The confirmation DM's links, each a named button rather than a raw
     * address: the ticket first, since it is what gets them in; then the
     * calendar hold, the strongest nudge there is against a no-show; then the
     * crew guide when one is set.
     *
     * @return list<list<array<string, string>>>
     */
    private function signupKeyboard(int $taskId, int $assignmentId): array
    {
        $rows = [
            [['text' => __('🎟 Show your ticket', 'eventcrew'), 'url' => $this->mailer->ticketUrl($assignmentId)]],
            [['text' => __('📅 Add to calendar', 'eventcrew'), 'url' => CalendarController::url($taskId)]],
        ];
        $guide = RoleGuide::button();

        if (null !== $guide) {
            $rows[] = [$guide];
        }

        return $rows;
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

        $note = AssignmentStatus::LATE_CANCEL === $status
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            ? __("This was a late cancellation, which counts against your standing. More notice, or finding a replacement with 🔁 Take over a task under /me, keeps it clear next time.", 'eventcrew')
            : __('Thanks for the notice.', 'eventcrew');

        // The texts are the Messages page templates (MessageTemplates).
        $context = [
            'person' => $person,
            'task' => $task,
            'cancelled' => AssignmentStatus::LATE_CANCEL === $status
                ? __('⚠️ Late cancellation', 'eventcrew')
                : __('❌ Cancelled', 'eventcrew'),
            'note' => $note,
            'standing' => $this->standingLine($person),
        ];

        $this->dm($person, MessageTemplates::body('cancel_confirmed_dm', $context));

        $this->mailer->toPerson(
            $person->id,
            $person->email,
            MessageTemplates::subject('cancel_confirmed', $context),
            MessageTemplates::body('cancel_confirmed', $context),
            // A cancellation is the moment someone is most likely to pick up a
            // different slot, so the board is one tap away.
            [['label' => __('See open tasks', 'eventcrew'), 'url' => $this->mailer->boardUrl()]]
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

        $this->telegram->sendMessage($person->telegramChatId, $text, $replyMarkup);
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
