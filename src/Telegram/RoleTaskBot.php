<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\Dates;
use EventCrew\Support\RoleTaskService;
use EventCrew\Support\SignupService;

/**
 * /roletasks - the restricted-role tasks (Organize, DJ, VJ, ...) in the bot.
 *
 * These tasks are never on the group board. A person who holds the role gets
 * them here, in the DM: one button per task to sign up or cancel. Signing up
 * asks "Count toward free-entry credit?" first. An organizer also gets "Add
 * someone" on every upcoming task (Organize excepted): they send a name and an
 * optional email, answer the same credit question on a restricted task, and
 * the person - a member, or someone who is not a crew member yet - is put on
 * the task, and given the role when the task is restricted.
 *
 * Callback data, all "rt:<kind>:<task_id>": s = sign up (asks the question),
 * y / n = the answer, c = cancel, a = add someone, ay / an = the answer for
 * the person being added.
 */
final class RoleTaskBot
{
    use GroupBreadcrumb;

    public const PREFIX = 'rt:';

    /** What an organizer is in the middle of adding: task id, then name and email. */
    private const AWAIT_PREFIX = 'eventcrew_tg_await_role_add_';

    public function __construct(
        private readonly PersonRepository $people,
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly SignupService $signup,
        private readonly RoleTaskService $roleTasks,
        private readonly ClaimNotifier $notifier,
        private readonly TelegramClient $telegram
    ) {
    }

    /**
     * /roletasks: the list, in the DM.
     */
    public function start(int $telegramUserId, int $chatId, bool $isPrivate = true): void
    {
        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $person->isEmailVerified()) {
            $this->telegram->sendMessage($chatId, __('Set yourself up first: send /start to the bot.', 'eventcrew'));

            return;
        }

        $this->sendList($person, $telegramUserId);
        $this->sentDmNote($chatId, $isPrivate);
    }

    public function isAwaitingPerson(int $telegramUserId): bool
    {
        $pending = get_transient(self::AWAIT_PREFIX . $telegramUserId);

        return is_array($pending) && ! isset($pending['name']);
    }

    /**
     * The organizer's reply with the person to add: "Name" or "Name email".
     */
    public function capturePerson(int $telegramUserId, int $chatId, string $text): void
    {
        $pending = get_transient(self::AWAIT_PREFIX . $telegramUserId);
        $taskId = is_array($pending) ? (int) ($pending['task'] ?? 0) : 0;
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $email = '';

        if ([] !== $words && str_contains((string) end($words), '@')) {
            $email = (string) array_pop($words);
        }

        $name = trim(implode(' ', $words));

        if (0 === $taskId || ('' === $name && '' === $email)) {
            delete_transient(self::AWAIT_PREFIX . $telegramUserId);
            $this->telegram->sendMessage($chatId, __('Nothing was added. Start again with /roletasks.', 'eventcrew'));

            return;
        }

        $task = $this->tasks->find($taskId);
        $organizer = $this->people->findByTelegramUserId($telegramUserId);

        // Only a restricted task asks "Count toward free-entry credit?"; every
        // other task always counts, so the person is added straight away.
        if (null !== $task && ! $task->isRestricted() && null !== $organizer) {
            delete_transient(self::AWAIT_PREFIX . $telegramUserId);
            $result = $this->roleTasks->addPerson($organizer, $taskId, $name, $email, true);
            $this->telegram->sendMessage($chatId, RoleTaskService::text($result['code'], $result['emailed']));

            return;
        }

        set_transient(
            self::AWAIT_PREFIX . $telegramUserId,
            ['task' => $taskId, 'name' => $name, 'email' => $email],
            15 * MINUTE_IN_SECONDS
        );

        $this->telegram->sendMessage(
            $chatId,
            __('Count toward free-entry credit?', 'eventcrew'),
            $this->creditKeyboard('ay', 'an', $taskId)
        );
    }

    /**
     * A tap on any "rt:" button.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onSelect(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $telegramUserId = (int) ($callbackQuery['from']['id'] ?? 0);
        $chatId = (int) ($callbackQuery['message']['chat']['id'] ?? $telegramUserId);

        if (1 !== preg_match('/^rt:(s|y|n|c|a|ay|an):(\d+)$/', (string) ($callbackQuery['data'] ?? ''), $m)) {
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        [, $kind, $taskId] = $m;
        $taskId = (int) $taskId;
        $person = 0 === $telegramUserId ? null : $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $person->isEmailVerified()) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('Set yourself up first: send /start to the bot.', 'eventcrew'),
                true
            );

            return;
        }

        match ($kind) {
            's' => $this->askCredit($callbackId, $chatId, $person, $taskId),
            'y', 'n' => $this->join($callbackId, $chatId, $person, $taskId, 'y' === $kind),
            'c' => $this->leave($callbackId, $chatId, $person, $taskId),
            'a' => $this->askPerson($callbackId, $chatId, $person, $taskId),
            default => $this->add($callbackId, $chatId, $person, $taskId, 'ay' === $kind),
        };
    }

    private function sendList(Person $person, int $chatId): void
    {
        $own = $this->roleTasks->tasksFor($person);
        $addable = $person->isOrganizer ? $this->roleTasks->addableTasks() : [];
        $byId = [];
        $addableIds = [];

        foreach ($addable as $task) {
            $addableIds[$task->id] = true;
        }

        foreach (array_merge($own, $addable) as $task) {
            $byId[$task->id] = $task;
        }

        if ([] === $byId) {
            $this->telegram->sendMessage(
                $chatId,
                __('There are no upcoming tasks for your roles. An organizer gives roles with /roles.', 'eventcrew')
            );

            return;
        }

        usort(
            $byId,
            static fn (Task $a, Task $b): int => [$a->taskDate, $a->startsAt, $a->id]
                <=> [$b->taskDate, $b->startsAt, $b->id]
        );
        $rows = [];

        foreach ($byId as $task) {
            $row = [];

            if ($person->hasRole($task->roleSlug)) {
                $row[] = $this->roleTasks->isOn($task->id, $person->id)
                    ? ['text' => '❌ ' . $this->taskLabel($task), 'callback_data' => self::PREFIX . 'c:' . $task->id]
                    : ['text' => '✋ ' . $this->taskLabel($task), 'callback_data' => self::PREFIX . 's:' . $task->id];
            }

            if (isset($addableIds[$task->id])) {
                $row[] = [
                    'text' => '➕ ' . ([] === $row ? $this->taskLabel($task) : __('Add someone', 'eventcrew')),
                    'callback_data' => self::PREFIX . 'a:' . $task->id,
                ];
            }

            $rows[] = $row;
        }

        $this->telegram->sendMessage(
            $chatId,
            $person->isOrganizer
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                ? __("Tasks for your roles, and every upcoming task you can add someone to.\n✋ = sign up · ❌ = cancel · ➕ = add someone.", 'eventcrew')
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                : __("Tasks for your roles. These are not on the group board.\n✋ = sign up · ❌ = cancel · ➕ = add someone (organizers).", 'eventcrew'),
            ['inline_keyboard' => $rows]
        );
    }

    private function taskLabel(Task $task): string
    {
        return sprintf('%s · %s', $task->roleLabel(), Dates::dayLabel($task->taskDate));
    }

    /**
     * @return array<string, mixed>
     */
    private function creditKeyboard(string $yes, string $no, int $taskId): array
    {
        return ['inline_keyboard' => [[
            ['text' => __('Yes', 'eventcrew'), 'callback_data' => self::PREFIX . $yes . ':' . $taskId],
            ['text' => __('No', 'eventcrew'), 'callback_data' => self::PREFIX . $no . ':' . $taskId],
        ]]];
    }

    /** The task, if it is still a restricted task this person may take. */
    private function ownTask(Person $person, int $taskId): ?Task
    {
        $task = $this->tasks->find($taskId);

        return null !== $task && $task->isRestricted() && $person->hasRole($task->roleSlug) ? $task : null;
    }

    private function askCredit(string $callbackId, int $chatId, Person $person, int $taskId): void
    {
        if (null === $this->ownTask($person, $taskId)) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('That task is no longer available.', 'eventcrew'),
                true
            );

            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);
        $this->telegram->sendMessage(
            $chatId,
            __('Count toward free-entry credit?', 'eventcrew'),
            $this->creditKeyboard('y', 'n', $taskId)
        );
    }

    private function join(string $callbackId, int $chatId, Person $person, int $taskId, bool $countsCredit): void
    {
        $outcome = null === $this->ownTask($person, $taskId)
            ? SignupService::ORGANIZERS_ONLY
            : $this->signup->claim($person->id, $taskId, $countsCredit);
        $joined = in_array($outcome, [AssignmentRepository::JOIN_OK, AssignmentRepository::JOIN_REJOINED], true);

        $message = match ($outcome) {
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            SignupService::GATED => __('Your recent attendance means sign-ups are paused for now — please message the organizer.', 'eventcrew'),
            AssignmentRepository::JOIN_OK,
            AssignmentRepository::JOIN_REJOINED => __('You’re in! See you there.', 'eventcrew'),
            AssignmentRepository::JOIN_DUPLICATE => __('You’re already signed up for that.', 'eventcrew'),
            default => __('That task is no longer available.', 'eventcrew'),
        };

        $this->telegram->answerCallbackQuery($callbackId, $message, true);

        if ($joined) {
            $this->notifier->confirmSignup($person, $taskId);
        }
    }

    private function leave(string $callbackId, int $chatId, Person $person, int $taskId): void
    {
        $status = null === $this->tasks->find($taskId) ? '' : $this->signup->drop($person->id, $taskId);

        $message = match ($status) {
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            AssignmentStatus::LATE_CANCEL => __('You’re out. This close to the event it counts as a late cancellation.', 'eventcrew'),
            AssignmentStatus::CANCELLED => __('You’re out. Thanks for the notice.', 'eventcrew'),
            default => __('You weren’t signed up for that one.', 'eventcrew'),
        };

        $this->telegram->answerCallbackQuery($callbackId, $message);

        if ('' !== $status) {
            $this->notifier->confirmCancellation($person, $taskId, $status);
        }
    }

    private function askPerson(string $callbackId, int $chatId, Person $organizer, int $taskId): void
    {
        if (! $organizer->isOrganizer) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                RoleTaskService::text(RoleTaskService::ORGANIZERS_ONLY),
                true
            );

            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);
        set_transient(self::AWAIT_PREFIX . $organizer->telegramUserId, ['task' => $taskId], 15 * MINUTE_IN_SECONDS);
        $this->telegram->sendMessage(
            $chatId,
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __("Send the person's name, and their email after it if they have one.\nExample: Alex Smith alex@example.com\nWith an email they get their ticket by email. They do not need to be a crew member.", 'eventcrew')
        );
    }

    private function add(string $callbackId, int $chatId, Person $organizer, int $taskId, bool $countsCredit): void
    {
        $key = self::AWAIT_PREFIX . $organizer->telegramUserId;
        $pending = get_transient($key);
        delete_transient($key);

        if (! is_array($pending) || $taskId !== (int) ($pending['task'] ?? 0) || ! isset($pending['name'])) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('Nothing was added. Start again with /roletasks.', 'eventcrew'),
                true
            );

            return;
        }

        $result = $this->roleTasks->addPerson(
            $organizer,
            $taskId,
            (string) $pending['name'],
            (string) ($pending['email'] ?? ''),
            $countsCredit
        );

        $this->telegram->answerCallbackQuery($callbackId);
        $this->telegram->sendMessage($chatId, RoleTaskService::text($result['code'], $result['emailed']));
    }
}
