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
        // Switched off on the Scheduled messages card (Owner 2026-11-14).
        if (! ScheduledMessages::isOn(ScheduledMessages::REMINDERS)) {
            return 0;
        }

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

                if (! $this->mayRemindNow($task)) {
                    continue;
                }

                $person = $this->people->find($assignment->personId);

                if (null === $person) {
                    continue;
                }

                $this->remind($person, $task, $assignment->id);
                $this->assignments->markReminded($assignment->id);
                ++$sent;
            }
        }

        return $sent;
    }

    /**
     * How many people sendNext() would remind: those signed up for the next
     * date's tasks that have not started yet, and not reminded about them.
     */
    public function countNext(): int
    {
        return count($this->nextDue());
    }

    /**
     * The organizer's "send now": reminds everyone sendNext() counts, at
     * once, whatever the hour and the switch. Each is still reminded once
     * only (reminded_at), so the scheduled reminder skips them later.
     * Returns how many were reminded.
     */
    public function sendNext(): int
    {
        $sent = 0;

        foreach ($this->nextDue() as [$person, $task, $assignmentId]) {
            $this->remind($person, $task, $assignmentId);
            $this->assignments->markReminded($assignmentId);
            ++$sent;
        }

        return $sent;
    }

    /**
     * The people to remind for the nearest date with a task still to come.
     *
     * @return list<array{0: Person, 1: Task, 2: int}>
     */
    private function nextDue(): array
    {
        $now = (string) current_time('mysql');

        foreach ($this->tasks->upcomingDates() as $date) {
            $tasks = array_filter(
                $this->tasks->forDate((string) $date),
                static fn (Task $task): bool => null === $task->startsAt || (string) $task->startsAt >= $now
            );

            if ([] === $tasks) {
                continue;
            }

            $due = [];

            foreach ($tasks as $task) {
                foreach ($this->assignments->forTask($task->id) as $assignment) {
                    if (! $assignment->isOccupying() || null !== $assignment->remindedAt) {
                        continue;
                    }

                    $person = $this->people->find($assignment->personId);

                    if (null !== $person) {
                        $due[] = [$person, $task, $assignment->id];
                    }
                }
            }

            return $due;
        }

        return [];
    }

    /**
     * Whether this reminder may go out on this tick.
     *
     * Inside the send window, yes. Outside it, only when waiting would mean
     * missing the task altogether - one starting at eight tomorrow morning
     * cannot wait for a window that opens at nine. Everything else holds until
     * a civilised hour, which is the whole reason the window exists.
     */
    private function mayRemindNow(Task $task): bool
    {
        if (SendWindow::isOpen()) {
            return true;
        }

        // Every task here came from startingBetween(), which selects only rows
        // with a start time, so there is always one to compare.
        $starts = strtotime((string) $task->startsAt);

        return false !== $starts && $starts <= SendWindow::nextOpening();
    }

    private function remind(Person $person, Task $task, int $assignmentId): void
    {
        Language::forPerson($person, fn () => $this->remindInLanguage($person, $task, $assignmentId));
    }

    private function remindInLanguage(Person $person, Task $task, int $assignmentId): void
    {
        $description = RoleGuide::taskDescription($task);
        $about = RoleGuide::aboutLine($task);
        // The texts are the Messages page templates (MessageTemplates), with
        // the task's description in {description}. A text that does not show
        // the description (an AI text, or one with {description} taken out)
        // gets the role's "about" line under it instead.
        $withAbout = static fn (string $body): string => ('' === $about
            || ('' !== $description && str_contains($body, $description)))
            ? $body
            : $body . "\n\n" . $about;
        $context = [
            'person' => $person,
            'task' => $task,
            'assignment_id' => $assignmentId,
            'description' => $description,
        ];

        if (null !== $person->telegramChatId) {
            $this->telegram->sendMessage(
                $person->telegramChatId,
                DmBody::frame(
                    $person,
                    $withAbout(MessageAiText::body('reminder_dm', $context, $task->eventPostId))
                ),
                // The ticket, and a one-tap out, so a "can't make it" turns into
                // an early cancel someone else can cover rather than a silent
                // no-show. Cancel carries the board's own leave callback ('l:'),
                // so tapping it runs the exact same drop - late-cancel
                // classification, the slot-freed broadcast and the cancellation
                // confirmation all included (MessageTemplates::actions()).
                MessageActions::replyMarkup(MessageActions::of('reminder_dm', $context, $this->mailer))
            );
        }

        $this->mailer->toPerson(
            $person->id,
            $person->email,
            MessageTemplates::subject('reminder', $context),
            $withAbout(MessageAiText::body('reminder', $context, $task->eventPostId)),
            // The ticket again, a day out: the reminder is the message people
            // still have open when they arrive at the door. The same actions
            // as the DM, with the bot's as "Open in Telegram" links.
            MessageActions::email(MessageActions::of('reminder', $context, $this->mailer)),
            null,
            null,
            // Right under the ticket button: the event's picture and its DJ
            // text, as in the ticket email (Owner, 2026-11-08).
            EventPicture::forTask($task, $person)
        );
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
