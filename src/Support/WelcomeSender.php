<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
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
 * by email, and its "In short" part by DM. Someone made an organizer later
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

    /** The most open tasks the message lists, and offers DM buttons for. */
    private const MAX_TASKS = 8;

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
        return match ($kind) {
            self::KIND_EMAIL => $this->sendEmail($person),
            self::KIND_DM => $this->sendDm($person),
            self::KIND_ORG_EMAIL => $this->sendOrganizerEmail($person),
            default => $this->sendOrganizerDm($person),
        };
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

    private function sendEmail(Person $person): bool
    {
        $actions = [];
        $board = $this->mailer->boardUrl();

        if ('' !== $board) {
            $actions[] = ['label' => __('Sign up for a task', 'eventcrew'), 'url' => $board];
            $actions[] = ['label' => RoleGuide::descriptionsLabel(), 'url' => RoleGuide::descriptionsUrl()];
        }

        $guide = RoleGuide::emailAction();

        if (null !== $guide) {
            $actions[] = $guide;
        }

        return $this->mailer->toPerson(
            $person->id,
            $person->email,
            self::subject(),
            sprintf(
                /* translators: %s: the person's first name */
                __('Hi %s,', 'eventcrew'),
                $person->name()
            ) . "\n\n" . $this->body(),
            $actions
        );
    }

    private function sendDm(Person $person): bool
    {
        $keyboard = [];

        foreach ($this->openTasks() as $row) {
            $keyboard[] = [[
                'text' => sprintf(
                    /* translators: 1: the role with its emoji, 2: the day, e.g. "Sat 7 Nov" */
                    __('Sign up: %1$s · %2$s', 'eventcrew'),
                    $row['task']->roleDisplay(),
                    Dates::dayLabel($row['task']->taskDate)
                ),
                // 'j' joins only. The board's 't' would turn a second tap into
                // a cancel, which a button in a welcome message must never do.
                'callback_data' => 'j:' . $row['task']->id,
            ]];
        }

        $descriptions = RoleGuide::descriptionsUrl();

        if ('' !== $descriptions) {
            $keyboard[] = [['text' => RoleGuide::descriptionsLabel(), 'url' => $descriptions]];
        }

        $guide = RoleGuide::button();

        if (null !== $guide) {
            $keyboard[] = [$guide];
        }

        return null !== $this->telegram->sendMessage(
            (int) $person->telegramChatId,
            DmBody::frame($person, EmailBody::plainHeadings($this->body(true)), true),
            [] === $keyboard ? null : ['inline_keyboard' => $keyboard]
        );
    }

    private function sendOrganizerEmail(Person $person): bool
    {
        return $this->mailer->toPerson(
            $person->id,
            $person->email,
            OrganizerGuide::subject(),
            sprintf(
                /* translators: %s: the person's first name */
                __('Hi %s,', 'eventcrew'),
                $person->name()
            ) . "\n\n" . $this->organizerIntro() . "\n\n" . OrganizerGuide::short() . "\n\n" . OrganizerGuide::details()
        );
    }

    private function sendOrganizerDm(Person $person): bool
    {
        $text = $this->organizerIntro() . "\n\n" . OrganizerGuide::short() . "\n\n"
            . __('The full guide, with the details, is in your email.', 'eventcrew');

        return null !== $this->telegram->sendMessage(
            (int) $person->telegramChatId,
            DmBody::frame($person, EmailBody::plainHeadings($text))
        );
    }

    private function organizerIntro(): string
    {
        return sprintf(
            /* translators: %s: the crew's name (the site name) */
            __('You are an organizer of %s. This guide explains how EventCrew works, first in short and then in detail.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            trim((string) get_bloginfo('name'))
        );
    }

    /**
     * The message itself, after the greeting. Plain text with "## " headings;
     * EmailBody draws those as headings and strips them for the DM.
     */
    public function body(bool $forDm = false): string
    {
        $settings = new ReputationSettings();
        $site = trim((string) get_bloginfo('name'));
        $noticeHours = max(0, (int) get_option(SignupService::NOTICE_HOURS_OPTION, 48));
        $threshold = (int) round($settings->threshold() * 100);

        $parts = [
            sprintf(
                /* translators: %s: the crew's name (the site name) */
                __('Welcome to the %s crew. This message explains how it works. You can see your own numbers any time: send /me to the bot in a private chat.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                $site
            ),
            '## ' . __('The tasks', 'eventcrew'),
            implode("\n", $this->roleLines()),
            '## ' . __('Tickets', 'eventcrew'),
            __('When you sign up for a task, you get a free ticket for that night. Show it at the door. It is the first button in your sign-up confirmation, and /mytickets lists all your tickets.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            '## ' . __('Credits', 'eventcrew'),
            sprintf(
                /* translators: %d: completed tasks needed for one credit */
                __('You earn 1 free-entry credit for every %d tasks you complete. To spend a credit, send /ticket to the bot or use the web page. You then get a ticket for the night you choose. Some nights (sold out, or special events) do not accept credits.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                $settings->tasksPerCredit()
            ),
            '## ' . __('Standing', 'eventcrew'),
            __('Your standing comes from how your past tasks went:', 'eventcrew'),
            implode("\n", $this->weightLines($settings, $noticeHours)),
            $this->standingRules($settings, $noticeHours, $threshold),
            '## ' . __('Open tasks', 'eventcrew'),
            $this->openTasksText($forDm),
        ];

        return implode("\n\n", $parts);
    }

    /**
     * One "- " line per role on offer, then the Leader when events carry one.
     *
     * @return array<int, string>
     */
    private function roleLines(): array
    {
        $lines = [];

        foreach (RoleGuide::descriptions() as $role) {
            $lines[] = '' === $role['description']
                ? '- ' . $role['name']
                : '- ' . $role['name'] . ': ' . $role['description'];
        }

        return $lines;
    }

    /**
     * The outcome weights, best to worst, from the live settings. The late
     * cancellation line names the notice period, which "Cancelled late" alone
     * does not say.
     *
     * @return array<int, string>
     */
    private function weightLines(ReputationSettings $settings, int $noticeHours): array
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

            $lines[] = sprintf('- %s: %d%%', $label, $percents[$status] ?? 0);
        }

        return $lines;
    }

    private function standingRules(ReputationSettings $settings, int $noticeHours, int $threshold): string
    {
        $text = sprintf(
            /* translators: 1: hours of notice, 2: tasks before a rating, 3: good-standing percentage */
            __('Cancelling %1$d hours or more before has no effect. Recent tasks count more than old ones. You get a rating after %2$d finished tasks. %3$d%% or higher is good standing.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            $noticeHours,
            $settings->minRatedTasks(),
            $threshold
        );

        // Only when the gate is on: otherwise nothing stops anyone signing up.
        if ((bool) get_option(SignupService::GATE_OPTION, true)) {
            $text .= ' ' . sprintf(
                /* translators: %d: good-standing percentage */
                __('Below %d%%, you cannot sign up for new tasks until you talk to an organizer.', 'eventcrew'),
                $threshold
            );
        }

        return $text;
    }

    private function openTasksText(bool $forDm): string
    {
        $lines = [];

        foreach ($this->openTasks() as $row) {
            $task = $row['task'];
            $time = $task->timeRange();

            $lines[] = sprintf(
                /* translators: 1: day, 2: time range or '', 3: role with emoji, 4: free places, 5: places in total */
                __('- %1$s%2$s: %3$s (%4$d of %5$d places free)', 'eventcrew'),
                Dates::dayLabel($task->taskDate),
                '' === $time ? '' : ' ' . $time,
                $task->roleDisplay(),
                $row['free'],
                $task->capacity
            );
        }

        if ([] === $lines) {
            return __('No open tasks right now. New ones are posted on the board and in the Telegram group.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
        }

        return implode("\n", $lines) . "\n\n" . ($forDm
            ? __('Tap a button below to sign up.', 'eventcrew')
            : __('Sign up with the button below.', 'eventcrew'));
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

        foreach ($tasks as $task) {
            $free = $task->capacity - ($occupancy[$task->id] ?? 0);

            if ($free > 0) {
                $open[] = ['task' => $task, 'free' => $free];
            }

            if (count($open) >= self::MAX_TASKS) {
                break;
            }
        }

        return $this->open = $open;
    }
}
