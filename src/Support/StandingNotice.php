<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Assignment;
use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Telegram\TelegramClient;

/**
 * The delayed word to someone whose attendance was marked: against them (a
 * no-show, or a late cancellation), or for them (a completed task, or one they
 * arrived late to: the thank-you, Owner 2026-11-07). It goes out a day after an
 * admin set it, on both channels the person has - a Telegram DM (so the chat
 * keeps the record) and an email - with the standing before and after.
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

    /** The ledger kind for a recorded thank-you after a completed task. */
    public const THANKS_KIND = 'task_thanks';

    /** How long after the mark before the person is told. */
    public const DELAY_HOURS = 24;

    /**
     * A thank-you only for marks from the last few days: without it, the
     * first run would thank everyone for every task they ever completed.
     */
    public const THANKS_WINDOW_HOURS = 96;

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly PersonRepository $people,
        private readonly NotificationsRepository $ledger,
        private readonly TelegramClient $telegram,
        private readonly Mailer $mailer,
        private readonly ?StandingCalculator $standing = null,
        private readonly ?ExtraTaskCredits $extra = null
    ) {
    }

    public function boot(): void
    {
        add_action('eventcrew/assignment_status_changed', [$this, 'onStatusChanged'], 10, 2);
    }

    /**
     * The eventcrew/assignment_status_changed listener: a task changed away
     * from completed (to no-show, say) after its extra-task credit was added
     * takes that credit back, and the person is told at once (Lou
     * 2026-11-08).
     */
    public function onStatusChanged(int $assignmentId, string $status): void
    {
        if (null === $this->extra || in_array($status, AssignmentStatus::credited(), true)) {
            return;
        }

        $assignment = $this->assignments->find($assignmentId);
        $task = null === $assignment ? null : $this->tasks->find($assignment->taskId);

        if (null === $assignment || null === $task) {
            return;
        }

        $removed = $this->extra->revoke($assignment->personId, $task->taskDate);
        $person = $removed > 0 ? $this->people->find($assignment->personId) : null;

        if (null !== $person) {
            $this->creditNote($person, $removed, true);
        }
    }

    /**
     * Sends for up to $limit people whose no-show or late-cancel was marked at
     * least DELAY_HOURS ago and not yet notified, then thank-yous for completed
     * tasks with what is left of $limit. Returns how many were told.
     */
    public function sendDue(int $limit): int
    {
        $cutoff = $this->hoursAgo(self::DELAY_HOURS);
        $sent = $this->sendFor($this->assignments->needingStandingNotice($cutoff, self::KIND, $limit), self::KIND);

        if ($sent >= $limit) {
            return $sent;
        }

        $thanks = $this->assignments->needingStandingNotice(
            $cutoff,
            self::THANKS_KIND,
            $limit - $sent,
            [AssignmentStatus::COMPLETED, AssignmentStatus::ARRIVED_LATE],
            $this->hoursAgo(self::DELAY_HOURS + self::THANKS_WINDOW_HOURS)
        );

        $sent += $this->sendFor($thanks, self::THANKS_KIND);

        if ($sent >= $limit || null === $this->extra) {
            return $sent;
        }

        // A task marked completed after the night's thank-you went out: its
        // credit for an extra task is added now, with a short note.
        foreach ($this->extra->releaseLate($cutoff, self::THANKS_KIND, $limit - $sent) as $night) {
            $person = $this->people->find($night['person_id']);

            if (null !== $person) {
                $this->creditNote($person, $night['added']);
                ++$sent;
            }
        }

        return $sent;
    }

    /**
     * @param array<int, array{assignment: Assignment, task_date: string}> $due
     */
    private function sendFor(array $due, string $ledgerKind): int
    {
        $sent = 0;

        foreach ($due as $entry) {
            $assignment = $entry['assignment'];
            $task = $this->tasks->find($assignment->taskId);
            $person = $this->people->find($assignment->personId);

            // A vanished person or task can never be told; record the send so the
            // orphan row is not re-scanned on every future run.
            if (null === $task || null === $person) {
                $this->ledger->recordSent($ledgerKind, $assignment->personId, $entry['task_date']);

                continue;
            }

            // The night is over and the task done: the credits for extra
            // tasks on the same night are added now, told in the thank-you.
            $added = self::THANKS_KIND === $ledgerKind && null !== $this->extra
                ? $this->extra->release($person->id, $entry['task_date'])
                : 0;

            $this->notify($person, $task, $assignment, $added);
            $this->ledger->recordSent($ledgerKind, $person->id, $entry['task_date'], $task->eventPostId);
            ++$sent;
        }

        return $sent;
    }

    private function notify(Person $person, Task $task, Assignment $assignment, int $added = 0): void
    {
        Language::forPerson($person, fn () => $this->notifyInLanguage($person, $task, $assignment, $added));
    }

    /**
     * The short note when credits for extra tasks were added on their own,
     * after the thank-you, or taken back ($removed): on both channels, under
     * the standing toggle.
     */
    private function creditNote(Person $person, int $count, bool $removed = false): void
    {
        Language::forPerson($person, function () use ($person, $count, $removed): void {
            $standing = $this->standing?->for($person->id);
            $text = ($removed ? ExtraTaskCredits::removedLine($count) : ExtraTaskCredits::addedLine($count))
                . (null === $standing ? '' : "\n\n" . $standing->line());
            $prefs = new NotificationPreferences();

            if ($prefs->dmAllowed($person, NotificationPreferences::STANDING)) {
                $this->telegram->sendMessage($person->telegramChatId, DmBody::frame($person, $text));
            }

            PushNotifier::notify($person, NotificationPreferences::STANDING, $text);

            if ($prefs->emailAllowed($person, NotificationPreferences::STANDING)) {
                $this->mailer->toPerson(
                    $person->id,
                    $person->email,
                    $removed
                        ? __('A free-entry credit was taken back', 'eventcrew')
                        : __('A free-entry credit for your extra task', 'eventcrew'),
                    /* translators: %s: the person's name */
                    sprintf(__('Hi %s,', 'eventcrew'), $person->name()) . "\n\n" . $text
                );
            }
        });
    }

    private function notifyInLanguage(Person $person, Task $task, Assignment $assignment, int $added = 0): void
    {
        $isNoShow = AssignmentStatus::NO_SHOW === $assignment->status;
        $kind = match ($assignment->status) {
            AssignmentStatus::NO_SHOW => 'no_show',
            AssignmentStatus::LATE_CANCEL => 'late_cancel',
            default => 'task_completed',
        };
        [$before, $after] = null === $this->standing
            ? [null, null]
            : $this->standing->beforeAndAfter($person->id, $assignment->id);
        $prefs = new NotificationPreferences();
        // The texts are the Messages page templates (MessageTemplates); both
        // versions share one list of actions (MessageTemplates::actions()).
        // free_cancel: until when a cancel costs nothing, the tip for next time.
        $context = [
            'person' => $person,
            'task' => $task,
            'standing' => $this->standingLine($after),
            'standing_before' => $before,
            'standing_after' => $after,
            'free_cancel' => ClaimNotifier::freeCancelLine(),
            // Credits for extra tasks that night, added just now.
            'extra_added' => $added,
        ];

        $dmOk = $prefs->dmAllowed($person, NotificationPreferences::STANDING);
        // The DM's text, made once for the DM and the app (it may be AI text).
        $dmText = $dmOk || $prefs->pushAllowed($person, NotificationPreferences::STANDING)
            ? MessageAiText::body($kind . '_dm', $context, $task->eventPostId)
            : '';

        if ($dmOk) {
            // Framed and signed like every DM the scan sends unasked (DmBody).
            $this->telegram->sendMessage(
                $person->telegramChatId,
                DmBody::frame($person, $dmText),
                MessageActions::replyMarkup(MessageActions::of($kind . '_dm', $context, $this->mailer))
            );
        }

        if ('' !== $dmText) {
            PushNotifier::notify($person, NotificationPreferences::STANDING, $dmText);
        }

        if (! $prefs->emailAllowed($person, NotificationPreferences::STANDING)) {
            return;
        }

        $this->mailer->toPerson(
            $person->id,
            $person->email,
            MessageTemplates::subject($kind, $context),
            MessageAiText::body($kind, $context, $task->eventPostId),
            // A person with no Telegram chat also gets the crew page (CrewPageHelp).
            array_values(array_filter([
                $isNoShow ? null : CrewPageHelp::manageAction($this->mailer, $person),
                ...MessageActions::email(MessageActions::of($kind, $context, $this->mailer)),
            ]))
        );
    }

    /**
     * "Your standing: ... · N free-entry credits.", the line the sign-up and
     * cancel confirmations end with (ClaimNotifier), or '' without a
     * calculator.
     */
    private function standingLine(?Standing $standing): string
    {
        if (null === $standing) {
            return '';
        }

        return $standing->line();
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
