<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\TaskRepository;

/**
 * The signup and cancellation confirmation emails, in one place so both the
 * Telegram bot and the web signup page send exactly the same message. Claiming
 * a slot is the same act on either channel, so its confirmation must not drift -
 * the same reasoning that put the claim/drop rules in SignupService.
 *
 * A person who switched their account off is never mailed: the confirmation is a
 * convenience, and their "no email" wish outranks it.
 */
final class ClaimNotifier
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly Mailer $mailer
    ) {
    }

    /**
     * Confirms a successful sign-up, with the door ticket link.
     */
    public function confirmSignup(Person $person, int $taskId): void
    {
        if ($person->isDisabled()) {
            return;
        }

        $task = $this->tasks->find($taskId);
        $assignment = $this->assignments->findFor($taskId, $person->id);

        if (null === $task || null === $assignment) {
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
                /* translators: 1: name, 2: role, 3: event, 4: date/time, 5: ticket link */
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                __("Hi %1\$s,\n\nYou're signed up for %2\$s at %3\$s, %4\$s.\n\nShow this ticket at the door:\n%5\$s\n\nCan't make it? Open the bot and tap the task to drop out, or /replace to hand it to someone.", 'eventcrew'),
                $person->name(),
                $task->roleLabel(),
                $task->eventName(),
                $this->whenText($task),
                $this->mailer->ticketUrl($assignment->id)
            )
        );
    }

    /**
     * Confirms a cancellation, with a standing note that depends on how much
     * notice it gave. The caller passes the status the drop resolved to.
     */
    public function confirmCancellation(Person $person, int $taskId, string $status): void
    {
        if ($person->isDisabled()) {
            return;
        }

        $task = $this->tasks->find($taskId);

        if (null === $task) {
            return;
        }

        $note = AssignmentStatus::LATE_CANCEL === $status
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            ? __("This was a late cancellation, which counts against your standing. More notice, or finding a replacement with /replace, keeps it clear next time.", 'eventcrew')
            : __('Thanks for the notice.', 'eventcrew');

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
                /* translators: 1: name, 2: role, 3: event, 4: standing note */
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                __("Hi %1\$s,\n\nYou've dropped out of %2\$s at %3\$s, and your ticket is now disabled.\n\n%4\$s", 'eventcrew'),
                $person->name(),
                $task->roleLabel(),
                $task->eventName(),
                $note
            )
        );
    }

    private function whenText(Task $task): string
    {
        $time = $task->timeRange();

        return '' === $time ? $task->taskDate : $task->taskDate . ' ' . $time;
    }
}
