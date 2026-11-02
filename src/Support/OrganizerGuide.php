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
            '- ✋ ' . __('Sign-up: members tap a task on the board in the Telegram group, or use the web sign-up page. /board posts a new board in the group.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            '- 🌙 ' . __('On the night: /roster marks who arrived, who completed their task and who did not come. The sales desk link sells tickets and checks people in at the door; /door, then 💳 Sales desk, sends you the link.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            ...self::mailImportShort(),
            sprintf(
                /* translators: %d: completed tasks needed for one credit */
                '- 🎁 ' . __('Credits: members earn 1 free-entry credit per %d completed tasks. /people, then 🎁 Give credits, gives credits by hand.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                $settings->tasksPerCredit()
            ),
            sprintf(
                /* translators: %d: good-standing percentage */
                '- 📈 ' . __('Standing: %d%% or higher is good standing. It comes from how each member\'s past tasks went.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                (int) round($settings->threshold() * 100)
            ),
            '- 🔑 ' . __('Roles: /people, then 🏷 Roles, organizers and passes, makes someone an organizer, gives a one-time pass past the sign-up gate, and gives or takes away roles such as DJ.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            '- 🎧 ' . __('Role tasks (DJ, VJ, Organize, ...) are not on the group board. People with the role see them with /board in a private chat and in the Crew app. You can add someone there, even a person who is not a crew member.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
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
            ...self::permissions(),
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
            __('The board in the Telegram group lists the open tasks under each event and date. Each task is a button that shows its role, time and places taken; a member taps it to sign up. It updates itself whenever something changes. /board in the group posts a new copy at the bottom. Settings > Telegram bot > Refresh the board redraws it in place.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __('A new member sends /start to the bot in a private chat and confirms their email address. On the web sign-up page they sign in with a link sent to their email. Both follow the same rules.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __('If a language model is set up in Settings > Telegram bot, the bot also answers plain messages. In a private chat it answers questions about tasks, events and the site\'s pages, and can sign a member up for a task or take them off one; it asks them to confirm with a button first. In the group it answers only when the message is meant for the bot and it knows the answer.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            sprintf(
                /* translators: %d: hours of notice, e.g. 48 */
                __('Cancelling less than %d hours before the task counts as a late cancellation. A member who cannot come can ask someone else to take the task with 🔁 Take over a task under /me.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                $noticeHours
            ),
        ];
    }

    /** @return array<int, string> */
    private static function night(): array
    {
        return [
            '## 🌙 ' . __('On the night: roster and sales desk', 'eventcrew'),
            __('/roster in a private chat with the bot shows the night\'s crew. Mark each person arrived, arrived late, completed or no-show, one by one or a whole crew at once. EventCrew > Roster in wp-admin does the same for any date. The night\'s welcome crew can mark the crew too.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __('The sales desk is a web page for the door. It shows tonight\'s list: the crew, members with a free-entry ticket, guests and Holvi ticket buyers. You mark people in and out and say how many people they brought. Add sells a ticket at the door, adds a guest, or adds a member who spends a credit or takes one of tonight\'s tasks.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            sprintf(
                /* translators: %d: minutes before the night's first task */
                __('When you are signed up for the night\'s Organize task, you get the sales desk link %d minutes before the night\'s first task, with a button to the Admin tab. The welcome crew (people on tasks of a role marked "Opens the sales desk") get it once they are marked arrived. /door, then 💳 Sales desk, sends a new link any time. /door has buttons for the door list (who has a ticket) and the guest list.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                DoorLinkSender::leadMinutes()
            ),
            __('Holvi ticket buyers: in EventCrew > Roster, pick the date and paste the names, one per line, under "Holvi ticket buyers". A name already on the list is skipped.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __('After each import, every imported ticket with an email address is emailed to its buyer automatically: a thank-you message with the ticket\'s QR code, in Finnish and English. Roster and the Admin tab show how many were sent, failed or not sent yet. "Send a test to me" sends one to your own address; "Send the rest now" sends every ticket not sent yet, failed ones too.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            ...self::mailImportDetails(),
        ];
    }

    /**
     * The "In short" line about mailing a buyer list, or nothing when the site
     * has no import mailbox (Settings → Email).
     *
     * @return array<int, string>
     */
    private static function mailImportShort(): array
    {
        $mailbox = BuyerImportController::mailbox();

        if ('' === $mailbox) {
            return [];
        }

        return [sprintf(
            /* translators: %s: the import email address */
            '- 📧 ' . __('Holvi ticket buyers: email the ticket file to %s from your EventCrew email address. The tickets go on the sales desk list of the next event and are emailed to the buyers. Names typed in the mail go on its guest list.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            $mailbox
        )];
    }

    /**
     * The details about mailing a buyer list, or nothing when the site has no
     * import mailbox.
     *
     * @return array<int, string>
     */
    private static function mailImportDetails(): array
    {
        $mailbox = BuyerImportController::mailbox();

        if ('' === $mailbox) {
            return [];
        }

        return [sprintf(
            /* translators: %s: the import email address */
            __('Or email the Holvi ticket file (.xlsx or .csv) to %s. Send it from the email address you have in EventCrew; mail from any other address is not used. The tickets go to the next event, which must already have its tasks. Names typed in the message itself, one per line, go to that event’s guest list instead; write "+2" after a name for two plus-ones. Put an email address on the line too (Anna Virtanen <anna@example.com> +2) and the guest gets a ticket by email. Lines after a signature line (--) are ignored. A name already on the list is skipped. Within a few minutes you get an email saying what was added and what was skipped. If no email comes, add the names in EventCrew > Roster instead.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            $mailbox
        )];
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
                __('Members earn 1 free-entry credit per %d completed tasks and spend it with 🎟 Free-entry ticket under /me or on the web page. You give credits with /people, on the People page, or at the sales desk. Each gift is written in the credit log on the People page, and the member is told.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
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
                __('The sign-up gate is on: a member below %d%% cannot sign up for new tasks. /people can give them a one-time pass.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                $threshold
            );
        }

        return $lines;
    }

    /** @return array<int, string> */
    private static function permissions(): array
    {
        $lines = ['## 🔑 ' . __('Roles and permissions', 'eventcrew')];

        $lines[] = __('A role can have conditions in EventCrew > Settings: how many tasks of which roles a member has completed. Meeting them does not give the role. You are told, by Telegram and by email, the first time someone meets a role\'s conditions and the first time someone is given a role. /people, then ✅ Who may hold each role, lists who meets the conditions of each role.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
        $lines[] = __('As an organizer you can add anyone to any task of a night, with /board in a private chat, in the Crew app Admin tab, or at the sales desk.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.

        $lines[] = __('/people, in a private chat with the bot, also gives a one-time pass past the sign-up gate, makes someone an organizer (or stops them being one), and gives or takes away roles such as DJ or VJ. The People page in wp-admin and the Admin tab in the Crew app can do the same.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.

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
                __('The scheduled ones go out only between %1$s and %2$s. Each goes by Telegram and by email. Members choose which ones they get with 🔔 Notifications under /me; confirmations and reminders always go.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
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
                '- 🔹 ' . __('People: members, their history and credits, the credit log, organizers, and the Role conditions tab.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
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
