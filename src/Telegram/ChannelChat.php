<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

/**
 * The crew's Telegram channel (Owner 2026-11-14, Q397): the bot posts the
 * next event's card (BoardService::announceChannelCard()) and the thank-you
 * after each event (ChannelThanks) there.
 *
 * Set when an organizer makes the bot an administrator of a channel
 * (BoardService::onBotMembershipChange()): the new channel replaces any
 * earlier one. Cleared when the bot leaves or is removed from it, or with
 * the Disconnect button in Settings → Telegram bot.
 */
final class ChannelChat
{
    /** {id: int, title: string}; missing when there is no channel. */
    public const OPTION = 'eventcrew_telegram_channel';

    /** The channel's chat id; 0 when none. */
    public static function id(): int
    {
        return (int) (self::stored()['id'] ?? 0);
    }

    /** The channel's title as Telegram gave it; '' when none. */
    public static function title(): string
    {
        return 0 === self::id() ? '' : (string) (self::stored()['title'] ?? '');
    }

    /** Stores $chatId as the channel, replacing any earlier one. */
    public static function set(int $chatId, string $title): void
    {
        if (0 === $chatId) {
            return;
        }

        update_option(self::OPTION, ['id' => $chatId, 'title' => trim($title)], false);
    }

    /** Forgets the channel. */
    public static function clear(): void
    {
        update_option(self::OPTION, [], false);
    }

    /** @return array<string, mixed> */
    private static function stored(): array
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) ? $stored : [];
    }
}
