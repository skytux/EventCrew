<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\BoardVisibility;
use EventCrew\Support\Dates;
use EventCrew\Support\Roles;
use EventCrew\Support\SignupService;
use EventCrew\Support\TakeoverService;

/**
 * "I'll cover for someone" - the person stepping in drives it, with no
 * organizer in the loop.
 *
 * The cover taps 🔁 Take over a task under /me, names the person they are covering, and the bot
 * lists that person's upcoming tasks. Picking one sends the holder a takeover
 * request (Support\TakeoverService): nothing moves until the holder agrees.
 * The holder's answer comes back here as a tko: button tap.
 */
final class ReplacementService
{
    use GroupBreadcrumb;

    private const AWAIT_PREFIX = 'eventcrew_tg_await_replace_target_';

    /** The board's Take someone's task: rep:d:<Y-m-d>, every task of that day someone is on. */
    public const DAY_PREFIX = 'rep:d:';

    /** One task's Take someone's task: rep:t:<taskId>. */
    public const TASK_PREFIX = 'rep:t:';

    public function __construct(
        private readonly AssignmentRepository $assignments,
        private readonly TaskRepository $tasks,
        private readonly PersonRepository $people,
        private readonly TelegramClient $telegram,
        private readonly TakeoverService $takeovers
    ) {
    }

    /**
     * 🔁 Take over a task: asks who the cover is standing in for. The exchange is personal
     * (it lists another member's tasks), so it runs in the DM - asked in a group
     * the prompt goes to the DM and a breadcrumb points there.
     */
    public function start(int $telegramUserId, int $chatId, bool $isPrivate = true): void
    {
        $cover = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $cover || ! $cover->isEmailVerified()) {
            // A harmless nudge, so it goes back to wherever they asked.
            $this->telegram->sendMessage($chatId, SetupNudge::text(! $isPrivate));

            return;
        }

        set_transient(self::AWAIT_PREFIX . $telegramUserId, true, 15 * MINUTE_IN_SECONDS);
        $this->telegram->sendMessage(
            $telegramUserId,
            __('Whose task are you covering? Send their name, or @mention them.', 'eventcrew'),
            MenuButtons::cancel()
        );
        $this->sentDmNote($chatId, $isPrivate);
    }

    public function isAwaitingTarget(int $telegramUserId): bool
    {
        return false !== get_transient(self::AWAIT_PREFIX . $telegramUserId);
    }

    /**
     * The Cancel button: forgets the "whose task?" question. True when one was waiting.
     */
    public function cancelPending(int $telegramUserId): bool
    {
        $waiting = $this->isAwaitingTarget($telegramUserId);
        delete_transient(self::AWAIT_PREFIX . $telegramUserId);

        return $waiting;
    }

    /**
     * The reply naming the person being covered. Resolves them - by a Telegram
     * text-mention if there is one, otherwise by name - and lists their
     * upcoming tasks to pick from.
     *
     * @param array<int, array<string, mixed>> $entities Telegram message entities.
     */
    public function captureTarget(int $telegramUserId, int $chatId, string $text, array $entities): void
    {
        delete_transient(self::AWAIT_PREFIX . $telegramUserId);

        $buttons = [];
        $cover = false;

        foreach (PersonResolver::matching($this->people, $text, $entities) as $person) {
            foreach ($this->upcomingSlots($person->id) as $entry) {
                $task = $entry['task'];

                // Someone who may not take a restricted task (Organize, DJ, ...),
                // or a task whose role is closed to guests, is not offered it to
                // cover either. The cover is only looked up when such a task is
                // in the list.
                if ($task->isRestricted() || ! Roles::guestSignup($task->roleSlug)) {
                    if (false === $cover) {
                        $cover = $this->people->findByTelegramUserId($telegramUserId);
                    }

                    if (
                        SignupService::blocksRestrictedSlot($task, $cover)
                        || BoardVisibility::blocksGuestSignup($task, $cover)
                    ) {
                        continue;
                    }
                }

                $buttons[] = [[
                    // Name · date · task · event, so the button says exactly whose
                    // slot on which day you are stepping into before you tap.
                    'text' => sprintf(
                        '%s · %s · %s · %s',
                        $person->name(),
                        Dates::dayLabel($task->taskDate),
                        $task->roleLabel(),
                        $task->eventName()
                    ),
                    'callback_data' => 'rep:' . $entry['assignmentId'],
                ]];
            }
        }

        if ([] === $buttons) {
            $this->telegram->sendMessage(
                $chatId,
                __('I couldn’t find anyone by that name with an upcoming task to cover.', 'eventcrew')
            );

            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            __('Which of their tasks are you covering?', 'eventcrew'),
            ['inline_keyboard' => $buttons]
        );
    }

    /**
     * A task was picked (callback rep:<assignmentId>): ask the holder. The
     * board's Take someone's task sends rep:d:<date> (every task of that day
     * someone is on, full or not) and one task's link rep:t:<taskId>: those
     * list the holders first (listHolders()).
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onSelect(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $coverTelegramId = (int) ($callbackQuery['from']['id'] ?? 0);
        $data = (string) ($callbackQuery['data'] ?? '');
        $cover = $this->people->findByTelegramUserId($coverTelegramId);

        if (null === $cover || ! $cover->isEmailVerified()) {
            $this->telegram->answerCallbackQuery($callbackId, SetupNudge::text(), true);

            return;
        }

        if (1 === preg_match('/^' . preg_quote(self::DAY_PREFIX, '/') . '(\d{4}-\d{2}-\d{2})$/', $data, $m)) {
            $this->listHolders($callbackQuery, $cover, $this->takenTasks($this->tasks->upcoming(), $m[1]));

            return;
        }

        if (1 === preg_match('/^' . preg_quote(self::TASK_PREFIX, '/') . '(\d+)$/', $data, $m)) {
            $task = $this->tasks->find((int) $m[1]);
            $this->listHolders($callbackQuery, $cover, null === $task ? [] : $this->takenTasks([$task], $task->taskDate));

            return;
        }

        $result = $this->takeovers->requestFor((int) preg_replace('/\D/', '', $data), $cover);

        if (! $result['ok']) {
            $this->telegram->answerCallbackQuery($callbackId, $result['text'], true);

            return;
        }

        // The full text names the deadline and is too long for a popup, so it
        // goes in the chat, where the cover can read it again later.
        $this->telegram->answerCallbackQuery($callbackId, __('Request sent.', 'eventcrew'));
        $this->telegram->sendMessage($cover->telegramChatId ?? $coverTelegramId, $result['text']);
    }

    /**
     * The 🔁 Take someone's task button under the board's task buttons, or
     * null when nobody is on any task shown. It lists the holders of every
     * task that day, full or not (Owner 2026-11-10): the board has no
     * buttons for full tasks, and a place on a task with room left can be
     * asked for too.
     *
     * @param array<int, Task> $tasks The tasks shown, all on $date.
     * @param array<int, int>  $taken Places taken, by task id.
     * @return array{text: string, callback_data: string}|null
     */
    public static function boardButton(array $tasks, array $taken, string $date): ?array
    {
        foreach ($tasks as $task) {
            if (($taken[$task->id] ?? 0) > 0) {
                return ['text' => self::takeLabel(), 'callback_data' => self::DAY_PREFIX . $date];
            }
        }

        return null;
    }

    /** The button for one task, rep:t:<id>, e.g. under "That slot just filled up." */
    public static function taskButton(int $taskId): array
    {
        return ['text' => self::takeLabel(), 'callback_data' => self::TASK_PREFIX . $taskId];
    }

    private static function takeLabel(): string
    {
        return '🔁 ' . __('Take someone’s task', 'eventcrew');
    }

    /**
     * The tasks among $tasks on $date that someone is on, full or not.
     *
     * @param array<int, Task> $tasks
     * @return list<Task>
     */
    private function takenTasks(array $tasks, string $date): array
    {
        $tasks = array_values(array_filter($tasks, static fn (Task $t): bool => $t->taskDate === $date));
        $taken = $this->tasks->occupancyFor(array_map(static fn (Task $t): int => $t->id, $tasks));

        return array_values(array_filter(
            $tasks,
            static fn (Task $t): bool => ($taken[$t->id] ?? 0) > 0
        ));
    }

    /**
     * Lists, in the DM, whose places on the given tasks the cover can
     * ask for, one button each (rep:<assignmentId>, as captureTarget()'s);
     * a tap asks that holder. Asked in the group, a breadcrumb points there.
     *
     * @param array<string, mixed> $callbackQuery
     * @param list<Task> $tasks
     */
    private function listHolders(array $callbackQuery, Person $cover, array $tasks): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $coverTelegramId = (int) ($callbackQuery['from']['id'] ?? 0);
        $chat = is_array($callbackQuery['message']['chat'] ?? null) ? $callbackQuery['message']['chat'] : [];
        $buttons = [];

        foreach ($tasks as $task) {
            foreach ($this->takeovers->holdersFor($task, $cover) as $holder) {
                $buttons[] = [[
                    'text' => implode(' · ', array_filter([$holder['name'], $task->roleDisplay(), $task->timeRange()])),
                    'callback_data' => 'rep:' . $holder['assignment_id'],
                ]];
            }
        }

        if ([] === $buttons) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('No task here can be taken over.', 'eventcrew'),
                true
            );

            return;
        }

        $this->telegram->sendMessage(
            $cover->telegramChatId ?? $coverTelegramId,
            __('Whose task do you want to take? They decide.', 'eventcrew'),
            ['inline_keyboard' => $buttons]
        );
        // Answers the tap: in a group with the note as a toast.
        $this->sentDmNote(
            (int) ($chat['id'] ?? $coverTelegramId),
            'private' === ($chat['type'] ?? 'private'),
            $callbackId
        );
    }

    /**
     * The takeover request email's "Open in Telegram" link
     * (t.me/<bot>?start=tov-<id>): the request again, with its two buttons,
     * for its holder only. Opening it answers nothing.
     */
    public function onRequestLink(int $telegramUserId, int $chatId, int $takeoverId): void
    {
        $this->takeovers->showRequest($takeoverId, $this->people->findByTelegramUserId($telegramUserId), $chatId);
    }

    /**
     * The holder tapped ✅ Let them take it or ❌ Keep my task on a takeover
     * request (callback tko:y:<id> / tko:n:<id>). The outcome replaces the
     * buttons, so the request cannot be answered twice from the same message.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onAnswer(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $data = (string) ($callbackQuery['data'] ?? '');
        $holder = $this->people->findByTelegramUserId((int) ($callbackQuery['from']['id'] ?? 0));

        if (1 !== preg_match('/^' . preg_quote(TakeoverService::CALLBACK_PREFIX, '/') . '([yn]):(\d+)$/', $data, $matches)) {
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        $outcome = null === $holder
            ? __('This link is no longer valid.', 'eventcrew')
            : $this->takeovers->answer((int) $matches[2], 'y' === $matches[1], $holder->id, true);

        $this->telegram->answerCallbackQuery($callbackId);

        $message = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);

        if (0 !== $chatId && 0 !== $messageId) {
            $original = (string) ($message['text'] ?? '');
            $this->telegram->editMessageText(
                $chatId,
                $messageId,
                '' === $original ? $outcome : $original . "\n\n" . $outcome
            );

            return;
        }

        $this->telegram->sendMessage((int) ($callbackQuery['from']['id'] ?? 0), $outcome);
    }

    /**
     * A person's occupying assignments whose task is still upcoming.
     *
     * @return array<int, array{assignmentId: int, task: \EventCrew\Models\Task}>
     */
    private function upcomingSlots(int $personId): array
    {
        $slots = [];

        foreach ($this->assignments->forPerson($personId) as $assignment) {
            if (! $assignment->isOccupying()) {
                continue;
            }

            $task = $this->tasks->find($assignment->taskId);

            if (null !== $task && ! $task->isPast()) {
                $slots[] = ['assignmentId' => $assignment->id, 'task' => $task];
            }
        }

        return $slots;
    }
}
