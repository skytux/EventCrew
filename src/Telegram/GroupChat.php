<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

/**
 * The crew's Telegram group, kept in its own option so it outlives the board.
 *
 * The board option (BoardService::BOARD_OPTION) holds the group's chat id next
 * to the board's message ids, and it is cleared or rewritten whenever the board
 * is: when it was deleted on 2026-11-04 the site also forgot which group it
 * serves. This option holds only the chat id. It changes when the bot is added
 * to another group the lock allows, when the board is moved with /board, and
 * when Telegram moves the group to a new chat id (a group upgraded to a
 * supergroup). When it is empty, the next update that comes from a group fills
 * it again (learn()).
 */
final class GroupChat
{
    public const OPTION = 'eventcrew_telegram_group_id';

    /** The stored group id; 0 when none. */
    public static function id(): int
    {
        return (int) get_option(self::OPTION, 0);
    }

    /**
     * The group the board lives in: the board option's chat, or the stored
     * group when the board option has none. 0 when neither is known.
     */
    public static function current(): int
    {
        $board = get_option(BoardService::BOARD_OPTION, []);
        $chatId = is_array($board) ? (int) ($board['chat_id'] ?? 0) : 0;

        return 0 !== $chatId ? $chatId : self::id();
    }

    /** Stores $chatId as the group. Group ids are negative; anything else is ignored. */
    public static function remember(int $chatId): void
    {
        if ($chatId >= 0 || self::id() === $chatId) {
            return;
        }

        update_option(self::OPTION, $chatId);
    }

    /**
     * Fills the option from the board option once, for sites that had a board
     * before this option existed. Does nothing when the option is set or the
     * board option has no chat.
     */
    public static function migrate(): void
    {
        if (0 !== self::id()) {
            return;
        }

        $board = get_option(BoardService::BOARD_OPTION, []);

        self::remember(is_array($board) ? (int) ($board['chat_id'] ?? 0) : 0);
    }

    /**
     * Telegram moved a group to a new chat id ($from to $to). When $from is
     * the crew's group, both the stored group and the board follow. The
     * board's message ids belonged to the old chat, so they are dropped and
     * the next refresh posts a new board. Returns whether it was the group.
     */
    public static function migrated(int $from, int $to): bool
    {
        if (0 === $from || 0 === $to || $from === $to) {
            return false;
        }

        $board = get_option(BoardService::BOARD_OPTION, []);
        $boardChat = is_array($board) ? (int) ($board['chat_id'] ?? 0) : 0;

        if (self::id() !== $from && $boardChat !== $from) {
            return false;
        }

        update_option(self::OPTION, $to);

        if ($boardChat === $from) {
            update_option(BoardService::BOARD_OPTION, ['chat_id' => $to, 'message_id' => 0]);
        }

        return true;
    }

    /**
     * Reads the group from an update the bot gets anyway: a message saying
     * the group moved to a supergroup (migrate_to_chat_id in the old chat,
     * migrate_from_chat_id in the new one) moves the group. Telegram sends
     * these service messages to the bot under privacy mode too.
     *
     * The bot's own membership changes (my_chat_member) are not read here:
     * BoardService::onBotMembershipChange() handles them, the lock included.
     *
     * @param array<string, mixed> $update
     */
    public static function learn(array $update): void
    {
        $message = null;

        if (is_array($update['callback_query']['message'] ?? null)) {
            $message = $update['callback_query']['message'];
        } elseif (is_array($update['message'] ?? null)) {
            $message = $update['message'];
        }

        if (null === $message || ! is_array($message['chat'] ?? null)) {
            return;
        }

        $chat = $message['chat'];
        $chatId = (int) ($chat['id'] ?? 0);

        if (! in_array((string) ($chat['type'] ?? ''), ['group', 'supergroup'], true) || 0 === $chatId) {
            return;
        }

        $to = (int) ($message['migrate_to_chat_id'] ?? 0);
        $from = (int) ($message['migrate_from_chat_id'] ?? 0);

        if (0 !== $to) {
            self::migrated($chatId, $to);

            return;
        }

        if (0 !== $from) {
            self::migrated($from, $chatId);
        }
    }
}
