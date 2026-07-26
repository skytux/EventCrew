<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\NotificationPreferences;
use EventCrew\Support\Roles;
use EventCrew\Support\SignedLink;
use EventCrew\Support\SignupService;
use EventCrew\Support\Standing;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\TicketList;
use EventCrew\Support\Turnstile;
use EventCrew\Support\WebSession;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\CalendarController;
use EventCrew\Telegram\TicketRedemptionService;

/**
 * Composes what the public signup board needs for a given (already resolved)
 * person: the open tasks grouped by event with occupancy and which are theirs,
 * their standing, any events a credit can be spent on, and the page's CSRF and
 * Turnstile bits.
 *
 * Pulled out of SignupController so the board's composition reads separately
 * from the request handling (session, dispatch, redirects). It takes the person
 * rather than reading the cookie itself, so it stays free of request state and
 * is testable without one.
 */
final class SignupBoardView
{
    /** How many finished tasks the profile lists. Enough to recognise, not a ledger. */
    private const HISTORY_LIMIT = 5;

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly StandingCalculator $standing,
        private readonly TicketRedemptionService $tickets,
        private readonly Turnstile $turnstile,
        private readonly SignupService $signup
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(?Person $person): array
    {
        $tasks = $this->tasks->upcoming();
        $occupancy = $this->tasks->occupancyFor(array_map(static fn (Task $t): int => $t->id, $tasks));
        $mine = null === $person ? [] : $this->occupiedTaskIds($person->id);
        $standing = null === $person ? null : $this->standing->for($person->id);

        // A person with a credit to spend is offered the events they can spend
        // it on, so the profile can redeem a free-entry ticket without the bot.
        $ticketDates = null !== $standing && $standing->creditBalance >= 1
            ? $this->tickets->eligibleDatesFor($person->id)
            : [];

        return [
            'person' => $person,
            'standing' => $standing,
            'csrf' => null === $person ? '' : WebSession::csrfToken($person->id),
            'groups' => $this->groupByEvent($tasks, $occupancy, $mine, $person),
            'telegram_group_link' => BoardService::groupLink(),
            'turnstile_site_key' => $this->turnstile->siteKey(),
            'ticket_dates' => $ticketDates,
            'notify_matrix' => null === $person ? [] : (new NotificationPreferences())->matrix($person),
            'my_tickets' => null === $person
                ? ['upcoming' => [], 'past' => []]
                : (new TicketList($this->assignments, $this->tasks, new RedemptionRepository()))->forPerson($person->id),
            // The person's own upcoming tasks, soonest first, so "when am I next
            // on?" is answered up front rather than by hunting the board.
            'my_upcoming' => null === $person ? [] : $this->upcomingFor($person->id),
            // What they have already done. Standing is built from exactly this,
            // so showing it turns a score into something a person can check.
            'my_history' => null === $person ? [] : $this->historyFor($person->id),
            // Account management (pause emails / delete data) reuses the signed
            // /manage endpoint the emails link to - the profile just posts to it.
            'manage_endpoint' => rest_url('eventcrew/v1/manage'),
            'manage_token' => null === $person ? '' : SignedLink::sign('manage', $person->id),
        ];
    }

    /**
     * The person's own upcoming tasks (occupying, today or later), soonest
     * first. Each carries an add-to-calendar link and the task id, so the
     * profile can surface "what am I next on" with a one-tap calendar hold.
     *
     * @return array<int, array{task_id: int, label: string, when: string, calendar_url: string}>
     */
    private function upcomingFor(int $personId): array
    {
        $today = (string) current_time('Y-m-d');
        $rows = [];

        foreach ($this->assignments->forPerson($personId) as $assignment) {
            if (! $assignment->isOccupying()) {
                continue;
            }

            $task = $this->tasks->find($assignment->taskId);

            if (null === $task || $task->taskDate < $today) {
                continue;
            }

            $time = $task->timeRange();

            $rows[] = [
                'sort' => $task->taskDate . ' ' . ($task->startsAt ?? ''),
                'task_id' => $task->id,
                'label' => $task->roleLabel() . ' · ' . $task->eventName(),
                'when' => '' === $time ? $task->taskDate : $task->taskDate . ' · ' . $time,
                'calendar_url' => CalendarController::url($task->id),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['sort'], $b['sort']));

        return array_map(
            static function (array $row): array {
                unset($row['sort']);

                return $row;
            },
            $rows
        );
    }

    /**
     * The person's finished tasks, most recent first, capped at a handful.
     *
     * Only outcomes that are actually settled: a task still to come is already
     * shown under "you're on next", and repeating it here as history would be
     * an odd thing to read.
     *
     * @return array<int, array{when: string, label: string, status: string}>
     */
    private function historyFor(int $personId): array
    {
        $today = (string) current_time('Y-m-d');
        $history = [];

        foreach ($this->assignments->historyFor($personId) as $entry) {
            if (count($history) >= self::HISTORY_LIMIT) {
                break;
            }

            $date = (string) $entry['task_date'];
            $assignment = $entry['assignment'];

            if ('' === $date || $date >= $today) {
                continue;
            }

            $task = $this->tasks->find($assignment->taskId);

            $history[] = [
                'when' => $date,
                'label' => null === $task ? '' : $task->roleLabel() . ' · ' . $task->eventName(),
                'status' => AssignmentStatus::label($assignment->status),
            ];
        }

        return $history;
    }

    /**
     * Task ids this person still occupies, so the board can show Drop rather
     * than Claim for them.
     *
     * @return array<int, bool> task id => true
     */
    private function occupiedTaskIds(int $personId): array
    {
        $ids = [];

        foreach ($this->assignments->forPerson($personId) as $assignment) {
            if ($assignment->isOccupying()) {
                $ids[$assignment->taskId] = true;
            }
        }

        return $ids;
    }

    /**
     * @param array<int, Task> $tasks
     * @param array<int, int> $occupancy
     * @param array<int, bool> $mine
     * @return array<int, array{title: string, tasks: array<int, array{task: Task, taken: int, mine: bool, blocked: string}>}>
     */
    private function groupByEvent(array $tasks, array $occupancy, array $mine, ?Person $person): array
    {
        $groups = [];

        // Asked once for the whole board: it is the same answer for every row.
        $personRefusal = null === $person ? '' : $this->signup->personRefusal($person->id);

        foreach ($tasks as $task) {
            $key = null !== $task->eventPostId
                ? 'e:' . $task->eventPostId
                : 'l:' . $task->eventLabel . '|' . $task->taskDate;

            if (! isset($groups[$key])) {
                $groups[$key] = ['title' => $task->eventName(), 'tasks' => []];
            }

            $groups[$key]['tasks'][] = [
                'task' => $task,
                'taken' => $occupancy[$task->id] ?? 0,
                'mine' => isset($mine[$task->id]),
                'blocked' => $this->blockedReason($task, $person, $personRefusal),
            ];
        }

        return array_values($groups);
    }

    /**
     * Why this person cannot take this task, or '' if they can.
     *
     * Both answers come from SignupService rather than being worked out again
     * here: the gate rule in particular is the sort that gets edited in one
     * place and silently disagreed with in another, and a board that offers a
     * button the claim will refuse is worse than one that never offered it.
     *
     * Only the two refusals that cost nothing per row. The third SignupService
     * knows about - an overlapping task - needs a query each to answer, which
     * is a lot of database work for a rare case, so it stays a message after
     * the press.
     */
    private function blockedReason(Task $task, ?Person $person, string $personRefusal): string
    {
        if (null === $person) {
            return '';
        }

        if (SignupService::blocksLeaderSlot($task, $person)) {
            return SignupService::LEADER_ONLY;
        }

        return $personRefusal;
    }
}
