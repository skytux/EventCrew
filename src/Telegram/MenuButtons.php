<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

/**
 * The buttons that replace the bot's old one-purpose commands: under /me
 * (ticket, take over, web sign-in, notifications, language), under /door
 * (door list, guest list, sales desk), under /people (credits, roles, who may
 * hold each role), and the Cancel button under every question the bot asks.
 *
 * Every tap carries PREFIX and is handled by UpdateRouter, which holds the
 * services the buttons open.
 */
final class MenuButtons
{
    public const PREFIX = 'mn:';

    public const TICKET = 'mn:ticket';
    public const REPLACE = 'mn:replace';
    public const WEB = 'mn:web';
    public const NOTIFICATIONS = 'mn:notif';
    public const LANGUAGE = 'mn:lang';
    public const SALES = 'mn:sales';
    public const GIFT = 'mn:gift';
    public const ROLES = 'mn:roles';
    public const ELIGIBLE = 'mn:elig';
    public const CANCEL = 'mn:cancel';

    /** /me, /board in a private chat and /people, as buttons under a message. */
    public const ME = 'mn:me';
    public const BOARD = 'mn:board';
    public const PEOPLE = 'mn:people';

    /**
     * The bot verbs a message can offer (MessageAction::$verb): the verb is
     * the deep link start payload an email's "Open in Telegram" link carries
     * (t.me/<bot>?start=<verb>), and this button's callback data in a DM.
     * Both run the same thing (UpdateRouter).
     */
    public const VERBS = [
        'me' => self::ME,
        'board' => self::BOARD,
        'ticket' => self::TICKET,
        'replace' => self::REPLACE,
        'sales' => self::SALES,
        'people' => self::PEOPLE,
    ];

    /** Followed by the night ("" = the next one), as typed after /door. */
    public const DOOR_LIST = 'mn:dl:';
    public const GUEST_LIST = 'mn:gl:';

    /** The callback data of bot verb $verb, '' for an unknown verb. */
    public static function forVerb(string $verb): string
    {
        return self::VERBS[$verb] ?? '';
    }

    /**
     * The buttons under /me.
     *
     * @return array{inline_keyboard: array<int, array<int, array{text: string, callback_data: string}>>}
     */
    public static function me(): array
    {
        return ['inline_keyboard' => [
            [self::button('🎟 ' . __('Free-entry ticket', 'eventcrew'), self::TICKET)],
            [self::button('🔁 ' . __('Take over a task', 'eventcrew'), self::REPLACE)],
            [
                self::button('🔑 ' . __('Web sign-in', 'eventcrew'), self::WEB),
                self::button('🔔 ' . __('Notifications', 'eventcrew'), self::NOTIFICATIONS),
            ],
            [self::button('🌐 ' . __('Language', 'eventcrew'), self::LANGUAGE)],
        ]];
    }

    /**
     * The buttons under /door. $date is what was typed after /door, or "".
     *
     * @return array{inline_keyboard: array<int, array<int, array{text: string, callback_data: string}>>}
     */
    public static function door(string $date): array
    {
        // Telegram allows 64 bytes of callback data; a date is far shorter,
        // and anything longer is not a date anyway.
        $date = substr($date, 0, 40);

        return ['inline_keyboard' => [
            [self::button('📋 ' . __('Door list', 'eventcrew'), self::DOOR_LIST . $date)],
            [self::button('👥 ' . __('Guest list', 'eventcrew'), self::GUEST_LIST . $date)],
            [self::button('💳 ' . __('Sales desk', 'eventcrew'), self::SALES)],
        ]];
    }

    /**
     * The buttons under /people.
     *
     * @return array{inline_keyboard: array<int, array<int, array{text: string, callback_data: string}>>}
     */
    public static function people(): array
    {
        return ['inline_keyboard' => [
            [self::button('🎁 ' . __('Give credits', 'eventcrew'), self::GIFT)],
            [self::button('🏷 ' . __('Roles, organizers and passes', 'eventcrew'), self::ROLES)],
            [self::button('✅ ' . __('Who may hold each role', 'eventcrew'), self::ELIGIBLE)],
        ]];
    }

    /**
     * The Cancel button row, to put under a question.
     *
     * @return array<int, array{text: string, callback_data: string}>
     */
    public static function cancelRow(): array
    {
        return [self::button('✖️ ' . __('Cancel', 'eventcrew'), self::CANCEL)];
    }

    /**
     * @return array{inline_keyboard: array<int, array<int, array{text: string, callback_data: string}>>}
     */
    public static function cancel(): array
    {
        return ['inline_keyboard' => [self::cancelRow()]];
    }

    /**
     * @return array{text: string, callback_data: string}
     */
    private static function button(string $text, string $data): array
    {
        return ['text' => $text, 'callback_data' => $data];
    }
}
