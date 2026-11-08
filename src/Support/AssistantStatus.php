<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CheckinRepository;
use EventCrew\Repositories\FollowupRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\NewsletterHistoryRepository;
use EventCrew\Repositories\NewsletterSubscriberRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;

/**
 * The live state of the site for the bot's language-model helper
 * (AssistantKnowledge), so it can answer "has the announcement gone out?" or
 * "who organizes on Saturday?" itself instead of sending the organizer to
 * wp-admin.
 *
 * - text(): the "Current status" section, for organizers only: the
 *   announcement email (newsletter), the post-event email, the ticket
 *   emails, the next NIGHTS nights (tasks, Organize, free-entry tickets,
 *   guest list, ticket sales, free entry open or closed, stop flags), the
 *   recent cancellations (who, which task, when, by whom, open or
 *   re-filled), the organizers and the newcomers waiting.
 * - personText(): the "This person" section: one person's own coming tasks,
 *   tickets, completed tasks and free-entry credits, for that person only.
 *
 * Both are read fresh on every message: no cache. No email address, phone
 * number or Telegram handle is ever written; buyers and guests appear only as
 * counts. Names appear only for organizers and the people on Organize.
 */
final class AssistantStatus
{
    /** How many coming nights are summed up. */
    public const NIGHTS = 3;

    /** How many past tasks of one person are listed. */
    private const PAST_TASKS = 5;

    /** How many days back cancellations are listed (plus any for a coming task). */
    public const CANCEL_DAYS = 14;

    /** How many cancellations are listed at most. */
    public const CANCEL_LIMIT = 15;

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly PersonRepository $people,
        private readonly NewsletterSubscriberRepository $subscribers,
        private readonly NewsletterHistoryRepository $history,
        private readonly FollowupRepository $followups,
        private readonly GuestRepository $guests,
        private readonly RedemptionRepository $redemptions,
        private readonly TicketBuyerRepository $buyers,
        private readonly FreeEntryGate $freeEntry,
        private readonly AssignmentRepository $assignments,
        private readonly ?StandingCalculator $standing = null,
        private readonly ?TicketTransferService $transfers = null
    ) {
    }

    /** The "Current status" section's text, for an organizer. */
    public function text(): string
    {
        $today = (string) current_time('Y-m-d');
        $now = (string) current_time('mysql');
        $newsletter = self::newsletterState();

        $parts = [
            'Read now, so it is live. Times are site time.',
            implode("\n", $this->newsletterLines($newsletter, $today, $now)),
            implode("\n", $this->followupLines($today)),
            implode("\n", $this->ticketSendLines()),
        ];

        foreach ($this->tasks->upcomingDates(self::NIGHTS) as $date) {
            $parts[] = implode("\n", $this->nightLines((string) $date, $newsletter));
        }

        $parts[] = implode("\n", $this->cancellationLines($today, $now));
        $parts[] = implode("\n", $this->peopleLines());

        return implode("\n", array_filter($parts, static fn (string $part): bool => '' !== $part));
    }

    /**
     * The "This person" section's text: only $personId's own data. '' for
     * nobody (0).
     */
    public function personText(int $personId): string
    {
        if ($personId <= 0) {
            return '';
        }

        $today = (string) current_time('Y-m-d');
        $coming = [];
        $past = [];

        foreach ($this->assignments->historyFor($personId) as $entry) {
            $assignment = $entry['assignment'];
            $task = $this->tasks->find($assignment->taskId);
            $what = null === $task
                ? 'a task'
                : $task->roleLabel() . ('' !== $task->timeRange() ? ' ' . $task->timeRange() : '')
                    . ' (' . $task->eventName() . ')';

            if ($entry['task_date'] >= $today) {
                if ($assignment->isOccupying()) {
                    $coming[] = self::day($entry['task_date']) . ' ' . $what;
                }
            } elseif (count($past) < self::PAST_TASKS) {
                $past[] = self::day($entry['task_date']) . ' ' . $what . ': ' . $assignment->statusLabel();
            }
        }

        $lines = [
            'Only this person\'s own data. Tell it only to them.',
            '- Coming tasks: ' . ([] !== $coming ? implode('; ', array_reverse($coming)) : 'none') . '.',
        ];

        $tickets = (new TicketList(
            $this->assignments,
            $this->tasks,
            $this->redemptions,
            new CheckinRepository(),
            $this->transfers
        ))->forPerson($personId);
        $ticketLine = static fn (array $ticket): string => self::day($ticket['when']) . ' ' . $ticket['label']
            . (null !== $ticket['used'] ? ' (used)' : '');

        $lines[] = '- Coming tickets: ' . ([] !== $tickets['upcoming']
            ? implode('; ', array_map($ticketLine, $tickets['upcoming']))
            : 'none') . '. /tickets shows them with their links.';

        if ([] !== $tickets['past']) {
            $lines[] = '- Past tickets: ' . count($tickets['past']) . '.';
        }

        if (null !== $this->standing) {
            $standing = $this->standing->for($personId);
            $lines[] = sprintf(
                '- Completed tasks: %d. Free-entry credits to spend: %d.',
                $standing->completedCount,
                $standing->creditBalance
            );
        }

        if ([] !== $past) {
            $lines[] = '- Latest past tasks: ' . implode('; ', $past) . '.';
        }

        return implode("\n", $lines);
    }

    /**
     * The announcement (newsletter): on or off, the last one sent, the plan
     * or the next one due, and the coming events already handled.
     *
     * @param array{ledger: array<int|string, array<string, mixed>>, plan: array<string, mixed>|null,
     *     last: array<string, mixed>|null} $state
     * @return list<string>
     */
    public function newsletterLines(array $state, string $today, string $now): array
    {
        $enabled = Newsletter::enabled();
        $lines = [
            sprintf(
                'Announcement email (the newsletter to subscribers): %s, sent %d days before each event.'
                    . ' Confirmed subscribers: %d.',
                $enabled ? 'switched on' : 'switched off',
                Newsletter::days(),
                $this->subscribers->countConfirmed()
            ),
        ];

        $last = $state['last'];

        if (null !== $last) {
            $subject = $this->lastSubject();
            $lines[] = sprintf(
                '- Last one sent: %s, %d event(s)%s, %d emails sent, %d failed.',
                self::moment((string) ($last['at'] ?? '')),
                (int) ($last['events'] ?? 0),
                '' !== $subject ? ', subject "' . $subject . '"' : '',
                (int) ($last['sent'] ?? 0),
                (int) ($last['failed'] ?? 0)
            );
        } else {
            $lines[] = '- None has been sent yet.';
        }

        $plan = $state['plan'];

        if (null !== $plan) {
            $events = implode(', ', array_map(
                static fn (array $event): string => (string) ($event['title'] ?? '') . ' '
                    . self::day((string) ($event['date'] ?? '')),
                is_array($plan['events'] ?? null) ? $plan['events'] : []
            ));

            if (Newsletter::PLAN_SENDING === ($plan['status'] ?? '')) {
                $lines[] = sprintf(
                    '- Being sent now (about %s): %s. So far %d sent, %d failed.',
                    self::moment((string) ($plan['send_at'] ?? '')),
                    $events,
                    (int) ($plan['sent'] ?? 0),
                    (int) ($plan['failed'] ?? 0)
                );
            } else {
                $written = ! NewsletterWriter::enabled()
                    ? 'the language model does not write them'
                    : (NewsletterWriter::missing($plan, false) ? 'not yet' : 'yes');
                $lines[] = sprintf(
                    '- Planned, not sent yet: %s. Preview to the organizers %s: %s. Event texts written: %s.'
                        . ' Sends %s. /stop announcement stops it.',
                    $events,
                    self::moment((string) ($plan['preview_at'] ?? '')),
                    (bool) ($plan['previewed'] ?? true) ? 'sent' : 'not sent yet',
                    $written,
                    self::moment((string) ($plan['send_at'] ?? (($plan['send_on'] ?? '') . ' '
                        . NewsletterSchedule::sendTime() . ':00')))
                );
            }
        } elseif ($enabled) {
            $lines[] = $this->nextPlanLine($state['ledger'], $today, $now);
        }

        $handled = [];

        foreach ($state['ledger'] as $id => $entry) {
            $date = (string) ($entry['d'] ?? '');

            if ($date < $today) {
                continue;
            }

            $title = EventSource::title((int) $id);
            $handled[] = sprintf(
                '%s %s: %s',
                '' !== $title ? $title : 'event ' . $id,
                self::day($date),
                (string) ($entry['s'] ?? '')
            );
        }

        if ([] !== $handled) {
            $lines[] = '- Coming events and their announcement: ' . implode('; ', $handled) . '.';
        }

        return $lines;
    }

    /**
     * The next coming event no announcement has handled, and when its plan,
     * preview and send are due.
     *
     * @param array<int|string, array<string, mixed>> $ledger
     */
    private function nextPlanLine(array $ledger, string $today, string $now): string
    {
        $all = EventSource::upcoming(-1);
        $starts = array_column($all, 'starts_at', 'id');

        foreach (Newsletter::comingEvents($today, $all) as $event) {
            if (isset($ledger[$event['id']])) {
                continue;
            }

            $startsAt = (string) ($starts[$event['id']] ?? '');
            $previewAt = NewsletterSchedule::previewAt(
                $event + ['starts_at' => $startsAt],
                Newsletter::previousEvent($all, $startsAt)
            );
            $sendAt = NewsletterSchedule::sendAt($event['date']);
            $planAt = NewsletterSchedule::planAt($previewAt, $sendAt);

            return sprintf(
                '- No announcement is planned right now. The next event without one: %s %s. Its plan is made'
                    . ' %s, the preview goes to the organizers %s, and it is sent %s%s.',
                $event['title'],
                self::day($event['date']),
                $planAt <= $now ? 'at the next hourly run' : self::moment($planAt),
                self::moment(max($previewAt, $planAt)),
                self::moment($sendAt),
                $sendAt <= $now ? ' (or the day after the plan, if that is later)' : ''
            );
        }

        return '- No announcement is planned, and no coming event is waiting for one.';
    }

    /** The subject of the last newsletter kept in the history, or ''. */
    private function lastSubject(): string
    {
        $rows = $this->history->sentList();

        return [] === $rows ? '' : (string) $rows[count($rows) - 1]['subject'];
    }

    /**
     * The post-event email: on or off, the last night sent and the next one.
     *
     * @return list<string>
     */
    public function followupLines(string $today): array
    {
        if (! FollowupMail::enabled()) {
            return ['Post-event email (to ticket holders the day after a night): switched off.'];
        }

        $state = self::followupState();
        $lines = ['Post-event email (to ticket holders the day after a night, 12:00): switched on.'];
        $done = array_map('strval', array_keys($state['done']));
        rsort($done);

        if ([] !== $done) {
            $counts = $this->followups->counts($done[0]);
            $lines[] = sprintf(
                '- Last sent: for the night of %s, %d sent, %d failed, %d clicked.',
                self::day($done[0]),
                $counts['sent'],
                $counts['failed'],
                $counts['clicked']
            );
        }

        foreach (array_keys($state['started']) as $date) {
            if (! isset($state['done'][$date])) {
                $counts = $this->followups->counts((string) $date);
                $lines[] = sprintf(
                    '- Being sent now: for the night of %s, %d sent, %d failed so far.',
                    self::day((string) $date),
                    $counts['sent'],
                    $counts['failed']
                );
            }
        }

        foreach ($this->tasks->upcomingDates(self::NIGHTS) as $date) {
            $date = (string) $date;
            $status = self::followupStatus($state, $date);

            if ('planned' === $status) {
                $hour = sprintf(' %02d:00:00', FollowupMail::SEND_HOUR);
                $lines[] = sprintf(
                    '- Next: for the night of %s, preview to the organizers %s, sent %s. /stop email stops it.',
                    self::day($date),
                    self::moment(NewsletterSchedule::addDays($date, -1) . $hour),
                    self::moment(NewsletterSchedule::addDays($date, 1) . $hour)
                );

                break;
            }
        }

        return $lines;
    }

    /**
     * A ticket send that waits (/sendtickets cancel stops it).
     *
     * @return list<string>
     */
    private function ticketSendLines(): array
    {
        $pending = TicketMail::pending();

        if (null === $pending) {
            return [];
        }

        return [sprintf(
            'Ticket emails: a send is waiting and starts %s. /sendtickets cancel stops it.',
            self::moment(self::siteTime($pending['at']))
        )];
    }

    /**
     * One coming night: its tasks, who organizes, the door's counts and the
     * switches.
     *
     * @param array{ledger: array<int|string, array<string, mixed>>, plan: array<string, mixed>|null,
     *     last: array<string, mixed>|null} $newsletter
     * @return list<string>
     */
    public function nightLines(string $date, array $newsletter): array
    {
        $tasks = $this->tasks->forDate($date);
        $ids = array_map(static fn (Task $task): int => $task->id, $tasks);
        $taken = $this->tasks->occupancyFor($ids);
        $names = $this->tasks->occupantsFor($ids);
        $taskTexts = [];
        $organize = [];
        $event = '';

        foreach ($tasks as $task) {
            $event = '' !== $event ? $event : $task->eventName();
            $time = $task->timeRange();
            $taskTexts[] = sprintf(
                '%s%s %d/%s',
                $task->roleLabel(),
                '' !== $time ? ' ' . $time : '',
                $taken[$task->id] ?? 0,
                $task->hasNoLimit() ? 'no limit' : (string) $task->capacity
            );

            if (Roles::ORGANIZE_SLUG === $task->roleSlug) {
                $organize = [...$organize, ...array_filter($names[$task->id] ?? [])];
            }
        }

        $lines = [
            sprintf('Night %s%s:', self::day($date), '' !== $event ? ', ' . $event : ''),
            '- Tasks (signed up/places): ' . ([] !== $taskTexts ? implode('; ', $taskTexts) : 'none') . '.',
            '- On Organize: ' . ([] !== $organize ? implode(', ', array_unique($organize)) : 'nobody') . '.',
        ];

        $guests = $this->guests->forDate($date);
        $plusOnes = array_sum(array_map(static fn ($guest): int => $guest->plusOnes, $guests));
        $lines[] = sprintf(
            '- Free-entry tickets given for credits: %d. Guest list: %d entries%s.',
            count($this->redemptions->forDate($date)),
            count($guests),
            $plusOnes > 0 ? sprintf(' (%d people with plus-ones)', count($guests) + $plusOnes) : ''
        );

        $sold = $this->buyers->countForDate($date);
        $imported = $this->buyers->countImportedForDate($date);
        $buyerMail = $this->buyers->mailCounts($date);
        $guestMail = $this->guests->mailCounts($date);
        $lines[] = sprintf(
            '- Ticket holders: %d (%d imported from the ticket shop, %d sold at the door or desk).'
                . ' Ticket emails to buyers: %s; to guests: %s.',
            $sold,
            $imported,
            $sold - $imported,
            self::mailCounts($buyerMail),
            self::mailCounts($guestMail)
        );

        $reason = $this->freeEntry->eventReason($date);
        $freeEntry = match (true) {
            $this->freeEntry->isClosedByHand($date) => 'closed by an organizer (/freeentry open reopens it)',
            '' !== $reason => 'closed by the event itself (' . $reason . ')',
            default => 'open (/freeentry close closes it)',
        };
        $lines[] = sprintf(
            '- Free entry: %s. Announcement: %s. Post-event email: %s.',
            $freeEntry,
            self::announcementFor($newsletter, $date),
            self::followupStatus(self::followupState(), $date)
        );

        return $lines;
    }

    /**
     * The newest cancellations: a person dropping their own task, an
     * organizer taking them off, or a deleted task cancelling its sign-ups.
     * Read from the assignments table, where each one stays as a
     * `cancelled` or `late_cancel` row with the time and who changed it.
     * The last CANCEL_DAYS days, plus any for a coming task, newest first, at
     * most CANCEL_LIMIT lines. A coming task that still exists says whether
     * its places are filled again.
     *
     * @return list<string>
     */
    public function cancellationLines(string $today, string $now): array
    {
        $nowTime = strtotime($now . ' UTC');
        $since = false === $nowTime
            ? $today . ' 00:00:00'
            : gmdate('Y-m-d H:i:s', $nowTime - self::CANCEL_DAYS * DAY_IN_SECONDS);
        $rows = $this->assignments->recentCancellations($since, $today, self::CANCEL_LIMIT + 1);
        $more = count($rows) > self::CANCEL_LIMIT;
        $rows = array_slice($rows, 0, self::CANCEL_LIMIT);

        if ([] === $rows) {
            return [sprintf(
                'Cancellations (the last %d days, and any for a coming task): none.',
                self::CANCEL_DAYS
            )];
        }

        $tasks = [];

        foreach ($rows as $row) {
            $task = ['id' => (int) $row['task_id']];

            foreach ($row as $key => $value) {
                if (str_starts_with((string) $key, 'task_') && 'task_id' !== $key) {
                    $task[substr((string) $key, 5)] = $value;
                }
            }

            $tasks[(int) $row['id']] = Task::fromRow($task);
        }

        $open = array_filter(
            $rows,
            static fn (array $row): bool => empty($row['task_deleted_at'])
                && (string) $row['task_task_date'] >= $today
        );
        $taken = $this->tasks->occupancyFor(array_map(static fn (array $row): int => (int) $row['task_id'], $open));

        $lines = [sprintf(
            'Cancellations (the last %d days, and any for a coming task; newest first%s):',
            self::CANCEL_DAYS,
            $more ? sprintf(', only the latest %d', self::CANCEL_LIMIT) : ''
        )];

        foreach ($rows as $row) {
            $task = $tasks[(int) $row['id']];
            $name = Person::fromRow([
                'display_name' => $row['display_name'] ?? '',
                'email' => $row['email'] ?? '',
            ])->name();
            $name = '' !== $name ? $name : 'someone';
            $time = $task->timeRange();
            $what = $task->roleLabel() . ' ' . self::day($task->taskDate) . ('' !== $time ? ' ' . $time : '')
                . ' (' . $task->eventName() . ')';
            $deletedAt = (string) ($row['task_deleted_at'] ?? '');
            $changedAt = (string) ($row['status_changed_at'] ?? '');
            $changedBy = (int) ($row['changed_by'] ?? 0);

            $how = match (true) {
                '' !== $deletedAt && $changedAt >= $deletedAt => 'the task was deleted, which cancelled '
                    . $name . '\'s place',
                $changedBy > 0 && MarkedBy::PERSON === ($row['changed_by_kind'] ?? null)
                    && $changedBy !== (int) $row['person_id'] => $name . ' was taken off by '
                    . self::changerName($row),
                $changedBy > 0 && MarkedBy::WP_USER === ($row['changed_by_kind'] ?? null)
                    => $name . ' was taken off in wp-admin',
                default => $name . ' cancelled',
            };

            $line = '- ' . self::moment($changedAt) . ': ' . $how . ': ' . $what
                . (AssignmentStatus::LATE_CANCEL === ($row['status'] ?? '') ? ', a late cancellation' : '');

            if ('' !== $deletedAt) {
                $line .= $changedAt >= $deletedAt ? '' : '. The task was deleted later';
            } elseif ($task->taskDate >= $today) {
                $count = $taken[$task->id] ?? 0;
                $line .= match (true) {
                    $task->hasNoLimit() => sprintf('. Now %d signed up (no limit)', $count),
                    $count >= $task->capacity => sprintf('. Filled again: %d/%d', $count, $task->capacity),
                    default => sprintf('. Still open: %d/%d signed up', $count, $task->capacity),
                };
            }

            $lines[] = $line . '.';
        }

        return $lines;
    }

    /**
     * The name of whoever took a person off (an EventCrew person), or "an organizer".
     *
     * @param array<string, mixed> $row
     */
    private static function changerName(array $row): string
    {
        $name = Person::fromRow([
            'display_name' => $row['changer_display_name'] ?? '',
            'email' => $row['changer_email'] ?? '',
        ])->name();

        return '' !== $name ? $name : 'an organizer';
    }

    /** @return list<string> */
    private function peopleLines(): array
    {
        $names = array_map(static fn ($person): string => $person->name(), $this->people->organizers());
        $lines = ['Organizers: ' . ([] !== $names ? implode(', ', $names) : 'none') . '.'];
        $waiting = count($this->people->waitingNewcomers());

        if ($waiting > 0) {
            $lines[] = sprintf('Newcomers waiting for an organizer\'s approval: %d (/newcomer).', $waiting);
        }

        return $lines;
    }

    /**
     * Where the announcement stands for the event on $date: sent, stopped,
     * missed, planned, being sent, or not planned yet.
     *
     * @param array{ledger: array<int|string, array<string, mixed>>, plan: array<string, mixed>|null} $state
     */
    private static function announcementFor(array $state, string $date): string
    {
        foreach ($state['ledger'] as $entry) {
            if ($date === (string) ($entry['d'] ?? '')) {
                return (string) ($entry['s'] ?? '');
            }
        }

        foreach (is_array($state['plan']['events'] ?? null) ? $state['plan']['events'] : [] as $event) {
            if ($date === (string) ($event['date'] ?? '')) {
                return Newsletter::PLAN_SENDING === ($state['plan']['status'] ?? '') ? 'being sent' : 'planned';
            }
        }

        return Newsletter::enabled() ? 'not planned yet' : 'switched off';
    }

    /**
     * FollowupMail::nightStatus(), from the stored state.
     *
     * @param array<string, array<string, mixed>> $state
     */
    private static function followupStatus(array $state, string $date): string
    {
        return match (true) {
            isset($state['stopped'][$date]) => 'stopped',
            isset($state['done'][$date]) => 'sent',
            isset($state['started'][$date]) => 'being sent',
            ! FollowupMail::enabled() => 'switched off',
            ! FollowupMail::covers($date) => 'not sent for this night (switched on after it)',
            default => 'planned',
        };
    }

    /**
     * The newsletter's stored state (Newsletter::STATE_OPTION).
     *
     * @return array{ledger: array<int|string, array<string, mixed>>, plan: array<string, mixed>|null,
     *     last: array<string, mixed>|null}
     */
    public static function newsletterState(): array
    {
        $stored = get_option(Newsletter::STATE_OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        return [
            'ledger' => is_array($stored['ledger'] ?? null) ? $stored['ledger'] : [],
            'plan' => is_array($stored['plan'] ?? null) ? $stored['plan'] : null,
            'last' => is_array($stored['last'] ?? null) ? $stored['last'] : null,
        ];
    }

    /**
     * The post-event email's stored state (FollowupMail::STATE_OPTION).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function followupState(): array
    {
        $stored = get_option(FollowupMail::STATE_OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $state = [];

        foreach (['stopped', 'started', 'done'] as $key) {
            $state[$key] = is_array($stored[$key] ?? null) ? $stored[$key] : [];
        }

        return $state;
    }

    /** @param array{sent: int, failed: int, unsent: int} $counts */
    private static function mailCounts(array $counts): string
    {
        if (0 === $counts['sent'] + $counts['failed'] + $counts['unsent']) {
            return 'none to send';
        }

        return sprintf('%d sent, %d failed, %d not sent', $counts['sent'], $counts['failed'], $counts['unsent']);
    }

    /** "2026-11-14 (Saturday)", as AssistantKnowledge's today line writes a date. */
    public static function day(string $ymd): string
    {
        $time = strtotime($ymd . ' 12:00:00 UTC');

        return false === $time || '' === $ymd ? $ymd : $ymd . ' (' . gmdate('l', $time) . ')';
    }

    /** "2026-11-14 (Saturday) 18:00" for a site-time "Y-m-d H:i:s". */
    public static function moment(string $mysql): string
    {
        if (strlen($mysql) < 16) {
            return '' !== $mysql ? self::day(substr($mysql, 0, 10)) : 'unknown';
        }

        return self::day(substr($mysql, 0, 10)) . ' ' . substr($mysql, 11, 5);
    }

    /** A Unix time as site time "Y-m-d H:i:s". */
    private static function siteTime(int $at): string
    {
        return function_exists('wp_date') ? (string) wp_date('Y-m-d H:i:s', $at) : gmdate('Y-m-d H:i:s', $at);
    }
}
