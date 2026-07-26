<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\TelegramClient;

/**
 * The open-task email: "some jobs still need people". Transactional now - it
 * goes to every active account, the off switch being disabling the account -
 * but it still only sends when there is genuinely something open, skips anyone
 * already signed up for that date, and records every send so a re-run never
 * doubles up.
 *
 * Each mail also carries the recipient's own recent history and total, so it
 * reads as a personal summary rather than a broadcast.
 */
final class OpenTaskCall
{
    /**
     * The nearer lead's ledger kind. Deliberately still the bare 'open_task'
     * this has always written, so an upgrade does not re-announce every date
     * that has already had its last-call email.
     */
    public const KIND = 'open_task';

    /** The further lead's ledger kind - the heads-up, new in 1.11. */
    public const KIND_WEEK = 'open_task_week';

    /** The further lead, in hours. Default 168 (one week). */
    public const LEAD_WEEK_OPTION = 'eventcrew_open_task_lead_week';

    /** The nearer lead, in hours. Default 48. */
    public const LEAD_SOON_OPTION = 'eventcrew_open_task_lead_soon';

    public const LEAD_WEEK_DEFAULT = 168;
    public const LEAD_SOON_DEFAULT = 48;

    /**
     * How many open tasks the message lists. Enough to show what is coming
     * without turning a nudge into a wall of text nobody reads to the end of.
     */
    private const DIGEST_TASKS = 5;

    /**
     * The configured leads, as the ledger kind each records under. Two sends per
     * date by design: a heads-up while there is still time to rearrange a
     * weekend, and a last call. A date that first appears inside both windows
     * gets one email, not two, because both kinds are recorded together.
     *
     * @return array<string, int>
     */
    public static function leads(): array
    {
        return [
            self::KIND_WEEK => max(0, (int) get_option(self::LEAD_WEEK_OPTION, self::LEAD_WEEK_DEFAULT)),
            self::KIND => max(0, (int) get_option(self::LEAD_SOON_OPTION, self::LEAD_SOON_DEFAULT)),
        ];
    }

    /**
     * Open-task lines per date, memoised for the life of one run.
     *
     * @var array<string, array<int, string>>
     */
    private array $openLines = [];

    /**
     * Upcoming dates, memoised alongside the lines. Both the due check and the
     * digest walk this list, once per recipient each.
     *
     * @var array<int, string>|null
     */
    private ?array $upcoming = null;

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly PersonRepository $people,
        private readonly NotificationsRepository $ledger,
        private readonly Mailer $mailer,
        private readonly TelegramClient $telegram
    ) {
    }

    /**
     * Sends for the nearest upcoming date that still has open slots. Returns
     * how many people were mailed (0 when nothing is open).
     */
    public function sendForNextOpenDate(): int
    {
        foreach ($this->upcomingDates() as $date) {
            if ($this->tasks->hasOpenSlotsOn($date)) {
                return $this->sendForDate($date);
            }
        }

        return 0;
    }

    /**
     * The automated call. One email per person per day, covering every date
     * that is due for them, rather than one email per date - two events falling
     * inside a window on the same day is a normal week, not a reason to send
     * somebody two separate mails.
     *
     * A date is due for a person when it has open slots and either it has
     * crossed into a lead they have not been mailed at, or a task has been
     * added to it since they were last mailed. That second clause is what fixes
     * the old behaviour: the ledger recorded "told them about this date", so a
     * job added to an already-announced day was never announced at all.
     *
     * Stops once $limit people have been mailed this run; the rest resume on
     * the next one, since nobody due is recorded until they are actually sent.
     *
     * @param array<string, int> $leads Ledger kind => hours before the date.
     */
    public function sendDue(array $leads, int $limit): int
    {
        $now = (string) current_time('mysql');
        $today = (string) current_time('Y-m-d');
        $prefs = new NotificationPreferences();
        $sent = 0;

        foreach ($this->people->activeEmailRecipients() as $person) {
            if ($sent >= $limit) {
                break;
            }

            // The daily cap, asked before any of the per-date work.
            if ($this->ledger->sentOnDay(self::KIND, $person->id, $today)) {
                continue;
            }

            $due = $this->dueDatesFor($person->id, $leads, $now);

            if ([] === $due) {
                continue;
            }

            if (! $this->notify($person, $prefs)) {
                continue;
            }

            foreach ($due as $date => $kinds) {
                foreach ($kinds as $kind) {
                    $this->ledger->recordSent($kind, $person->id, (string) $date);
                }
            }

            ++$sent;
        }

        return $sent;
    }

    /**
     * The dates this person is due to hear about, each with the ledger kinds
     * the send should be recorded under.
     *
     * @param array<string, int> $leads
     *
     * @return array<string, array<int, string>> date => kinds
     */
    private function dueDatesFor(int $personId, array $leads, string $now): array
    {
        $nowTime = strtotime($now);
        $furthest = max([0, ...array_values($leads)]);
        $due = [];

        if (false === $nowTime || $furthest <= 0) {
            return [];
        }

        foreach ($this->upcomingDates() as $date) {
            // task_date is the day a task is filed under; a date is due once
            // its midnight is within the lead window.
            $dateStart = strtotime($date . ' 00:00:00');

            if (false === $dateStart) {
                continue;
            }

            // upcomingDates is ascending, so once one date is beyond the
            // furthest lead, so is everything after it.
            if ($dateStart > $nowTime + $furthest * HOUR_IN_SECONDS) {
                break;
            }

            if (! $this->hasOpenSlots($date)) {
                continue;
            }

            foreach ($leads as $kind => $leadHours) {
                if ($leadHours <= 0 || $dateStart > $nowTime + $leadHours * HOUR_IN_SECONDS) {
                    continue;
                }

                $sentAt = $this->ledger->sentAt($kind, $personId, $date);

                // Never mailed at this lead, or something has been added to the
                // date since we last did.
                if (null === $sentAt || $this->tasks->hasTaskCreatedSince($date, $sentAt)) {
                    $due[$date][] = $kind;
                }
            }
        }

        return $due;
    }

    /**
     * Sends the digest on whichever channels this person allows. Returns false
     * when they allow neither, so they are left unrecorded and a later opt-in
     * still reaches them.
     *
     * @param array<int, string> $dates
     */
    private function notify(Person $person, NotificationPreferences $prefs): bool
    {
        $emailOk = $prefs->emailAllowed($person, NotificationPreferences::OPEN_TASK);
        $dmOk = $prefs->dmAllowed($person, NotificationPreferences::OPEN_TASK);

        if (! $emailOk && ! $dmOk) {
            return false;
        }

        $openList = $this->openTasksText($this->digest(self::DIGEST_TASKS));
        $groupLink = self::groupLink();

        if ($emailOk) {
            $this->mailer->toPerson(
                $person->id,
                $person->email,
                __('Some tasks still need people', 'eventcrew'),
                $this->body($person, $openList),
                [
                    ['label' => __('See open tasks', 'eventcrew'), 'url' => $this->mailer->boardUrl()],
                    ['label' => __('Open the group in Telegram', 'eventcrew'), 'url' => $groupLink],
                ]
            );
        }

        if ($dmOk) {
            $this->telegram->sendMessage(
                (int) $person->telegramChatId,
                DmBody::frame(
                    $person,
                    sprintf(
                        /* translators: %s: the open-task list, already grouped by date */
                        __("🔔 Some tasks still need people:\n\n%s\n\nSign up on the board in the group.", 'eventcrew'),
                        $openList
                    ),
                    true
                ),
                // The DM arrives in a private chat, so the group is a tap away
                // rather than a scroll away. Omitted when no link is configured;
                // a chat id cannot be turned into one.
                '' === $groupLink
                    ? null
                    : ['inline_keyboard' => [[
                        ['text' => __('Open the group', 'eventcrew'), 'url' => $groupLink],
                    ]]]
            );
        }

        return true;
    }

    /**
     * The link to the crew's group - the organizer's own, or the one discovered
     * from Telegram. A plain option read; the discovery runs on the heartbeat.
     */
    private static function groupLink(): string
    {
        return BoardService::groupLink();
    }

    /**
     * Mails/DMs every active recipient about the open slots on $date, up to
     * $limit of them (0 means no cap - the manual button sends the lot). Crew
     * already working that day are included, since they may want a second,
     * non-overlapping slot; the ledger makes a capped or re-run send resume
     * without doubling up.
     */
    public function sendForDate(string $date, int $limit = 0): int
    {
        if (! $this->hasOpenSlots($date)) {
            return 0;
        }

        $prefs = new NotificationPreferences();
        $sent = 0;

        foreach ($this->people->activeEmailRecipients() as $person) {
            if ($limit > 0 && $sent >= $limit) {
                break;
            }

            if ($this->ledger->hasSent(self::KIND, $person->id, $date)) {
                continue;
            }

            if (! $this->notify($person, $prefs)) {
                continue;
            }

            $this->ledger->recordSent(self::KIND, $person->id, $date);
            ++$sent;
        }

        return $sent;
    }

    private function body(Person $person, string $openList): string
    {
        return sprintf(
            /* translators: 1: name, 2: open-task list grouped by date, 3: personal recap */
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __("Hi %1\$s,\n\nSome tasks still need people:\n\n%2\$s\n\nSign up on the board.\n\n%3\$s", 'eventcrew'),
            $person->name(),
            $openList,
            $this->recap($person)
        );
    }

    /**
     * The next few open tasks across all upcoming dates - not only the dates
     * that made this send due.
     *
     * Someone deciding whether they can help wants to see what is coming, and
     * an early sign-up is worth more to an organizer than a last-minute one. The
     * dates that triggered the send are the soonest open ones, so they head the
     * list naturally; the rest is what is behind them.
     *
     * @return array<string, array<int, string>> date => open-task lines
     */
    private function digest(int $limit): array
    {
        $blocks = [];
        $counted = 0;

        foreach ($this->upcomingDates() as $date) {
            if ($counted >= $limit) {
                break;
            }

            $lines = $this->openTaskLines($date);

            if ([] === $lines) {
                continue;
            }

            $lines = array_slice($lines, 0, $limit - $counted);
            $blocks[$date] = $lines;
            $counted += count($lines);
        }

        return $blocks;
    }

    /**
     * The digest as text, a heading per date. The heading is always shown, even
     * for a single date: the list now reaches past whatever made the send due,
     * so "when" is no longer implied by the message around it.
     *
     * @param array<string, array<int, string>> $blocks
     */
    private function openTasksText(array $blocks): string
    {
        $rendered = [];

        foreach ($blocks as $date => $lines) {
            $rendered[] = $date . "\n" . implode("\n", $lines);
        }

        return implode("\n\n", $rendered);
    }

    /**
     * @return array<int, string>
     */
    private function upcomingDates(): array
    {
        return $this->upcoming ??= $this->tasks->upcomingDates();
    }

    /** A date has something worth calling about when it has an open line. */
    private function hasOpenSlots(string $date): bool
    {
        return [] !== $this->openTaskLines($date);
    }

    /**
     * The open tasks on one date, remembered for the rest of this run.
     *
     * Every recipient is offered the same list for a given date, and each date
     * is also tested for open slots before it is listed, so without this the
     * same two queries would run once per person per date - the difference
     * between a couple of queries and a couple of hundred on a decent crew.
     *
     * @return array<int, string>
     */
    private function openTaskLines(string $date): array
    {
        if (isset($this->openLines[$date])) {
            return $this->openLines[$date];
        }

        $tasks = $this->tasks->forDate($date);
        $occupancy = $this->tasks->occupancyFor(array_map(static fn ($task): int => $task->id, $tasks));
        $lines = [];

        foreach ($tasks as $task) {
            $taken = $occupancy[$task->id] ?? 0;

            if ($taken < $task->capacity) {
                $lines[] = sprintf(
                    '- %s at %s (%d/%d)',
                    $task->roleLabel(),
                    $task->eventName(),
                    $taken,
                    $task->capacity
                );
            }
        }

        $this->openLines[$date] = $lines;

        return $lines;
    }

    private function recap(Person $person): string
    {
        $completed = $this->assignments->countCompletedFor($person->id);
        $recent = array_slice($this->assignments->historyFor($person->id), 0, 3);
        $lines = [];

        foreach ($recent as $entry) {
            $lines[] = sprintf(
                '- %s: %s',
                $entry['task_date'],
                AssignmentStatus::label($entry['assignment']->status)
            );
        }

        $history = [] === $lines
            ? __('This will be your first task — welcome!', 'eventcrew')
            : __('Your recent tasks:', 'eventcrew') . "\n" . implode("\n", $lines);

        return sprintf(
            /* translators: 1: recent-task list, 2: total completed */
            __("%1\$s\n\nTasks completed in total: %2\$d.", 'eventcrew'),
            $history,
            $completed
        );
    }
}
