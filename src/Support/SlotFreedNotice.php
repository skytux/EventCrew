<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Telegram\TelegramClient;

/**
 * When a late cancellation frees a slot - close to the event, when it most needs
 * filling - this tells the crew, on both channels, so someone can step in.
 *
 * Listens on the eventcrew/slot_freed action that ClaimNotifier fires, but does
 * not send there: the cancellation only puts the task in a queue (an option).
 * The hourly Scheduler run sends it, inside the SendWindow like the other
 * crew-wide messages, in batches, so a cancellation at 02:00 does not message
 * the whole crew at 02:00, and the request that cancelled does not send one
 * email per person.
 *
 * At send time the notice is dropped when the task is gone, under way, or full
 * again (the person re-joined, or someone else took the place).
 *
 * The audience is every active recipient except the people who cancelled and
 * anyone blocked - someone already working that day is deliberately still told,
 * since they may want a second, non-overlapping slot. It honours each person's
 * open-task preference so a fill-call and the scheduled open-task call share
 * one switch.
 */
final class SlotFreedNotice
{
    /**
     * The queue: task id => queued_at (timestamp), cancellers (person ids not
     * to tell), told (person ids already handled by an earlier batch).
     */
    public const QUEUE_OPTION = 'eventcrew_slot_freed_queue';

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly PersonRepository $people,
        private readonly Mailer $mailer,
        private readonly TelegramClient $telegram
    ) {
    }

    public function boot(): void
    {
        add_action('eventcrew/slot_freed', [$this, 'queue'], 10, 2);
    }

    /**
     * Puts the task in the queue. Sends nothing. A second late cancellation on
     * a task already queued adds its canceller and keeps the rest, so nobody
     * already told is told twice.
     */
    public function queue(int $taskId, int $cancellerId): void
    {
        $queue = self::queued();
        $entry = $queue[$taskId] ?? ['queued_at' => time(), 'cancellers' => [], 'told' => []];

        if (! in_array($cancellerId, $entry['cancellers'], true)) {
            $entry['cancellers'][] = $cancellerId;
        }

        $queue[$taskId] = $entry;

        update_option(self::QUEUE_OPTION, $queue, false);
    }

    /**
     * Sends the queued notices, at most $limit people per run. Called by the
     * Scheduler, only while the SendWindow is open. A task's entry stays in the
     * queue until everyone has been handled, so the next run carries on.
     */
    public function sendDue(int $limit): void
    {
        $queue = self::queued();

        if ([] === $queue) {
            return;
        }

        $sent = 0;
        $recipients = null;
        $prefs = new NotificationPreferences();

        foreach ($queue as $taskId => $entry) {
            $task = $this->tasks->findLive($taskId);

            if (null === $task || ! $this->stillOpen($task)) {
                unset($queue[$taskId]);
                continue;
            }

            if ($sent >= $limit) {
                break;
            }

            $recipients ??= $this->people->activeEmailRecipients();
            $finished = true;

            foreach ($recipients as $person) {
                if (in_array($person->id, $entry['told'], true)) {
                    continue;
                }

                if ($sent >= $limit) {
                    $finished = false;
                    break;
                }

                $entry['told'][] = $person->id;

                // Skip the people who cancelled this slot, and anyone blocked.
                if (in_array($person->id, $entry['cancellers'], true) || $person->isBanned()) {
                    continue;
                }

                if (Language::forPerson($person, fn (): bool => $this->tell($person, $task, $prefs))) {
                    $sent++;
                }
            }

            if ($finished) {
                unset($queue[$taskId]);
            } else {
                $queue[$taskId] = $entry;
            }
        }

        update_option(self::QUEUE_OPTION, $queue, false);
    }

    /**
     * Whether the freed place is still there to step into. A cancelled
     * organize slot frees nothing: it has no limit, and it is not something to
     * announce to people who may not take it. A task already under way cannot
     * be stepped into. A task full again needs nobody.
     */
    private function stillOpen(Task $task): bool
    {
        if ($task->isRestricted() || $task->hasStarted()) {
            return false;
        }

        $taken = $this->tasks->occupancyFor([$task->id])[$task->id] ?? 0;

        return $taken < $task->capacity;
    }

    /**
     * @return array<int, array{queued_at: int, cancellers: list<int>, told: list<int>}>
     */
    private static function queued(): array
    {
        $raw = get_option(self::QUEUE_OPTION, []);
        $queue = [];

        foreach (is_array($raw) ? $raw : [] as $taskId => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $queue[(int) $taskId] = [
                'queued_at' => (int) ($entry['queued_at'] ?? 0),
                'cancellers' => array_values(array_map('intval', (array) ($entry['cancellers'] ?? []))),
                'told' => array_values(array_map('intval', (array) ($entry['told'] ?? []))),
            ];
        }

        return $queue;
    }

    /** One person, in the language they chose. Returns whether anything was sent. */
    private function tell(Person $person, Task $task, NotificationPreferences $prefs): bool
    {
        // The texts are the Messages page templates (MessageTemplates).
        $context = ['person' => $person, 'task' => $task];
        $sent = false;

        if ($prefs->dmAllowed($person, NotificationPreferences::OPEN_TASK)) {
            $this->telegram->sendMessage(
                (int) $person->telegramChatId,
                DmBody::frame($person, MessageAiText::body('slot_freed_dm', $context, $task->eventPostId), true),
                MessageActions::replyMarkup(MessageActions::of('slot_freed_dm', $context, $this->mailer))
            );
            $sent = true;
        }

        if ($prefs->emailAllowed($person, NotificationPreferences::OPEN_TASK)) {
            $this->mailer->toPerson(
                $person->id,
                $person->email,
                MessageTemplates::subject('slot_freed', $context),
                MessageAiText::body('slot_freed', $context, $task->eventPostId),
                MessageActions::email(MessageActions::of('slot_freed', $context, $this->mailer))
            );
            $sent = true;
        }

        return $sent;
    }
}
