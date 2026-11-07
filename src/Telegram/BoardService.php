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
use EventCrew\Support\Language;
use EventCrew\Support\Logger;
use EventCrew\Support\MessageActions;
use EventCrew\Support\RoleGuide;
use EventCrew\Support\SignupService;
use EventCrew\Support\SlotFreedNotice;

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
     * bare bd: for the next event. In a private chat it redraws that copy. In
     * the group it never changes the one shared message (Telegram has no
     * per-person view of a message): that day goes to the private chat of
     * the person who tapped (onPickDate()).
     */
    public const PICK_PREFIX = 'bd:';

    /**
     * The start payload verb that sends one day of the board to the private
     * chat (t.me/<bot>?start=board-20261114, MessageActions::SCREEN_VERBS):
     * used when a day tap in the group could not reach someone who never
     * started the bot. Without a day it is the menu's board verb.
     */
    public const START_VERB = 'board';

    /**
     * Callback data prefix of the Cancel button under a message about one's
     * own task (MessageTemplates::cancelAction()): lc:<task id> asks first,
     * in a new message, whether to cancel, saying whether it would be late.
     * Its buttons add a step: :y cancels (the board's leave), :f shows how
     * to hand the task over, :k keeps it. Only :y changes anything.
     */
    public const CANCEL_ASK_PREFIX = 'lc:';

    /**
     * The start payload that asks the cancel question for one task in the
     * private chat (t.me/<bot>?start=cancel-12): used when a board tap could
     * not reach someone who never started the bot.
     */
    public const CANCEL_START_PREFIX = 'cancel-';

    /**
     * The card format switch, off by default (Settings → Telegram bot). On, the
     * board is two messages: first a photo message for the next event
     * (BoardRenderer::nextCard(): its picture, name, date, price and a short
     * text about its DJ or host, and the card's link buttons), then the text
     * board (BoardRenderer::crewBoard(): the next event with who is signed
     * up, the task buttons and the row of day buttons). With no next event
     * with a picture there is only the text board. BOARD_OPTION then also
     * holds 'card': the photo message's id, its event, its picture, and hashes
     * of its caption and keyboard, so a refresh edits only what changed.
     *
     * 1.126.x showed one message per day and kept them under 'days'; any left
     * there are deleted by the next refresh or repost.
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
        GroupChat::remember($chatId);
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
     * The group's board always shows the next event; other days are shown
     * only in private copies.
     */
    public function refresh(): bool
    {
        if (! $this->telegram->isConfigured()) {
            return false;
        }

        $board = $this->board();
        $chatId = (int) ($board['chat_id'] ?? 0);

        if (0 === $chatId) {
            return false;
        }

        // Messages left from 1.126.x's one message per day.
        if ([] !== $this->days($board)) {
            $board = $this->dropDays($chatId, $board);
        }

        $ok = true;
        $messageId = (int) ($board['message_id'] ?? 0);

        if (self::cardsOn()) {
            $card = $this->refreshCard($chatId, $board);
            $ok = $card['ok'];

            // A card posted under an existing text board would sit below it:
            // the text board is posted again under the card, and the old one
            // deleted, so the order stays photo, then text.
            if ($card['posted'] && $messageId > 0) {
                $rendered = $this->textBoard();
                $result = $this->telegram->sendMessage($chatId, $rendered['text'], $this->markup($rendered));

                if (null !== $result) {
                    $this->telegram->deleteMessage($chatId, $messageId);
                }

                $this->storeBoard($chatId, $result);

                return $ok && null !== $result;
            }
        } elseif (null !== $this->cardState($board)) {
            $this->dropCard($chatId, $board);
        }

        $rendered = $this->textBoard();
        $markup = $this->markup($rendered);

        if ($messageId > 0) {
            return null !== $this->telegram->editMessageText($chatId, $messageId, $rendered['text'], $markup) && $ok;
        }

        $result = $this->telegram->sendMessage($chatId, $rendered['text'], $markup);

        $this->storeBoard($chatId, $result);

        return null !== $result && $ok;
    }

    /**
     * The text board for the day $date ('' = the next event), with the row of
     * day buttons: with the card format on, the day with who is signed up
     * (BoardRenderer::crewBoard()); otherwise render().
     *
     * The group's board is English, the whole group reads it. A private copy
     * ($group false) is for one person, so it is in the language the update
     * runs in: the person's own (UpdateRouter).
     *
     * @return array{text: string, keyboard: array<int, array<int, array<string, mixed>>>}
     */
    private function textBoard(string $date = '', bool $group = true): array
    {
        $build = fn (): array => self::cardsOn()
            ? $this->renderer->crewBoard($date, $group)
            : $this->renderer->render($date, $group);

        return $group ? Language::run(Language::ENGLISH, $build) : $build();
    }

    /**
     * @param array{keyboard: array<int, array<int, array<string, mixed>>>} $rendered
     * @return array<string, mixed>|null
     */
    private function markup(array $rendered): ?array
    {
        return [] === $rendered['keyboard'] ? null : ['inline_keyboard' => $rendered['keyboard']];
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

        $board = $this->board();
        $chatId = (int) ($board['chat_id'] ?? 0);

        if (0 === $chatId) {
            return;
        }

        $rendered = $this->textBoard();
        $card = self::cardsOn() ? $this->renderCard() : null;
        $hash = md5(
            $rendered['text'] . '|' . (string) wp_json_encode($rendered['keyboard'])
                . '|' . (string) wp_json_encode($card)
        );

        // Messages left from 1.126.x's days, or a card left after the format
        // was switched off, have to go even when the text board is the same.
        $leftovers = [] !== $this->days($board) || (! self::cardsOn() && null !== $this->cardState($board));

        if ($hash === (string) get_option(self::BOARD_HASH_OPTION, '') && ! $leftovers) {
            return;
        }

        $this->refresh();
        update_option(self::BOARD_HASH_OPTION, $hash);
    }

    /**
     * Re-posts the board into the chat it already lives in. Used by the
     * scheduled reminder and the Settings button. Inert until the bot and a
     * board chat are set. Returns whether the text board was posted.
     */
    public function repost(): bool
    {
        $chatId = (int) ($this->board()['chat_id'] ?? 0);

        return $this->repostInto($chatId);
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
     * Returns whether the text board was posted.
     */
    public function repostInto(int $chatId): bool
    {
        if (! $this->telegram->isConfigured() || 0 === $chatId) {
            return false;
        }

        // /board in a different group cannot move a locked board either; the
        // group it already lives in still reposts (same chat passes the check).
        if ($this->moveBlocked($chatId)) {
            return false;
        }

        $previous = $this->board();
        $previousChat = (int) ($previous['chat_id'] ?? 0);
        $previousId = (int) ($previous['message_id'] ?? 0);
        $previousCard = $this->cardState($previous);

        // Remember the chat immediately (with no message yet), so the board's
        // home survives even if the post below fails; storeBoard fills in the
        // new message id once Telegram confirms it.
        update_option(self::BOARD_OPTION, ['chat_id' => $chatId, 'message_id' => 0]);
        GroupChat::remember($chatId);

        // The card first, so it sits above the text board.
        if (self::cardsOn()) {
            $card = $this->renderCard();
            $state = null === $card ? null : $this->postCard($chatId, $card);

            if (null !== $state) {
                update_option(self::BOARD_OPTION, ['chat_id' => $chatId, 'message_id' => 0, 'card' => $state]);
            }
        }

        $rendered = $this->textBoard();

        $result = $this->telegram->sendMessage($chatId, $rendered['text'], $this->markup($rendered));

        if (null === $result) {
            // The new board did not go up: the old one stays. In the same chat
            // it stays the board too, so the next refresh edits it, and a card
            // just posted above the failed board is taken down again.
            if ($previousChat === $chatId) {
                $posted = $this->cardState($this->board());

                if (null !== $posted) {
                    $this->telegram->deleteMessage($chatId, $posted['message_id']);
                }

                update_option(self::BOARD_OPTION, $previous);
            }

            return false;
        }

        if (0 !== $previousChat) {
            if ($previousId > 0) {
                $this->telegram->deleteMessage($previousChat, $previousId);
            }

            if (null !== $previousCard) {
                $this->telegram->deleteMessage($previousChat, $previousCard['message_id']);
            }

            // Messages left from 1.126.x's one message per day.
            foreach ($this->days($previous) as $old) {
                $this->telegram->deleteMessage($previousChat, $old['message_id']);
            }
        }

        $this->storeBoard($chatId, $result);

        return true;
    }

    /** Whether the board is shown in card format (Settings → Telegram bot). */
    public static function cardsOn(): bool
    {
        return (bool) get_option(self::CARDS_OPTION, false);
    }

    /**
     * The card format's photo message: edited in place with the smallest edit
     * that does it (editMessageMedia for a new picture or another event,
     * editMessageCaption for a new caption, editMessageReplyMarkup when only
     * the buttons changed, nothing when nothing changed), deleted when there
     * is no next event with a picture, posted when it is missing. A card whose
     * post or edit fails keeps its old state, so the next refresh tries again.
     *
     * 'posted' says a new photo message went up, below any text board.
     *
     * @param array<string, mixed> $board
     * @return array{ok: bool, posted: bool}
     */
    private function refreshCard(int $chatId, array $board): array
    {
        $card = $this->renderCard();
        $previous = $this->cardState($board);

        if (null === $card) {
            if (null !== $previous) {
                $this->dropCard($chatId, $board);
            }

            return ['ok' => true, 'posted' => false];
        }

        if (null === $previous) {
            $state = $this->postCard($chatId, $card);

            if (null === $state) {
                return ['ok' => false, 'posted' => false];
            }

            $this->storeCard($state);

            return ['ok' => true, 'posted' => true];
        }

        $state = $this->cardStateOf($previous['message_id'], $card);
        $markup = $this->cardMarkup($card);

        if ($state['photo'] !== $previous['photo'] || $state['event'] !== $previous['event']) {
            $result = $this->telegram->editMessageMedia(
                $chatId,
                $previous['message_id'],
                $card['photo'],
                $card['caption'],
                $markup
            );
        } elseif ($state['text'] !== $previous['text']) {
            $result = $this->telegram->editMessageCaption($chatId, $previous['message_id'], $card['caption'], $markup);
        } elseif ($state['keyboard'] !== $previous['keyboard']) {
            $result = $this->telegram->editMessageReplyMarkup($chatId, $previous['message_id'], $markup);
        } else {
            return ['ok' => true, 'posted' => false];
        }

        if (null === $result) {
            return ['ok' => false, 'posted' => false];
        }

        $this->storeCard($state);

        return ['ok' => true, 'posted' => false];
    }

    /**
     * Deletes the stored photo message and forgets it.
     *
     * @param array<string, mixed> $board
     */
    private function dropCard(int $chatId, array $board): void
    {
        $previous = $this->cardState($board);

        if (null !== $previous) {
            $this->telegram->deleteMessage($chatId, $previous['message_id']);
        }

        $stored = $this->board();
        unset($stored['card']);
        update_option(self::BOARD_OPTION, $stored);
    }

    /**
     * Deletes the messages left from 1.126.x's one message per day.
     *
     * @param array<string, mixed> $board
     * @return array<string, mixed> The board without its days.
     */
    private function dropDays(int $chatId, array $board): array
    {
        foreach ($this->days($board) as $old) {
            $this->telegram->deleteMessage($chatId, $old['message_id']);
        }

        unset($board['days']);
        update_option(self::BOARD_OPTION, $board);

        return $board;
    }

    /**
     * Posts the photo message. Returns its state for 'card', or null when
     * Telegram did not take it.
     *
     * @param array{
     *     event: int,
     *     photo: string,
     *     caption: string,
     *     buttons: array<int, array<int, array<string, string>>>
     * } $card
     * @return array{message_id: int, event: int, photo: string, text: string, keyboard: string}|null
     */
    private function postCard(int $chatId, array $card): ?array
    {
        $result = $this->telegram->sendPhoto($chatId, $card['photo'], $card['caption'], $this->cardMarkup($card));

        if (null === $result) {
            return null;
        }

        return $this->cardStateOf((int) $result['message_id'], $card);
    }

    /**
     * @param array{
     *     event: int,
     *     photo: string,
     *     caption: string,
     *     buttons: array<int, array<int, array<string, string>>>
     * } $card
     * @return array<string, mixed>|null
     */
    private function cardMarkup(array $card): ?array
    {
        return [] === $card['buttons'] ? null : ['inline_keyboard' => $card['buttons']];
    }

    /**
     * @param array{
     *     event: int,
     *     photo: string,
     *     caption: string,
     *     buttons: array<int, array<int, array<string, string>>>
     * } $card
     * @return array{message_id: int, event: int, photo: string, text: string, keyboard: string}
     */
    private function cardStateOf(int $messageId, array $card): array
    {
        return [
            'message_id' => $messageId,
            'event' => $card['event'],
            'photo' => $card['photo'],
            'text' => md5($card['caption']),
            'keyboard' => md5((string) wp_json_encode($card['buttons'])),
        ];
    }

    /**
     * @param array{message_id: int, event: int, photo: string, text: string, keyboard: string} $state
     */
    private function storeCard(array $state): void
    {
        $stored = $this->board();
        $stored['card'] = $state;
        update_option(self::BOARD_OPTION, $stored);
    }

    /**
     * The stored photo message, or null when there is none.
     *
     * @param array<string, mixed> $board
     * @return array{message_id: int, event: int, photo: string, text: string, keyboard: string}|null
     */
    private function cardState(array $board): ?array
    {
        $card = $board['card'] ?? null;

        if (! is_array($card) || (int) ($card['message_id'] ?? 0) <= 0) {
            return null;
        }

        return [
            'message_id' => (int) $card['message_id'],
            'event' => (int) ($card['event'] ?? 0),
            'photo' => (string) ($card['photo'] ?? ''),
            'text' => (string) ($card['text'] ?? ''),
            'keyboard' => (string) ($card['keyboard'] ?? ''),
        ];
    }

    /**
     * The next event's photo message, in English like render(), or null when
     * the next event has no picture or there is none. When its DJ text is not
     * written yet, a background run is asked for (BoardDjText::schedule());
     * the model is never asked here.
     *
     * @return array{
     *     event: int,
     *     photo: string,
     *     caption: string,
     *     buttons: array<int, array<int, array<string, string>>>
     * }|null
     */
    private function renderCard(): ?array
    {
        $card = Language::run(Language::ENGLISH, fn (): ?array => $this->renderer->nextCard());

        if (null !== $card && '' === BoardDjText::stored($card['event'])) {
            BoardDjText::schedule($card['event']);
        }

        return $card;
    }

    /** The post id of the event the photo message shows, or null. */
    public function nextCardEvent(): ?int
    {
        return $this->renderer->nextCardEvent();
    }

    /**
     * The messages left from 1.126.x's one message per day, keyed by day.
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
     * With the card format on, the copy is the same two messages as the
     * group's: the photo message, then the text board. Neither is stored in
     * BOARD_OPTION. The text board is in the person's language; the photo
     * message stays as the group's.
     */
    public function sendPrivate(int $chatId): void
    {
        if (! $this->telegram->isConfigured() || 0 === $chatId) {
            return;
        }

        if (self::cardsOn()) {
            $card = $this->renderCard();

            if (null !== $card) {
                $this->postCard($chatId, $card);
            }
        }

        $rendered = $this->textBoard('', false);

        $this->telegram->sendMessage($chatId, $rendered['text'], $this->markup($rendered));
    }

    /**
     * One day of the board ('' = the next event) as a new message in the
     * private chat $chatId, in the person's language: the private copy a
     * day tap in the group asks for, or the start link board-<Ymd> opens.
     * Returns whether Telegram took it.
     */
    public function sendDay(int $chatId, string $date): bool
    {
        if (! $this->telegram->isConfigured() || 0 === $chatId) {
            return false;
        }

        $rendered = $this->textBoard($date, false);

        return null !== $this->telegram->sendMessage($chatId, $rendered['text'], $this->markup($rendered));
    }

    /**
     * What each task involves (MenuButtons::TASK_INFO, the board's 📝 Task
     * info link, Owner 2026-11-07): one line per role with its description,
     * always in the person's private chat $chatId.
     */
    public function sendTaskInfo(int $chatId): void
    {
        if (! $this->telegram->isConfigured() || 0 === $chatId) {
            return;
        }

        $lines = RoleGuide::lines();
        $text = [] === $lines
            ? __('There are no task descriptions yet.', 'eventcrew')
            : implode("\n", array_merge([RoleGuide::descriptionsLabel(), ''], $lines));

        $this->telegram->sendMessage($chatId, $text);
    }

    /**
     * /start board-<Ymd> in the private chat: that day of the board (a day
     * with no open tasks shows the next event). False when the id is not a
     * date, so the caller goes on.
     */
    public function openDay(int $chatId, string $ymd): bool
    {
        if (1 !== preg_match('/^(\d{4})(\d{2})(\d{2})$/', $ymd, $m)) {
            return false;
        }

        $this->sendDay($chatId, $m[1] . '-' . $m[2] . '-' . $m[3]);

        return true;
    }

    /**
     * One task in a private chat, from an email's "Sign up in Telegram" link
     * (t.me/<bot>?start=task-<id>, SlotFreedNotice::startPayload()): its
     * details and a Sign up button, which is the join-only j: callback, so
     * signing up runs onJoinLeave() with all the board's checks. Nothing is
     * signed up by opening the link. A task that is gone, under way or full
     * (or restricted to a role) says so instead. Both end with the See open tasks button.
     */
    public function sendTask(int $chatId, int $taskId): void
    {
        if (! $this->telegram->isConfigured() || 0 === $chatId) {
            return;
        }

        $task = $this->tasks->findLive($taskId);
        $board = [['text' => __('See open tasks', 'eventcrew'), 'callback_data' => MenuButtons::BOARD]];

        // A restricted task is never a spot opened to the crew, and the board
        // does not show it to everyone either.
        if (null === $task || $task->hasStarted() || $task->isRestricted()) {
            $this->telegram->sendMessage(
                $chatId,
                __('That task is no longer available.', 'eventcrew'),
                ['inline_keyboard' => [$board]]
            );

            return;
        }

        $free = $task->capacity - ($this->tasks->occupancyFor([$task->id])[$task->id] ?? 0);

        if ($free <= 0) {
            $this->telegram->sendMessage(
                $chatId,
                __('That slot just filled up.', 'eventcrew'),
                ['inline_keyboard' => [$board]]
            );

            return;
        }

        $signup = [
            'text' => sprintf(
                /* translators: 1: the role with its emoji, 2: the day, e.g. "Sat 7 Nov" */
                __('Sign up: %1$s · %2$s', 'eventcrew'),
                $task->roleDisplay(),
                Dates::dayLabel($task->taskDate)
            ),
            'callback_data' => 'j:' . $task->id,
        ];

        $this->telegram->sendMessage(
            $chatId,
            SlotFreedNotice::details($task, $free),
            ['inline_keyboard' => [[$signup], $board]]
        );
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

        $rendered = $this->textBoard($date, false);

        $this->telegram->editMessageText($chatId, $messageId, $rendered['text'], $this->markup($rendered));
    }

    /**
     * A day button tap ('' = the next event). On a private copy it redraws
     * that message on the day. In the group it never touches the shared
     * message, which always shows the next event: the day goes as a new
     * message to the private chat of the person who tapped (sendDay()), and
     * the tap's popup says so. When that chat cannot be reached (the person
     * never started the bot), the tap opens the bot with a start link that
     * sends the same day there (openDay()), or, with no bot name known, says
     * to start the bot first.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onPickDate(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $message = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);
        $fromId = (int) ($callbackQuery['from']['id'] ?? 0);
        $date = substr((string) ($callbackQuery['data'] ?? ''), strlen(self::PICK_PREFIX));

        if (('' !== $date && 1 !== preg_match('#^\d{4}-\d{2}-\d{2}$#', $date)) || 0 === $chatId || 0 === $messageId) {
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        $type = (string) ($message['chat']['type'] ?? ($chatId > 0 ? 'private' : ''));

        if ('private' === $type && ! $this->isGroupBoard($callbackQuery)) {
            $this->telegram->answerCallbackQuery($callbackId);
            $rendered = $this->textBoard($date, false);
            $this->telegram->editMessageText($chatId, $messageId, $rendered['text'], $this->markup($rendered));

            return;
        }

        if (0 !== $fromId && $this->sendDay($fromId, $date)) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('Check your private chat with the bot: that day’s tasks are there.', 'eventcrew')
            );

            return;
        }

        $link = MessageActions::startUrl(self::START_VERB, str_replace('-', '', $date));

        if ('' !== $link) {
            $this->telegram->answerCallbackQuery($callbackId, '', false, $link);

            return;
        }

        $this->telegram->answerCallbackQuery(
            $callbackId,
            __('Open a private chat with the bot and tap Start first, then tap the date again.', 'eventcrew'),
            true
        );
    }

    /**
     * Whether the tap came from the group's own board message. A day tap
     * there goes to the private chat (onPickDate()), whatever kind of chat
     * the group is.
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
     * place and the next repost knows which message to delete. The stored
     * photo message ('card') is kept.
     *
     * @param array<string, mixed>|null $result The sendMessage response.
     */
    private function storeBoard(int $chatId, ?array $result): void
    {
        if (null !== $result && isset($result['message_id'])) {
            $board = [
                'chat_id' => $chatId,
                'message_id' => (int) $result['message_id'],
            ];
            $card = $this->cardState($this->board());

            if (null !== $card) {
                $board['card'] = $card;
            }

            update_option(self::BOARD_OPTION, $board);
        }
    }

    /**
     * Builds the board text and its inline keyboard. Delegated to BoardRenderer;
     * kept here so the lifecycle methods (and the tests) call it as before.
     *
     * Always English: the board is one message the whole group reads, and it
     * is often re-rendered inside a tap by someone who chose Finnish. Private
     * copies go through textBoard() in the person's language instead.
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
        if (str_starts_with((string) ($callbackQuery['data'] ?? ''), self::CANCEL_ASK_PREFIX)) {
            $this->onCancelAsk($callbackQuery);

            return;
        }

        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $telegramUserId = (int) ($callbackQuery['from']['id'] ?? 0);
        [$action, $taskId, $date] = $this->parseData((string) ($callbackQuery['data'] ?? ''));

        if ('' === $action || 0 === $taskId || 0 === $telegramUserId) {
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        $person = $this->verifiedPerson($callbackId, $telegramUserId);

        if (null === $person) {
            return;
        }

        // 't' is the toggle a task button now sends: join if not signed up,
        // leave if already. 'j' and 'l' stay understood so a board posted
        // before this change keeps working until its next refresh. A leave
        // never acts at once: it asks first, in the person's private chat,
        // what the cancel costs (askCancel()).
        $changed = match ($action) {
            'j' => $this->handleJoin($callbackId, $taskId, $person),
            'l' => $this->askLeave($callbackQuery, $taskId, $person),
            default => $this->handleToggle($callbackQuery, $taskId, $person),
        };

        // The group's board always shows the next event. A private copy's
        // day comes with the tap, so its redraw stays on that day.
        if ($changed) {
            $this->refresh();
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
    private function handleToggle(array $callbackQuery, int $taskId, Person $person): bool
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $existing = $this->assignments->findFor($taskId, $person->id);

        if (null !== $existing && $existing->isOccupying()) {
            return $this->askLeave($callbackQuery, $taskId, $person);
        }

        return $this->handleJoin($callbackId, $taskId, $person);
    }

    /**
     * A leave tap on a board: the cancel question goes to the person's
     * private chat, never into the group. Nothing changes yet, so the board
     * is not redrawn (false).
     *
     * @param array<string, mixed> $callbackQuery
     */
    private function askLeave(array $callbackQuery, int $taskId, Person $person): bool
    {
        $telegramUserId = (int) ($callbackQuery['from']['id'] ?? 0);
        $chatId = (int) ($callbackQuery['message']['chat']['id'] ?? 0);

        $this->askCancel((string) ($callbackQuery['id'] ?? ''), $person, $taskId, $telegramUserId, $chatId !== $telegramUserId);

        return false;
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
            SignupService::WAITING => SignupService::waitingText(),
            SignupService::TURNED_DOWN => SignupService::turnedDownText(),
            AssignmentRepository::JOIN_OK,
            AssignmentRepository::JOIN_REJOINED => __('You’re in! See you there.', 'eventcrew'),
            AssignmentRepository::JOIN_DUPLICATE => __('You’re already signed up for that.', 'eventcrew'),
            AssignmentRepository::JOIN_FULL => __('That slot just filled up.', 'eventcrew'),
            default => __('That task is no longer available.', 'eventcrew'),
        };

        $this->telegram->answerCallbackQuery($callbackId, $message, true);

        if ($joined) {
            $this->notifier->signedUp($person, $taskId);
        }

        return $joined;
    }

    /** True for a status that ended a sign-up: CANCELLED or LATE_CANCEL. */
    private static function isCancel(string $status): bool
    {
        return in_array($status, [AssignmentStatus::CANCELLED, AssignmentStatus::LATE_CANCEL], true);
    }

    /**
     * Drops the task and answers the tap. Returns what SignupService::drop()
     * returned: the cancellation recorded, CANCEL_LOCKED, or ''.
     */
    private function leave(string $callbackId, int $taskId, Person $person): string
    {
        // Cancel, don't delete: the row stays for the reputation history, and
        // is classified late_cancel/cancelled by how much notice this gives.
        $status = $this->signup->drop($person->id, $taskId);

        $this->telegram->answerCallbackQuery(
            $callbackId,
            self::leaveText($status),
            AssignmentRepository::CANCEL_LOCKED === $status
        );

        if (self::isCancel($status)) {
            $this->notifier->cancelled($person, $taskId, $status);
        }

        return $status;
    }

    /** The answer to a leave tap that ended in $status (SignupService::drop()). */
    private static function leaveText(string $status): string
    {
        return match ($status) {
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            AssignmentStatus::LATE_CANCEL => __('You’re out. This close to the event it counts as a late cancellation.', 'eventcrew'),
            AssignmentStatus::CANCELLED => __('You’re out. Thanks for the notice.', 'eventcrew'),
            AssignmentRepository::CANCEL_LOCKED => SignupService::signoutLockedText(),
            default => __('You weren’t signed up for that one.', 'eventcrew'),
        };
    }

    /**
     * The person behind a tap, when their email is confirmed. Otherwise the
     * tap is answered with what to do first, and null comes back.
     */
    private function verifiedPerson(string $callbackId, int $telegramUserId): ?Person
    {
        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $person->isEmailVerified()) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('First tap the “New here? Sign up” button below to confirm your email.', 'eventcrew'),
                true
            );

            return null;
        }

        return $person;
    }

    /**
     * A tap on the Cancel button under a message about one's own task, or on
     * one of the buttons of its question (CANCEL_ASK_PREFIX). The tap acts
     * for the person who tapped, so a forwarded message cancels nobody
     * else's task.
     *
     * @param array<string, mixed> $callbackQuery
     */
    private function onCancelAsk(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $telegramUserId = (int) ($callbackQuery['from']['id'] ?? 0);
        $message = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];
        $chatId = (int) ($message['chat']['id'] ?? $telegramUserId);
        $messageId = (int) ($message['message_id'] ?? 0);

        if (
            1 !== preg_match('/^lc:(\d+)(?::([yfk]))?$/', (string) ($callbackQuery['data'] ?? ''), $m)
            || 0 === $telegramUserId
        ) {
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        $taskId = (int) $m[1];
        $step = $m[2] ?? '';
        $person = $this->verifiedPerson($callbackId, $telegramUserId);

        if (null === $person) {
            return;
        }

        if ('' === $step) {
            $this->askCancel($callbackId, $person, $taskId, $chatId);

            return;
        }

        $task = $this->tasks->find($taskId);

        if ('y' === $step) {
            $status = $this->leave($callbackId, $taskId, $person);
            $this->replaceQuestion($chatId, $messageId, self::leaveIcon($status) . self::leaveText($status));

            if (self::isCancel($status)) {
                $this->refresh();
            }

            return;
        }

        $outcome = $this->signup->dropOutcome($person->id, $taskId);

        // Nothing to cancel, or too close to cancel: said at once, no question.
        if (null === $task || ! self::isCancel($outcome)) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                self::leaveText($outcome),
                AssignmentRepository::CANCEL_LOCKED === $outcome
            );
            $this->replaceQuestion($chatId, $messageId, self::leaveText($outcome));

            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        if ('k' === $step) {
            $this->replaceQuestion($chatId, $messageId, '👍 ' . sprintf(
                /* translators: %s: the task, the event and the day, like "Door at Party, Sat 14 Nov 18:00" */
                __('Kept: you are still on %s.', 'eventcrew'),
                self::taskLine($task)
            ));

            return;
        }

        $text = self::cancelQuestion($task, AssignmentStatus::LATE_CANCEL === $outcome);

        // 'f': how to hand it over.
        $this->telegram->editMessageText(
            $chatId,
            $messageId,
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            $text . "\n\n🔁 " . __('To hand it over, ask someone in the crew to send /me to the bot and tap 🔁 Take over a task. You get a message to say yes, and you stay on it until then.', 'eventcrew'),
            $this->cancelKeyboard($taskId, false)
        );
    }

    /**
     * The first step of every Telegram cancel: "Cancel <task>?" with what it
     * costs (late or not) and the Yes / hand over / Keep buttons, sent to
     * $chatId. Nothing is cancelled here. When there is nothing to cancel, or
     * it is too close to cancel, the tap is answered with that instead.
     * $elsewhere: the tap was in another chat (the group's board), so its
     * popup says where the question went. When the private chat cannot be
     * reached (the person never started the bot), the tap opens the bot with
     * a start link that asks the same question there (openCancel()).
     */
    public function askCancel(string $callbackId, Person $person, int $taskId, int $chatId, bool $elsewhere = false): void
    {
        $task = $this->tasks->find($taskId);
        $outcome = $this->signup->dropOutcome($person->id, $taskId);

        if (null === $task || ! self::isCancel($outcome)) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                self::leaveText($outcome),
                AssignmentRepository::CANCEL_LOCKED === $outcome
            );

            return;
        }

        $sent = $this->telegram->sendMessage(
            $chatId,
            self::cancelQuestion($task, AssignmentStatus::LATE_CANCEL === $outcome),
            $this->cancelKeyboard($taskId, true)
        );

        if (null !== $sent) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                $elsewhere ? __('Check your private chat with the bot: it asks there first.', 'eventcrew') : ''
            );

            return;
        }

        $username = ltrim(trim((string) get_option(self::USERNAME_OPTION, '')), '@');

        if ('' !== $username) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                '',
                false,
                'https://t.me/' . $username . '?start=' . StartCode::append(self::CANCEL_START_PREFIX . $taskId)
            );

            return;
        }

        $this->telegram->answerCallbackQuery(
            $callbackId,
            __('Open a private chat with the bot and tap Start first, then tap the task again.', 'eventcrew'),
            true
        );
    }

    /**
     * /start cancel-<id> in the private chat: the cancel question for that
     * task, for the person who opened the link. False when the payload is not
     * a cancel link or the person is not a verified member yet, so the caller
     * goes on to the sign-up flow.
     */
    public function openCancel(int $telegramUserId, int $chatId, string $payload): bool
    {
        if (1 !== preg_match('/^' . preg_quote(self::CANCEL_START_PREFIX, '/') . '([1-9]\d{0,18})$/', $payload, $m)) {
            return false;
        }

        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $person->isEmailVerified()) {
            return false;
        }

        $taskId = (int) $m[1];
        $task = $this->tasks->find($taskId);
        $outcome = $this->signup->dropOutcome($person->id, $taskId);

        if (null === $task || ! self::isCancel($outcome)) {
            $this->telegram->sendMessage($chatId, self::leaveText($outcome));

            return true;
        }

        $this->telegram->sendMessage(
            $chatId,
            self::cancelQuestion($task, AssignmentStatus::LATE_CANCEL === $outcome),
            $this->cancelKeyboard($taskId, true)
        );

        return true;
    }

    /**
     * "Cancel Door at Dance Night, Sat 14 Nov 18:00–20:00?", and whether it
     * counts against the standing. The web page's Cancel asks the same.
     */
    public static function cancelQuestion(Task $task, bool $late): string
    {
        return sprintf(
            /* translators: %s: the task, the event and the day, like "Door at Party, Sat 14 Nov 18:00" */
            __('Cancel %s?', 'eventcrew'),
            self::taskLine($task)
        ) . "\n\n" . ($late
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            ? '⚠️ ' . __('This close to the task it is a late cancellation: it counts against your standing.', 'eventcrew')
            : __('Early enough: it does not affect your standing.', 'eventcrew'));
    }

    /**
     * The question's buttons: Yes, cancel; how to hand it over (until it was
     * shown), else the crew's group when its link is known; Keep it.
     *
     * @return array{inline_keyboard: list<list<array<string, string>>>}
     */
    private function cancelKeyboard(int $taskId, bool $withHandOver): array
    {
        $data = static fn (string $step): string => self::CANCEL_ASK_PREFIX . $taskId . ':' . $step;
        $rows = [[['text' => '✅ ' . __('Yes, cancel', 'eventcrew'), 'callback_data' => $data('y')]]];
        $group = self::groupLink();

        if ($withHandOver) {
            $rows[] = [['text' => '🔁 ' . __('Find someone to take it', 'eventcrew'), 'callback_data' => $data('f')]];
        } elseif ('' !== $group) {
            $rows[] = [['text' => __('Open the group', 'eventcrew'), 'url' => $group]];
        }

        $rows[] = [['text' => '👍 ' . __('Keep it', 'eventcrew'), 'callback_data' => $data('k')]];

        return ['inline_keyboard' => $rows];
    }

    /** "Door at Dance Night, Sat 14 Nov 18:00–20:00". */
    private static function taskLine(Task $task): string
    {
        $day = Dates::dayLabel($task->taskDate);

        return sprintf(
            /* translators: 1: role, 2: event, 3: day and time */
            __('%1$s at %2$s, %3$s', 'eventcrew'),
            $task->roleLabel(),
            $task->eventName(),
            '' === $task->timeRange() ? $day : $day . ' ' . $task->timeRange()
        );
    }

    private static function leaveIcon(string $status): string
    {
        return match ($status) {
            AssignmentStatus::LATE_CANCEL => '⚠️ ',
            AssignmentStatus::CANCELLED => '❌ ',
            default => '',
        };
    }

    /** The question's text becomes $text, and its buttons go. */
    private function replaceQuestion(int $chatId, int $messageId, string $text): void
    {
        if (0 === $chatId || 0 === $messageId) {
            $this->telegram->sendMessage($chatId, $text);

            return;
        }

        $this->telegram->editMessageText($chatId, $messageId, $text, ['inline_keyboard' => []]);
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
     * The board option. With no chat in it (the option was deleted), the
     * stored group (GroupChat) is its chat, so the board is posted there again.
     *
     * @return array<string, mixed>
     */
    private function board(): array
    {
        $board = get_option(self::BOARD_OPTION, []);
        $board = is_array($board) ? $board : [];

        if (0 === (int) ($board['chat_id'] ?? 0)) {
            $group = GroupChat::id();

            if (0 !== $group) {
                $board['chat_id'] = $group;
            }
        }

        return $board;
    }
}
