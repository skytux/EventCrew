<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Assignment;
use EventCrew\Models\Person;
use EventCrew\Models\Redemption;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\Dates;
use EventCrew\Support\MarkedBy;
use EventCrew\Support\NewcomerApproval;
use EventCrew\Support\StandingCalculator;
use EventCrew\Web\AssistantWebChanges;
use EventCrew\Web\CrewAdminPeople;
use EventCrew\Web\CrewAdminRoster;
use EventCrew\Support\ExtraTaskCredits;

/**
 * Organizer commands about one person, in a private chat:
 *
 * - /mark [date] <name> [; arrived|late|noshow|done]: the person's mark for a
 *   task, written as the roster's buttons write it (so the standing notices
 *   follow as usual).
 * - /standing <name>: standing, credits and the recent log.
 * - /newcomer [name]: a newcomer waiting for an OK, with the request's own
 *   OK and No buttons (NewcomerApproval).
 * - /ticket [date] <name> and /unticket [date] <name>: spend a credit for
 *   free entry, or hand it back, as the door list on the crew page does.
 * - /unban <name>: lift a block.
 *
 * Every change waits for ✅; organizers only, checked again at every tap.
 */
final class PersonCommands
{
    public const PREFIX = 'pc:';
    public const STATE_PREFIX = 'eventcrew_tg_person_';

    public const MARK = 'mark';
    public const STANDING = 'standing';
    public const NEWCOMER = 'newcomer';
    public const TICKET = 'ticket';
    public const UNTICKET = 'unticket';
    public const UNBAN = 'unban';

    /** The commands this class answers. */
    public const COMMANDS = [self::MARK, self::STANDING, self::NEWCOMER, self::TICKET, self::UNTICKET, self::UNBAN];

    /** The commands the Crew app's helper box runs (prepareWeb(), runWeb()). */
    public const WEB = [self::TICKET, self::UNTICKET, self::UNBAN];

    /** The marks /mark sets, by button number. */
    private const MARKS = [
        1 => AssignmentStatus::ARRIVED,
        2 => AssignmentStatus::ARRIVED_LATE,
        3 => AssignmentStatus::COMPLETED,
        4 => AssignmentStatus::NO_SHOW,
    ];

    /** The words /mark takes for each mark. */
    private const MARK_WORDS = [
        'arrived' => AssignmentStatus::ARRIVED,
        'here' => AssignmentStatus::ARRIVED,
        'late' => AssignmentStatus::ARRIVED_LATE,
        'noshow' => AssignmentStatus::NO_SHOW,
        'no-show' => AssignmentStatus::NO_SHOW,
        'missed' => AssignmentStatus::NO_SHOW,
        'done' => AssignmentStatus::COMPLETED,
        'completed' => AssignmentStatus::COMPLETED,
    ];

    /** The statuses /mark may change: a sign-up still held, or a mark already made. */
    private const MARKABLE = [
        AssignmentStatus::SIGNED_UP,
        AssignmentStatus::ARRIVED,
        AssignmentStatus::ARRIVED_LATE,
        AssignmentStatus::COMPLETED,
        AssignmentStatus::NO_SHOW,
    ];

    /** Without a date, /mark looks this many days back. */
    private const MARK_DAYS = 14;

    /** Lines of each log /standing shows. */
    private const LOG_LINES = 5;

    private CommandFlow $flow;

    public function __construct(
        private readonly PersonRepository $people,
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly StandingCalculator $standing,
        private readonly CreditGrantRepository $grants,
        private readonly RedemptionRepository $redemptions,
        private readonly CrewAdminRoster $roster,
        private readonly CrewAdminPeople $peopleAdmin,
        private readonly NewcomerApproval $newcomers,
        TelegramClient $telegram
    ) {
        $this->flow = new CommandFlow($people, $telegram, self::PREFIX, self::STATE_PREFIX);
    }

    public function onCommand(int $chatId, int $fromId, string $text, bool $isPrivate, string $command): void
    {
        if (! $this->flow->inPrivate($chatId, $isPrivate, '/' . $command)) {
            return;
        }

        // A member can't cancel a free-entry ticket: /unticket is the
        // organizers' tool, and a member who tries it is told the rule.
        if (self::UNTICKET === $command && null === $this->flow->organizer($fromId)) {
            $this->flow->telegram()->sendMessage($chatId, TicketRedemptionService::noCancelText());

            return;
        }

        if (self::UNTICKET !== $command && null === $this->flow->organizerOrSay($chatId, $fromId, '/' . $command)) {
            return;
        }

        $args = CommandFlow::args($text);
        $date = '';

        if (in_array($command, [self::MARK, self::TICKET, self::UNTICKET], true)) {
            [$date, $args] = CommandFlow::takeDate($args);
        }

        $state = ['user' => $fromId, 'op' => $command, 'date' => $date, 'person' => 0];

        if (self::MARK === $command) {
            [$args, $state['status']] = self::markWord($args);
        }

        if (self::NEWCOMER === $command) {
            $this->startNewcomer($chatId, $fromId, $args, $state);

            return;
        }

        if ('' === $args) {
            $this->flow->telegram()->sendMessage($chatId, self::usage($command));

            return;
        }

        if (self::TICKET === $command || self::UNTICKET === $command) {
            $state['date'] = '' !== $date ? $date : (string) ($this->tasks->upcomingDates(1)[0] ?? '');

            if ('' === $state['date']) {
                $this->flow->telegram()->sendMessage($chatId, __('There are no upcoming tasks.', 'eventcrew'));

                return;
            }
        }

        $pool = $this->flow->members();
        $none = null;

        if (self::UNBAN === $command) {
            $pool = array_values(array_filter($pool, static fn (Person $p): bool => $p->isBanned()));
            /* translators: %s: the name the organizer typed. */
            $none = sprintf(__('No blocked member called “%s” was found.', 'eventcrew'), $args);
        }

        $found = $this->flow->findPeople($chatId, $args, $pool, $none);

        if (null === $found) {
            return;
        }

        $state['people'] = array_map(static fn (Person $p): int => $p->id, $found);
        $state['person'] = 1 === count($found) ? $found[0]->id : 0;
        $id = $this->flow->start($state);
        $this->next($chatId, $id, $state, $found);
    }

    /**
     * @param array<string, mixed> $callbackQuery
     */
    public function onCallback(array $callbackQuery): void
    {
        $tap = $this->flow->tap($callbackQuery);

        if (null === $tap) {
            return;
        }

        $telegram = $this->flow->telegram();
        $organizer = $this->flow->organizerTap($tap);

        if (null === $organizer) {
            return;
        }

        $telegram->answerCallbackQuery($tap['callback']);
        $state = $tap['state'];
        $chatId = $tap['chat'];
        $id = $tap['id'];
        $choice = $tap['choice'];

        switch ($tap['step']) {
            case 'p':
                if (! CommandFlow::offered($state, 'people', $choice)) {
                    return;
                }

                $state['person'] = $choice;
                $this->flow->save($id, $state);

                if (self::NEWCOMER === $state['op']) {
                    $this->flow->drop($id);
                    $this->newcomers->showRequest($tap['from'], $chatId, $choice);

                    return;
                }

                $this->next($chatId, $id, $state);

                return;

            case 't':
                if (! CommandFlow::offered($state, 'assignments', $choice)) {
                    return;
                }

                $state['assignment'] = $choice;
                $this->flow->save($id, $state);
                $this->next($chatId, $id, $state);

                return;

            case 's':
                if (! isset(self::MARKS[$choice])) {
                    return;
                }

                $state['status'] = self::MARKS[$choice];
                $this->flow->save($id, $state);
                $this->next($chatId, $id, $state);

                return;

            case 'y':
                $this->flow->drop($id);
                $this->apply($chatId, $state, $organizer);

                return;
        }
    }

    public static function usage(string $command): string
    {
        return match ($command) {
            self::MARK => __("Mark a task: /mark name ; arrived, late, noshow or done\nExample: /mark Milla ; late\nA date first picks another night: /mark 14.11. Milla ; done", 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            self::STANDING => __("Someone’s standing, credits and log: /standing name\nExample: /standing Milla", 'eventcrew'),
            self::TICKET => __("Free entry for a credit: /ticket name\nExample: /ticket Milla\nA date first picks another night: /ticket 21.11. Milla", 'eventcrew'),
            self::UNTICKET => __("Cancel a free-entry ticket: /unticket name\nExample: /unticket Milla\nA date first picks another night: /unticket 21.11. Milla", 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            self::UNBAN => __("Lift a block: /unban name\nExample: /unban Milla", 'eventcrew'),
            default => '',
        };
    }

    /**
     * Ask for what is still open (the member, the task, the mark), then show
     * the confirmation, or answer /standing.
     *
     * @param array<string, mixed> $state
     * @param list<Person>|null $found
     */
    private function next(int $chatId, string $id, array $state, ?array $found = null): void
    {
        if (0 === (int) $state['person']) {
            $found ??= array_values(array_filter(array_map(
                fn ($pid): ?Person => $this->people->find((int) $pid),
                (array) $state['people']
            )));
            $this->flow->choose($chatId, $id, 'p', __('Which member?', 'eventcrew'), CommandFlow::personOptions($found));

            return;
        }

        $person = $this->people->find((int) $state['person']);

        if (null === $person || $person->isAnonymized()) {
            $this->flow->drop($id);
            $this->flow->telegram()->sendMessage($chatId, __('That person was not found.', 'eventcrew'));

            return;
        }

        match ($state['op']) {
            self::STANDING => $this->showStanding($chatId, $id, $person),
            self::MARK => $this->nextMark($chatId, $id, $state, $person),
            self::TICKET, self::UNTICKET, self::UNBAN => $this->ask($chatId, $id, $state, $person),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $state
     */
    private function nextMark(int $chatId, string $id, array $state, Person $person): void
    {
        $telegram = $this->flow->telegram();

        if (0 === (int) ($state['assignment'] ?? 0)) {
            $today = (string) current_time('Y-m-d');
            $date = (string) $state['date'];

            if ('' !== $date && $date > $today) {
                $this->flow->drop($id);
                $telegram->sendMessage($chatId, __('A task can be marked on its day or later.', 'eventcrew'));

                return;
            }

            $rows = $this->markable($person->id, $date, $today);

            if ([] === $rows) {
                $this->flow->drop($id);
                $telegram->sendMessage(
                    $chatId,
                    '' === $date
                        /* translators: 1: a person's name, 2: number of days. */
                        ? sprintf(__('%1$s has no task in the last %2$d days. Put the date first: /mark 14.11. name', 'eventcrew'), $person->name(), self::MARK_DAYS)
                        /* translators: 1: a person's name, 2: a short day label, like "Sat 14 Nov". */
                        : sprintf(__('%1$s has no task on %2$s.', 'eventcrew'), $person->name(), Dates::dayLabel($date))
                );

                return;
            }

            if (count($rows) > 1) {
                $options = [];

                foreach ($rows as $row) {
                    $task = $this->tasks->find($row->taskId);
                    $options[$row->id] = null === $task ? '#' . $row->id : CommandFlow::taskLabel($task);
                }

                $state['assignments'] = array_keys($options);
                $this->flow->save($id, $state);
                $this->flow->choose($chatId, $id, 't', __('Which task?', 'eventcrew'), $options);

                return;
            }

            $state['assignment'] = $rows[0]->id;
            $this->flow->save($id, $state);
        }

        $assignment = $this->assignments->find((int) $state['assignment']);
        $task = null === $assignment ? null : $this->tasks->find($assignment->taskId);

        if (null === $assignment || null === $task) {
            $this->flow->drop($id);
            $telegram->sendMessage($chatId, __('That sign-up no longer exists. Send /roster again to see the current list.', 'eventcrew'));

            return;
        }

        $head = $person->name() . ' · ' . CommandFlow::taskLabel($task);

        if ('' === (string) ($state['status'] ?? '')) {
            $options = [];

            foreach (self::MARKS as $number => $status) {
                $options[$number] = AssignmentStatus::label($status);
            }

            /* translators: %s: the mark the task has now, like "Signed up". */
            $now = sprintf(__('Now: %s', 'eventcrew'), $assignment->statusLabel());
            $this->flow->choose($chatId, $id, 's', $head . "\n" . $now, $options, 2);

            return;
        }

        $this->flow->confirm(
            $chatId,
            $id,
            /* translators: %s: a mark, like "Arrived late". */
            $head . "\n" . sprintf(__('Mark as “%s”?', 'eventcrew'), AssignmentStatus::label((string) $state['status'])),
            __('Mark', 'eventcrew')
        );
    }

    /**
     * The sign-ups /mark may change: on $date, or without a date on the most
     * recent day within MARK_DAYS on which the person had a task.
     *
     * @return list<Assignment>
     */
    private function markable(int $personId, string $date, string $today): array
    {
        $ok = static fn (Assignment $a): bool => in_array($a->status, self::MARKABLE, true);

        if ('' !== $date) {
            return array_values(array_filter($this->assignments->forPersonOn($personId, $date), $ok));
        }

        $from = gmdate('Y-m-d', (int) strtotime($today . ' -' . self::MARK_DAYS . ' days'));
        $day = '';
        $rows = [];

        // Newest first.
        foreach ($this->assignments->historyFor($personId) as $entry) {
            $taskDate = (string) $entry['task_date'];

            if ($taskDate > $today || $taskDate < $from || ! $ok($entry['assignment'])) {
                continue;
            }

            if ('' !== $day && $taskDate !== $day) {
                break;
            }

            $day = $taskDate;
            $rows[] = $entry['assignment'];
        }

        return array_reverse($rows);
    }

    private function showStanding(int $chatId, string $id, Person $person): void
    {
        $this->flow->drop($id);
        $standing = $this->standing->for($person->id);
        $lines = [
            $person->name(),
            implode(' · ', [
                $standing->ratedSummary(),
                /* translators: %d: number of free-entry credits. */
                sprintf(_n('%d credit', '%d credits', $standing->creditBalance, 'eventcrew'), $standing->creditBalance)
                    . ExtraTaskCredits::pendingSuffix((new ExtraTaskCredits())->pendingFor($person->id)),
                /* translators: %d: number of tasks done. */
                sprintf(_n('%d task done', '%d tasks done', $standing->completedCount, 'eventcrew'), $standing->completedCount),
            ]),
        ];

        if ($person->isBanned()) {
            $lines[] = '⛔ ' . __('Blocked from sign-ups.', 'eventcrew');
        }

        if ($person->isWaitingForOk()) {
            $lines[] = '⏳ ' . __('Waiting for an OK.', 'eventcrew');
        }

        $tasks = [];

        foreach (array_slice($this->assignments->historyFor($person->id), 0, self::LOG_LINES) as $entry) {
            $task = $this->tasks->find($entry['assignment']->taskId);
            $tasks[] = '• ' . Dates::dayLabel((string) $entry['task_date']) . ' · '
                . (null === $task ? __('a task', 'eventcrew') : $task->roleLabel()) . ': '
                . $entry['assignment']->statusLabel();
        }

        if ([] !== $tasks) {
            $lines[] = '';
            $lines[] = __('Recent tasks:', 'eventcrew');
            array_push($lines, ...$tasks);
        }

        $credits = [];

        foreach ($this->grants->forPerson($person->id, self::LOG_LINES) as $grant) {
            $credits[(string) $grant['granted_at'] . '#g' . count($credits)] = '• ' . sprintf('%+d', $grant['credits']) . ' · '
                . Dates::dayLabel(substr((string) $grant['granted_at'], 0, 10))
                . ('' === $grant['note'] ? '' : ' · ' . $grant['note']);
        }

        foreach (array_slice($this->redemptions->forPerson($person->id), 0, self::LOG_LINES) as $redemption) {
            $credits[$redemption->redeemedAt . '#' . $redemption->id] = '• −1 · ' . sprintf(
                /* translators: %s: a short day label, like "Sat 14 Nov". */
                __('free entry %s', 'eventcrew'),
                null === $redemption->redeemedFor ? '' : Dates::dayLabel($redemption->redeemedFor)
            );
        }

        if ([] !== $credits) {
            krsort($credits);
            $lines[] = '';
            $lines[] = __('Credits:', 'eventcrew');
            array_push($lines, ...array_slice(array_values($credits), 0, self::LOG_LINES));
        }

        $this->flow->telegram()->sendMessage($chatId, implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function startNewcomer(int $chatId, int $fromId, string $args, array $state): void
    {
        $waiting = array_values(array_filter(
            $this->people->waitingNewcomers(),
            static fn (Person $p): bool => ! $p->isAnonymized()
        ));

        if ([] === $waiting) {
            $this->flow->telegram()->sendMessage($chatId, __('No newcomer is waiting for an OK.', 'eventcrew'));

            return;
        }

        if ('' !== $args) {
            /* translators: %s: the name the organizer typed. */
            $waiting = $this->flow->findPeople($chatId, $args, $waiting, sprintf(__('No newcomer called “%s” is waiting.', 'eventcrew'), $args));

            if (null === $waiting) {
                return;
            }
        }

        if (1 === count($waiting)) {
            $this->newcomers->showRequest($fromId, $chatId, $waiting[0]->id);

            return;
        }

        $waiting = array_slice($waiting, 0, CommandFlow::MAX_CHOICES);
        $state['people'] = array_map(static fn (Person $p): int => $p->id, $waiting);
        $id = $this->flow->start($state);
        $this->flow->choose($chatId, $id, 'p', __('Which newcomer?', 'eventcrew'), CommandFlow::personOptions($waiting));
    }

    /**
     * /ticket, /unticket or /unban for $person: the question with its ✅
     * label, or why it cannot be done.
     *
     * @param array<string, mixed> $state
     */
    private function ask(int $chatId, string $id, array $state, Person $person): void
    {
        $asked = $this->question($state, $person);

        if (! $asked['ok']) {
            $this->flow->drop($id);
            $this->flow->telegram()->sendMessage($chatId, $asked['text']);

            return;
        }

        $this->flow->save($id, $asked['state']);
        $this->flow->confirm($chatId, $id, $asked['text'], $asked['button']);
    }

    /**
     * The question before /ticket, /unticket or /unban changes anything, or
     * (ok false) the sentence why it cannot be done. The state gains what ✅
     * acts on (the ticket of /unticket).
     *
     * @param array<string, mixed> $state
     * @return array{ok: bool, text: string, button: string, state: array<string, mixed>}
     */
    private function question(array $state, Person $person): array
    {
        $date = (string) ($state['date'] ?? '');
        $day = Dates::dayLabel($date);
        $refuse = static fn (string $text): array => ['ok' => false, 'text' => $text, 'button' => '', 'state' => $state];

        if (self::UNBAN === $state['op']) {
            if (! $person->isBanned()) {
                /* translators: %s: a person's name. */
                return $refuse(sprintf(__('%s is not blocked.', 'eventcrew'), $person->name()));
            }

            return [
                'ok' => true,
                /* translators: %s: a person's name. */
                'text' => sprintf(__('Lift the block on %s? They can sign in and sign up again.', 'eventcrew'), $person->name()),
                'button' => __('Lift block', 'eventcrew'),
                'state' => $state,
            ];
        }

        if (self::UNTICKET === $state['op']) {
            $ticket = $this->ticketFor($person->id, $date);

            if (null === $ticket) {
                /* translators: 1: a person's name, 2: a short day label, like "Sat 14 Nov". */
                return $refuse(sprintf(__('%1$s has no free-entry ticket for %2$s.', 'eventcrew'), $person->name(), $day));
            }

            $state['redemption'] = $ticket->id;

            return [
                'ok' => true,
                /* translators: 1: a person's name, 2: a short day label, like "Sat 14 Nov". */
                'text' => sprintf(__('Cancel the free-entry ticket of %1$s for %2$s? The credit goes back.', 'eventcrew'), $person->name(), $day),
                'button' => __('Cancel ticket', 'eventcrew'),
                'state' => $state,
            ];
        }

        $balance = $this->standing->for($person->id)->creditBalance;

        if ($balance < 1) {
            return $refuse(CommandFlow::result(false, $person->name() . ' · ' . $day, CrewAdminRoster::noticeText('admin_door_no_credit')));
        }

        if (null !== $this->ticketFor($person->id, $date)) {
            return $refuse(CommandFlow::result(false, $person->name() . ' · ' . $day, CrewAdminRoster::noticeText('admin_door_already')));
        }

        return [
            'ok' => true,
            'text' => sprintf(
                /* translators: 1: a person's name, 2: a short day label, 3: credits they have now. */
                _n('Free entry for %1$s on %2$s? It uses 1 of their %3$d credit.', 'Free entry for %1$s on %2$s? It uses 1 of their %3$d credits.', $balance, 'eventcrew'),
                $person->name(),
                $day,
                $balance
            ),
            'button' => __('Give ticket', 'eventcrew'),
            'state' => $state,
        ];
    }

    /**
     * The Crew app's helper box (AssistantWebChanges): /ticket, /unticket or
     * /unban with its arguments, read as the bot reads them, for exactly one
     * member. The question to show with Confirm and the state to run, or (ok
     * false) the answer why not: no such member, several (the page has no
     * buttons to choose with), or the bot's own refusal.
     *
     * @return array{ok: bool, text: string, state: array<string, mixed>}
     */
    public function prepareWeb(string $command, string $args): array
    {
        $refuse = static fn (string $text): array => ['ok' => false, 'text' => $text, 'state' => []];

        if (! in_array($command, self::WEB, true)) {
            return $refuse(__('That can no longer be done.', 'eventcrew'));
        }

        $date = '';

        if (self::UNBAN !== $command) {
            [$date, $args] = CommandFlow::takeDate($args);
            $date = '' !== $date ? $date : (string) ($this->tasks->upcomingDates(1)[0] ?? '');

            if ('' === $date) {
                return $refuse(__('There are no upcoming tasks.', 'eventcrew'));
            }
        }

        if ('' === $args) {
            return $refuse(self::usage($command));
        }

        $pool = $this->flow->members();
        /* translators: %s: the name the organizer typed. */
        $none = sprintf(__('No member called “%s” was found.', 'eventcrew'), $args);

        if (self::UNBAN === $command) {
            $pool = array_values(array_filter($pool, static fn (Person $p): bool => $p->isBanned()));
            /* translators: %s: the name the organizer typed. */
            $none = sprintf(__('No blocked member called “%s” was found.', 'eventcrew'), $args);
        }

        $found = AssignCommand::matchPeople($args, $pool);

        if ([] === $found) {
            return $refuse($none);
        }

        if (count($found) > 1) {
            return $refuse(AssistantWebChanges::several(array_values(CommandFlow::personOptions($found))));
        }

        $state = ['user' => 0, 'op' => $command, 'date' => $date, 'person' => $found[0]->id];
        $asked = $this->question($state, $found[0]);

        return ['ok' => $asked['ok'], 'text' => $asked['text'], 'state' => $asked['state']];
    }

    /**
     * Confirm in the helper box: the state prepareWeb() gave, run as ✅ runs
     * it. "changed" only when the change really happened.
     *
     * @param array<string, mixed> $state
     * @return array{changed: bool, text: string}
     */
    public function runWeb(Person $organizer, array $state): array
    {
        if (! in_array($state['op'] ?? '', self::WEB, true)) {
            return ['changed' => false, 'text' => CrewAdminRoster::noticeText('admin_change_failed')];
        }

        $person = $this->people->find((int) ($state['person'] ?? 0));

        if (null === $person) {
            return ['changed' => false, 'text' => __('That person was not found.', 'eventcrew')];
        }

        $done = $this->change($state, $person, $organizer);

        return ['changed' => $done['done'], 'text' => CommandFlow::result($done['done'], $done['head'], $done['text'])];
    }

    /**
     * ✅: the change, through the same service as the crew page or roster.
     *
     * @param array<string, mixed> $state
     */
    private function apply(int $chatId, array $state, Person $organizer): void
    {
        $telegram = $this->flow->telegram();
        $person = $this->people->find((int) ($state['person'] ?? 0));

        if (null === $person) {
            $telegram->sendMessage($chatId, __('That person was not found.', 'eventcrew'));

            return;
        }

        switch ($state['op']) {
            case self::MARK:
                $assignment = $this->assignments->find((int) ($state['assignment'] ?? 0));
                $task = null === $assignment ? null : $this->tasks->find($assignment->taskId);
                $status = (string) ($state['status'] ?? '');

                if (null === $assignment || null === $task || $assignment->personId !== $person->id || ! isset(array_flip(self::MARKS)[$status])) {
                    $telegram->sendMessage($chatId, __('That sign-up no longer exists. Send /roster again to see the current list.', 'eventcrew'));

                    return;
                }

                $this->assignments->setStatus($assignment->id, $status, $organizer->id, MarkedBy::PERSON);
                $telegram->sendMessage($chatId, CommandFlow::result(
                    true,
                    $person->name() . ' · ' . CommandFlow::taskLabel($task),
                    match ($status) {
                        AssignmentStatus::ARRIVED => __('Marked arrived.', 'eventcrew'),
                        AssignmentStatus::COMPLETED => __('Marked completed.', 'eventcrew'),
                        AssignmentStatus::ARRIVED_LATE => __('Marked arrived late.', 'eventcrew'),
                        default => __('Marked no-show.', 'eventcrew'),
                    }
                ));

                return;

            case self::TICKET:
            case self::UNTICKET:
            case self::UNBAN:
                $done = $this->change($state, $person, $organizer);
                $telegram->sendMessage($chatId, CommandFlow::result($done['done'], $done['head'], $done['text']));

                return;
        }
    }

    /**
     * ✅ on /ticket, /unticket or /unban: the change through the crew page's
     * own service. "done" only when it really happened.
     *
     * @param array<string, mixed> $state
     * @return array{done: bool, head: string, text: string}
     */
    private function change(array $state, Person $person, Person $organizer): array
    {
        $day = Dates::dayLabel((string) ($state['date'] ?? ''));

        if (self::UNBAN === $state['op']) {
            $code = $this->peopleAdmin->unban($person->id);

            return ['done' => 'admin_person_unbanned' === $code, 'head' => $person->name(), 'text' => CrewAdminPeople::noticeText($code)];
        }

        $head = $person->name() . ' · ' . $day;

        if (self::TICKET === $state['op']) {
            $code = $this->roster->redeem((string) $state['date'], $person->id);

            return ['done' => 'admin_door_redeemed' === $code, 'head' => $head, 'text' => CrewAdminRoster::noticeText($code)];
        }

        $ticket = $this->redemptions->find((int) ($state['redemption'] ?? 0));

        if (null === $ticket || $ticket->personId !== $person->id || null !== $ticket->cancelledAt) {
            return ['done' => false, 'head' => $head, 'text' => CrewAdminRoster::noticeText('admin_change_failed')];
        }

        // ✅ only when the row really changed (CrewAdminRoster::unredeem()).
        $code = $this->roster->unredeem($ticket->id);

        return [
            'done' => in_array($code, ['admin_door_returned', 'admin_door_transfer_cancelled'], true),
            'head' => $head,
            'text' => CrewAdminRoster::noticeText($code),
        ];
    }

    /**
     * The person's free-entry ticket for $date, if any.
     */
    private function ticketFor(int $personId, string $date): ?Redemption
    {
        foreach ($this->redemptions->forPerson($personId) as $redemption) {
            if ($redemption->redeemedFor === $date && null === $redemption->cancelledAt) {
                return $redemption;
            }
        }

        return null;
    }

    /**
     * The mark word at the end of /mark ("; late" or a last word "late"),
     * and the rest.
     *
     * @return array{0: string, 1: string}
     */
    private static function markWord(string $args): array
    {
        if (str_contains($args, ';')) {
            [$name, $word] = array_map('trim', explode(';', $args, 2));

            return [$name, self::MARK_WORDS[mb_strtolower(str_replace(' ', '', $word))] ?? ''];
        }

        $words = preg_split('/\s+/u', $args) ?: [];
        $last = mb_strtolower((string) end($words));

        if (count($words) > 1 && isset(self::MARK_WORDS[$last])) {
            array_pop($words);

            return [implode(' ', $words), self::MARK_WORDS[$last]];
        }

        return [$args, ''];
    }
}
