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

    /**
     * The bot's start payload that shows one task with its Sign up button:
     * this followed by the task id (t.me/<bot>?start=task-12).
     */
    public const START_PREFIX = 'task-';

    /** The crew page element of one task row: this followed by the task id. */
    public const ANCHOR_PREFIX = 'eventcrew-task-';

    /**
     * The placeholders the texts gained in 1.140.139: the task's details and
     * the other open tasks. Each one brings its own empty line, so they go
     * at the end of the text as they are.
     */
    public const NEW_PLACEHOLDERS = '{task_details}{other_tasks}';

    /** Set to '1' once migrateStoredTexts() has run. */
    public const MIGRATED_OPTION = 'eventcrew_slot_freed_details_migrated';

    /** The most other open tasks listed under the notice. */
    public const MAX_OTHERS = 10;

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly PersonRepository $people,
        private readonly Mailer $mailer,
        private readonly TelegramClient $telegram
    ) {
    }

    public function boot(): void
    {
        self::migrateStoredTexts();
        add_action('eventcrew/slot_freed', [$this, 'queue'], 10, 2);
    }

    /**
     * Once per site: appends {task_details} and {other_tasks} to the stored
     * texts of both kinds (slot_freed, slot_freed_dm), to the source and to
     * every language text that is not empty, each only when that text does
     * not have it yet. A kind with no stored text has the built-in one,
     * which has them already.
     */
    public static function migrateStoredTexts(): void
    {
        if ('1' === get_option(self::MIGRATED_OPTION, '')) {
            return;
        }

        foreach (['slot_freed', 'slot_freed_dm'] as $id) {
            $option = MessageTemplates::OPTION_PREFIX . $id;
            $stored = get_option($option, []);

            $body = is_array($stored) ? ($stored[MessageTemplates::BODY] ?? null) : null;

            if (is_string($body)) {
                $stored[MessageTemplates::BODY] = self::withNewPlaceholders($body);
                update_option($option, $stored, false);
                continue;
            }

            if (! is_array($body)) {
                continue;
            }

            $body['source'] = self::withNewPlaceholders((string) ($body['source'] ?? ''));

            foreach (is_array($body['texts'] ?? null) ? $body['texts'] : [] as $lang => $text) {
                $body['texts'][$lang] = self::withNewPlaceholders((string) $text);
            }

            $stored[MessageTemplates::BODY] = $body;
            update_option($option, $stored, false);
        }

        update_option(self::MIGRATED_OPTION, '1', false);
    }

    /** $text with each new placeholder it lacks added at its end; '' stays ''. */
    private static function withNewPlaceholders(string $text): string
    {
        $missing = array_filter(
            ['{task_details}', '{other_tasks}'],
            static fn (string $tag): bool => ! str_contains($text, $tag)
        );

        return '' === trim($text) || [] === $missing ? $text : rtrim($text) . implode('', $missing);
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
            $free = null === $task ? 0 : $this->freePlaces($task);

            if (null === $task || $free <= 0) {
                unset($queue[$taskId]);
                continue;
            }

            if ($sent >= $limit) {
                break;
            }

            $recipients ??= $this->people->activeEmailRecipients();
            $finished = true;
            $facts = ['free' => $free, 'other_rows' => $this->otherOpen($task)];

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

                if (Language::forPerson($person, fn (): bool => $this->tell($person, $task, $prefs, $facts))) {
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
     * How many places are still there to step into, 0 when none. A cancelled
     * organize slot frees nothing: it has no limit, and it is not something to
     * announce to people who may not take it. A task already under way cannot
     * be stepped into. A task full again needs nobody.
     */
    private function freePlaces(Task $task): int
    {
        if ($task->isRestricted() || $task->hasStarted()) {
            return 0;
        }

        $taken = $this->tasks->occupancyFor([$task->id])[$task->id] ?? 0;

        return max(0, $task->capacity - $taken);
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

    /**
     * The other open tasks of $task's event, for the short board under the
     * notice: not $task, not restricted to a role, not under way, with a free
     * place. At most MAX_OTHERS, in time order. Only tasks and counts: no
     * names.
     *
     * @return list<array{task: Task, free: int}>
     */
    public function otherOpen(Task $task): array
    {
        $tasks = null !== $task->eventPostId
            ? $this->tasks->forEvent($task->eventPostId)
            : array_filter(
                $this->tasks->forDate($task->taskDate),
                static fn (Task $other): bool => null === $other->eventPostId && $other->eventLabel === $task->eventLabel
            );
        $tasks = array_values(array_filter(
            $tasks,
            static fn (Task $other): bool => $other->id !== $task->id && ! $other->isRestricted() && ! $other->hasStarted()
        ));

        if ([] === $tasks) {
            return [];
        }

        $occupancy = $this->tasks->occupancyFor(array_map(static fn (Task $other): int => $other->id, $tasks));
        $rows = [];

        foreach ($tasks as $other) {
            $free = $other->capacity - ($occupancy[$other->id] ?? 0);

            if ($free > 0) {
                $rows[] = ['task' => $other, 'free' => $free];
            }

            if (count($rows) >= self::MAX_OTHERS) {
                break;
            }
        }

        return $rows;
    }

    /**
     * The task's details, one per line: the role, the event, the day and
     * time, the free places, then the description of the task (else of its
     * role) after an empty line when there is one.
     */
    public static function details(Task $task, int $free): string
    {
        $lines = [
            /* translators: %s: the task's role, with its emoji */
            sprintf(__('Task: %s', 'eventcrew'), $task->roleDisplay()),
            /* translators: %s: the name of the event */
            sprintf(__('Event: %s', 'eventcrew'), $task->eventName()),
            /* translators: %s: the day and the time, like Sat 14 Nov 18:00–20:00 */
            sprintf(__('When: %s', 'eventcrew'), self::when($task)),
            self::freeText(max(0, $free), $task->capacity),
        ];
        $about = self::description($task);

        return implode("\n", $lines) . ('' === $about ? '' : "\n\n" . $about);
    }

    /**
     * The short board of the other open tasks: a heading and one "- " line
     * per task (role, time, free places), or '' when there are none. The day
     * is named only when it is not $task's day.
     *
     * @param list<array{task: Task, free: int}> $rows
     */
    public static function others(Task $task, array $rows): string
    {
        if ([] === $rows) {
            return '';
        }

        $lines = [sprintf(
            /* translators: %s: the name of the event */
            __('Other open tasks at %s:', 'eventcrew'),
            $task->eventName()
        )];

        foreach ($rows as $row) {
            $other = $row['task'];
            $time = $other->taskDate === $task->taskDate
                ? $other->timeRange()
                : trim(Dates::dayLabel($other->taskDate) . ' ' . $other->timeRange());
            $parts = array_filter([$other->roleDisplay(), $time, self::freeText($row['free'], $other->capacity)]);
            $lines[] = '- ' . implode(' · ', $parts);
        }

        return implode("\n", $lines);
    }

    /**
     * Two made-up open tasks on $task's day, for the preview on the Messages
     * page: roles other than $task's where there are any.
     *
     * @return list<array{task: Task, free: int}>
     */
    public static function sampleOthers(Task $task): array
    {
        $slugs = array_values(array_diff(
            array_map(static fn (array $role): string => (string) $role['slug'], Roles::forTemplate()),
            [$task->roleSlug]
        ));
        $slugs = [] === $slugs ? [$task->roleSlug] : $slugs;
        $rows = [];

        foreach ([['20:00', '22:00', 1], ['22:00', '00:30', 2]] as $index => [$start, $end, $free]) {
            $endDay = '00:30' === $end ? gmdate('Y-m-d', (int) strtotime($task->taskDate . ' +1 day')) : $task->taskDate;
            $rows[] = ['task' => new Task(
                0,
                $task->taskDate,
                $slugs[$index % count($slugs)],
                2,
                $task->eventPostId,
                $task->eventLabel,
                $task->taskDate . ' ' . $start . ':00',
                $endDay . ' ' . $end . ':00'
            ), 'free' => $free];
        }

        return $rows;
    }

    /** The bot's start payload for $taskId, task-12. */
    public static function startPayload(int $taskId): string
    {
        return self::START_PREFIX . $taskId;
    }

    /** The task id in a start payload made by startPayload(), 0 for any other payload. */
    public static function taskFromPayload(string $payload): int
    {
        $screen = MessageActions::screenPayload($payload);

        return null !== $screen && 'task' === $screen[0] ? (int) $screen[1] : 0;
    }

    /**
     * The task's description (its notes, which the calendar file also uses
     * as its description), else its role's, in the language being composed
     * in; '' when neither has one.
     */
    private static function description(Task $task): string
    {
        $notes = trim(TranslatedText::forReader($task->notes));

        return '' !== $notes ? $notes : trim($task->roleDescription());
    }

    private static function when(Task $task): string
    {
        $day = Dates::dayLabel($task->taskDate);

        return '' === $task->timeRange() ? $day : $day . ' ' . $task->timeRange();
    }

    private static function freeText(int $free, int $capacity): string
    {
        return sprintf(
            /* translators: 1: free places, 2: places in total */
            __('%1$d of %2$d places free', 'eventcrew'),
            $free,
            $capacity
        );
    }

    /**
     * One person, in the language they chose. Returns whether anything was sent.
     *
     * @param array{free: int, other_rows: list<array{task: Task, free: int}>} $facts
     */
    private function tell(Person $person, Task $task, NotificationPreferences $prefs, array $facts): bool
    {
        // The texts are the Messages page templates (MessageTemplates).
        $context = ['person' => $person, 'task' => $task] + $facts;
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
