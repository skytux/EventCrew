<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Telegram\TelegramClient;

/**
 * The task reminder: a nudge to everyone signed up for a task that is about to
 * happen, sent once each. It goes out on both channels a person has - a Telegram
 * DM and an email - since a reminder is worth more than tidiness.
 *
 * `assignments.reminded_at` is the once-per-assignment guard, stamped by
 * markReminded(), so a re-run (or the cron fallback firing again) never reminds
 * the same person about the same task twice.
 */
final class ReminderCall
{
    /** How far ahead a task's start must be to be worth reminding about. */
    public const REMINDER_HOURS = 24;

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly PersonRepository $people,
        private readonly TelegramClient $telegram,
        private readonly Mailer $mailer
    ) {
    }

    /**
     * Reminds up to $limit people about imminent tasks. Returns how many were
     * reminded; the cap is what keeps a run on a shared host from timing out,
     * with the next run resuming on the still-unreminded rows.
     */
    public function run(int $limit): int
    {
        $now = (string) current_time('mysql');
        $until = $this->hoursAhead(self::REMINDER_HOURS);

        $sent = 0;

        foreach ($this->tasks->startingBetween($now, $until) as $task) {
            foreach ($this->assignments->forTask($task->id) as $assignment) {
                if ($sent >= $limit) {
                    return $sent;
                }

                if (! $assignment->isOccupying() || null !== $assignment->remindedAt) {
                    continue;
                }

                $person = $this->people->find($assignment->personId);

                if (null === $person) {
                    continue;
                }

                $this->remind($person, $task);
                $this->assignments->markReminded($assignment->id);
                ++$sent;
            }
        }

        return $sent;
    }

    private function remind(Person $person, Task $task): void
    {
        $when = $this->whenText($task);

        if (null !== $person->telegramChatId) {
            $this->telegram->sendMessage(
                $person->telegramChatId,
                sprintf(
                    /* translators: 1: role, 2: event, 3: date/time */
                    __('Reminder: you’re on %1$s at %2$s, %3$s. See you there!', 'eventcrew'),
                    $task->roleLabel(),
                    $task->eventName(),
                    $when
                )
            );
        }

        // A disabled account asked for no email; the DM above still goes, since
        // it is about a commitment they made, but the mail is held back.
        if ($person->isDisabled()) {
            return;
        }

        $this->mailer->toPerson(
            $person->id,
            $person->email,
            sprintf(
                /* translators: 1: role, 2: event */
                __('Reminder: %1$s for %2$s', 'eventcrew'),
                $task->roleLabel(),
                $task->eventName()
            ),
            sprintf(
                /* translators: 1: name, 2: role, 3: event, 4: date/time */
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                __("Hi %1\$s,\n\nJust a reminder that you're on %2\$s at %3\$s, %4\$s.\n\nCan't make it? Open the bot and tap the task to cancel, or ask someone to type /replace to take it over.", 'eventcrew'),
                $person->name(),
                $task->roleLabel(),
                $task->eventName(),
                $when
            )
        );
    }

    private function whenText(Task $task): string
    {
        $time = $task->timeRange();

        return '' === $time ? $task->taskDate : $task->taskDate . ' ' . $time;
    }

    /**
     * "now + N hours" as a mysql datetime in the site's timezone, matching the
     * format starts_at is stored and compared in.
     */
    private function hoursAhead(int $hours): string
    {
        $now = strtotime((string) current_time('mysql'));

        return gmdate('Y-m-d H:i:s', ($now === false ? time() : $now) + $hours * HOUR_IN_SECONDS);
    }
}
