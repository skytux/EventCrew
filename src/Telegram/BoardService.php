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
use EventCrew\Support\Logger;
use EventCrew\Support\SignupService;

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

    /** Hash of the last-rendered board, so the hourly tick only edits on change. */
    public const BOARD_HASH_OPTION = 'eventcrew_telegram_board_hash';

    /** Cached from getMe, for the "set me up" deep-link button. */
    public const USERNAME_OPTION = 'eventcrew_telegram_bot_username';

    /**
     * The public t.me link to the group (a @username or a +invite hash), set by
     * the organizer. A chat id cannot be turned into a joinable link, so this is
     * the only way the web page can offer an "Open in Telegram" jump to the group.
     */
    public const GROUP_LINK_OPTION = 'eventcrew_telegram_group_link';

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly PersonRepository $people,
        private readonly TelegramClient $telegram,
        private readonly Logger $logger,
        private readonly ClaimNotifier $notifier,
        private readonly SignupService $signup
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
     * Posts a one-off message into the board's group - used to announce a
     * replacement to everyone. Inert until the bot and board chat are set.
     */
    public function announce(string $text): void
    {
        if (! $this->telegram->isConfigured()) {
            return;
        }

        $chatId = (int) ($this->board()['chat_id'] ?? 0);

        if (0 !== $chatId) {
            $this->telegram->sendMessage($chatId, $text);
        }
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

        $this->storeBoard($chatId, $result);
    }

    /**
     * Edits the board in place, but only when what it would show has actually
     * changed since last time - the hourly heartbeat calls this so a day's board
     * winds down (a finished task drops off) before midnight, without an editing
     * request every hour when nothing has moved. The content hash is the guard.
     *
     * Data-change refreshes (a join, a task edit) go through refresh() directly
     * and do not touch the hash, so at worst this makes one redundant edit after
     * such a change, which Telegram answers with a harmless "not modified".
     */
    public function refreshIfChanged(): void
    {
        if (! $this->telegram->isConfigured()) {
            return;
        }

        $chatId = (int) ($this->board()['chat_id'] ?? 0);

        if (0 === $chatId) {
            return;
        }

        $rendered = $this->render();
        $hash = md5($rendered['text'] . '|' . (string) wp_json_encode($rendered['keyboard']));

        if ($hash === (string) get_option(self::BOARD_HASH_OPTION, '')) {
            return;
        }

        $this->refresh();
        update_option(self::BOARD_HASH_OPTION, $hash);
    }

    /**
     * Re-posts the board into the chat it already lives in. Used by the
     * scheduled reminder. Inert until the bot and a board chat are set.
     */
    public function repost(): void
    {
        $chatId = (int) ($this->board()['chat_id'] ?? 0);

        $this->repostInto($chatId);
    }

    /**
     * Posts a brand-new board into $chatId and deletes the previous one, so a
     * reminder (or a re-run of /board) surfaces as a fresh message at the foot
     * of the group rather than a silent in-place edit nobody notices, and the
     * stale copy is cleared rather than left stacking up.
     *
     * The chat is captured up front, so even a failed post leaves the board's
     * home remembered for the next attempt. The old board is deleted only after
     * the new one is up, so a failed post never leaves the group with no board;
     * a delete that fails - the message is already gone, or is older than the
     * 48 hours Telegram lets a non-admin bot delete - is a swallowed benign
     * case. Inert until the bot is configured and a real chat is given.
     */
    public function repostInto(int $chatId): void
    {
        if (! $this->telegram->isConfigured() || 0 === $chatId) {
            return;
        }

        $previous = $this->board();
        $previousChat = (int) ($previous['chat_id'] ?? 0);
        $previousId = (int) ($previous['message_id'] ?? 0);

        // Remember the chat immediately (with no message yet), so the board's
        // home survives even if the post below fails; storeBoard fills in the
        // new message id once Telegram confirms it.
        update_option(self::BOARD_OPTION, ['chat_id' => $chatId, 'message_id' => 0]);

        $rendered = $this->render();
        $markup = [] === $rendered['keyboard'] ? null : ['inline_keyboard' => $rendered['keyboard']];

        $result = $this->telegram->sendMessage($chatId, $rendered['text'], $markup);

        if ($previousId > 0 && 0 !== $previousChat) {
            $this->telegram->deleteMessage($previousChat, $previousId);
        }

        $this->storeBoard($chatId, $result);
    }

    /**
     * Records the freshly-posted board's id, so the next refresh edits it in
     * place and the next repost knows which message to delete.
     *
     * @param array<string, mixed>|null $result The sendMessage response.
     */
    private function storeBoard(int $chatId, ?array $result): void
    {
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

        $lines = [__('Open tasks — tap one to sign up, tap again to cancel.', 'eventcrew')];
        $lines[] = __('Send /me in a DM to get your summary.', 'eventcrew');
        $keyboard = [];

        foreach ($groups as $group) {
            if ($multiEvent) {
                $lines[] = '';
                $lines[] = '📅 ' . $group['date'] . ' · ' . $group['title'];
            }

            foreach ($group['tasks'] as $task) {
                $taken = $occupancy[$task->id] ?? 0;
                //$lines[] = $this->taskLine($task, $taken);
                $keyboard[] = [$this->taskButton($task, $taken, $multiEvent)];
            }
        }

        $deepLinkOnboard = $this->deepLinkButton('onboard', __('New here? Sign up →', 'eventcrew'));

        if (null !== $deepLinkOnboard) {
            $keyboard[] = [$deepLinkOnboard];
        }

        $deepLinkMe = $this->deepLinkButton('me', __('See my info →', 'eventcrew'));

        if (null !== $deepLinkMe) {
            $keyboard[] = [$deepLinkMe];
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
            'j' => $this->handleJoin($callbackId, $taskId, $person),
            'l' => $this->handleLeave($callbackId, $taskId, $person),
            default => $this->handleToggle($callbackId, $taskId, $person),
        };

        if ($changed) {
            $this->refresh();
        }
    }

    /**
     * One button, both directions: an occupying slot means the tap is a cancel,
     * otherwise it is a join (which reactivates a previously cancelled row if
     * there is one). This is how a shared group board offers a personal "leave"
     * it cannot show or hide per person - the button looks the same to everyone,
     * but does the right thing for whoever taps it.
     */
    private function handleToggle(string $callbackId, int $taskId, Person $person): bool
    {
        $existing = $this->assignments->findFor($taskId, $person->id);

        if (null !== $existing && $existing->isOccupying()) {
            return $this->handleLeave($callbackId, $taskId, $person);
        }

        return $this->handleJoin($callbackId, $taskId, $person);
    }

    private function handleJoin(string $callbackId, int $taskId, Person $person): bool
    {
        // The rules - the reputation gate, the overlap check, the capacity race -
        // live in SignupService, shared with the web page; the bot keeps only the
        // wording of the reply.
        $outcome = $this->signup->claim($person->id, $taskId);
        $joined = in_array($outcome, [AssignmentRepository::JOIN_OK, AssignmentRepository::JOIN_REJOINED], true);

        $message = match ($outcome) {
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            SignupService::GATED => __('Your recent attendance means sign-ups are paused for now — please message the organizer.', 'eventcrew'),
            SignupService::OVERLAP => __('That clashes with another slot you already hold.', 'eventcrew'),
            AssignmentRepository::JOIN_OK,
            AssignmentRepository::JOIN_REJOINED => __('You’re in! See you there.', 'eventcrew'),
            AssignmentRepository::JOIN_DUPLICATE => __('You’re already signed up for that.', 'eventcrew'),
            AssignmentRepository::JOIN_FULL => __('That slot just filled up.', 'eventcrew'),
            default => __('That task is no longer available.', 'eventcrew'),
        };

        $this->telegram->answerCallbackQuery($callbackId, $message, true);

        if ($joined) {
            $this->notifier->confirmSignup($person, $taskId);
        }

        return $joined;
    }

    private function handleLeave(string $callbackId, int $taskId, Person $person): bool
    {
        // Cancel, don't delete: the row stays for the reputation history, and
        // is classified late_cancel/cancelled by how much notice this gives.
        $status = $this->signup->drop($person->id, $taskId);

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

        return '' !== $status;
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
                $groups[$key] = [
                    'title' => $task->eventName(),
                    'tasks' => [],
                    'date' => $this->shortDate($task->taskDate),
                ];
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
            $label = $this->shortDate($task->taskDate) . ' · ' . $task->timeRange() . ' · ' . $label;
        }

        return ['text' => $label, 'callback_data' => 't:' . $task->id];
    }

    /**
     * @return array<string, string>|null
     */
    private function deepLinkButton(string $payload = 'onboard', string $text = 'New here? Sign up →'): ?array
    {
        $username = trim((string) get_option(self::USERNAME_OPTION, ''));

        if ('' === $username) {
            return null;
        }

        return [
            'text' => $text,
            'url' => 'https://t.me/' . $username . '?start=' . rawurlencode($payload),
        ];
    }

    /**
     * The task's filing day as a short, localized label like "Sat 1 Aug".
     */
    private function shortDate(string $date): string
    {
        return Dates::dayLabel($date);
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
