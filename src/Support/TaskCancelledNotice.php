<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;

/**
 * When an organizer deletes a whole task, every person who was signed up for
 * it gets exactly one email about it (Q263), in Finnish and English.
 *
 * Listens on the eventcrew/task_deleted action that TaskRepository::delete
 * fires with the people whose sign-up it cancelled. When the person's ticket
 * for the night was emailed, TicketMail::crewTasksChanged() sends it again
 * with the tasks left, or the "Your ticket is cancelled" email when none is
 * left; either one names the deleted task. When no ticket was emailed, the
 * person gets the "Task cancelled" email instead. A task whose date is over,
 * or whose end time has passed, sends nothing: there is nobody left to tell.
 *
 * It is about a commitment the person made, so like the sign-up and cancel
 * confirmations it is not behind a notification switch.
 */
final class TaskCancelledNotice
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly PersonRepository $people,
        private readonly Mailer $mailer,
        private readonly ?TicketMail $ticketMail = null
    ) {
    }

    public function boot(): void
    {
        add_action('eventcrew/task_deleted', [$this, 'notify'], 10, 2);
    }

    /**
     * @param array<int, int> $personIds The people whose sign-up was cancelled.
     */
    public function notify(int $taskId, array $personIds): void
    {
        if ([] === $personIds) {
            return;
        }

        $task = $this->tasks->find($taskId);

        if (null === $task || self::isOver($task)) {
            return;
        }

        foreach (array_unique(array_map('intval', $personIds)) as $personId) {
            $person = $this->people->find($personId);

            if (null === $person || $person->isAnonymized()) {
                continue;
            }

            // One email per person (Q263). When their ticket for the night
            // was emailed: the ticket again with the tasks left, or the
            // red-band cancel email when no task is left, each naming the
            // deleted task. Otherwise the "Task cancelled" email.
            if (null !== $this->ticketMail && $this->ticketMail->ticketEmailed($person->id, $task->taskDate)) {
                $this->ticketMail->crewTasksChanged($person, $task->taskDate, $task);
                continue;
            }

            $this->send($person, $task);
        }
    }

    /** True when the task's date is over, or its end time has passed. */
    private static function isOver(Task $task): bool
    {
        if ($task->isPast()) {
            return true;
        }

        return null !== $task->endsAt && $task->endsAt <= (string) current_time('mysql');
    }

    private function send(Person $person, Task $task): void
    {
        $words = function () use ($person, $task): array {
            $day = Dates::dayLabel($task->taskDate);
            $when = '' === $task->timeRange() ? $day : $day . ' ' . $task->timeRange();

            return [
                'subject' => sprintf(
                    /* translators: 1: role, 2: event */
                    __('Task cancelled: %1$s for %2$s', 'eventcrew'),
                    $task->roleLabel(),
                    $task->eventName()
                ),
                'body' => sprintf(
                    /* translators: 1: name, 2: role, 3: event, 4: date/time */
                    // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                    __("Hi %1\$s,\n\nThe organizers have cancelled the task %2\$s at %3\$s, %4\$s. You are no longer signed up for it, and its ticket no longer works at the door. This does not count against your standing.", 'eventcrew'),
                    $person->name(),
                    $task->roleLabel(),
                    $task->eventName(),
                    $when
                ),
                'button' => __('See open tasks', 'eventcrew'),
            ];
        };

        $fi = Language::run(Language::FINNISH, $words);
        $en = Language::run(Language::ENGLISH, $words);
        $both = static fn (string $key): string => $fi[$key] === $en[$key] ? $en[$key] : $fi[$key] . ' / ' . $en[$key];
        $body = $fi['body'] === $en['body'] ? $en['body'] : $fi['body'] . "\n\n————————\n\n" . $en['body'];

        $this->mailer->toPerson(
            $person->id,
            $person->email,
            $both('subject'),
            $body,
            [['label' => $both('button'), 'url' => $this->mailer->boardUrl()]]
        );
    }
}
