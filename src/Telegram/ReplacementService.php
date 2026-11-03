<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\Dates;
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

                // Someone who may not take a restricted task (Organize, DJ, ...)
                // is not offered it to cover either. The cover is only looked
                // up when such a task is in the list.
                if ($task->isRestricted()) {
                    if (false === $cover) {
                        $cover = $this->people->findByTelegramUserId($telegramUserId);
                    }

                    if (SignupService::blocksRestrictedSlot($task, $cover)) {
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
     * A task was picked (callback rep:<assignmentId>): ask the holder.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onSelect(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $coverTelegramId = (int) ($callbackQuery['from']['id'] ?? 0);
        $assignmentId = (int) preg_replace('/\D/', '', (string) ($callbackQuery['data'] ?? ''));

        $original = $this->assignments->find($assignmentId);
        $cover = $this->people->findByTelegramUserId($coverTelegramId);

        if (null === $cover || ! $cover->isEmailVerified()) {
            $this->telegram->answerCallbackQuery($callbackId, SetupNudge::text(), true);

            return;
        }

        $task = null === $original ? null : $this->tasks->find($original->taskId);

        if (null === $original || null === $task || ! $original->isOccupying() || $task->isPast()) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('That task is no longer available.', 'eventcrew'),
                true
            );

            return;
        }

        // Worded like a task that is gone: someone without the role is never offered it.
        if (SignupService::blocksRestrictedSlot($task, $cover)) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('That task is no longer available.', 'eventcrew'),
                true
            );

            return;
        }

        $refusal = $this->refusalFor($cover, $original->personId, $task->id);

        if (null !== $refusal) {
            $this->telegram->answerCallbackQuery($callbackId, $refusal, true);

            return;
        }

        $result = $this->takeovers->request($original, $task, $cover);

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

    private function refusalFor(Person $cover, int $originalPersonId, int $taskId): ?string
    {
        if ($cover->id === $originalPersonId) {
            return __('That’s your own task.', 'eventcrew');
        }

        $existing = $this->assignments->findFor($taskId, $cover->id);

        if (null !== $existing && $existing->isOccupying()) {
            return __('You’re already signed up for that one.', 'eventcrew');
        }

        // No overlap check: covering someone is exactly the case where a
        // clash on paper is somebody's own business, and it was refused here
        // for the same reason it was refused on the board - which is no longer
        // a reason. Taking the same task twice is still stopped, above.
        return null;
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
