<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssignmentStatus;

/**
 * "I'll cover for someone" - the person stepping in drives it, and it completes
 * itself with no organizer in the loop.
 *
 * The cover runs /replace, names the person they are covering, and the bot
 * lists that person's upcoming tasks. Picking one swaps the two in a single
 * step: the original is marked `replaced` (which frees the slot and earns them
 * the reputation credit for arranging cover), and the cover is signed up in
 * their place. Both are told, and the group is told.
 */
final class ReplacementService
{
    private const AWAIT_PREFIX = 'eventcrew_tg_await_replace_target_';

    public function __construct(
        private readonly AssignmentRepository $assignments,
        private readonly TaskRepository $tasks,
        private readonly PersonRepository $people,
        private readonly BoardService $board,
        private readonly TelegramClient $telegram
    ) {
    }

    /**
     * /replace: asks who the cover is standing in for. The exchange is personal
     * (it lists another member's tasks), so it runs in the DM - asked in a group
     * the prompt goes to the DM and a breadcrumb points there.
     */
    public function start(int $telegramUserId, int $chatId, bool $isPrivate = true): void
    {
        $cover = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $cover || ! $cover->isEmailVerified()) {
            // A harmless nudge, so it goes back to wherever they asked.
            $this->telegram->sendMessage(
                $chatId,
                __('Set yourself up first, then you can cover someone’s task.', 'eventcrew')
            );

            return;
        }

        set_transient(self::AWAIT_PREFIX . $telegramUserId, true, 15 * MINUTE_IN_SECONDS);
        $this->telegram->sendMessage(
            $telegramUserId,
            __('Whose task are you covering? Send their name, or @mention them.', 'eventcrew')
        );

        if (! $isPrivate) {
            $this->telegram->sendMessage($chatId, __('📬 Sent you a DM.', 'eventcrew'));
        }
    }

    public function isAwaitingTarget(int $telegramUserId): bool
    {
        return false !== get_transient(self::AWAIT_PREFIX . $telegramUserId);
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

        foreach ($this->resolveTargets($text, $entities) as $person) {
            foreach ($this->upcomingSlots($person->id) as $entry) {
                $task = $entry['task'];
                $buttons[] = [[
                    // Name · date · task · event, so the button says exactly whose
                    // slot on which day you are stepping into before you tap.
                    'text' => sprintf(
                        '%s · %s · %s · %s',
                        $person->name(),
                        $task->taskDate,
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
     * A task was picked (callback rep:<assignmentId>): swap the two.
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
            $this->telegram->answerCallbackQuery($callbackId, __('Set yourself up first.', 'eventcrew'), true);

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

        $refusal = $this->refusalFor($cover, $original->personId, $task->id);

        if (null !== $refusal) {
            $this->telegram->answerCallbackQuery($callbackId, $refusal, true);

            return;
        }

        $this->swap($callbackId, $original->id, $original->personId, $task->id, $cover);
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

        if ($this->assignments->hasOverlapping($cover->id, $taskId)) {
            return __('That clashes with another slot you already hold.', 'eventcrew');
        }

        return null;
    }

    private function swap(
        string $callbackId,
        int $originalAssignmentId,
        int $originalPersonId,
        int $taskId,
        Person $cover
    ): void {
        // Free the original's slot as `replaced` (their credit for arranging
        // cover), then sign the cover up. If the freed slot is lost to a race
        // before the cover can take it, put the original back and say so.
        $this->assignments->setStatus($originalAssignmentId, AssignmentStatus::REPLACED, $cover->id);
        $outcome = $this->assignments->join($taskId, $cover->id);

        if (! in_array($outcome, [AssignmentRepository::JOIN_OK, AssignmentRepository::JOIN_REJOINED], true)) {
            $this->assignments->setStatus($originalAssignmentId, AssignmentStatus::SIGNED_UP, $cover->id);
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('That slot filled up before I could swap it.', 'eventcrew'),
                true
            );

            return;
        }

        $task = $this->tasks->find($taskId);
        $original = $this->people->find($originalPersonId);
        $originalName = null === $original ? __('someone', 'eventcrew') : $original->name();
        $role = null === $task ? '' : $task->roleLabel();
        $event = null === $task ? '' : $task->eventName();

        $this->telegram->answerCallbackQuery(
            $callbackId,
            sprintf(
                /* translators: 1: person being covered, 2: role */
                __('Done — you’re covering %1$s’s %2$s.', 'eventcrew'),
                $originalName,
                $role
            ),
            true
        );

        if (null !== $original && null !== $original->telegramChatId) {
            $this->telegram->sendMessage(
                $original->telegramChatId,
                sprintf(
                    /* translators: 1: cover's name, 2: role, 3: event */
                    __('%1$s is now covering your %2$s at %3$s. Thanks for arranging it!', 'eventcrew'),
                    $cover->name(),
                    $role,
                    $event
                )
            );
        }

        $this->board->announce(sprintf(
            /* translators: 1: cover's name, 2: person being covered, 3: role, 4: event */
            __('%1$s is covering for %2$s: %3$s at %4$s.', 'eventcrew'),
            $cover->name(),
            $originalName,
            $role,
            $event
        ));
        $this->board->refresh();
    }

    /**
     * The people the named text points at: an exact match from a Telegram
     * text-mention if present, else a name search.
     *
     * @param array<int, array<string, mixed>> $entities
     * @return array<int, Person>
     */
    private function resolveTargets(string $text, array $entities): array
    {
        foreach ($entities as $entity) {
            if ('text_mention' === ($entity['type'] ?? '') && isset($entity['user']['id'])) {
                $person = $this->people->findByTelegramUserId((int) $entity['user']['id']);

                if (null !== $person) {
                    return [$person];
                }
            }
        }

        $name = ltrim(trim($text), '@');

        return '' === $name ? [] : $this->people->all(['search' => $name, 'per_page' => 10]);
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
