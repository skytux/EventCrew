<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Telegram\TelegramClient;

/**
 * The signup and cancellation confirmation messages, in one place so both the
 * Telegram bot and the web signup page send exactly the same thing. Claiming a
 * slot is the same act on either channel, so its confirmation must not drift -
 * the same reasoning that put the claim/drop rules in SignupService.
 *
 * Each confirmation goes on both channels a person has: a Telegram DM (so the
 * chat itself becomes a record they can scroll back through) and an email. The
 * DM is about a commitment they just made, so it goes even to a switched-off
 * account and even to one that has muted the open-task calls; only the email is
 * held back for a disabled account, honouring its "no email" wish.
 */
final class ClaimNotifier
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly Mailer $mailer,
        private readonly TelegramClient $telegram,
        private readonly StandingCalculator $standing
    ) {
    }

    /**
     * Confirms a successful sign-up, with the door ticket link.
     */
    public function confirmSignup(Person $person, int $taskId): void
    {
        $task = $this->tasks->find($taskId);
        $assignment = $this->assignments->findFor($taskId, $person->id);

        if (null === $task || null === $assignment) {
            return;
        }

        $standingLine = $this->standingLine($person);

        $this->dm(
            $person,
            sprintf(
                /* translators: 1: role, 2: event, 3: date/time */
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                __('✅ Signed up: %1$s at %2$s, %3$s. Tap the task again to cancel, or /replace to hand it on.', 'eventcrew'),
                $task->roleLabel(),
                $task->eventName(),
                $this->whenText($task)
            ) . "\n\n" . $standingLine
        );

        if ($person->isDisabled()) {
            return;
        }

        $this->mailer->toPerson(
            $person->id,
            $person->email,
            sprintf(
                /* translators: 1: role, 2: event */
                __('You’re signed up: %1$s for %2$s', 'eventcrew'),
                $task->roleLabel(),
                $task->eventName()
            ),
            sprintf(
                /* translators: 1: name, 2: role, 3: event, 4: date/time, 5: ticket link, 6: standing line */
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                __("Hi %1\$s,\n\nYou're signed up for %2\$s at %3\$s, %4\$s.\n\nShow this ticket at the door:\n%5\$s\n\n%6\$s\n\nCan't make it? Open the bot and tap the task to cancel, or /replace to hand it to someone.", 'eventcrew'),
                $person->name(),
                $task->roleLabel(),
                $task->eventName(),
                $this->whenText($task),
                $this->mailer->ticketUrl($assignment->id),
                $standingLine
            )
        );
    }

    /**
     * Confirms a cancellation, with a standing note that depends on how much
     * notice it gave. The caller passes the status the drop resolved to.
     */
    public function confirmCancellation(Person $person, int $taskId, string $status): void
    {
        $task = $this->tasks->find($taskId);

        if (null === $task) {
            return;
        }

        $note = AssignmentStatus::LATE_CANCEL === $status
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            ? __("This was a late cancellation, which counts against your standing. More notice, or finding a replacement with /replace, keeps it clear next time.", 'eventcrew')
            : __('Thanks for the notice.', 'eventcrew');

        $standingLine = $this->standingLine($person);

        $this->dm(
            $person,
            sprintf(
                /* translators: 1: cancellation kind, 2: role, 3: event, 4: standing note */
                __('%1$s: %2$s at %3$s. %4$s', 'eventcrew'),
                AssignmentStatus::LATE_CANCEL === $status
                    ? __('⚠️ Late cancellation', 'eventcrew')
                    : __('❌ Cancelled', 'eventcrew'),
                $task->roleLabel(),
                $task->eventName(),
                $note
            ) . "\n\n" . $standingLine
        );

        if ($person->isDisabled()) {
            return;
        }

        $this->mailer->toPerson(
            $person->id,
            $person->email,
            sprintf(
                /* translators: 1: role, 2: event */
                __('Cancelled: %1$s for %2$s', 'eventcrew'),
                $task->roleLabel(),
                $task->eventName()
            ),
            sprintf(
                /* translators: 1: name, 2: role, 3: event, 4: standing note, 5: standing line */
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                __("Hi %1\$s,\n\nYou've cancelled %2\$s at %3\$s, and your ticket is now disabled.\n\n%4\$s\n\n%5\$s", 'eventcrew'),
                $person->name(),
                $task->roleLabel(),
                $task->eventName(),
                $note,
                $standingLine
            )
        );
    }

    /**
     * Sends a confirmation to the person's Telegram DM, when they have one. A
     * no-op for a web-only person, and swallowed by TelegramClient if the send
     * fails - a confirmation that doesn't arrive must never fail the signup.
     */
    private function dm(Person $person, string $text): void
    {
        if (null === $person->telegramChatId || ! $person->wantsBotDms()) {
            return;
        }

        $this->telegram->sendMessage($person->telegramChatId, $text);
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

    private function whenText(Task $task): string
    {
        $time = $task->timeRange();

        return '' === $time ? $task->taskDate : $task->taskDate . ' ' . $time;
    }
}
