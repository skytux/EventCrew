<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\MenuButtons;
use EventCrew\Telegram\TelegramClient;

/**
 * The welcome message: how tasks, tickets, credits and standing work, and
 * which tasks are open now. One email and one Telegram DM per member, each
 * sent once.
 *
 * Every number in it is read from the live settings when it is sent, so the
 * text cannot disagree with what the plugin actually does.
 *
 * Two ways out:
 * - A new member gets it the moment their email address is confirmed
 *   (Telegram /start, the web sign-in link, the sales desk's new member). The
 *   person just acted, so this is not held for the send window.
 * - Everyone else - the members who joined before this existed, and anyone
 *   who links Telegram later - gets it from the hourly heartbeat, inside the
 *   send window, at most a batch per run.
 *
 * Organizers also get the organizer guide (OrganizerGuide): the whole guide
 * by email, and its "In short" part and its AI section in short by DM
 * (OrganizerGuide::dm()). Someone made an organizer later
 * gets it from the next heartbeat.
 *
 * Off until an organizer switches it on in Settings, so the text can be
 * checked with a test send first. Each of the four messages is recorded in
 * the ledger on its own: someone who links Telegram after the email went
 * still gets the DM, and nobody gets any of them twice.
 */
final class WelcomeSender
{
    public const ENABLED_OPTION = 'eventcrew_welcome_enabled';

    public const KIND_EMAIL = 'welcome_email';
    public const KIND_DM = 'welcome_dm';
    public const KIND_ORG_EMAIL = 'welcome_org_email';
    public const KIND_ORG_DM = 'welcome_org_dm';

    private const KINDS = [self::KIND_EMAIL, self::KIND_DM, self::KIND_ORG_EMAIL, self::KIND_ORG_DM];

    /**
     * The ledger is keyed by date, and this message belongs to no date. Every
     * welcome row is filed under this one fixed date instead.
     */
    public const LEDGER_DATE = '2000-01-01';

    /** The most open tasks the message lists, over at most MAX_DATES dates (Owner 2026-11-07). */
    private const MAX_TASKS = 8;

    private const MAX_DATES = 3;

    /** The crew page's Board tab, where the email's See open tasks goes. */
    public const BOARD_ANCHOR = 'eventcrew-panel-board';

    /**
     * Who to contact when the bot cannot be reached, free text (Settings >
     * Welcome message); '' = maintainerDefault() (Owner 2026-11-07, Q367).
     */
    public const MAINTAINER_OPTION = 'eventcrew_maintainer_contact';

    /** @var array<int, array{task: Task, free: int}>|null Open tasks, memoised for one run. */
    private ?array $open = null;

    /** @var array<string, array<int, true>>|null Who has had each kind, loaded once per heartbeat run. */
    private ?array $sent = null;

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly PersonRepository $people,
        private readonly NotificationsRepository $ledger,
        private readonly Mailer $mailer,
        private readonly TelegramClient $telegram
    ) {
    }

    public function boot(): void
    {
        add_action('eventcrew/email_verified', [$this, 'onEmailVerified']);
    }

    public static function enabled(): bool
    {
        return (bool) get_option(self::ENABLED_OPTION, false);
    }

    /**
     * A confirmed address: welcome the person now, if they have not had it.
     * Fires on every web sign-in too (each one re-confirms the address); the
     * ledger turns those into no-ops.
     */
    public function onEmailVerified(int $personId): void
    {
        if (! self::enabled()) {
            return;
        }

        $person = $this->people->find($personId);

        if (null !== $person) {
            $this->deliver($person);
        }
    }

    /**
     * The heartbeat's share: everyone verified who is still missing the email
     * or the DM, up to $limit people this run. Returns how many were reached.
     */
    public function sendDue(int $limit): int
    {
        if (! self::enabled()) {
            return 0;
        }

        $this->sent = [];

        foreach (self::KINDS as $kind) {
            $this->sent[$kind] = $this->ledger->personIdsSent($kind, self::LEDGER_DATE);
        }

        $reached = 0;

        foreach ($this->people->activeEmailRecipients() as $person) {
            if ($reached >= $limit) {
                break;
            }

            if ($this->deliver($person)) {
                ++$reached;
            }
        }

        $this->sent = null;

        return $reached;
    }

    /**
     * Sends whichever of the two this person has not had yet, and records each
     * one that went. Returns whether anything was sent.
     */
    public function deliver(Person $person): bool
    {
        if ($person->isAnonymized() || ! $person->isEmailVerified()) {
            return false;
        }

        $hasChat = null !== $person->telegramChatId;
        $due = [
            self::KIND_EMAIL => true,
            self::KIND_DM => $hasChat,
            self::KIND_ORG_EMAIL => $person->isOrganizer,
            self::KIND_ORG_DM => $person->isOrganizer && $hasChat,
        ];
        $any = false;

        foreach ($due as $kind => $applies) {
            if (! $applies || $this->hasHad($kind, $person->id) || ! $this->send($kind, $person)) {
                continue;
            }

            $this->ledger->recordSent($kind, $person->id, self::LEDGER_DATE);
            $any = true;
        }

        return $any;
    }

    private function hasHad(string $kind, int $personId): bool
    {
        return null === $this->sent
            ? $this->ledger->hasSent($kind, $personId, self::LEDGER_DATE)
            : isset($this->sent[$kind][$personId]);
    }

    private function send(string $kind, Person $person): bool
    {
        return Language::forPerson($person, fn (): bool => match ($kind) {
            self::KIND_EMAIL => $this->sendEmail($person),
            self::KIND_DM => $this->sendDm($person),
            self::KIND_ORG_EMAIL => $this->sendOrganizerEmail($person),
            default => $this->sendOrganizerDm($person),
        });
    }

    /**
     * The Settings test: every message this person would get, whether or not
     * the feature is on, and recorded nowhere, so the real send still reaches
     * them.
     *
     * @return array<string, bool|null> Keyed by kind; null when the message
     *     does not apply to them (no DM chat, or not an organizer).
     */
    public function sendTest(Person $person): array
    {
        return Language::forPerson($person, fn (): array => $this->sendTestInLanguage($person));
    }

    /**
     * @return array<string, bool|null>
     */
    private function sendTestInLanguage(Person $person): array
    {
        $hasChat = null !== $person->telegramChatId;

        return [
            self::KIND_EMAIL => $this->sendEmail($person),
            self::KIND_DM => $hasChat ? $this->sendDm($person) : null,
            self::KIND_ORG_EMAIL => $person->isOrganizer ? $this->sendOrganizerEmail($person) : null,
            self::KIND_ORG_DM => $person->isOrganizer && $hasChat ? $this->sendOrganizerDm($person) : null,
        ];
    }

    public static function subject(): string
    {
        return __('Welcome to the crew: how tasks, tickets and credits work', 'eventcrew');
    }

    /**
     * Same channel (Owner 2026-11-07): web buttons, then the bot's board as an
     * "Open in Telegram" link. At most three buttons and three links, and no link repeats a button.
     */
    private function sendEmail(Person $person): bool
    {
        $board = $this->webBoardAction();
        $buttons = array_values(array_filter([
            $board,
            self::groupAction(),
            self::helpAction(HelpPage::PANEL_ANCHOR),
        ]));

        // No wp-admin here: organizers get it in their own welcome email
        // (Owner 2026-11-07).
        $links = [];
        $bot = $this->botBoardUrl();

        if ('' !== $bot) {
            $links[] = ['label' => __('See open tasks', 'eventcrew'), 'url' => $bot, 'style' => EmailBody::STYLE_TELEGRAM];
        }

        $guide = Roles::guideUrl();

        if ('' !== $guide && ! in_array($guide, array_column($buttons, 'url'), true)) {
            $links[] = ['label' => RoleGuide::label(), 'url' => $guide, 'style' => EmailBody::STYLE_LINK];
        }

        // The texts are the Messages page templates (MessageTemplates).
        $context = [
            'person' => $person,
            'open_tasks' => $this->openTasksText(null !== $board || '' !== $bot),
        ];

        return $this->mailer->toPerson(
            $person->id,
            $person->email,
            MessageTemplates::subject(self::KIND_EMAIL, $context),
            self::tidy(MessageTemplates::body(self::KIND_EMAIL, $context)),
            [...$buttons, ...$links]
        );
    }

    /**
     * Telegram to Telegram (Owner 2026-11-07): the board and the task
     * descriptions open in the bot, then the group; the web pages are links
     * in the text, with no visible address.
     */
    private function sendDm(Person $person): bool
    {
        $board = '📋 ' . __('See open tasks', 'eventcrew');
        $day = [] === $this->openTasks() ? '' : $this->botBoardUrl();
        $keyboard = [
            // My info on top; no task-descriptions button (Owner 2026-11-07).
            [['text' => __('👤 My info', 'eventcrew'), 'callback_data' => MenuButtons::ME]],
            // board-<Ymd> opens the next date with open tasks as a new
            // message; with no such date, the bot's own board button.
            [
                '' !== $day
                    ? ['text' => $board, 'url' => $day]
                    : ['text' => $board, 'callback_data' => MenuButtons::BOARD],
            ],
        ];
        $group = BoardService::groupLink();

        if ('' !== $group) {
            $keyboard[] = [['text' => '💬 ' . __('Open the group', 'eventcrew'), 'url' => $group]];
        }

        $links = [];
        $guide = Roles::guideUrl();

        if ('' !== $guide) {
            $links[] = ['label' => RoleGuide::label(), 'url' => $guide];
        }

        $help = HelpPage::url(HelpPage::PANEL_ANCHOR);

        if ('' !== $help) {
            $links[] = ['label' => self::helpLabel(), 'url' => $help];
        }

        $context = [
            'person' => $person,
            'open_tasks' => $this->openTasksText(true, true),
        ];
        $text = EmailBody::forDm(DmBody::frame($person, self::tidy(MessageTemplates::body(self::KIND_DM, $context)), true), true);

        return $this->sendHtmlDm($person, $text, $keyboard, $links);
    }

    /**
     * A welcome DM as Telegram HTML, so its links show only their label:
     * $html (already Telegram HTML, EmailBody::forDm(..., true)), then $links
     * on one line under it.
     *
     * @param list<list<array<string, string>>> $keyboard
     * @param list<array{label: string, url: string}> $links
     */
    private function sendHtmlDm(Person $person, string $html, array $keyboard, array $links): bool
    {
        $anchors = array_map(
            static fn (array $link): string => sprintf(
                '<a href="%s">%s</a>',
                htmlspecialchars($link['url'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($link['label'], ENT_NOQUOTES, 'UTF-8')
            ),
            $links
        );

        if ([] !== $anchors) {
            $html .= "\n\n" . implode(' · ', $anchors);
        }

        return null !== $this->telegram->sendMessage(
            (int) $person->telegramChatId,
            $html,
            [] === $keyboard ? null : ['inline_keyboard' => $keyboard],
            ['parse_mode' => 'HTML', 'link_preview_options' => ['is_disabled' => true]]
        );
    }

    /** The crew page's Board tab, as the email's first button, or null when no crew page is set. */
    private function webBoardAction(): ?array
    {
        $url = $this->mailer->boardUrl();

        return '' === $url ? null : ['label' => '📋 ' . __('See open tasks', 'eventcrew'), 'url' => $url . '#' . self::BOARD_ANCHOR];
    }

    /**
     * The bot's board on the next date with open tasks (board-<Ymd>,
     * BoardService::openDay()), else the bot's board; '' when the bot is not
     * set up.
     */
    private function botBoardUrl(): string
    {
        $open = $this->openTasks();
        $day = [] === $open ? '' : MessageActions::startUrl(BoardService::START_VERB, str_replace('-', '', $open[0]['task']->taskDate));

        return '' !== $day ? $day : MessageActions::startUrl(BoardService::START_VERB);
    }

    /**
     * The Help button: the Help tab of the crew page at $anchor, else the
     * crew guide; null when neither is set.
     *
     * @return array{label: string, url: string}|null
     */
    private static function helpAction(string $anchor): ?array
    {
        $url = HelpPage::url($anchor);
        $url = '' !== $url ? $url : Roles::guideUrl();

        return '' === $url ? null : ['label' => self::helpLabel(), 'url' => $url];
    }

    private static function helpLabel(): string
    {
        return '📖 ' . __('Help', 'eventcrew');
    }

    /**
     * The organizer guide by email, with the same rules as the welcome (Owner
     * 2026-11-07, Q368): Admin tab, Help and the group, plus wp-admin, as
     * buttons; People and Sales desk as "Open in Telegram" links.
     */
    private function sendOrganizerEmail(Person $person): bool
    {
        $actions = array_values(array_filter([
            self::adminTabAction(),
            self::helpAction(HelpPage::ORGANIZER_ANCHOR),
            self::groupAction(),
        ]));
        $actions[] = self::wpAdminAction();

        foreach (self::organizerBotLinks() as $verb => $label) {
            $url = MessageActions::startUrl($verb);

            if ('' !== $url) {
                $actions[] = ['label' => $label, 'url' => $url, 'style' => EmailBody::STYLE_TELEGRAM];
            }
        }

        $context = [
            'person' => $person,
            'guide' => OrganizerGuide::full(false),
        ];

        return $this->mailer->toPerson(
            $person->id,
            $person->email,
            MessageTemplates::subject(self::KIND_ORG_EMAIL, $context),
            MessageTemplates::body(self::KIND_ORG_EMAIL, $context),
            $actions
        );
    }

    /**
     * The bot screens the organizer messages offer: verb => label.
     *
     * @return array<string, string>
     */
    private static function organizerBotLinks(): array
    {
        return [
            'people' => '👥 ' . __('People', 'eventcrew'),
            'sales' => '💳 ' . __('Sales desk', 'eventcrew'),
        ];
    }

    /**
     * The Admin tab of the crew page, or null when no crew page is set.
     *
     * @return array{label: string, url: string}|null
     */
    private static function adminTabAction(): ?array
    {
        $url = HelpPage::url(DoorLinkSender::ADMIN_ANCHOR);

        return '' === $url ? null : ['label' => DoorLinkSender::adminButtonLabel(), 'url' => $url];
    }

    /**
     * The button to EventCrew in wp-admin.
     *
     * @return array{label: string, url: string}
     */
    private static function wpAdminAction(): array
    {
        return ['label' => '🧰 ' . __('Open EventCrew in wp-admin', 'eventcrew'), 'url' => OrganizerGuide::wpAdminUrl()];
    }

    /**
     * The button to the crew's Telegram group, or null when no group link is
     * known.
     *
     * @return array{label: string, url: string}|null
     */
    private static function groupAction(): ?array
    {
        $url = BoardService::groupLink();

        return '' === $url ? null : ['label' => '💬 ' . __('Open our group in Telegram', 'eventcrew'), 'url' => $url];
    }

    /**
     * The short organizer guide by DM (Owner 2026-11-07, Q368): the bot's
     * Admin menu on top, the Admin tab, wp-admin, and Help at the bottom (no
     * People button, Owner 2026-11-07); the bot's Sales desk as a link.
     */
    private function sendOrganizerDm(Person $person): bool
    {
        $keyboard = [[['text' => '🛠 ' . __('Admin', 'eventcrew'), 'callback_data' => MenuButtons::ADMIN]]];
        $admin = self::adminTabAction();

        if (null !== $admin) {
            $keyboard[] = [['text' => $admin['label'], 'url' => $admin['url']]];
        }

        $wpAdmin = self::wpAdminAction();
        $keyboard[] = [['text' => $wpAdmin['label'], 'url' => $wpAdmin['url']]];
        $help = self::helpAction(HelpPage::ORGANIZER_ANCHOR);

        if (null !== $help) {
            $keyboard[] = [['text' => $help['label'], 'url' => $help['url']]];
        }

        $links = [];
        $sales = MessageActions::startUrl('sales');

        if ('' !== $sales) {
            $links[] = ['label' => self::organizerBotLinks()['sales'], 'url' => $sales];
        }

        $context = [
            'person' => $person,
            'guide' => OrganizerGuide::dm(),
        ];

        return $this->sendHtmlDm(
            $person,
            EmailBody::forDm(DmBody::frame($person, MessageTemplates::body(self::KIND_ORG_DM, $context)), true),
            $keyboard,
            $links
        );
    }

    /**
     * The first line of the organizer guide email and DM, with {crew} for the
     * crew's name. Part of their built-in texts in MessageTemplates.
     */
    public static function organizerIntroTemplate(): string
    {
        return MessageTemplates::tag(
            /* translators: %s: the crew's name (the site name) */
            __('You are an organizer of %s. This guide explains how EventCrew works, first in short and then in detail.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            ['%s' => '{crew}']
        );
    }

    /**
     * The message itself, after the greeting, with {placeholders}: the
     * built-in text of the welcome_email and welcome_dm templates in
     * MessageTemplates. Plain text with "## " headings; EmailBody draws those
     * as headings and strips them for the DM. It opens with why the crew
     * matters, and names no bot command (Owner 2026-11-07).
     */
    public static function bodyTemplate(): string
    {
        return implode("\n\n", [
            // A spacious, starry opening, one thought per line (Owner 2026-11-07).
            '✨ ' . MessageTemplates::tag(
                /* translators: %s: the crew's name (the site name) */
                __('Welcome to the %s crew!', 'eventcrew'),
                ['%s' => '{crew}']
            ) . ' ✨',
            __('Our nights happen because people like you lend a hand.', 'eventcrew'),
            '🌟 ' . __('Every task you take keeps the door open, the room ready and the music going.', 'eventcrew'),
            '🎟️ ' . __('And every task earns you free entry.', 'eventcrew'),
            MessageTemplates::tag(
                /* translators: %s: where to see your own numbers */
                __('This message explains how it all works. %s', 'eventcrew'),
                ['%s' => '{me_help}']
            ),
            ...self::sectionsTemplate(),
            '## 📋 ' . __('Open tasks', 'eventcrew'),
            '{open_tasks}',
            ...self::botTalkTemplate(),
        ]);
    }

    /**
     * The "Talking to the bot" section (Owner 2026-11-07): plain words work in
     * the private chat, the bot passes problems on to the organizers
     * (ChatForwarder), and whom to contact when the bot cannot be reached.
     *
     * @return array<int, string>
     */
    private static function botTalkTemplate(): array
    {
        return [
            '## 💬 ' . __('Talking to the bot', 'eventcrew'),
            __('In a private chat with the bot, it is usually enough to write what you want in your own words. There are no commands or keywords to remember.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __('Any difficulty, feedback or a bug? Just tell the bot: it passes it on to the organizers.', 'eventcrew'),
            MessageTemplates::tag(
                /* translators: %s: how to reach the maintainer, e.g. an email address and a Telegram name */
                __('If you cannot reach the bot, contact the maintainer, %s, or just try again a bit later.', 'eventcrew'),
                ['%s' => '{maintainer}']
            ),
        ];
    }

    /** The maintainer contact from Settings, or the default when none is set. */
    public static function maintainerContact(): string
    {
        $stored = trim((string) get_option(self::MAINTAINER_OPTION, ''));

        return '' !== $stored ? $stored : self::maintainerDefault();
    }

    public static function maintainerDefault(): string
    {
        return sprintf(
            /* translators: 1: an email address, 2: a Telegram user name */
            __('%1$s or %2$s', 'eventcrew'),
            'maintainer@example.org',
            '@maintainer'
        );
    }

    /**
     * The {me_help} of the welcome message for a person with a Telegram chat:
     * where their own numbers, tickets and credits are, in one line, so the
     * {credit_help} and {tickets_help} are empty for them. A person without
     * one gets the crew page (MessageTemplates, CrewPageHelp). In plain words,
     * no command (Owner 2026-11-07).
     */
    public static function meBotLine(): string
    {
        return __('To see your numbers, tickets or credits, just ask the bot in a private chat.', 'eventcrew');
    }

    /**
     * $text without the gaps an empty placeholder leaves: a double space, a
     * space before a line break or at the end.
     */
    private static function tidy(string $text): string
    {
        return (string) preg_replace(['/ {2,}/', '/ +(?=\n|$)/'], [' ', ''], $text);
    }

    /**
     * The {credit_help} of the welcome message and the web Help tab for a
     * person with a Telegram chat: how to spend a credit. A person without one
     * gets the crew page in the welcome email (MessageTemplates, CrewPageHelp).
     */
    public static function creditBotLine(): string
    {
        return __('To spend a credit, ask the bot for a free-entry ticket, or use the web page.', 'eventcrew');
    }

    /**
     * The {tickets_help} of the welcome message for a
     * person with a Telegram chat: where their tickets are. A person without
     * one gets the crew page in the welcome email (MessageTemplates,
     * CrewPageHelp), and the web Help tab names the Me tab (helpText()).
     */
    public static function ticketsBotLine(): string
    {
        return __(
            'It is the first button in your sign-up confirmation, and the bot lists all your tickets when you ask.',
            'eventcrew'
        );
    }

    /**
     * The member part of the web Help tab: the welcome message without the
     * open tasks, which the Board tab already shows.
     */
    public static function helpText(): string
    {
        return implode("\n\n", [
            sprintf(
                /* translators: %s: the crew's name (the site name) */
                __('How the %s crew works. Your own numbers are in the Me tab, and the bot shows them when you ask in a private chat.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                Organization::name()
            ),
            ...self::sections([
                // On the crew page itself the tickets are in the Me tab, for
                // everyone.
                'tickets_help' => __(
                    'It is the first button in your sign-up confirmation, and the Me tab lists all your tickets.',
                    'eventcrew'
                ),
            ]),
        ]);
    }

    /**
     * The sections both texts share: tasks, tickets, credits, standing.
     * $values replaces some of the sectionValues().
     *
     * @param array<string, string> $values
     * @return array<int, string>
     */
    private static function sections(array $values = []): array
    {
        $pairs = [];

        foreach ($values + self::sectionValues() as $name => $value) {
            $pairs['{' . $name . '}'] = $value;
        }

        return array_map(static fn (string $text): string => strtr($text, $pairs), self::sectionsTemplate());
    }

    /**
     * The sections with {placeholders} for the numbers and lists that come
     * from the live settings (sectionValues()).
     *
     * @return array<int, string>
     */
    private static function sectionsTemplate(): array
    {
        return [
            '## 🧩 ' . __('The tasks', 'eventcrew'),
            '{roles}',
            '## 🎟️ ' . __('Tickets', 'eventcrew'),
            MessageTemplates::tag(
                /* translators: %s: where all your tickets are listed */
                __(
                    'When you sign up for a task, you get a free ticket for that night. Show it at the door. %s',
                    'eventcrew'
                ),
                ['%s' => '{tickets_help}']
            ),
            '## 🎁 ' . __('Credits', 'eventcrew'),
            MessageTemplates::tag(
                /* translators: 1: completed tasks needed for one credit, 2: how to spend a credit */
                __('You earn 1 free-entry credit for every %1$d tasks you complete. %2$s You then get a ticket for the night you choose. Some nights (sold out, or special events) do not accept credits.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                ['%1$d' => '{tasks_per_credit}', '%2$s' => '{credit_help}']
            ),
            '## 📈 ' . __('Standing', 'eventcrew'),
            __('Your standing comes from how your past tasks went:', 'eventcrew'),
            '{outcomes}',
            MessageTemplates::tag(
                /* translators: 1: hours of notice, 2: tasks before a rating, 3: good-standing percentage */
                __('Cancelling %1$d hours or more before has no effect. Recent tasks count more than old ones. You get a rating after %2$d finished tasks. %3$d%% or higher is good standing.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                ['%1$d' => '{notice_hours}', '%2$d' => '{rated_tasks}', '%3$d' => '{good_standing}']
            ) . '{gate_rule}',
        ];
    }

    /**
     * What each placeholder of the sections becomes, from the live settings:
     * name (without the curly brackets) => text.
     *
     * @return array<string, string>
     */
    public static function sectionValues(): array
    {
        $settings = new ReputationSettings();
        $noticeHours = max(0, (int) get_option(SignupService::NOTICE_HOURS_OPTION, 48));
        $threshold = (int) round($settings->threshold() * 100);

        return [
            'credit_help' => self::creditBotLine(),
            'tickets_help' => self::ticketsBotLine(),
            'roles' => implode("\n", self::roleLines()),
            'tasks_per_credit' => (string) $settings->tasksPerCredit(),
            'outcomes' => implode("\n", self::weightLines($settings, $noticeHours)),
            'notice_hours' => (string) $noticeHours,
            'rated_tasks' => (string) $settings->minRatedTasks(),
            'good_standing' => (string) $threshold,
            'gate_rule' => self::gateRule($threshold),
        ];
    }

    /**
     * One "- " line per role on offer.
     * The role's own emoji is the line's bullet; a role without one gets 🔹.
     *
     * @return array<int, string>
     */
    private static function roleLines(): array
    {
        return RoleGuide::lines();
    }

    /**
     * The outcome weights, best to worst, from the live settings. The late
     * cancellation line names the notice period, which "Cancelled late" alone
     * does not say.
     *
     * @return array<int, string>
     */
    private static function weightLines(ReputationSettings $settings, int $noticeHours): array
    {
        $percents = $settings->weightPercents();
        $lines = [];

        foreach (Reputation::outcomeLabels() as $status => $label) {
            if (AssignmentStatus::LATE_CANCEL === $status) {
                $label = sprintf(
                    /* translators: %d: hours of notice, e.g. 48 */
                    __('Cancelled less than %d hours before', 'eventcrew'),
                    $noticeHours
                );
            }

            $lines[] = sprintf('- 🔹 %s: %d%%', $label, $percents[$status] ?? 0);
        }

        return $lines;
    }

    /**
     * The sentence that someone below good standing cannot sign up, with a
     * space before it. Empty when the standing gate is off: then nothing stops
     * anyone signing up.
     */
    private static function gateRule(int $threshold): string
    {
        if (! (bool) get_option(SignupService::GATE_OPTION, true)) {
            return '';
        }

        return ' ' . sprintf(
            /* translators: %d: good-standing percentage */
            __('Below %d%%, you cannot sign up for new tasks until you talk to an organizer.', 'eventcrew'),
            $threshold
        );
    }

    /**
     * The open tasks grouped by date (Owner 2026-11-07): the day, then a line
     * per task. Signing up is the one See open tasks button, not a button
     * per task. Without that button (no crew page and no bot), the line
     * pointing at it is left out. $dm: the lines without their "- ", so
     * EmailBody::forDm() puts no empty line between the tasks of one date
     * (Owner 2026-11-07).
     */
    private function openTasksText(bool $button = true, bool $dm = false): string
    {
        $byDate = [];

        foreach ($this->openTasks() as $row) {
            $byDate[$row['task']->taskDate][] = $row;
        }

        if ([] === $byDate) {
            return __('No open tasks right now. New ones are posted on the board and in the Telegram group.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
        }

        $blocks = [];

        foreach ($byDate as $date => $rows) {
            $lines = [];

            foreach ($rows as $row) {
                $task = $row['task'];
                $time = $task->timeRange();
                $line = '- ' . $task->roleDisplay() . ('' === $time ? '' : ' ' . $time) . ': ' . sprintf(
                    /* translators: 1: free places, 2: places in total */
                    __('%1$d of %2$d places free', 'eventcrew'),
                    $row['free'],
                    $task->capacity
                );
                // The role's emoji is the bullet; a role without one gets 🔹.
                $line = EmailBody::isEmojiLine($line) ? $line : '- 🔹 ' . substr($line, 2);
                $lines[] = $dm ? substr($line, 2) : $line;
            }

            $blocks[] = '📅 ' . Dates::dayLabel((string) $date) . "\n\n" . implode("\n", $lines);
        }

        $text = implode("\n\n", $blocks);

        return $button ? $text . "\n\n" . __('To sign up, tap 📋 See open tasks below.', 'eventcrew') : $text;
    }

    /**
     * The next open tasks, soonest first, with how many places each has left.
     *
     * @return array<int, array{task: Task, free: int}>
     */
    private function openTasks(): array
    {
        if (null !== $this->open) {
            return $this->open;
        }

        $tasks = $this->tasks->upcoming();
        $occupancy = $this->tasks->occupancyFor(array_map(static fn (Task $task): int => $task->id, $tasks));
        $open = [];
        $dates = [];

        foreach ($tasks as $task) {
            // Never offered to a newcomer: restricted to a role, and no places to count.
            if ($task->isRestricted()) {
                continue;
            }

            $free = $task->capacity - ($occupancy[$task->id] ?? 0);

            if ($free <= 0) {
                continue;
            }

            // Upcoming is soonest first: a new date past the last one allowed ends the list.
            $dates[$task->taskDate] = true;

            if (count($dates) > self::MAX_DATES) {
                break;
            }

            $open[] = ['task' => $task, 'free' => $free];

            if (count($open) >= self::MAX_TASKS) {
                break;
            }
        }

        return $this->open = $open;
    }
}
