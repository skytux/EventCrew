<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

/**
 * The buttons that replace the bot's old one-purpose commands: under /me
 * (Tickets, Tasks, Settings, Help and, for organizers, Admin - each a screen
 * of its own, Owner 2026-11-07), under /door
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

    /** "Not right? Tell us" under a no-show notice: asks what is wrong (UpdateRouter). */
    public const TELL = 'mn:tell';

    /** What each task involves, as a private message (the board's 📝 Task info, Owner 2026-11-07). */
    public const TASK_INFO = 'mn:tasks';

    /**
     * The screens under My info (Owner 2026-11-07): tickets, the person's own
     * tasks, their past tasks, settings, /help, and for organizers the admin
     * menu with the roster and /door.
     */
    public const TICKETS = 'mn:tix';
    public const MY_TASKS = 'mn:mytasks';
    public const HISTORY = 'mn:hist';
    public const SETTINGS = 'mn:set';
    public const HELP = 'mn:help';
    public const ADMIN = 'mn:admin';
    public const ROSTER = 'mn:roster';
    public const DOOR = 'mn:door';

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
        'tell' => self::TELL,
        'tasks' => self::TASK_INFO,
        // "tasks" is the board's Task info, so the Tasks screen is "mytasks".
        'mytasks' => self::MY_TASKS,
        'tickets' => self::TICKETS,
        'settings' => self::SETTINGS,
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
     * The buttons under /me, one to a row (Owner 2026-11-07): Tickets, Tasks,
     * Settings, Help, and for an organizer Admin at the bottom.
     *
     * @return array{inline_keyboard: array<int, array<int, array{text: string, callback_data: string}>>}
     */
    public static function me(bool $organizer = false): array
    {
        $rows = [
            [self::button('🎟 ' . __('Tickets', 'eventcrew'), self::TICKETS)],
            [self::button('📋 ' . __('Tasks', 'eventcrew'), self::MY_TASKS)],
            [self::button('⚙️ ' . __('Settings', 'eventcrew'), self::SETTINGS)],
            [self::button('❓ ' . __('Help', 'eventcrew'), self::HELP)],
        ];

        if ($organizer) {
            $rows[] = [self::button('🛠 ' . __('Admin', 'eventcrew'), self::ADMIN)];
        }

        return ['inline_keyboard' => $rows];
    }

    /**
     * The buttons under the Tickets screen, below the ticket buttons.
     *
     * @return array<int, array<int, array{text: string, callback_data: string}>>
     */
    public static function ticketsRows(): array
    {
        return [
            [self::button('🎟 ' . __('Free-entry ticket', 'eventcrew'), self::TICKET)],
            self::backRow(),
        ];
    }

    /**
     * The buttons under the Tasks screen, below the cancel buttons: the
     * board first (Owner 2026-11-07), then Take over a task and Past tasks.
     *
     * @return array<int, array<int, array{text: string, callback_data: string}>>
     */
    public static function tasksRows(): array
    {
        return [
            [self::button('📅 ' . __('Open tasks', 'eventcrew'), self::BOARD)],
            [self::button('🔁 ' . __('Take over a task', 'eventcrew'), self::REPLACE)],
            [self::button('🕘 ' . __('Past tasks', 'eventcrew'), self::HISTORY)],
            self::backRow(),
        ];
    }

    /**
     * The buttons under the Settings screen. Web sign-in lives here now
     * (Owner 2026-11-07: rarely needed).
     *
     * @return array{inline_keyboard: array<int, array<int, array{text: string, callback_data: string}>>}
     */
    public static function settings(): array
    {
        return ['inline_keyboard' => [
            [self::button('🔔 ' . __('Notifications', 'eventcrew'), self::NOTIFICATIONS)],
            [self::button('🌐 ' . __('Language', 'eventcrew'), self::LANGUAGE)],
            [self::button('🔑 ' . __('Web sign-in', 'eventcrew'), self::WEB)],
            self::backRow(),
        ]];
    }

    /**
     * The organizers' admin menu under My info: the screens /roster, /door
     * and /people open.
     *
     * @return array{inline_keyboard: array<int, array<int, array{text: string, callback_data: string}>>}
     */
    public static function admin(): array
    {
        return ['inline_keyboard' => [
            [self::button('📅 ' . __('Roster', 'eventcrew'), self::ROSTER)],
            [self::button('🚪 ' . __('Door', 'eventcrew'), self::DOOR)],
            [self::button('👥 ' . __('People', 'eventcrew'), self::PEOPLE)],
            self::backRow(),
        ]];
    }

    /**
     * The "👤 My info" row that takes a screen under /me back to it.
     *
     * @return array<int, array{text: string, callback_data: string}>
     */
    public static function backRow(): array
    {
        return [self::button(__('👤 My info', 'eventcrew'), self::ME)];
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
