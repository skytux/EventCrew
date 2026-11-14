<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\TelegramClient;

/**
 * The messages the plugin sends on its own (Owner 2026-11-14): one row each
 * on the Admin tab's Messages card "Scheduled messages", in the bot's
 * /scheduled and in the helper.
 *
 * Each can be switched on or off. Where wp-admin already has a switch, it is
 * the same option, so the card and wp-admin never differ; the others have
 * their own option here, on until switched off, and their sender asks
 * isOn() before it sends.
 *
 * A few can also be sent once now (SENDABLE), through the same code the
 * scheduler uses, with the same opt-outs and ledgers. Messages sent to one
 * person because of something they did (a freed place, a takeover or a
 * newcomer running out of time, a welcome) have no "send now".
 */
final class ScheduledMessages
{
    public const REMINDERS = 'reminders';
    public const OPEN_TASKS = 'opentasks';
    public const BOARD = 'board';
    public const CARD = 'card';
    public const FREED = 'freed';
    public const STANDING = 'standing';
    public const ROLE_MET = 'rolemet';
    public const TAKEOVER = 'takeover';
    public const NEWCOMER = 'newcomer';
    public const WELCOME = 'welcome';
    public const NEWSLETTER = 'newsletter';
    public const FOLLOWUP = 'followup';
    public const MONTHLY = 'monthly';

    /** The order of the card and of /scheduled. */
    public const KEYS = [
        self::REMINDERS,
        self::OPEN_TASKS,
        self::BOARD,
        self::CARD,
        self::FREED,
        self::STANDING,
        self::ROLE_MET,
        self::TAKEOVER,
        self::NEWCOMER,
        self::WELCOME,
        self::NEWSLETTER,
        self::FOLLOWUP,
        self::MONTHLY,
    ];

    /**
     * The switches added for this card, each on until switched off ('1' or
     * '0'). The others are wp-admin's own options (isOn(), setOn()).
     */
    public const OPTIONS = [
        self::REMINDERS => 'eventcrew_reminders_enabled',
        self::OPEN_TASKS => 'eventcrew_open_task_call_enabled',
        self::CARD => 'eventcrew_next_card_enabled',
        self::FREED => 'eventcrew_slot_freed_enabled',
        self::STANDING => 'eventcrew_standing_notice_enabled',
        self::ROLE_MET => 'eventcrew_role_met_enabled',
        self::TAKEOVER => 'eventcrew_takeover_expiry_enabled',
        self::NEWCOMER => 'eventcrew_newcomer_timeout_enabled',
    ];

    /** The messages that can be sent once now. */
    public const SENDABLE = [
        self::REMINDERS,
        self::OPEN_TASKS,
        self::BOARD,
        self::CARD,
        self::NEWSLETTER,
        self::FOLLOWUP,
        self::MONTHLY,
    ];

    /**
     * The messages whose "send now" needs them switched on: switched off,
     * the newsletter's batches wait and the post-event email covers no
     * night (FollowupMail::covers()).
     */
    public const SEND_NEEDS_ON = [self::NEWSLETTER, self::FOLLOWUP];

    public function __construct(
        private readonly ?ReminderCall $reminders = null,
        private readonly ?OpenTaskCall $openTasks = null,
        private readonly ?BoardService $board = null,
        private readonly ?TelegramClient $telegram = null,
        private readonly ?MonthlyReport $monthly = null,
        private readonly ?Newsletter $newsletter = null,
        private readonly ?FollowupMail $followup = null
    ) {
    }

    /** Whether $key names a scheduled message. */
    public static function exists(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    /** Whether the message is switched on. Unknown keys are off. */
    public static function isOn(string $key): bool
    {
        return match ($key) {
            self::BOARD => (bool) get_option(BoardPush::ENABLED_OPTION, true),
            self::WELCOME => WelcomeSender::enabled(),
            self::NEWSLETTER => Newsletter::enabled(),
            self::FOLLOWUP => FollowupMail::enabled(),
            self::MONTHLY => MonthlyReport::isOn(),
            default => isset(self::OPTIONS[$key]) && '0' !== (string) get_option(self::OPTIONS[$key], '1'),
        };
    }

    /** Switches the message on or off. False for an unknown key. */
    public static function setOn(string $key, bool $on): bool
    {
        if (! self::exists($key)) {
            return false;
        }

        $value = $on ? '1' : '0';

        match ($key) {
            self::BOARD => update_option(BoardPush::ENABLED_OPTION, $value),
            self::WELCOME => update_option(WelcomeSender::ENABLED_OPTION, $value),
            self::NEWSLETTER => update_option(Newsletter::ENABLED_OPTION, $value),
            self::FOLLOWUP => FollowupMail::setEnabled($on),
            self::MONTHLY => MonthlyReport::save($on),
            default => update_option(self::OPTIONS[$key], $value),
        };

        return true;
    }

    /** Whether the message has a "send now". */
    public static function sendable(string $key): bool
    {
        return in_array($key, self::SENDABLE, true);
    }

    /** Whether "send now" waits for the message to be switched on first. */
    public static function sendNeedsOn(string $key): bool
    {
        return in_array($key, self::SEND_NEEDS_ON, true) && ! self::isOn($key);
    }

    /** The message's short name, translated. */
    public static function name(string $key): string
    {
        return match ($key) {
            self::REMINDERS => __('Task reminder', 'eventcrew'),
            self::OPEN_TASKS => __('Open tasks call', 'eventcrew'),
            self::BOARD => __('Board in the group', 'eventcrew'),
            self::CARD => __('Next event card', 'eventcrew'),
            self::FREED => __('Place freed', 'eventcrew'),
            self::STANDING => __('Thanks and no-show notes', 'eventcrew'),
            self::ROLE_MET => __('Role conditions met', 'eventcrew'),
            self::TAKEOVER => __('Takeover expired', 'eventcrew'),
            self::NEWCOMER => __('Newcomer not answered', 'eventcrew'),
            self::WELCOME => __('Welcome', 'eventcrew'),
            self::NEWSLETTER => __('Newsletter', 'eventcrew'),
            self::FOLLOWUP => __('Follow-up email', 'eventcrew'),
            self::MONTHLY => __('Monthly report', 'eventcrew'),
            default => $key,
        };
    }

    /** Where the message goes, translated. */
    public static function channel(string $key): string
    {
        return match ($key) {
            self::BOARD, self::CARD => __('Group', 'eventcrew'),
            self::NEWSLETTER, self::FOLLOWUP => __('Email', 'eventcrew'),
            self::ROLE_MET, self::MONTHLY => __('Organizers', 'eventcrew'),
            default => __('Email + DM', 'eventcrew'),
        };
    }

    /** When the message goes, short and translated. */
    public static function when(string $key): string
    {
        return match ($key) {
            /* translators: %d: hours. */
            self::REMINDERS => sprintf(__('%d h before', 'eventcrew'), ReminderCall::REMINDER_HOURS),
            self::OPEN_TASKS, self::BOARD => __('Before each event', 'eventcrew'),
            self::CARD => __('When an event is next', 'eventcrew'),
            self::FREED => __('After a late cancel', 'eventcrew'),
            /* translators: %d: hours. */
            self::STANDING => sprintf(__('%d h after marking', 'eventcrew'), StandingNotice::DELAY_HOURS),
            self::ROLE_MET => __('Hourly check', 'eventcrew'),
            self::TAKEOVER, self::NEWCOMER => __('When time runs out', 'eventcrew'),
            self::WELCOME => __('After joining', 'eventcrew'),
            /* translators: %d: days. */
            self::NEWSLETTER => sprintf(__('%d days before', 'eventcrew'), Newsletter::days()),
            self::FOLLOWUP => __('Day after the event', 'eventcrew'),
            self::MONTHLY => __('Early each month', 'eventcrew'),
            default => '',
        };
    }

    /**
     * Every message for the card and /scheduled.
     *
     * @return list<array{key: string, name: string, channel: string, when: string, on: bool, sendable: bool}>
     */
    public static function rows(): array
    {
        return array_map(
            static fn (string $key): array => [
                'key' => $key,
                'name' => self::name($key),
                'channel' => self::channel($key),
                'when' => self::when($key),
                'on' => self::isOn($key),
                'sendable' => self::sendable($key),
            ],
            self::KEYS
        );
    }

    /**
     * How many would get it if it were sent now: people, or 1 for the group.
     * 0 when nobody would; null for a message without "send now".
     */
    public function count(string $key): ?int
    {
        return match ($key) {
            self::REMINDERS => $this->reminders?->countNext() ?? 0,
            self::OPEN_TASKS => $this->openTasks?->countForNextOpenDate(true) ?? 0,
            self::BOARD => $this->hasGroup() ? 1 : 0,
            self::CARD => null !== $this->board && $this->board->canPostNextCard() ? 1 : 0,
            self::NEWSLETTER => $this->newsletter?->sendNowCount() ?? 0,
            self::FOLLOWUP => $this->followup?->sendNowCount() ?? 0,
            self::MONTHLY => $this->monthly?->recipientCount() ?? 0,
            default => null,
        };
    }

    /**
     * "Send the task reminder now to 12 people?": the confirmation, with the
     * count only. '' when nobody would get it or it has no "send now".
     */
    public function question(string $key): string
    {
        $count = $this->count($key);

        if (null === $count || $count < 1) {
            return '';
        }

        if (self::BOARD === $key) {
            return __('Post the board in the group now?', 'eventcrew');
        }

        if (self::CARD === $key) {
            return __('Post the next event card in the group now?', 'eventcrew');
        }

        return sprintf(
            /* translators: 1: the message's name, 2: how many people get it. */
            _n('Send "%1$s" now to %2$d person?', 'Send "%1$s" now to %2$d people?', $count, 'eventcrew'),
            self::name($key),
            $count
        );
    }

    /**
     * Sends the message once now, through its own sender, outside the send
     * window (the organizer asked). Returns how many got it (1 for the
     * group), 0 when nobody did.
     */
    public function send(string $key): int
    {
        return match ($key) {
            self::REMINDERS => $this->reminders?->sendNext() ?? 0,
            self::OPEN_TASKS => $this->openTasks?->sendForNextOpenDate(true) ?? 0,
            self::BOARD => $this->hasGroup() && null !== $this->board && $this->board->repost() ? 1 : 0,
            self::CARD => null !== $this->board && $this->board->postNextCardNow() ? 1 : 0,
            self::NEWSLETTER => self::isOn($key) ? $this->newsletter?->sendNow() ?? 0 : 0,
            self::FOLLOWUP => self::isOn($key) ? $this->followup?->sendNow() ?? 0 : 0,
            self::MONTHLY => $this->monthly?->sendNow() ?? 0,
            default => 0,
        };
    }

    /** "Task reminder sent to 12 people." or "Nobody to send it to." */
    public static function sentText(string $key, int $sent): string
    {
        if ($sent < 1) {
            return __('Nobody to send it to.', 'eventcrew');
        }

        if (self::BOARD === $key) {
            return __('Board posted in the group.', 'eventcrew');
        }

        if (self::CARD === $key) {
            return __('Next event card posted in the group.', 'eventcrew');
        }

        return sprintf(
            /* translators: 1: the message's name, 2: how many people got it. */
            _n('"%1$s" sent to %2$d person.', '"%1$s" sent to %2$d people.', $sent, 'eventcrew'),
            self::name($key),
            $sent
        );
    }

    /**
     * The list for the bot and the helper: one line per message, with its
     * key, state, channel and when.
     */
    public static function listText(): string
    {
        $lines = [__('Scheduled messages', 'eventcrew')];

        foreach (self::rows() as $row) {
            $lines[] = sprintf(
                '%s %s (%s): %s · %s',
                $row['on'] ? '✅' : '⏸',
                $row['name'],
                $row['key'],
                $row['channel'],
                $row['when']
            );
        }

        $lines[] = '';
        $lines[] = __('/scheduled <name> on|off · /scheduled <name> send', 'eventcrew');

        return implode("\n", $lines);
    }

    /**
     * The card's rows, each with its "send now" confirmation ('' when there
     * is nobody to send it to or it has none).
     *
     * @return list<array{key: string, name: string, channel: string, when: string, on: bool, sendable: bool, question: string}>
     */
    public function view(): array
    {
        return array_map(
            fn (array $row): array => $row + ['question' => $row['sendable'] ? $this->question($row['key']) : ''],
            self::rows()
        );
    }

    /**
     * The card's form and the bot's command: "on", "off" or "send" for one
     * message. Returns the notice code (noticeText()).
     */
    public function apply(string $key, string $op): string
    {
        if (! self::exists($key)) {
            return 'admin_sched_unknown';
        }

        if ('on' === $op || 'off' === $op) {
            self::setOn($key, 'on' === $op);

            return 'admin_sched_' . $op . '_' . $key;
        }

        if ('send' !== $op || ! self::sendable($key)) {
            return 'admin_sched_unknown';
        }

        if (self::sendNeedsOn($key)) {
            return 'admin_sched_needson_' . $key;
        }

        $sent = $this->send($key);

        return $sent < 1 ? 'admin_sched_none' : 'admin_sched_sent_' . $key . '_' . $sent;
    }

    /** The text for apply()'s notice code, or '' for another code. */
    public static function noticeText(string $code): string
    {
        if ('admin_sched_unknown' === $code) {
            return __('No such scheduled message.', 'eventcrew');
        }

        if ('admin_sched_none' === $code) {
            return self::sentText('', 0);
        }

        if (1 === preg_match('/^admin_sched_(on|off)_([a-z]+)$/', $code, $m) && self::exists($m[2])) {
            return sprintf(
                /* translators: %s: the message's name. */
                'on' === $m[1] ? __('%s: on.', 'eventcrew') : __('%s: off.', 'eventcrew'),
                self::name($m[2])
            );
        }

        if (1 === preg_match('/^admin_sched_needson_([a-z]+)$/', $code, $m) && self::exists($m[1])) {
            return sprintf(
                /* translators: %s: the message's name. */
                __('"%s" is switched off. Switch it on to send it now.', 'eventcrew'),
                self::name($m[1])
            );
        }

        if (1 === preg_match('/^admin_sched_sent_([a-z]+)_(\d+)$/', $code, $m) && self::exists($m[1])) {
            return self::sentText($m[1], (int) $m[2]);
        }

        return '';
    }

    private function hasGroup(): bool
    {
        return null !== $this->board && null !== $this->telegram
            && $this->telegram->isConfigured() && $this->board->hasHome();
    }
}
