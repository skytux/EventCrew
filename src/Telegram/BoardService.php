<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Task;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\Logger;

/**
 * The board itself: the single group message that lists open tasks, and the
 * join/leave taps that come back from its buttons.
 *
 * The board is one shared message, not one per person, so its buttons show a
 * task's state (taken/capacity) rather than any one viewer's. Who tapped is
 * known only from the callback, and the per-person answer - "you're in",
 * "that's full", "confirm your email first" - is delivered as a private
 * callback alert, while the shared counts are refreshed in place afterwards.
 */
final class BoardService
{
    /** Stores the live board's chat and message ids: {chat_id, message_id}. */
    public const BOARD_OPTION = 'eventcrew_telegram_board';

    /** Cached from getMe, for the "set me up" deep-link button. */
    public const USERNAME_OPTION = 'eventcrew_telegram_bot_username';

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly PersonRepository $people,
        private readonly TelegramClient $telegram,
        private readonly Logger $logger
    ) {
    }

    /**
     * Remembers which chat the board lives in, learned when an organizer runs
     * /board there. The message id is dropped so the next refresh posts a
     * fresh board into this chat rather than editing one from another.
     */
    public function setBoardChat(int $chatId): void
    {
        update_option(self::BOARD_OPTION, ['chat_id' => $chatId, 'message_id' => 0]);
    }

    /**
     * Posts the board, or edits the existing one in place when we already have
     * its message id. Inert until both a bot token and a board chat exist, so
     * task edits on an un-configured install do nothing.
     */
    public function refresh(): void
    {
        if (! $this->telegram->isConfigured()) {
            return;
        }

        $board = $this->board();
        $chatId = (int) ($board['chat_id'] ?? 0);

        if (0 === $chatId) {
            return;
        }

        $rendered = $this->render();
        $markup = [] === $rendered['keyboard'] ? null : ['inline_keyboard' => $rendered['keyboard']];
        $messageId = (int) ($board['message_id'] ?? 0);

        if ($messageId > 0) {
            $this->telegram->editMessageText($chatId, $messageId, $rendered['text'], $markup);

            return;
        }

        $result = $this->telegram->sendMessage($chatId, $rendered['text'], $markup);

        if (null !== $result && isset($result['message_id'])) {
            update_option(self::BOARD_OPTION, [
                'chat_id' => $chatId,
                'message_id' => (int) $result['message_id'],
            ]);
        }
    }

    /**
     * Builds the board text and its inline keyboard. Tasks are grouped by
     * event, and the grouping only shows headings when more than one event is
     * open at once - the multi-event board - so a single event stays a plain
     * list.
     *
     * @return array{text: string, keyboard: array<int, array<int, array<string, mixed>>>}
     */
    public function render(): array
    {
        $tasks = $this->tasks->upcoming();

        if ([] === $tasks) {
            return [
                'text' => __('No open tasks right now. Check back soon!', 'eventcrew'),
                'keyboard' => [],
            ];
        }

        $occupancy = $this->tasks->occupancyFor(array_map(static fn (Task $t): int => $t->id, $tasks));
        $groups = $this->groupByEvent($tasks);
        $multiEvent = count($groups) > 1;

        $lines = [__('Open tasks — tap one to sign up, tap again to drop out:', 'eventcrew')];
        $keyboard = [];

        foreach ($groups as $group) {
            if ($multiEvent) {
                $lines[] = '';
                $lines[] = '📅 ' . $group['title'];
            }

            foreach ($group['tasks'] as $task) {
                $taken = $occupancy[$task->id] ?? 0;
                $lines[] = $this->taskLine($task, $taken);
                $keyboard[] = [$this->taskButton($task, $taken, $multiEvent)];
            }
        }

        $deepLink = $this->deepLinkButton();

        if (null !== $deepLink) {
            $keyboard[] = [$deepLink];
        }

        return ['text' => implode("\n", $lines), 'keyboard' => $keyboard];
    }

    /**
     * Captures the board's group when the bot is added to one, and posts the
     * board straight away as confirmation. This is what makes setup work under
     * group privacy mode with no admin: Telegram delivers the bot's own
     * membership change regardless of privacy, unlike group messages.
     *
     * @param array<string, mixed> $membership A my_chat_member update.
     */
    public function onBotMembershipChange(array $membership): void
    {
        $chat = is_array($membership['chat'] ?? null) ? $membership['chat'] : [];
        $status = (string) ($membership['new_chat_member']['status'] ?? '');

        // Only groups, and only when the bot is actually in - a removal
        // ('left'/'kicked') must not re-capture a chat it just left.
        if (! in_array((string) ($chat['type'] ?? ''), ['group', 'supergroup'], true)) {
            return;
        }

        if (! in_array($status, ['member', 'administrator', 'creator'], true)) {
            return;
        }

        $chatId = (int) ($chat['id'] ?? 0);

        if (0 !== $chatId) {
            $this->setBoardChat($chatId);
            $this->refresh();
        }
    }

    /**
     * Handles a join/leave button tap. The capacity race is the database's to
     * win (AssignmentRepository::join is one conditional statement); this only
     * decides the person is allowed to try, maps the outcome to a human answer,
     * and refreshes the shared counts when something actually changed.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onJoinLeave(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $telegramUserId = (int) ($callbackQuery['from']['id'] ?? 0);
        [$action, $taskId] = $this->parseData((string) ($callbackQuery['data'] ?? ''));

        if ('' === $action || 0 === $taskId || 0 === $telegramUserId) {
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $person->isEmailVerified()) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('First tap the “New here? Sign up” button below to confirm your email.', 'eventcrew'),
                true
            );

            return;
        }

        // 't' is the toggle a task button now sends: join if not signed up,
        // leave if already. 'j' and 'l' stay understood so a board posted
        // before this change keeps working until its next refresh.
        $changed = match ($action) {
            'j' => $this->handleJoin($callbackId, $taskId, $person->id),
            'l' => $this->handleLeave($callbackId, $taskId, $person->id),
            default => $this->handleToggle($callbackId, $taskId, $person->id),
        };

        if ($changed) {
            $this->refresh();
        }
    }

    /**
     * One button, both directions: already signed up means the tap is a leave,
     * otherwise it is a join. This is how a shared group board offers a
     * personal "leave" it cannot show or hide per person - the button looks the
     * same to everyone, but does the right thing for whoever taps it.
     */
    private function handleToggle(string $callbackId, int $taskId, int $personId): bool
    {
        if (null !== $this->assignments->findFor($taskId, $personId)) {
            return $this->handleLeave($callbackId, $taskId, $personId);
        }

        return $this->handleJoin($callbackId, $taskId, $personId);
    }

    private function handleJoin(string $callbackId, int $taskId, int $personId): bool
    {
        // Holding a slot across events is fine; a genuine time clash between
        // two of them is not, and that is exactly what hasOverlapping refuses.
        if ($this->assignments->hasOverlapping($personId, $taskId)) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('That clashes with another slot you already hold.', 'eventcrew'),
                true
            );

            return false;
        }

        $outcome = $this->assignments->join($taskId, $personId);

        $message = match ($outcome) {
            AssignmentRepository::JOIN_OK => __('You’re in! See you there.', 'eventcrew'),
            AssignmentRepository::JOIN_DUPLICATE => __('You’re already signed up for that.', 'eventcrew'),
            AssignmentRepository::JOIN_FULL => __('That slot just filled up.', 'eventcrew'),
            default => __('That task is no longer available.', 'eventcrew'),
        };

        $this->telegram->answerCallbackQuery($callbackId, $message, true);

        return AssignmentRepository::JOIN_OK === $outcome;
    }

    private function handleLeave(string $callbackId, int $taskId, int $personId): bool
    {
        $left = $this->assignments->leave($taskId, $personId);

        $this->telegram->answerCallbackQuery(
            $callbackId,
            $left
                ? __('You’re out. Thanks for letting us know.', 'eventcrew')
                : __('You weren’t signed up for that one.', 'eventcrew')
        );

        return $left;
    }

    /**
     * @param array<int, Task> $tasks
     * @return array<string, array{title: string, tasks: array<int, Task>}>
     */
    private function groupByEvent(array $tasks): array
    {
        $groups = [];

        foreach ($tasks as $task) {
            $key = null !== $task->eventPostId
                ? 'e:' . $task->eventPostId
                : 'l:' . $task->eventLabel . '|' . $task->taskDate;

            if (! isset($groups[$key])) {
                $groups[$key] = ['title' => $task->eventName(), 'tasks' => []];
            }

            $groups[$key]['tasks'][] = $task;
        }

        return $groups;
    }

    private function taskLine(Task $task, int $taken): string
    {
        $time = $task->timeRange();
        $when = '' === $time ? '' : ' ' . $time;

        return sprintf('• %s%s (%d/%d)', $task->roleDisplay(), $when, $taken, $task->capacity);
    }

    /**
     * One toggle button per task. It carries the task's own emoji and count -
     * no ✅, which read as "done" - and, when more than one event is open, the
     * task's date, since the buttons sit in a flat list under the text and
     * otherwise give no clue which day a task belongs to.
     *
     * @return array<string, string>
     */
    private function taskButton(Task $task, int $taken, bool $multiEvent): array
    {
        $label = sprintf('%s %d/%d', $task->roleDisplay(), $taken, $task->capacity);

        if ($taken >= $task->capacity) {
            $label .= ' · ' . __('full', 'eventcrew');
        }

        if ($multiEvent) {
            $label = $this->shortDate($task->taskDate) . ' · ' . $label;
        }

        return ['text' => $label, 'callback_data' => 't:' . $task->id];
    }

    /**
     * @return array<string, string>|null
     */
    private function deepLinkButton(): ?array
    {
        $username = trim((string) get_option(self::USERNAME_OPTION, ''));

        if ('' === $username) {
            return null;
        }

        return [
            'text' => __('New here? Sign up →', 'eventcrew'),
            'url' => 'https://t.me/' . $username . '?start=onboard',
        ];
    }

    /**
     * The task's filing day as a short, localized label like "Sat 1 Aug". Uses
     * wp_date so weekday and month follow the site's locale; a bare date
     * (no WordPress) falls back to the same English format.
     */
    private function shortDate(string $date): string
    {
        $timestamp = strtotime($date . ' 12:00:00');

        if (false === $timestamp) {
            return $date;
        }

        return function_exists('wp_date')
            ? (string) wp_date('D j M', $timestamp)
            : gmdate('D j M', $timestamp);
    }

    /**
     * @return array{0: string, 1: int} action ('j'|'l'|'t'|''), task id
     */
    private function parseData(string $data): array
    {
        if (1 !== preg_match('/^([jlt]):(\d+)$/', $data, $matches)) {
            return ['', 0];
        }

        return [$matches[1], (int) $matches[2]];
    }

    /**
     * @return array<string, mixed>
     */
    private function board(): array
    {
        $board = get_option(self::BOARD_OPTION, []);

        return is_array($board) ? $board : [];
    }
}
