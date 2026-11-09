<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

/**
 * The Telegram accounts that Telegram reported as deactivated (deleted).
 *
 * The person keeps their Telegram id, their history and their rewards. Only
 * direct messages stop, because each one would be refused. The mark is removed
 * when that Telegram id writes to the bot or the group again.
 *
 * Stored as one option, Telegram id => time of the mark.
 */
final class ClosedAccounts
{
    public const OPTION = 'eventcrew_closed_telegram_accounts';

    /**
     * @return array<int, int> Telegram id => Unix time of the mark.
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) ? $stored : [];
    }

    public static function isClosed(int $telegramUserId): bool
    {
        return isset(self::all()[$telegramUserId]);
    }

    public static function mark(int $telegramUserId): void
    {
        $closed = self::all();
        $closed[$telegramUserId] = time();
        update_option(self::OPTION, $closed);
    }

    public static function clear(int $telegramUserId): void
    {
        $closed = self::all();

        if (! isset($closed[$telegramUserId])) {
            return;
        }

        unset($closed[$telegramUserId]);
        update_option(self::OPTION, $closed);
    }
}
