<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Telegram\TelegramClient;

/**
 * Sends the sales desk's (door page's) login link on an event night, without
 * anyone asking.
 *
 * Organizers signed up on the night's Organize task get theirs a set number of
 * minutes before the night's first task starts, with a second button to the
 * crew page's Admin tab, on the channels set in Settings. People on the
 * night's task of a sales-desk role get theirs under SalesDeskGate's rule: a
 * few minutes before their own task starts, once they are marked arrived, and
 * not after the event has ended. Someone marked arrived after
 * that moment gets it there and then, through the status-changed hook every
 * status write fires.
 *
 * Each person gets one link per night, recorded in the notifications ledger.
 * These sends ignore the send window and the notification preferences: the
 * link is a work tool for that evening, and a door crew member who had muted
 * their reminders still needs it. /door in Telegram (💳 Sales desk), and the button on the
 * member's web page, hand out another link whenever one is needed.
 *
 * The check runs at most every five minutes: from its own WP-Cron event, and
 * from an ordinary request when that event has not fired, which costs one
 * option read while nothing is due.
 */
final class DoorLinkSender
{
    /** The cron hook. Cleared on deactivation through Scheduler::unschedule(). */
    public const HOOK = 'eventcrew_door_links';

    /** The five-minute WP-Cron schedule this registers. */
    public const SCHEDULE = 'eventcrew_five_minutes';

    public const LAST_RUN_OPTION = 'eventcrew_door_links_last_run';

    public const LEAD_OPTION = 'eventcrew_door_link_lead_minutes';
    public const DEFAULT_LEAD_MINUTES = 30;

    /** Settings: the channels the organizer notice uses. */
    public const ORGANIZER_CHANNELS_OPTION = 'eventcrew_organizer_notice_channels';

    /** Every channel, and the default: both on. */
    public const ORGANIZER_CHANNELS = ['telegram', 'email'];

    /** The crew page's Admin tab. */
    public const ADMIN_ANCHOR = 'eventcrew-panel-admin';

    /** The ledger kind. One per person per night. */
    public const KIND = 'door_link';

    /** The start used for a night where no task has a start time. */
    private const FALLBACK_START = '17:00:00';

    private const INTERVAL_SECONDS = 5 * MINUTE_IN_SECONDS;

    /** The MySQL lock name that keeps two runs from sending at once. */
    private const LOCK = 'eventcrew_door_link';

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly PersonRepository $people,
        private readonly NotificationsRepository $ledger,
        private readonly AuthTokenRepository $tokens,
        private readonly SalesDeskGate $gate,
        private readonly TelegramClient $telegram,
        private readonly Mailer $mailer
    ) {
    }

    public function boot(): void
    {
        add_filter('cron_schedules', [$this, 'addSchedule']);
        add_action(self::HOOK, [$this, 'run']);
        add_action('init', [$this, 'runIfDue'], 99);
        add_action('eventcrew/assignment_status_changed', [$this, 'onStatusChanged'], 10, 2);

        if (false === wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time(), self::SCHEDULE, self::HOOK);
        }
    }

    /**
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public function addSchedule(array $schedules): array
    {
        // WordPress applies this filter whenever anything asks for the cron
        // schedules, including boot() itself on plugins_loaded. Translating
        // before init makes WordPress 6.7+ warn, so until then the label stays
        // in English. Only the interval matters for scheduling.
        $display = did_action('init')
            ? __('Every five minutes (EventCrew sales desk links)', 'eventcrew')
            : 'Every five minutes (EventCrew sales desk links)';

        $schedules[self::SCHEDULE] = [
            'interval' => self::INTERVAL_SECONDS,
            'display' => $display,
        ];

        return $schedules;
    }

    public static function leadMinutes(): int
    {
        return max(0, (int) get_option(self::LEAD_OPTION, self::DEFAULT_LEAD_MINUTES));
    }

    /**
     * Runs the check when the last run is five minutes old or more. Returns
     * whether it ran.
     */
    public function runIfDue(): bool
    {
        if ((time() - (int) get_option(self::LAST_RUN_OPTION, 0)) < self::INTERVAL_SECONDS) {
            return false;
        }

        $this->run();

        return true;
    }

    /**
     * Sends whatever is due tonight. The run is stamped first, so a second
     * request arriving while this one is still sending does not start another.
     */
    public function run(): void
    {
        global $wpdb;

        update_option(self::LAST_RUN_OPTION, time());

        // The stamp alone does not stop WP-Cron and a visitor request that
        // start in the same second: both would read the ledger before either
        // writes to it and send the link twice. The second run gives up here.
        if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::LOCK))) {
            return;
        }

        try {
            $this->runLocked();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::LOCK));
        }
    }

    private function runLocked(): void
    {
        $date = (string) current_time('Y-m-d');
        $tasks = $this->tasks->forDate($date);

        if ([] === $tasks) {
            return;
        }

        if ((string) current_time('mysql') >= $this->organizersFrom($date)) {
            foreach ($this->signedUpOrganizers($tasks) as $organizer) {
                $this->sendOrganizerOnce($organizer, $date);
            }
        }

        foreach ($tasks as $task) {
            if (! SalesDeskGate::isDoorRole($task->roleSlug)) {
                continue;
            }

            foreach ($this->assignments->forTask($task->id) as $assignment) {
                if (! SalesDeskGate::hasArrived($assignment->status)) {
                    continue;
                }

                $person = $this->people->find($assignment->personId);

                if (null !== $person && SalesDeskGate::OK === $this->gate->check($person, $date)) {
                    $this->sendOnce($person, $date);
                }
            }
        }
    }

    /**
     * The status-changed hook: someone on a sales-desk task marked arrived
     * after their link was due gets it now, instead of at the next check.
     */
    public function onStatusChanged(int $assignmentId, string $status): void
    {
        if (! SalesDeskGate::hasArrived($status)) {
            return;
        }

        $assignment = $this->assignments->find($assignmentId);
        $task = null === $assignment ? null : $this->tasks->find($assignment->taskId);

        if (null === $assignment || null === $task || ! SalesDeskGate::isDoorRole($task->roleSlug)) {
            return;
        }

        if ($task->taskDate < (string) current_time('Y-m-d')) {
            return;
        }

        $person = $this->people->find($assignment->personId);

        if (null !== $person && SalesDeskGate::OK === $this->gate->check($person, $task->taskDate)) {
            $this->sendOnce($person, $task->taskDate);
        }
    }

    /**
     * Sends one person a fresh link for $date by email and, when they have a
     * DM chat with the bot, by DM - with no ledger check and no access check;
     * the caller has decided. 💳 Sales desk uses this for a link asked for by hand,
     * DM only, to $dmChatId. Returns false when the link would already be dead
     * (the night has closed for this person), and then sends nothing.
     */
    public function send(Person $person, string $date, bool $byEmail = true, ?int $dmChatId = null): bool
    {
        return Language::forPerson(
            $person,
            fn (): bool => $this->sendInLanguage($person, $date, $byEmail, $dmChatId)
        );
    }

    private function sendInLanguage(Person $person, string $date, bool $byEmail, ?int $dmChatId): bool
    {
        $url = $this->loginUrl($person, $date);

        if ('' === $url) {
            return false;
        }

        // The texts are the Messages page templates (MessageTemplates).
        $context = ['person' => $person, 'day' => Dates::dayLabel($date), 'desk_url' => $url];

        // The DM goes to the chat the person opened with the bot, or - for an
        // answer to 💳 Sales desk - to the chat the command came from.
        $chatId = $dmChatId ?? $person->telegramChatId;

        if (null !== $chatId) {
            $this->telegram->sendMessage(
                $chatId,
                DmBody::frame($person, MessageTemplates::body('sales_desk_link_dm', $context)),
                MessageActions::replyMarkup(MessageActions::of('sales_desk_link_dm', $context, $this->mailer))
            );
        }

        // Only a verified address, the rule every other send here keeps: an
        // unverified one may be somebody else's inbox.
        if ($byEmail && $person->isEmailVerified() && ! $person->isAnonymized()) {
            $this->mailer->toPerson(
                $person->id,
                $person->email,
                MessageTemplates::subject('sales_desk_link', $context),
                EmailBody::withGreeting($person->displayName, MessageTemplates::body('sales_desk_link', $context)),
                MessageActions::email(MessageActions::of('sales_desk_link', $context, $this->mailer))
            );
        }

        return true;
    }

    /**
     * A fresh one-time login link for $person on $date, lasting as long as
     * their session may, or '' when that is no time at all. The web page's
     * "Sales desk" button redirects through one of these.
     */
    public function loginUrl(Person $person, string $date): string
    {
        $ttl = $this->gate->secondsLeft($person, $date);

        if ($ttl <= 0) {
            return '';
        }

        return DoorSession::loginUrl($this->tokens->issue($person->id, DoorSession::TOKEN_PURPOSE, $ttl), $date);
    }

    public static function buttonLabel(): string
    {
        return __('🚪 Open the sales desk', 'eventcrew');
    }

    /**
     * Which channels the organizer notice goes out on: 'telegram', 'email',
     * both (the default) or neither. Settings > Reputation & alerts.
     *
     * @return list<string>
     */
    public static function organizerChannels(): array
    {
        $saved = get_option(self::ORGANIZER_CHANNELS_OPTION, self::ORGANIZER_CHANNELS);
        $saved = is_array($saved) ? $saved : self::ORGANIZER_CHANNELS;

        return array_values(array_intersect(self::ORGANIZER_CHANNELS, array_map('strval', $saved)));
    }

    /**
     * The organizers signed up on the night's Organize task: an occupying
     * sign-up, by a person who is still an organizer. Each once.
     *
     * @param list<\EventCrew\Models\Task> $tasks
     * @return list<Person>
     */
    private function signedUpOrganizers(array $tasks): array
    {
        $found = [];

        foreach ($tasks as $task) {
            if (Roles::ORGANIZE_SLUG !== $task->roleSlug) {
                continue;
            }

            foreach ($this->assignments->forTask($task->id) as $assignment) {
                if (! $assignment->isOccupying() || isset($found[$assignment->personId])) {
                    continue;
                }

                $person = $this->people->find($assignment->personId);

                if (null !== $person && $person->isOrganizer) {
                    $found[$person->id] = $person;
                }
            }
        }

        return array_values($found);
    }

    private function sendOrganizerOnce(Person $person, string $date): void
    {
        if ($this->ledger->hasSent(self::KIND, $person->id, $date)) {
            return;
        }

        $sent = Language::forPerson(
            $person,
            fn (): bool => $this->sendOrganizerNotice($person, $date)
        );

        if ($sent) {
            $this->ledger->recordSent(self::KIND, $person->id, $date);
        }
    }

    /**
     * The organizer's notice: the sales desk link, plus a button to the
     * crew page's Admin tab, on the channels set in Settings. Returns false
     * when nothing could be sent, so the next check tries again.
     */
    private function sendOrganizerNotice(Person $person, string $date): bool
    {
        $channels = self::organizerChannels();
        $byTelegram = in_array('telegram', $channels, true) && null !== $person->telegramChatId;
        $byEmail = in_array('email', $channels, true) && $person->isEmailVerified() && ! $person->isAnonymized();

        // No login token is made when there is nowhere to send it.
        if (! $byTelegram && ! $byEmail) {
            return false;
        }

        $url = $this->loginUrl($person, $date);

        if ('' === $url) {
            return false;
        }

        // The texts are the Messages page templates (MessageTemplates).
        $context = ['person' => $person, 'day' => Dates::dayLabel($date), 'desk_url' => $url];

        if ($byTelegram) {
            $this->telegram->sendMessage(
                (int) $person->telegramChatId,
                DmBody::frame($person, MessageTemplates::body('organizer_notice_dm', $context)),
                MessageActions::replyMarkup(MessageActions::of('organizer_notice_dm', $context, $this->mailer))
            );
        }

        if ($byEmail) {
            $this->mailer->toPerson(
                $person->id,
                $person->email,
                MessageTemplates::subject('organizer_notice', $context),
                EmailBody::withGreeting($person->displayName, MessageTemplates::body('organizer_notice', $context)),
                MessageActions::email(MessageActions::of('organizer_notice', $context, $this->mailer))
            );
        }

        return true;
    }

    public static function adminButtonLabel(): string
    {
        return __('🛠️ Open the Admin tab', 'eventcrew');
    }

    private function sendOnce(Person $person, string $date): void
    {
        if ($this->ledger->hasSent(self::KIND, $person->id, $date)) {
            return;
        }

        if ($this->send($person, $date)) {
            $this->ledger->recordSent(self::KIND, $person->id, $date);
        }
    }

    /**
     * When organizers get their link: the set number of minutes before the
     * night's first task starts, or before 17:00 when no task has a start.
     */
    private function organizersFrom(string $date): string
    {
        $first = $this->tasks->earliestStartOn($date) ?? $date . ' ' . self::FALLBACK_START;

        return gmdate('Y-m-d H:i:s', (int) strtotime($first . ' UTC') - self::leadMinutes() * MINUTE_IN_SECONDS);
    }
}
