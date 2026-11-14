<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Support\Language;
use EventCrew\Support\RoleGuide;
use EventCrew\Support\SiteLanguages;

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
    public const ACT_AS = 'mn:actas';
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

    /**
     * The screens under Help (Owner 2026-11-08): How this works? (the
     * welcome guide), Commands (the command list), FAQ (paged as
     * mn:faq:<page>) and, for organizers, the organizer guide.
     */
    public const HELP_GUIDE = 'mn:hguide';
    public const HELP_COMMANDS = 'mn:hcmd';
    public const FAQ = 'mn:faq';
    public const HELP_ORGANIZER = 'mn:horg';

    /**
     * Under Settings (Owner 2026-11-08, as on the web): change name and
     * email (the /name and /email flows), sign out of the web everywhere
     * and delete my data, the last two asked first (MemberCommands).
     */
    public const NAME = 'mn:name';
    public const EMAIL = 'mn:email';
    public const SIGN_OUT = 'mn:signout';
    public const DELETE = 'mn:delete';
    public const ROSTER = 'mn:roster';
    public const DOOR = 'mn:door';

    /**
     * "📱 Get the app" under an organizer's /appinvite post in the group
     * (Owner 2026-11-13): DMs the tapper a sign-in link that opens the Crew
     * app page with its install pop-in (ProfileService::onAppInvite()).
     */
    public const APP = 'mn:app';

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

    /**
     * The screens that come in pages (Paging, 25 to a page): ◀️ and ▶️ send
     * the screen's data, ":" and the page, from 1 - "mn:hist:2". Page 1 is
     * the plain data, so the button that opens the screen opens page 1.
     */
    public const PAGED = [self::MY_TASKS, self::HISTORY];

    /** Followed by the night ("" = the next one), as typed after /door. */
    public const DOOR_LIST = 'mn:dl:';
    public const GUEST_LIST = 'mn:gl:';

    /** The callback data of bot verb $verb, '' for an unknown verb. */
    public static function forVerb(string $verb): string
    {
        return self::VERBS[$verb] ?? '';
    }

    /**
     * The data of page $page of screen $screen (one of PAGED).
     */
    public static function pageData(string $screen, int $page): string
    {
        return $page <= 1 ? $screen : $screen . ':' . $page;
    }

    /**
     * The screen and page a paged tap asks for ("mn:hist:2" → ["mn:hist", 2]),
     * or null when $data is not a page of a paged screen.
     *
     * @return array{0: string, 1: int}|null
     */
    public static function parsePage(string $data): ?array
    {
        foreach (self::PAGED as $screen) {
            if ($data === $screen) {
                return [$screen, 1];
            }

            if (1 === preg_match('/^' . preg_quote($screen, '/') . ':(\d{1,5})$/', $data, $m)) {
                return [$screen, max(1, (int) $m[1])];
            }
        }

        return null;
    }

    /**
     * The ◀️ ▶️ row under page $page of $pages of $screen, side by side like
     * MessagePages' row; none when everything fits on one page.
     *
     * @return array<int, array<int, array{text: string, callback_data: string}>>
     */
    public static function pageRows(string $screen, int $page, int $pages): array
    {
        $row = [];

        if ($page > 1) {
            $row[] = self::button('◀️', self::pageData($screen, $page - 1));
        }

        if ($page < $pages) {
            $row[] = self::button('▶️', self::pageData($screen, $page + 1));
        }

        return [] === $row ? [] : [$row];
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
            [self::button('🎟 ' . __('Get a free-entry ticket', 'eventcrew'), self::TICKET)],
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
            [self::button('📅 ' . __('See open tasks', 'eventcrew'), self::BOARD)],
            [self::button('🔁 ' . __('Take over a task', 'eventcrew'), self::REPLACE)],
            [self::button('🕘 ' . __('Past tasks', 'eventcrew'), self::HISTORY)],
            self::backRow(),
        ];
    }

    /**
     * The buttons under the Settings screen. Web sign-in lives here now
     * (Owner 2026-11-07: rarely needed). In the order of the web's Settings
     * tab (Owner 2026-11-08: Settings alike on both): name and email,
     * notifications, language, the other side (web sign-in here, Telegram
     * there), sign out everywhere else, delete my data.
     *
     * @return array{inline_keyboard: array<int, array<int, array{text: string, callback_data: string}>>}
     */
    public static function settings(bool $acting = false): array
    {
        if ($acting) {
            // An organizer acting as the person (ActingAs): their name, email
            // and sign-in stay theirs.
            return ['inline_keyboard' => [
                [self::button('🔔 ' . __('Choose notifications', 'eventcrew'), self::NOTIFICATIONS)],
                [self::button('🌐 ' . __('Change language', 'eventcrew'), self::LANGUAGE)],
                self::backRow(),
            ]];
        }

        return ['inline_keyboard' => [
            [self::button('✏️ ' . __('Change name', 'eventcrew'), self::NAME)],
            [self::button('📧 ' . __('Change email', 'eventcrew'), self::EMAIL)],
            [self::button('🔔 ' . __('Choose notifications', 'eventcrew'), self::NOTIFICATIONS)],
            [self::button('🌐 ' . __('Change language', 'eventcrew'), self::LANGUAGE)],
            [self::button('🔑 ' . __('Get a web sign-in link', 'eventcrew'), self::WEB)],
            [self::button('🚪 ' . __('Sign out everywhere else', 'eventcrew'), self::SIGN_OUT)],
            [self::button('🗑 ' . __('Delete my data', 'eventcrew'), self::DELETE)],
            self::backRow(),
        ]];
    }

    /**
     * The Help menu, one button to a row (Owner 2026-11-08): How this works?,
     * Commands, FAQ, the organizer guide for an organizer, the crew guide as
     * a link when one is set, and Back to My info.
     *
     * @return array{inline_keyboard: array<int, array<int, array<string, string>>>}
     */
    public static function help(bool $organizer = false): array
    {
        $rows = [
            [self::button('📖 ' . __('How this works?', 'eventcrew'), self::HELP_GUIDE)],
            [self::button('⌨️ ' . __('Commands', 'eventcrew'), self::HELP_COMMANDS)],
            [self::button('💡 ' . __('FAQ', 'eventcrew'), self::FAQ)],
        ];

        if ($organizer) {
            $rows[] = [self::button('🧭 ' . __('Organizer guide', 'eventcrew'), self::HELP_ORGANIZER)];
        }

        $guide = RoleGuide::emailAction();

        if (null !== $guide) {
            $rows[] = [['text' => $guide['label'], 'url' => $guide['url']]];
        }

        $rows[] = self::backRow();

        return ['inline_keyboard' => $rows];
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
     * The "⬅️ Back" row, the last row of every screen under a menu (Owner
     * 2026-11-07: "In the menus always put a back button"). $parent is the
     * callback data of the screen it goes back to: My info by default.
     *
     * @return array<int, array{text: string, callback_data: string}>
     */
    public static function backRow(string $parent = self::ME): array
    {
        return [self::button(__('⬅️ Back', 'eventcrew'), $parent)];
    }

    /**
     * A keyboard of the Back row alone.
     *
     * @return array{inline_keyboard: array<int, array<int, array{text: string, callback_data: string}>>}
     */
    public static function back(string $parent = self::ME): array
    {
        return ['inline_keyboard' => [self::backRow($parent)]];
    }

    /**
     * $markup (a reply markup or null) with the Back row to $parent as its
     * last row.
     *
     * @param array<string, mixed>|null $markup
     * @return array<string, mixed>
     */
    public static function withBack(?array $markup, string $parent): array
    {
        $rows = is_array($markup['inline_keyboard'] ?? null) ? $markup['inline_keyboard'] : [];
        $rows[] = self::backRow($parent);

        return ['inline_keyboard' => $rows];
    }

    /**
     * The buttons under /door. $date is what was typed after /door, or "".
     * An organizer gets Back to Admin under them, however /door was opened
     * (Admin is theirs alone; for the welcome crew /door is the top).
     *
     * @return array{inline_keyboard: array<int, array<int, array{text: string, callback_data: string}>>}
     */
    public static function door(string $date, bool $organizer = false): array
    {
        // Telegram allows 64 bytes of callback data; a date is far shorter,
        // and anything longer is not a date anyway.
        $date = substr($date, 0, 40);

        $rows = [
            [self::button('📋 ' . __('Door list', 'eventcrew'), self::DOOR_LIST . $date)],
            [self::button('👥 ' . __('Guest list', 'eventcrew'), self::GUEST_LIST . $date)],
            [self::button('💳 ' . __('Sales desk', 'eventcrew'), self::SALES)],
        ];

        if ($organizer) {
            $rows[] = self::backRow(self::ADMIN);
        }

        return ['inline_keyboard' => $rows];
    }

    /**
     * The buttons under /people (organizers only), then Back to Admin.
     *
     * @return array{inline_keyboard: array<int, array<int, array{text: string, callback_data: string}>>}
     */
    public static function people(): array
    {
        return ['inline_keyboard' => [
            [self::button('🎁 ' . __('Give credits', 'eventcrew'), self::GIFT)],
            [self::button('🏷 ' . __('Give or remove roles', 'eventcrew'), self::ROLES)],
            [self::button('👤 ' . __('Act as someone', 'eventcrew'), self::ACT_AS)],
            [self::button('✅ ' . __('Who may hold each role', 'eventcrew'), self::ELIGIBLE)],
            self::backRow(self::ADMIN),
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
    /**
     * The group post's one button, "📱 Hae sovellus · Get the app": a group
     * button names itself in the site's first two languages, on one line
     * (Owner 2026-11-06), once when both read the same.
     *
     * @return array{inline_keyboard: array<int, array<int, array{text: string, callback_data: string}>>}
     */
    public static function appInvite(): array
    {
        $labels = [];

        foreach (array_slice(SiteLanguages::codes(), 0, 2) as $code) {
            $labels[] = (string) Language::run($code, static fn (): string => __('Get the app', 'eventcrew'));
        }

        $labels = array_values(array_unique([] === $labels ? [__('Get the app', 'eventcrew')] : $labels));

        return ['inline_keyboard' => [[self::button('📱 ' . implode(' · ', $labels), self::APP)]]];
    }

    private static function button(string $text, string $data): array
    {
        return ['text' => $text, 'callback_data' => $data];
    }
}
