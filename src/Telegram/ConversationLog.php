<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

/**
 * What was said in a private chat between one person and the bot's
 * language-model helper, kept for 24 hours so the model can follow the
 * conversation ("that one", "the second", "and on Sunday?").
 *
 * Only the plain messages the helper read and the helper's own answers are
 * kept; commands and what they show are not. Nothing from a group is kept.
 * The record is one transient per Telegram user: each message older than 24
 * hours is dropped when the record is read or written, and the transient
 * itself expires 24 hours after the last message.
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

    /** Adds one message to the person's record. */
    public static function add(int $userId, string $who, string $text, ?int $now = null): void
    {
        $text = trim($text);

        if (0 === $userId || '' === $text) {
            return;
        }

        $now ??= time();
        $entries = self::entries($userId, $now);
        $entries[] = [
            't' => $now,
            'w' => self::BOT === $who ? self::BOT : self::PERSON,
            'x' => mb_substr($text, 0, self::MAX_ENTRY_CHARS),
        ];

        set_transient(self::key($userId), array_slice($entries, -self::MAX_ENTRIES), self::KEEP);
    }

    /**
     * The messages of the last 24 hours, oldest first.
     *
     * @return list<array{t: int, w: string, x: string}>
     */
    public static function entries(int $userId, ?int $now = null): array
    {
        $stored = get_transient(self::key($userId));

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
    public static function forPrompt(int $userId, ?int $now = null): string
    {
        $lines = [];
        $length = 0;

        foreach (array_reverse(self::entries($userId, $now)) as $entry) {
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

    /** Deletes the person's record. */
    public static function forget(int $userId): void
    {
        delete_transient(self::key($userId));
    }

    private static function key(int $userId): string
    {
        return self::PREFIX . $userId;
    }
}
