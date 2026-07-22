<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;

/**
 * The open-task email: "some jobs still need people". Transactional now - it
 * goes to every active account, the off switch being disabling the account -
 * but it still only sends when there is genuinely something open, skips anyone
 * already signed up for that date, and records every send so a re-run never
 * doubles up.
 *
 * Each mail also carries the recipient's own recent history and total, so it
 * reads as a personal summary rather than a broadcast.
 */
final class OpenTaskCall
{
    public const KIND = 'open_task';

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly PersonRepository $people,
        private readonly NotificationsRepository $ledger,
        private readonly Mailer $mailer
    ) {
    }

    /**
     * Sends for the nearest upcoming date that still has open slots. Returns
     * how many people were mailed (0 when nothing is open).
     */
    public function sendForNextOpenDate(): int
    {
        foreach ($this->tasks->upcomingDates() as $date) {
            if ($this->tasks->hasOpenSlotsOn($date)) {
                return $this->sendForDate($date);
            }
        }

        return 0;
    }

    /**
     * The automated call: sends for every upcoming date whose event is within
     * $leadHours of now and still has open slots. Sending for a window rather
     * than one exact day makes it robust to a missed cron run, and the ledger
     * inside sendForDate() stops anyone being mailed twice. Stops once $limit
     * people have been mailed this run; the rest resume on the next run.
     */
    public function sendDue(int $leadHours, int $limit): int
    {
        $cutoff = strtotime((string) current_time('mysql')) + $leadHours * HOUR_IN_SECONDS;
        $sent = 0;

        foreach ($this->tasks->upcomingDates() as $date) {
            if ($sent >= $limit) {
                break;
            }

            // task_date is the day a task is filed under; a date is due once its
            // midnight is within the lead window.
            $dateStart = strtotime($date . ' 00:00:00');

            if (false === $dateStart || $dateStart > $cutoff) {
                continue;
            }

            // sendForDate() no-ops on a fully-staffed date, so it is the only
            // open-slots check needed.
            $sent += $this->sendForDate($date, $limit - $sent);
        }

        return $sent;
    }

    /**
     * Mails every active recipient not already signed up for $date, up to
     * $limit of them (0 means no cap - the manual button sends the lot). The
     * ledger makes a capped or re-run send resume without doubling up.
     */
    public function sendForDate(string $date, int $limit = 0): int
    {
        if (! $this->tasks->hasOpenSlotsOn($date)) {
            return 0;
        }

        $alreadyOn = array_flip($this->assignments->personIdsAssignedOn($date));
        $openList = $this->openTasksText($date);
        $sent = 0;

        foreach ($this->people->activeEmailRecipients() as $person) {
            if ($limit > 0 && $sent >= $limit) {
                break;
            }

            if (isset($alreadyOn[$person->id]) || $this->ledger->hasSent(self::KIND, $person->id, $date)) {
                continue;
            }

            $this->mailer->toPerson(
                $person->id,
                $person->email,
                __('Some tasks still need people', 'eventcrew'),
                $this->body($person, $date, $openList)
            );
            $this->ledger->recordSent(self::KIND, $person->id, $date);
            ++$sent;
        }

        return $sent;
    }

    private function body(Person $person, string $date, string $openList): string
    {
        return sprintf(
            /* translators: 1: name, 2: date, 3: open-task list, 4: personal recap */
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __("Hi %1\$s,\n\nSome tasks still need people on %2\$s:\n\n%3\$s\n\nSign up on the board in the group.\n\n%4\$s", 'eventcrew'),
            $person->name(),
            $date,
            $openList,
            $this->recap($person)
        );
    }

    private function openTasksText(string $date): string
    {
        $tasks = $this->tasks->forDate($date);
        $occupancy = $this->tasks->occupancyFor(array_map(static fn ($task): int => $task->id, $tasks));
        $lines = [];

        foreach ($tasks as $task) {
            $taken = $occupancy[$task->id] ?? 0;

            if ($taken < $task->capacity) {
                $lines[] = sprintf(
                    '- %s at %s (%d/%d)',
                    $task->roleLabel(),
                    $task->eventName(),
                    $taken,
                    $task->capacity
                );
            }
        }

        return implode("\n", $lines);
    }

    private function recap(Person $person): string
    {
        $completed = $this->assignments->countCompletedFor($person->id);
        $recent = array_slice($this->assignments->historyFor($person->id), 0, 3);
        $lines = [];

        foreach ($recent as $entry) {
            $lines[] = sprintf(
                '- %s: %s',
                $entry['task_date'],
                AssignmentStatus::label($entry['assignment']->status)
            );
        }

        $history = [] === $lines
            ? __('This will be your first task — welcome!', 'eventcrew')
            : __('Your recent tasks:', 'eventcrew') . "\n" . implode("\n", $lines);

        return sprintf(
            /* translators: 1: recent-task list, 2: total completed */
            __("%1\$s\n\nTasks completed in total: %2\$d.", 'eventcrew'),
            $history,
            $completed
        );
    }
}
