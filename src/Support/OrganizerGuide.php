<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Web\BuyerImportController;

/**
 * The organizer welcome text: how the whole plugin works, first in short and
 * then in detail.
 *
 * Every number is read from the live settings when the text is built, so it
 * cannot disagree with what the plugin does. Plain text with "## " headings,
 * which EmailBody draws as headings in the email and strips for Telegram.
 * Each heading and each list line starts with an emoji. The same text is the
 * organizer part of the Help tab on the crew web page (HelpPage).
 */
final class OrganizerGuide
{
    public static function subject(): string
    {
        return __('Organizer guide: how EventCrew works', 'eventcrew');
    }

    /**
     * The EventCrew menu in wp-admin. The slug is Admin::MENU_SLUG, kept
     * literal so this Support class does not reach up into the Admin layer.
     */
    public static function wpAdminUrl(): string
    {
        return admin_url('admin.php?page=eventcrew');
    }

    /** The wp-admin link as a line of text. */
    private static function wpAdminLine(): string
    {
        return '🔗 ' . __('Open EventCrew in wp-admin:', 'eventcrew') . ' ' . self::wpAdminUrl();
    }

    /**
     * The contents list, the short part and the details: the whole email, and
     * the organizer part of the web Help tab.
     */
    public static function full(): string
    {
        return self::contents() . "\n\n" . self::short() . "\n\n" . self::details();
    }

    /**
     * A list of every heading below it, each a link to that heading. Built from
     * the headings themselves, so it cannot list a section that is not there.
     */
    public static function contents(): string
    {
        preg_match_all('/^## (.+)$/m', self::short() . "\n\n" . self::details(), $matches);
        $lines = [];

        foreach ($matches[1] as $heading) {
            $parts = explode(' ', $heading, 2);
            $lines[] = sprintf('- %s [%s](#%s)', $parts[0], $parts[1] ?? '', EmailBody::anchor($heading));
        }

        return '## 📑 ' . __('Contents', 'eventcrew') . "\n\n" . implode("\n", $lines);
    }

    /** The "In short" part: the email's first section, and the whole DM. */
    public static function short(): string
    {
        $settings = new ReputationSettings();

        $lines = [
            '- 🗓️ ' . __('Tasks: you create them in wp-admin, EventCrew > Tasks. One click makes all the tasks of a night.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            '- ✋ ' . __('Sign-up: members tap Join on the board in the Telegram group, or use the web sign-up page. /board posts a new board in the group.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            '- 🌙 ' . __('On the night: /roster marks who arrived, who completed their task and who did not come. The sales desk link sells tickets and checks people in at the door; /sales sends you the link.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            sprintf(
                /* translators: %s: the import email address */
                '- 📧 ' . __('Holvi ticket buyers: email the list of names to %s from your EventCrew email address. The names go on the sales desk list of the next event.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                BuyerImportController::MAILBOX
            ),
            sprintf(
                /* translators: %d: completed tasks needed for one credit */
                '- 🎁 ' . __('Credits: members earn 1 free-entry credit per %d completed tasks. /gift gives credits by hand.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                $settings->tasksPerCredit()
            ),
            sprintf(
                /* translators: %d: good-standing percentage */
                '- 📈 ' . __('Standing: %d%% or higher is good standing. It comes from how each member\'s past tasks went.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                (int) round($settings->threshold() * 100)
            ),
            '- 🔑 ' . __('Permissions: /allow makes someone a leader, gives a one-time pass past the sign-up gate, or makes someone an organizer.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            sprintf(
                /* translators: 1: opening time, e.g. 09:00, 2: closing time, e.g. 21:00 */
                '- 🔔 ' . __('Messages: reminders and calls go out by themselves, only between %1$s and %2$s.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                self::windowStart(),
                self::windowEnd()
            ),
            '- ⚙️ ' . __('wp-admin, EventCrew > Settings changes how things work. EventCrew > Diagnostics shows whether everything is running.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            '- ' . self::wpAdminLine(),
        ];

        return '## ⚡ ' . __('In short', 'eventcrew') . "\n\n" . implode("\n", $lines);
    }

    /** The detailed sections, after the short part, for the email only. */
    public static function details(): string
    {
        return implode("\n\n", [
            ...self::tasks(),
            ...self::signUp(),
            ...self::night(),
            ...self::credits(),
            ...self::leaders(),
            ...self::messages(),
            ...self::admin(),
        ]);
    }

    /** @return array<int, string> */
    private static function tasks(): array
    {
        return [
            '## 🗓️ ' . __('Tasks and events', 'eventcrew'),
            __('A role is a kind of job, for example Welcome or Clean. You set the roles in Settings: name, emoji, a short description, how many people, and when it starts and ends compared to the event.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __('A task is one role on one date. In EventCrew > Tasks you create tasks one by one, or all the tasks of a night at once from the roles. A task can be linked to an event, so it shows under the event\'s name. A task set to 0 people stays saved but leaves the board.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
        ];
    }

    /** @return array<int, string> */
    private static function signUp(): array
    {
        $noticeHours = max(0, (int) get_option(SignupService::NOTICE_HOURS_OPTION, 48));

        return [
            '## 📣 ' . __('The board and sign-up', 'eventcrew'),
            __('The board in the Telegram group lists the open tasks under each event and date, with a Join button per task. It updates itself whenever something changes. /board in the group posts a new copy at the bottom. Settings > Telegram bot > Refresh the board redraws it in place.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __('A new member sends /start to the bot in a private chat and confirms their email address. On the web sign-up page they sign in with a link sent to their email. Both follow the same rules.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            sprintf(
                /* translators: %d: hours of notice, e.g. 48 */
                __('Cancelling less than %d hours before the task counts as a late cancellation. A member who cannot come can ask someone else to take the task with /replace.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                $noticeHours
            ),
        ];
    }

    /** @return array<int, string> */
    private static function night(): array
    {
        return [
            '## 🌙 ' . __('On the night: roster and sales desk', 'eventcrew'),
            __('/roster in a private chat with the bot shows the night\'s crew. Mark each person arrived, arrived late, completed or no-show, one by one or a whole crew at once. EventCrew > Roster in wp-admin does the same for any date. The night\'s leader can mark the crew too.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __('The sales desk is a web page for the door. It shows tonight\'s list: the crew, members with a free-entry ticket, guests and Holvi ticket buyers. You mark people in and out and say how many people they brought. Add sells a ticket at the door, adds a guest, or adds a member who spends a credit or takes one of tonight\'s tasks.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            sprintf(
                /* translators: %d: minutes before the night's first task */
                __('You get the sales desk link by itself %d minutes before the night\'s first task. The leader and the Welcome crew get it once they are marked arrived. /sales sends a new link any time. /tickets lists who has a ticket and /guest shows the guest list.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                DoorLinkSender::leadMinutes()
            ),
            __('Holvi ticket buyers: in EventCrew > Roster, pick the date and paste the names, one per line, under "Holvi ticket buyers". A name already on the list is skipped.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            sprintf(
                /* translators: %s: the import email address */
                __('Or email the list to %s. Send it from the email address you have in EventCrew; mail from any other address is not used. Put one name per line in the message, or attach a .txt or .csv file with the names in the first column. The names go to the next event, which must already have its tasks. A name already on the list is skipped. Within a few minutes you get an email saying how many names were added and how many were skipped. If no email comes, add the names in EventCrew > Roster instead.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                BuyerImportController::MAILBOX
            ),
        ];
    }

    /** @return array<int, string> */
    private static function credits(): array
    {
        $settings = new ReputationSettings();
        $threshold = (int) round($settings->threshold() * 100);
        $lines = [
            '## 🎟️ ' . __('Tickets, credits and standing', 'eventcrew'),
            __('Signing up for a task gives the member a free ticket for that night. The ticket is a web page with a clock that ticks. A clock that does not move is a screenshot.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            sprintf(
                /* translators: %d: completed tasks needed for one credit */
                __('Members earn 1 free-entry credit per %d completed tasks and spend it with /ticket or on the web page. You give credits with /gift, on the People page, or at the sales desk. Each gift is written in the credit log on the People page, and the member is told.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                $settings->tasksPerCredit()
            ),
            sprintf(
                /* translators: 1: tasks before a rating, 2: good-standing percentage */
                __('Standing is worked out again each time from how a member\'s past tasks went; recent tasks count more. A member gets a rating after %1$d finished tasks. %2$d%% or higher is good standing. Settings > Reputation holds the weights and limits.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                $settings->minRatedTasks(),
                $threshold
            ),
        ];

        if ((bool) get_option(SignupService::GATE_OPTION, true)) {
            $lines[] = sprintf(
                /* translators: %d: good-standing percentage */
                __('The sign-up gate is on: a member below %d%% cannot sign up for new tasks. /allow can give them a one-time pass.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                $threshold
            );
        }

        return $lines;
    }

    /** @return array<int, string> */
    private static function leaders(): array
    {
        $lines = ['## 🧭 ' . __('Leaders and permissions', 'eventcrew')];

        if ((bool) get_option(LeaderGate::ENABLED_OPTION, false)) {
            $lines[] = sprintf(
                /* translators: %d: how many times each other task must be done first */
                __('The 🧭 Leader task runs the crew for the night and can mark the crew from Telegram, without wp-admin. A member becomes eligible after doing each of the other tasks %d times. You are told when someone becomes eligible. They can take the Leader task once you allow it with /allow. /leaders lists who is eligible and who is allowed.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                max(1, (int) get_option(LeaderEligibility::THRESHOLD_OPTION, 2))
            );
        }

        $lines[] = __('/allow, in a private chat with the bot, also gives a one-time pass past the sign-up gate, and makes someone an organizer (or stops them being one). The People page in wp-admin can do the same.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.

        return $lines;
    }

    /** @return array<int, string> */
    private static function messages(): array
    {
        return [
            '## 🔔 ' . __('Automatic messages', 'eventcrew'),
            implode("\n", [
                '- 🔹 ' . __('Sign-up and cancellation confirmations, at once.', 'eventcrew'),
                sprintf(
                    /* translators: %d: hours before the task */
                    '- 🔹 ' . __('A reminder %d hours before each task.', 'eventcrew'),
                    ReminderCall::REMINDER_HOURS
                ),
                '- 🔹 ' . __('A call to all members when tasks still need people.', 'eventcrew'),
                '- 🔹 ' . __('Notices about standing, credits, tickets and permissions.', 'eventcrew'),
                '- 🔹 ' . __('The sales desk link on the night.', 'eventcrew'),
                '- 🔹 ' . __('A welcome message to each new member when they confirm their email, and this guide to each organizer.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            ]),
            sprintf(
                /* translators: 1: opening time, e.g. 09:00, 2: closing time, e.g. 21:00 */
                __('The scheduled ones go out only between %1$s and %2$s. Each goes by Telegram and by email. Members choose which ones they get with /notifications; confirmations and reminders always go.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                self::windowStart(),
                self::windowEnd()
            ),
        ];
    }

    /** @return array<int, string> */
    private static function admin(): array
    {
        return [
            '## ⚙️ ' . __('The wp-admin pages', 'eventcrew'),
            implode("\n", [
                '- 🔹 ' . __('Tasks: create, change and remove tasks.', 'eventcrew'),
                '- 🔹 ' . __('People: members, their history and credits, the credit log, organizers, and the Leadership tab.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                '- 🔹 ' . __('Roster: attendance and the door list for any date, and the Holvi ticket buyers.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                '- 🔹 ' . __('Settings: roles, reputation, notifications and their times, the Telegram bot, email, privacy, and the welcome message.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                '- 🔹 ' . __('Email template: how every email looks.', 'eventcrew'),
                '- 🔹 ' . __('Diagnostics: a check of the database, bot, scheduled sends, sign-up page and mail.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            ]),
            self::wpAdminLine(),
        ];
    }

    private static function windowStart(): string
    {
        return sprintf('%02d:00', SendWindow::hour());
    }

    private static function windowEnd(): string
    {
        return sprintf('%02d:00', (SendWindow::hour() + 12) % 24);
    }
}
