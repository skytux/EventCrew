<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\Language;
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

    /** The bot's display name, cached from getMe, so the helper knows what it is called. */
    public const NAME_OPTION = 'eventcrew_telegram_bot_name';

    /**
     * The public t.me link to the group, set by the organizer. A chat id cannot
     * be turned into a joinable link, so a link has to come from somewhere: this
     * is the organizer's own, and it always wins over the discovered one.
     */
    public const GROUP_LINK_OPTION = 'eventcrew_telegram_group_link';

    /**
     * The link discovered from Telegram, kept apart from the organizer's own so
     * a refresh can never overwrite something typed by hand.
     */
    public const GROUP_LINK_AUTO_OPTION = 'eventcrew_telegram_group_link_auto';

    /** When the discovery last ran, successful or not; throttles the lookup. */
    public const GROUP_LINK_CHECKED_OPTION = 'eventcrew_telegram_group_link_checked';

    /** How long a discovered link is trusted before it is looked up again. */
    private const GROUP_LINK_TTL = 7 * DAY_IN_SECONDS;

    /**
     * The link to the crew's group: the organizer's, or the one discovered from
     * Telegram. Empty when neither exists, and every caller is expected to drop
     * whatever button it was going to draw rather than point at nothing.
     *
     * A plain option read with no API call, so the web page, the emails and the
     * DMs can all ask without any of them needing a Telegram client.
     */
    public static function groupLink(): string
    {
        $manual = trim((string) get_option(self::GROUP_LINK_OPTION, ''));

        return '' !== $manual
            ? $manual
            : trim((string) get_option(self::GROUP_LINK_AUTO_OPTION, ''));
    }

    /**
     * When set (the default), the board stays in the first group the bot joins:
     * a later attempt to move it - the bot being added to another group, or
     * /board run there - is ignored, so nobody can quietly hijack the crew's
     * board into their own chat. Turned off from Settings to move it on purpose.
     */
    public const LOCK_OPTION = 'eventcrew_telegram_group_lock';

    /**
     * Callback data prefix for a day button under the board: bd:<Y-m-d>, or a
     * bare bd: for the next event. In the group it changes the one shared
     * message for everyone - Telegram has no per-person view of a message.
     */
    public const PICK_PREFIX = 'bd:';

    /**
     * The card format switch, off by default (Settings → Telegram bot). On, the
     * board is one message per day (BoardRenderer::cards()): a photo message
     * for a day with an EventMesh event, a text message for any other day, and
     * no row of day buttons. BOARD_OPTION then also holds 'days': per day, the
     * message id, its kind ('card' or 'text'), the picture, and hashes of the
     * text and the keyboard, so a refresh edits only what changed.
     */
    public const CARDS_OPTION = 'eventcrew_telegram_board_cards';

    /** Composes the board text + keyboard; the pure "what it looks like" half. */
    private readonly BoardRenderer $renderer;

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly PersonRepository $people,
        private readonly TelegramClient $telegram,
        private readonly Logger $logger,
        private readonly ClaimNotifier $notifier,
        private readonly SignupService $signup
    ) {
        $this->renderer = new BoardRenderer($tasks);
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
     * Whether the group lock forbids moving the board to $chatId: it does when
     * the lock is on (the default) and a *different* group already holds the
     * board. The first capture (no group yet) and any action in the group that
     * already holds it are always allowed, so locking never blocks legitimate
     * refreshes - only a hijack to a new chat.
     */
    private function moveBlocked(int $chatId): bool
    {
        if (! (bool) get_option(self::LOCK_OPTION, true)) {
            return false;
        }

        $current = (int) ($this->board()['chat_id'] ?? 0);

        return 0 !== $current && $chatId !== $current;
    }

    /**
     * Asks Telegram for a link to the board's group and caches it, so the
     * organizer does not have to find and paste one by hand.
     *
     * Three outcomes, in order of preference: a public group answers with a
     * @username, which needs no permissions at all; a private group whose bot is
     * an administrator answers with its primary invite_link; failing both, and
     * only if the bot may invite, a fresh additional link is created. A bot that
     * is merely a member of a private group can do none of this - Telegram tells
     * it nothing about invites - and the organizer's own field stays the answer.
     *
     * Throttled to once a week, and skipped outright while a manual link is set,
     * so the hourly heartbeat this hangs off does not talk to Telegram for
     * something that changes approximately never. Returns the resolved link.
     */
    public function refreshGroupLink(bool $force = false): string
    {
        $manual = trim((string) get_option(self::GROUP_LINK_OPTION, ''));

        if ('' !== $manual) {
            return $manual;
        }

        $cached = trim((string) get_option(self::GROUP_LINK_AUTO_OPTION, ''));
        $checked = (int) get_option(self::GROUP_LINK_CHECKED_OPTION, 0);

        if (! $force && (time() - $checked) < self::GROUP_LINK_TTL) {
            return $cached;
        }

        $chatId = (int) ($this->board()['chat_id'] ?? 0);

        if (! $this->telegram->isConfigured() || 0 === $chatId) {
            return $cached;
        }

        // Stamped before the calls, not after: a bot that is not an admin will
        // fail every week otherwise, and the failure is the normal state of a
        // perfectly working install rather than something to keep retrying.
        update_option(self::GROUP_LINK_CHECKED_OPTION, time());

        $link = $this->discoverGroupLink($chatId);

        if ('' !== $link) {
            update_option(self::GROUP_LINK_AUTO_OPTION, $link);

            return $link;
        }

        return $cached;
    }

    private function discoverGroupLink(int $chatId): string
    {
        $chat = $this->telegram->getChat($chatId);

        if (is_array($chat)) {
            $username = trim((string) ($chat['username'] ?? ''));

            if ('' !== $username) {
                return 'https://t.me/' . $username;
            }

            $invite = trim((string) ($chat['invite_link'] ?? ''));

            if ('' !== $invite) {
                return $invite;
            }
        }

        $created = $this->telegram->createChatInviteLink(
            $chatId,
            /* translators: the name shown against the invite link in Telegram's admin list */
            __('EventCrew', 'eventcrew')
        );

        return is_array($created) ? trim((string) ($created['invite_link'] ?? '')) : '';
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
     *
     * Returns whether the board is now up to date. Every automatic caller - a
     * join, a task edit, the heartbeat - ignores that and should: there is
     * nobody watching to tell, and a failed edit is already logged. It exists
     * for the Settings button, which is pressed by a person waiting for an
     * answer, and "nothing appeared to happen" is the one answer that helps
     * nobody. An unchanged board counts as success: Telegram's "message is not
     * modified" is a benign error TelegramClient already treats as one.
     *
     * $date is the day to show; '' (every automatic redraw) is the next event.
     */
    public function refresh(string $date = ''): bool
    {
        if (! $this->telegram->isConfigured()) {
            return false;
        }

        $board = $this->board();
        $chatId = (int) ($board['chat_id'] ?? 0);

        if (0 === $chatId) {
            return false;
        }

        // The card format shows every day at once, so the day asked for does
        // not matter there.
        if (self::cardsOn()) {
            return $this->refreshCards($chatId, $board);
        }

        if ([] !== $this->days($board)) {
            $board = $this->dropCards($chatId, $board);
        }

        $rendered = $this->render($date);
        $markup = [] === $rendered['keyboard'] ? null : ['inline_keyboard' => $rendered['keyboard']];
        $messageId = (int) ($board['message_id'] ?? 0);

        if ($messageId > 0) {
            return null !== $this->telegram->editMessageText($chatId, $messageId, $rendered['text'], $markup);
        }

        $result = $this->telegram->sendMessage($chatId, $rendered['text'], $markup);

        $this->storeBoard($chatId, $result);

        return null !== $result;
    }

    /** Whether a board chat has been captured yet - the Settings button asks. */
    public function hasHome(): bool
    {
        return 0 !== (int) ($this->board()['chat_id'] ?? 0);
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

        // The card format keeps a hash per day and edits only changed days.
        if (self::cardsOn()) {
            $this->refresh();

            return;
        }

        $rendered = $this->render();
        $hash = md5($rendered['text'] . '|' . (string) wp_json_encode($rendered['keyboard']));

        // Messages left from the card format mean it was just switched off:
        // the text board has to replace them even when its own text is the same.
        if ($hash === (string) get_option(self::BOARD_HASH_OPTION, '') && [] === $this->days($this->board())) {
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

        // /board in a different group cannot move a locked board either; the
        // group it already lives in still reposts (same chat passes the check).
        if ($this->moveBlocked($chatId)) {
            return;
        }

        $previous = $this->board();
        $previousChat = (int) ($previous['chat_id'] ?? 0);
        $previousId = (int) ($previous['message_id'] ?? 0);

        // Remember the chat immediately (with no message yet), so the board's
        // home survives even if the post below fails; storeBoard fills in the
        // new message id once Telegram confirms it.
        update_option(self::BOARD_OPTION, ['chat_id' => $chatId, 'message_id' => 0]);

        if (self::cardsOn()) {
            $this->repostCards($chatId, $previous);

            return;
        }

        $rendered = $this->render();
        $markup = [] === $rendered['keyboard'] ? null : ['inline_keyboard' => $rendered['keyboard']];

        $result = $this->telegram->sendMessage($chatId, $rendered['text'], $markup);

        if ($previousId > 0 && 0 !== $previousChat) {
            $this->telegram->deleteMessage($previousChat, $previousId);
        }

        // Messages left from the card format, when it was switched off since.
        if (0 !== $previousChat) {
            foreach ($this->days($previous) as $old) {
                $this->telegram->deleteMessage($previousChat, $old['message_id']);
            }
        }

        $this->storeBoard($chatId, $result);
    }

    /** Whether the board is shown in card format (Settings → Telegram bot). */
    public static function cardsOn(): bool
    {
        return (bool) get_option(self::CARDS_OPTION, false);
    }

    /**
     * The card format's refresh: each day's message is edited in place when it
     * changed, posted when the day is new, and deleted when the day is gone.
     * A day that changes between a text message and a photo message is posted
     * again and the old message deleted, because Telegram cannot edit one
     * into the other. A text board from before the switch is deleted.
     *
     * A day whose post or edit fails keeps its old state, so the next refresh
     * tries it again. Returns whether every day is up to date.
     *
     * @param array<string, mixed> $board
     */
    private function refreshCards(int $chatId, array $board): bool
    {
        $messages = $this->renderCards();
        $old = $this->days($board);
        $days = [];
        $ok = true;

        $textId = (int) ($board['message_id'] ?? 0);

        if ($textId > 0) {
            $this->telegram->deleteMessage($chatId, $textId);
        }

        foreach ($messages as $day => $message) {
            $day = (string) $day;
            $previous = $old[$day] ?? null;
            unset($old[$day]);

            if (null !== $previous && $previous['kind'] === $message['kind']) {
                $state = $this->editDay($chatId, $previous, $message);
                $ok = $ok && null !== $state;
                $days[$day] = $state ?? $previous;

                continue;
            }

            $state = $this->postDay($chatId, $message);

            if (null === $state) {
                $ok = false;

                if (null !== $previous) {
                    $days[$day] = $previous;
                }

                continue;
            }

            if (null !== $previous) {
                $this->telegram->deleteMessage($chatId, $previous['message_id']);
            }

            $days[$day] = $state;
        }

        // Days no longer on the board: past, or their last task removed.
        foreach ($old as $gone) {
            $this->telegram->deleteMessage($chatId, $gone['message_id']);
        }

        update_option(self::BOARD_OPTION, ['chat_id' => $chatId, 'message_id' => 0, 'days' => $days]);

        return $ok;
    }

    /**
     * The card format's repost: every day posted fresh into $chatId, then the
     * previous board's messages deleted, so the board is at the foot of the
     * group and nothing old is left above it.
     *
     * @param array<string, mixed> $previous The board before the repost.
     */
    private function repostCards(int $chatId, array $previous): void
    {
        $days = [];

        foreach ($this->renderCards() as $day => $message) {
            $state = $this->postDay($chatId, $message);

            if (null !== $state) {
                $days[(string) $day] = $state;
            }
        }

        $previousChat = (int) ($previous['chat_id'] ?? 0);
        $previousId = (int) ($previous['message_id'] ?? 0);

        if (0 !== $previousChat) {
            if ($previousId > 0) {
                $this->telegram->deleteMessage($previousChat, $previousId);
            }

            foreach ($this->days($previous) as $old) {
                $this->telegram->deleteMessage($previousChat, $old['message_id']);
            }
        }

        update_option(self::BOARD_OPTION, ['chat_id' => $chatId, 'message_id' => 0, 'days' => $days]);
    }

    /**
     * Deletes the card format's messages after it was switched off, so the
     * text board that follows is the only board in the group.
     *
     * @param array<string, mixed> $board
     * @return array<string, mixed> The board without its days.
     */
    private function dropCards(int $chatId, array $board): array
    {
        foreach ($this->days($board) as $old) {
            $this->telegram->deleteMessage($chatId, $old['message_id']);
        }

        unset($board['days']);
        update_option(self::BOARD_OPTION, $board);

        return $board;
    }

    /**
     * Posts one day's message. Returns its state for 'days', or null when
     * Telegram did not take it.
     *
     * @param array{
     *     kind: string,
     *     text: string,
     *     photo: string,
     *     keyboard: array<int, array<int, array<string, mixed>>>
     * } $message
     * @return array{message_id: int, kind: string, photo: string, text: string, keyboard: string}|null
     */
    private function postDay(int $chatId, array $message): ?array
    {
        $markup = [] === $message['keyboard'] ? null : ['inline_keyboard' => $message['keyboard']];

        $result = 'card' === $message['kind']
            ? $this->telegram->sendPhoto($chatId, $message['photo'], $message['text'], $markup)
            : $this->telegram->sendMessage($chatId, $message['text'], $markup);

        if (null === $result || ! isset($result['message_id'])) {
            return null;
        }

        return $this->dayState((int) $result['message_id'], $message);
    }

    /**
     * Edits one day's message in place, with the smallest edit that does it:
     * a text message gets editMessageText; a photo message gets
     * editMessageMedia for a new picture, editMessageCaption for a new
     * caption, and editMessageReplyMarkup when only the buttons changed.
     * Nothing is sent when nothing changed.
     *
     * @param array{message_id: int, kind: string, photo: string, text: string, keyboard: string} $previous
     * @param array{
     *     kind: string,
     *     text: string,
     *     photo: string,
     *     keyboard: array<int, array<int, array<string, mixed>>>
     * } $message
     * @return array{message_id: int, kind: string, photo: string, text: string, keyboard: string}|null
     *         The new state, or null when the edit failed.
     */
    private function editDay(int $chatId, array $previous, array $message): ?array
    {
        $messageId = $previous['message_id'];
        $state = $this->dayState($messageId, $message);
        $markup = [] === $message['keyboard'] ? null : ['inline_keyboard' => $message['keyboard']];
        $newText = $state['text'] !== $previous['text'];
        $newKeyboard = $state['keyboard'] !== $previous['keyboard'];

        if ('card' !== $message['kind']) {
            if (! $newText && ! $newKeyboard) {
                return $state;
            }

            $result = $this->telegram->editMessageText($chatId, $messageId, $message['text'], $markup);
        } elseif ($state['photo'] !== $previous['photo']) {
            $result = $this->telegram->editMessageMedia(
                $chatId,
                $messageId,
                $message['photo'],
                $message['text'],
                $markup
            );
        } elseif ($newText) {
            $result = $this->telegram->editMessageCaption($chatId, $messageId, $message['text'], $markup);
        } elseif ($newKeyboard) {
            $result = $this->telegram->editMessageReplyMarkup($chatId, $messageId, $markup);
        } else {
            return $state;
        }

        return null === $result ? null : $state;
    }

    /**
     * @param array{
     *     kind: string,
     *     text: string,
     *     photo: string,
     *     keyboard: array<int, array<int, array<string, mixed>>>
     * } $message
     * @return array{message_id: int, kind: string, photo: string, text: string, keyboard: string}
     */
    private function dayState(int $messageId, array $message): array
    {
        return [
            'message_id' => $messageId,
            'kind' => $message['kind'],
            'photo' => $message['photo'],
            'text' => md5($message['text']),
            'keyboard' => md5((string) wp_json_encode($message['keyboard'])),
        ];
    }

    /**
     * The card format's messages per day, in English like render().
     *
     * @return array<string, array{
     *     kind: string,
     *     text: string,
     *     photo: string,
     *     keyboard: array<int, array<int, array<string, mixed>>>
     * }>
     */
    private function renderCards(): array
    {
        return Language::run(Language::ENGLISH, fn (): array => $this->renderer->cards());
    }

    /**
     * The card format's stored messages, keyed by day; empty for a text board.
     *
     * @param array<string, mixed> $board
     * @return array<string, array{message_id: int, kind: string, photo: string, text: string, keyboard: string}>
     */
    private function days(array $board): array
    {
        $days = [];

        foreach ((array) ($board['days'] ?? []) as $day => $state) {
            if (! is_array($state) || (int) ($state['message_id'] ?? 0) <= 0) {
                continue;
            }

            $days[(string) $day] = [
                'message_id' => (int) $state['message_id'],
                'kind' => (string) ($state['kind'] ?? ''),
                'photo' => (string) ($state['photo'] ?? ''),
                'text' => (string) ($state['text'] ?? ''),
                'keyboard' => (string) ($state['keyboard'] ?? ''),
            ];
        }

        return $days;
    }

    /**
     * Sends a copy of the board to a private chat, for /board asked in a DM.
     * The copy is not remembered as the board: refreshes and reposts still go
     * to the group. Its buttons work like the group's, and a tap on them
     * redraws that copy too (see onJoinLeave).
     *
     * With the card format on, the copy is one message per day, the same
     * messages as the group's; none of them is stored in BOARD_OPTION.
     */
    public function sendPrivate(int $chatId): void
    {
        if (! $this->telegram->isConfigured() || 0 === $chatId) {
            return;
        }

        if (self::cardsOn()) {
            foreach ($this->renderCards() as $message) {
                $this->postDay($chatId, $message);
            }

            return;
        }

        $rendered = $this->render();
        $markup = [] === $rendered['keyboard'] ? null : ['inline_keyboard' => $rendered['keyboard']];

        $this->telegram->sendMessage($chatId, $rendered['text'], $markup);
    }

    /**
     * Redraws the private copy of the board a button was tapped on, so its
     * counts match the group's. A tap on the group's own board needs nothing
     * more: refresh() already redrew it.
     *
     * @param array<string, mixed> $callbackQuery
     */
    private function refreshPrivateCopy(array $callbackQuery, string $date): void
    {
        $message = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $chatId = (int) ($chat['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);

        if ('private' !== ($chat['type'] ?? '') || 0 === $chatId || 0 === $messageId) {
            return;
        }

        if (self::cardsOn()) {
            $this->refreshPrivateDay($chatId, $messageId, $message, $date);

            return;
        }

        $rendered = $this->render($date);
        $markup = [] === $rendered['keyboard'] ? null : ['inline_keyboard' => $rendered['keyboard']];

        $this->telegram->editMessageText($chatId, $messageId, $rendered['text'], $markup);
    }

    /**
     * The card format's private redraw: only the one day's message that was
     * tapped. $date is the day the task button carries; '' is the first day
     * on the board. A photo message gets its caption and buttons edited, a
     * text message its text and buttons. A day no longer on the board, or a
     * day that changed between photo and text, leaves the message as it is.
     *
     * @param array<string, mixed> $tapped The message the button was tapped on.
     */
    private function refreshPrivateDay(int $chatId, int $messageId, array $tapped, string $date): void
    {
        $messages = $this->renderCards();
        $day = '' === $date ? (string) array_key_first($messages) : $date;

        if ('' === $day || ! isset($messages[$day])) {
            return;
        }

        $message = $messages[$day];
        $markup = [] === $message['keyboard'] ? null : ['inline_keyboard' => $message['keyboard']];

        $isPhoto = isset($tapped['photo']);

        // Telegram cannot turn a text message into a photo message or back.
        if ($isPhoto !== ('card' === $message['kind'])) {
            return;
        }

        if ($isPhoto) {
            $this->telegram->editMessageCaption($chatId, $messageId, $message['text'], $markup);

            return;
        }

        $this->telegram->editMessageText($chatId, $messageId, $message['text'], $markup);
    }

    /**
     * A day button tap: redraws the message it was tapped on - the group's
     * board or a private copy - showing that day ('' = the next event).
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onPickDate(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $message = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);
        $date = substr((string) ($callbackQuery['data'] ?? ''), strlen(self::PICK_PREFIX));

        $this->telegram->answerCallbackQuery($callbackId);

        if (('' !== $date && 1 !== preg_match('#^\d{4}-\d{2}-\d{2}$#', $date)) || 0 === $chatId || 0 === $messageId) {
            return;
        }

        $rendered = $this->render($date);
        $markup = [] === $rendered['keyboard'] ? null : ['inline_keyboard' => $rendered['keyboard']];

        $this->telegram->editMessageText($chatId, $messageId, $rendered['text'], $markup);
    }

    /**
     * Whether the tap came from the group's own board message, so a redraw
     * may keep the day it shows; a tap on a private copy must not move the
     * group's board off the next event.
     *
     * @param array<string, mixed> $callbackQuery
     */
    private function isGroupBoard(array $callbackQuery): bool
    {
        $board = $this->board();
        $message = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];

        return 0 !== (int) ($board['message_id'] ?? 0)
            && (int) ($message['chat']['id'] ?? 0) === (int) ($board['chat_id'] ?? 0)
            && (int) ($message['message_id'] ?? 0) === (int) ($board['message_id'] ?? 0);
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
     * Builds the board text and its inline keyboard. Delegated to BoardRenderer;
     * kept here so the lifecycle methods (and the tests) call it as before.
     *
     * Always English: the board is one message the whole group reads, and it
     * is often re-rendered inside a tap by someone who chose Finnish.
     *
     * @return array{text: string, keyboard: array<int, array<int, array<string, mixed>>>}
     */
    public function render(string $date = ''): array
    {
        return Language::run(Language::ENGLISH, fn (): array => $this->renderer->render($date));
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

        if (0 === $chatId) {
            return;
        }

        // With the lock on, only the first group ever holds the board. Any
        // other group that adds the bot is left straight away, so the bot is
        // never a member of a group it does not serve.
        if ($this->moveBlocked($chatId)) {
            $this->logger->info(sprintf(
                'Bot added to group %d while the board is locked to another group; leaving it.',
                $chatId
            ));
            $this->telegram->leaveChat($chatId);

            return;
        }

        $this->setBoardChat($chatId);
        $this->refresh();
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
        [$action, $taskId, $date] = $this->parseData((string) ($callbackQuery['data'] ?? ''));

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

        // The day shown comes with the tap, so the redraw stays on it.
        if ($changed) {
            $this->refresh($this->isGroupBoard($callbackQuery) ? $date : '');
            $this->refreshPrivateCopy($callbackQuery, $date);
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
        // The rules - the reputation gate, the restricted roles, the capacity race -
        // live in SignupService, shared with the web page; the bot keeps only the
        // wording of the reply.
        $outcome = $this->signup->claim($person->id, $taskId);
        $joined = in_array($outcome, [AssignmentRepository::JOIN_OK, AssignmentRepository::JOIN_REJOINED], true);

        $message = match ($outcome) {
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            SignupService::GATED => __('Your recent attendance means sign-ups are paused for now — please message an organizer.', 'eventcrew'),
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
            AssignmentRepository::CANCEL_LOCKED => SignupService::signoutLockedText(),
            default => __('You weren’t signed up for that one.', 'eventcrew'),
        };

        $this->telegram->answerCallbackQuery($callbackId, $message, AssignmentRepository::CANCEL_LOCKED === $status);

        if ('' === $status || AssignmentRepository::CANCEL_LOCKED === $status) {
            return false;
        }

        $this->notifier->confirmCancellation($person, $taskId, $status);

        return true;
    }

    /**
     * Anything that is not a recognised action on a task id comes back as the
     * empty action, which onJoinLeave() answers and drops. That is what makes
     * the board's heading rows inert: BoardRenderer::SPACER_DATA is deliberately
     * not of this shape, so a tap on one lands here, matches nothing, and ends
     * as a bare answerCallbackQuery - spinner stopped, nothing changed.
     *
     * A task button on a picked day carries that day after the id
     * (t:<id>:<Y-m-d>); the plain forms are the next event, and old boards.
     *
     * @return array{0: string, 1: int, 2: string} action ('j'|'l'|'t'|''), task id, day shown ('' = next)
     */
    private function parseData(string $data): array
    {
        if (1 !== preg_match('/^([jlt]):(\d+)(?::(\d{4}-\d{2}-\d{2}))?$/', $data, $matches)) {
            return ['', 0, ''];
        }

        return [$matches[1], (int) $matches[2], $matches[3] ?? ''];
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
