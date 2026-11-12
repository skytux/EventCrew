<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

/**
 * What one person said to the bot's language-model helper, and the helper's
 * answers, kept for 24 hours so the model can follow the conversation
 * ("that one", "the second", "and on Sunday?").
 *
 * Only the plain messages the helper read and the helper's own answers are
 * kept; commands and what they show are not. When the helper runs a command
 * for the person, a short bot line like "[showed /roster 14.11.]" (the
 * command and its date only) marks it (BotLog::marker()). In a group, only the messages
 * written to the bot are kept. The record is one transient per Telegram user
 * for the private chat, and a separate one per user and group ($chatId of
 * the group), so what was said in private never reaches a group answer. Each
 * message older than 24 hours is dropped when the record is read or written,
 * and the transient itself expires 24 hours after the last message.
 */
final class ConversationLog
{
    public const PREFIX = 'eventcrew_ai_chat_';

    /** How long a message is kept, in seconds. */
    public const KEEP = DAY_IN_SECONDS;

    /** Most messages kept per person; the oldest go first. */
    public const MAX_ENTRIES = 100;

    /** Characters kept of one message. */
    private const MAX_ENTRY_CHARS = 2000;

    /** Characters of the conversation sent to the model; the newest are kept. */
    private const PROMPT_CHARS = 12000;

    public const PERSON = 'person';
    public const BOT = 'bot';

    /**
     * Adds one message to the person's record: the private one, or with
     * $chatId of a group, the one for that group.
     */
    public static function add(int $userId, string $who, string $text, ?int $now = null, int $chatId = 0): void
    {
        $text = trim($text);

        if (0 === $userId || '' === $text) {
            return;
        }

        $now ??= time();
        $entries = self::entries($userId, $now, $chatId);
        $entries[] = [
            't' => $now,
            'w' => self::BOT === $who ? self::BOT : self::PERSON,
            'x' => mb_substr($text, 0, self::MAX_ENTRY_CHARS),
        ];

        set_transient(self::key($userId, $chatId), array_slice($entries, -self::MAX_ENTRIES), self::KEEP);
    }

    /**
     * The messages of the last 24 hours, oldest first.
     *
     * @return list<array{t: int, w: string, x: string}>
     */
    public static function entries(int $userId, ?int $now = null, int $chatId = 0): array
    {
        $stored = get_transient(self::key($userId, $chatId));

        if (! is_array($stored)) {
            return [];
        }

        $from = ($now ?? time()) - self::KEEP;
        $kept = [];

        foreach ($stored as $entry) {
            if (
                is_array($entry)
                && is_int($entry['t'] ?? null)
                && $entry['t'] > $from
                && is_string($entry['x'] ?? null)
            ) {
                $kept[] = [
                    't' => $entry['t'],
                    'w' => self::BOT === ($entry['w'] ?? '') ? self::BOT : self::PERSON,
                    'x' => $entry['x'],
                ];
            }
        }

        return $kept;
    }

    /**
     * The conversation as lines for the model, oldest first, each with its
     * time in the site's time zone. When it is longer than the model is sent,
     * the oldest lines are left out. Empty when there is nothing.
     */
    public static function forPrompt(int $userId, ?int $now = null, int $chatId = 0): string
    {
        $lines = [];
        $length = 0;

        foreach (array_reverse(self::entries($userId, $now, $chatId)) as $entry) {
            $line = sprintf(
                '[%s] %s: %s',
                function_exists('wp_date') ? (string) wp_date('D H:i', $entry['t']) : gmdate('D H:i', $entry['t']),
                self::BOT === $entry['w'] ? 'Bot' : 'Person',
                str_replace("\n", ' / ', $entry['x'])
            );
            $length += mb_strlen($line) + 1;

            if ($length > self::PROMPT_CHARS) {
                break;
            }

            $lines[] = $line;
        }

        return implode("\n", array_reverse($lines));
    }

    /**
     * The record a person's private conversation is kept under, for the Crew
     * app page's helper box: their Telegram user id when they have one, so
     * the page and the bot's private chat share one conversation; otherwise
     * minus their person id, which never matches a Telegram user id (those
     * are positive).
     */
    public static function keyFor(?int $telegramUserId, int $personId): int
    {
        return null !== $telegramUserId && $telegramUserId > 0 ? $telegramUserId : -$personId;
    }

    /** Deletes the person's record: the private one, or the one for group $chatId. */
    public static function forget(int $userId, int $chatId = 0): void
    {
        delete_transient(self::key($userId, $chatId));
    }

    /**
     * The private record keeps the key it always had; a group record adds
     * the group's id (without the minus sign Telegram puts on group ids).
     */
    private static function key(int $userId, int $chatId = 0): string
    {
        return self::PREFIX . (0 === $chatId ? '' : 'g' . abs($chatId) . '_') . $userId;
    }
}
