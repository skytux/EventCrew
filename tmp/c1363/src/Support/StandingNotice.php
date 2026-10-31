<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Telegram\TelegramClient;

/**
 * The delayed word to someone whose attendance was marked against them: a
 * no-show, or a late cancellation. It goes out a day after an admin set it, on
 * both channels the person has - a Telegram DM (so the chat keeps the record)
 * and an email.
 *
 * The day's grace is deliberate: an admin who taps no-show by mistake, or flips
 * it back after the person turns up late, has a window to undo it before the
 * person is told. The ledger, keyed like every other send, is what stops a
 * second run repeating a notice already sent.
 */
final class StandingNotice
{
    /** The ledger kind for a recorded standing notice. */
    public const KIND = 'standing_hit';

    /** How long after the mark before the person is told. */
    public const DELAY_HOURS = 24;

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly PersonRepository $people,
        private readonly NotificationsRepository $ledger,
        private readonly TelegramClient $telegram,
        private readonly Mailer $mailer
    ) {
    }

    /**
     * Sends for up to $limit people whose no-show or late-cancel was marked at
     * least DELAY_HOURS ago and not yet notified. Returns how many were told.
     */
    public function sendDue(int $limit): int
    {
        $cutoff = $this->hoursAgo(self::DELAY_HOURS);
        $sent = 0;

        foreach ($this->assignments->needingStandingNotice($cutoff, self::KIND, $limit) as $entry) {
            $assignment = $entry['assignment'];
            $task = $this->tasks->find($assignment->taskId);
            $person = $this->people->find($assignment->personId);

            // A vanished person or task can never be told; record the send so the
            // orphan row is not re-scanned on every future run.
            if (null === $task || null === $person) {
                $this->ledger->recordSent(self::KIND, $assignment->personId, $entry['task_date']);

                continue;
            }

            $this->notify($person, $task, $assignment->status);
            $this->ledger->recordSent(self::KIND, $person->id, $entry['task_date'], $task->eventPostId);
            ++$sent;
        }

        return $sent;
    }

    private function notify(Person $person, Task $task, string $status): void
    {
        $isNoShow = AssignmentStatus::NO_SHOW === $status;
        $prefs = new NotificationPreferences();

        if ($prefs->dmAllowed($person, NotificationPreferences::STANDING)) {
            // Framed but not signed: a warm sign-off under "you were marked as a
            // no-show" would read as sarcasm.
            $this->telegram->sendMessage(
                $person->telegramChatId,
                DmBody::frame($person, $this->dmText($task, $isNoShow))
            );
        }

        if (! $prefs->emailAllowed($person, NotificationPreferences::STANDING)) {
            return;
        }

        $this->mailer->toPerson(
            $person->id,
            $person->email,
            $isNoShow
                ? __('You were marked as a no-show', 'eventcrew')
                : __('Your late cancellation was recorded', 'eventcrew'),
            $this->emailBody($person, $task, $isNoShow)
        );
    }

    private function dmText(Task $task, bool $isNoShow): string
    {
        $where = sprintf('%s at %s, %s', $task->roleLabel(), $task->eventName(), $this->whenText($task));

        return $isNoShow
            ? sprintf(
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                /* translators: %s: role at event, date */
                __('⚠️ You were marked as a no-show for %s. No-shows count against your standing. If that’s a mistake, tell an organizer.', 'eventcrew'),
                $where
            )
            : sprintf(
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                /* translators: %s: role at event, date */
                __('⚠️ Your late cancellation for %s is recorded. It counts against your standing; more notice keeps it clear next time.', 'eventcrew'),
                $where
            );
    }

    private function emailBody(Person $person, Task $task, bool $isNoShow): string
    {
        $where = sprintf('%s at %s, %s', $task->roleLabel(), $task->eventName(), $this->whenText($task));

        $detail = $isNoShow
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            ? __("You were marked as a no-show for %2\$s.\n\nNo-shows count against your standing. If this is a mistake, contact an organizer and they can correct it.", 'eventcrew')
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            : __("Your late cancellation for %2\$s has been recorded.\n\nIt counts against your standing. More notice next time, or finding a replacement with /replace, keeps it clear.", 'eventcrew');

        return sprintf(
            /* translators: 1: name, 2: role at event, date */
            "Hi %1\$s,\n\n" . $detail,
            $person->name(),
            $where
        );
    }

    private function whenText(Task $task): string
    {
        $time = $task->timeRange();

        return '' === $time ? $task->taskDate : $task->taskDate . ' ' . $time;
    }

    /**
     * "now - N hours" as a mysql datetime in the site's timezone, matching the
     * format status_changed_at is stored and compared in.
     */
    private function hoursAgo(int $hours): string
    {
        $now = strtotime((string) current_time('mysql'));

        return gmdate('Y-m-d H:i:s', ($now === false ? time() : $now) - $hours * HOUR_IN_SECONDS);
    }
}
