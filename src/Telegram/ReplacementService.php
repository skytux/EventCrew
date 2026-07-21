<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssignmentStatus;

/**
 * "I found someone to cover" - the graceful way off a task.
 *
 * A plain cancel close to the event counts against reputation because a
 * replacement is hard to find; doing that finding yourself is exactly the
 * behaviour to reward, not penalise. So `/replace` frees the slot as a neutral
 * `cancelled` (no late penalty), names the replacement in the group so the crew
 * knows who is coming, and leaves the organizer to confirm it as `replaced`
 * (which credits the person) once the cover actually shows up.
 */
final class ReplacementService
{
    private const AWAIT_PREFIX = 'eventcrew_tg_await_replacement_';

    public function __construct(
        private readonly AssignmentRepository $assignments,
        private readonly TaskRepository $tasks,
        private readonly PersonRepository $people,
        private readonly BoardService $board,
        private readonly TelegramClient $telegram
    ) {
    }

    /**
     * Handles /replace in a private chat: lists the person's upcoming slots to
     * pick which one they have found cover for.
     */
    public function start(int $telegramUserId, int $chatId): void
    {
        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $person->isEmailVerified()) {
            $this->telegram->sendMessage(
                $chatId,
                __('Set yourself up first before handing a task over.', 'eventcrew')
            );

            return;
        }

        $slots = $this->upcomingSlots($person->id);

        if ([] === $slots) {
            $this->telegram->sendMessage(
                $chatId,
                __('You have no upcoming tasks to hand over.', 'eventcrew')
            );

            return;
        }

        $keyboard = [];

        foreach ($slots as $task) {
            $keyboard[] = [[
                'text' => sprintf('%s — %s', $task->roleLabel(), $task->eventName()),
                'callback_data' => 'rep:' . $task->id,
            ]];
        }

        $this->telegram->sendMessage(
            $chatId,
            __('Which task did you find a replacement for?', 'eventcrew'),
            ['inline_keyboard' => $keyboard]
        );
    }

    /**
     * A task was picked (callback rep:<taskId>): remember it and ask for the
     * replacement's name.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onSelect(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $telegramUserId = (int) ($callbackQuery['from']['id'] ?? 0);
        $chatId = (int) ($callbackQuery['message']['chat']['id'] ?? $telegramUserId);
        $taskId = (int) preg_replace('/\D/', '', (string) ($callbackQuery['data'] ?? ''));

        $person = $this->people->findByTelegramUserId($telegramUserId);
        $assignment = null === $person ? null : $this->assignments->findFor($taskId, $person->id);

        if (null === $assignment || ! $assignment->isOccupying()) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('You are not signed up for that task.', 'eventcrew'),
                true
            );

            return;
        }

        set_transient(self::AWAIT_PREFIX . $telegramUserId, $taskId, 15 * MINUTE_IN_SECONDS);
        $this->telegram->answerCallbackQuery($callbackId);
        $this->telegram->sendMessage(
            $chatId,
            __('Who is taking your place? Reply with their name.', 'eventcrew')
        );
    }

    public function isAwaitingName(int $telegramUserId): bool
    {
        return false !== get_transient(self::AWAIT_PREFIX . $telegramUserId);
    }

    /**
     * The reply after a task was picked: the replacement's name. Frees the slot
     * (neutral cancel), announces the cover in the group, and confirms.
     */
    public function captureName(int $telegramUserId, int $chatId, string $text): void
    {
        $taskId = (int) get_transient(self::AWAIT_PREFIX . $telegramUserId);

        if ($taskId <= 0) {
            return;
        }

        delete_transient(self::AWAIT_PREFIX . $telegramUserId);

        $name = sanitize_text_field($text);
        $person = $this->people->findByTelegramUserId($telegramUserId);
        $task = $this->tasks->find($taskId);

        if ('' === $name || null === $person || null === $task) {
            return;
        }

        $assignment = $this->assignments->findFor($taskId, $person->id);

        if (null !== $assignment && $assignment->isOccupying()) {
            // Neutral cancel: no late penalty, because cover was found. The
            // organizer upgrades it to `replaced` on the roster once confirmed.
            $this->assignments->setStatus($assignment->id, AssignmentStatus::CANCELLED);
        }

        $this->board->announce(sprintf(
            /* translators: 1: replacement's name, 2: person handing over, 3: role, 4: event */
            __('%1$s is replacing %2$s for %3$s (%4$s).', 'eventcrew'),
            $name,
            $person->name(),
            $task->roleLabel(),
            $task->eventName()
        ));

        $this->telegram->sendMessage(
            $chatId,
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __('Thanks — I’ve let the group know and freed your slot. An organizer will confirm the cover.', 'eventcrew')
        );
    }

    /**
     * The person's occupying assignments whose task is still upcoming - the
     * only ones it makes sense to hand over.
     *
     * @return array<int, \EventCrew\Models\Task>
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
                $slots[] = $task;
            }
        }

        return $slots;
    }
}
