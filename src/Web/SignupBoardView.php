<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CheckinRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\BoardVisibility;
use EventCrew\Support\Dates;
use EventCrew\Support\EventName;
use EventCrew\Support\ExtraTaskCredits;
use EventCrew\Support\NotificationPreferences;
use EventCrew\Support\Paging;
use EventCrew\Support\SignedLink;
use EventCrew\Support\SignupService;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\TakeoverService;
use EventCrew\Support\TicketList;
use EventCrew\Support\TicketMail;
use EventCrew\Support\TicketTransferService;
use EventCrew\Support\Turnstile;
use EventCrew\Support\WebSession;
use EventCrew\Telegram\BoardBanner;
use EventCrew\Telegram\BoardRenderer;
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
    /**
     * The query arguments that carry the page of the Tasks and Past tasks
     * cards (Paging, Owner 2026-11-08), one per list so paging one keeps the
     * other where it was.
     */
    public const TASKS_PAGE_ARG = 'tasks_page';
    public const PAST_PAGE_ARG = 'past_page';

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly StandingCalculator $standing,
        private readonly TicketRedemptionService $tickets,
        private readonly Turnstile $turnstile,
        private readonly SignupService $signup,
        // Optional: with it a ticket given away reads "Given to …", as in
        // the bot's Tickets screen.
        private readonly ?TicketTransferService $transfers = null,
        // Optional: with it a full task offers Take someone's task.
        private readonly ?TakeoverService $takeovers = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(?Person $person, int $tasksPage = 1, int $pastPage = 1): array
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

        // Paged inside the array below, so the queries keep the order they
        // had before paging (the tests' scripted database relies on it).
        $upcoming = [];
        $history = [];

        return [
            'person' => $person,
            'standing' => $standing,
            // Credits for extra tasks on the same night, not yet added.
            'pending_credits' => null === $person ? 0 : (new ExtraTaskCredits())->pendingFor($person->id),
            'csrf' => null === $person ? '' : WebSession::csrfToken($person->id),
            'groups' => $this->groupByEvent($tasks, $occupancy, $mine, $person),
            // Signed out, the sign-in card wears the banner the Telegram
            // board message carries (BoardBanner); '' when there is none.
            'signin_banner' => null === $person ? BoardBanner::url() : '',
            'telegram_group_link' => BoardService::groupLink(),
            'turnstile_site_key' => $this->turnstile->siteKey(),
            'ticket_dates' => $ticketDates,
            'notify_matrix' => null === $person ? [] : (new NotificationPreferences())->matrix($person),
            'my_tickets' => null === $person
                ? ['upcoming' => [], 'past' => []]
                // Every ticket, task tickets too, the same list as the bot's
                // Tickets screen (Owner 2026-11-08: web and Telegram alike).
                : $this->withGiving((new TicketList(
                    $this->assignments,
                    $this->tasks,
                    new RedemptionRepository(),
                    new CheckinRepository(),
                    $this->transfers
                ))->forPerson($person->id), $person->id),
            // The person's own upcoming tasks, soonest first, so "when am I next
            // on?" is answered up front rather than by hunting the board.
            'my_upcoming' => ($upcoming = Paging::slice(
                null === $person ? [] : $this->upcomingFor($person->id),
                $tasksPage
            ))['items'],
            'my_upcoming_paging' => ['page' => $upcoming['page'], 'pages' => $upcoming['pages']],
            // What they have already done, all of it like the bot's Past
            // tasks (Owner 2026-11-08), a page at a time. Standing is built
            // from exactly this, so showing it turns a score into something a
            // person can check.
            'my_history' => ($history = Paging::slice(
                null === $person ? [] : $this->historyFor($person->id),
                $pastPage
            ))['items'],
            'my_history_paging' => ['page' => $history['page'], 'pages' => $history['pages']],
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
     * @return array<int, array{task_id: int, label: string, dj: string, when: string, calendar_url: string, ticket_url: string, cancel_ask: string, used: ?string}>
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
            $day = self::humanDate($task->taskDate);
            $event = EventName::ofTask($task, EventName::lang());

            $rows[] = [
                'sort' => $task->taskDate . ' ' . ($task->startsAt ?? ''),
                'date' => $task->taskDate,
                'task_id' => $task->id,
                'label' => $task->roleLabel() . ' · ' . $event['name'],
                'dj' => $event['dj'],
                'when' => '' === $time ? $day : $day . ' · ' . $time,
                'calendar_url' => CalendarController::url($task->id),
                'ticket_url' => TicketMail::workUrl($personId, $task->taskDate),
                // Cancel sits on each row too, asking what the board's
                // Cancel asks (Owner 2026-11-08).
                'cancel_ask' => $this->cancelAsk($task, $personId),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['sort'], $b['sort']));

        // When the door marked each task's ticket in: one read for them all.
        $times = [] === $rows ? [] : (new CheckinRepository())->personTimes($personId);

        return array_map(
            static function (array $row) use ($times): array {
                $row['used'] = CheckinRepository::inAtFrom($times, $row['date'], CheckinRepository::TASK_TICKET_KINDS);
                unset($row['sort'], $row['date']);

                return $row;
            },
            $rows
        );
    }

    /**
     * The person's finished tasks, most recent first, every one of them (the
     * card pages them, Paging), each with the status word the bot's Past
     * tasks shows (AssignmentStatus::label(), which Assignment::statusLabel()
     * also returns).
     *
     * Only outcomes that are actually settled: a task still to come is already
     * shown under "you're on next", and repeating it here as history would be
     * an odd thing to read.
     *
     * @return array<int, array{when: string, label: string, dj: string, status: string}>
     */
    private function historyFor(int $personId): array
    {
        $today = (string) current_time('Y-m-d');
        $history = [];

        foreach ($this->assignments->historyFor($personId) as $entry) {
            $date = (string) $entry['task_date'];
            $assignment = $entry['assignment'];

            if ('' === $date || $date >= $today) {
                continue;
            }

            $task = $this->tasks->find($assignment->taskId);
            $event = null === $task ? ['name' => '', 'dj' => ''] : EventName::ofTask($task, EventName::lang());

            $history[] = [
                'when' => self::humanDate($date),
                'label' => null === $task ? '' : $task->roleLabel() . ' · ' . $event['name'],
                'dj' => $event['dj'],
                'status' => AssignmentStatus::label($assignment->status),
            ];
        }

        return $history;
    }

    /**
     * The address of page $page of the list $arg names (TASKS_PAGE_ARG or
     * PAST_PAGE_ARG), keeping the other list's page and ending in #$anchor,
     * the card's id: the Me tab opens on it, and without JavaScript the page
     * scrolls to it. Page 1 leaves its argument out.
     *
     * @param array<string, int> $pages Each list's current page, by argument.
     */
    public static function pageUrl(string $here, array $pages, string $arg, int $page, string $anchor): string
    {
        $pages[$arg] = $page;
        $url = remove_query_arg(array_keys($pages), $here);
        $keep = array_filter($pages, static fn (int $p): bool => $p > 1);

        if ([] !== $keep) {
            $url = add_query_arg(array_map('strval', $keep), $url);
        }

        return $url . '#' . $anchor;
    }

    /**
     * A Y-m-d date as the page shows dates to people: "Sat 7 Nov", in the
     * site's language. The board and the Me tab use the same form, so a task
     * reads the same in both places. Anything that is not a date comes back
     * unchanged.
     */
    public static function humanDate(string $date): string
    {
        return false === strtotime($date) ? $date : Dates::dayLabel($date);
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
     * @return array<int, array{title: string, dj: string, image: string, tasks: array<int, array{task: Task, taken: int, mine: bool, blocked: string, role_only: bool, crew_only: bool, holders: list<array{assignment_id: int, name: string}>, names: list<string>}>}>
     */
    private function groupByEvent(array $tasks, array $occupancy, array $mine, ?Person $person): array
    {
        $groups = [];

        // Asked once for the whole board: it is the same answer for every row.
        $personRefusal = null === $person ? '' : $this->signup->personRefusal($person->id);

        // Who is signed up, by first name, for someone signed in whom the
        // team has let in (Owner 2026-11-07); per row only where the role does
        // not hide names from them (Anonymous display). One read for all.
        $named = null !== $person && ! $person->isBanned() && '' === SignupService::approvalRefusal($person);
        $occupants = [];

        if ($named) {
            $visible = array_filter($tasks, static fn (Task $t): bool => BoardVisibility::visibleTo($t, $person));
            $occupants = $this->tasks->occupantsFor(array_map(static fn (Task $t): int => $t->id, $visible));
        }

        foreach ($tasks as $task) {
            // Signed out, the board shows what the Telegram group board shows.
            // Signed in, also the tasks of the person's roles and of the roles
            // ticked Members display; organizers see every task (BoardVisibility).
            if (! BoardVisibility::visibleTo($task, $person)) {
                continue;
            }

            $key = null !== $task->eventPostId
                ? 'e:' . $task->eventPostId
                : 'l:' . $task->eventLabel . '|' . $task->taskDate;

            if (! isset($groups[$key])) {
                $event = EventName::ofTask($task, EventName::lang());
                $groups[$key] = [
                    'title' => $event['name'],
                    'dj' => $event['dj'],
                    'image' => self::eventImage($task->eventPostId),
                    'tasks' => [],
                ];
            }

            $groups[$key]['tasks'][] = [
                'task' => $task,
                'taken' => $occupancy[$task->id] ?? 0,
                'mine' => isset($mine[$task->id]),
                // What Cancel asks first, with what it costs (the bot's
                // words); '' when a cancel would not go through anyway.
                'cancel_ask' => isset($mine[$task->id]) && null !== $person ? $this->cancelAsk($task, $person->id) : '',
                'blocked' => $this->blockedReason($task, $person, $personRefusal),
                // An organizer sees a restricted task of a role they do not
                // hold, with a disabled "DJ only" button.
                'role_only' => BoardVisibility::roleOnly($task, $person),
                // A guest on a role with Guest sign up off, or a member
                // shown a restricted task by Members display, sees the task
                // and its places, with no Sign up (Owner 2026-11-07).
                'crew_only' => BoardVisibility::crewOnly($task, $person),
                // Whose places the person could ask to take over, on a full
                // task they are not on (Owner 2026-11-08); [] otherwise.
                'holders' => $this->holders($task, $occupancy[$task->id] ?? 0, isset($mine[$task->id]), $person),
                'names' => $named && BoardVisibility::showsNames($task, $person)
                    ? BoardRenderer::firstNames($occupants[$task->id] ?? [])
                    : [],
            ];
        }

        return array_values($groups);
    }

    /**
     * Marks the upcoming free-entry tickets that can be given away
     * ('give' => true) or wait on an offer ('offer_to' => the name), as the
     * bot's Tickets screen does (Owner 2026-11-08). Only a credit ticket has
     * a redemption_id; TicketTransferService decides the rest. A waiting
     * offer is shown even while giving is off, so it can still be cancelled.
     *
     * @param array{upcoming: list<array<string, mixed>>, past: list<array<string, mixed>>} $tickets
     * @return array{upcoming: list<array<string, mixed>>, past: list<array<string, mixed>>}
     */
    private function withGiving(array $tickets, int $personId): array
    {
        if (null === $this->transfers) {
            return $tickets;
        }

        foreach ($tickets['upcoming'] as $i => $ticket) {
            $redemptionId = (int) ($ticket['redemption_id'] ?? 0);

            if ($redemptionId <= 0) {
                continue;
            }

            $offer = $this->transfers->pendingOffer($redemptionId);

            if (null !== $offer) {
                $tickets['upcoming'][$i]['offer_to'] = $offer->offeredTo();
            } elseif (TicketTransferService::isOn()) {
                $tickets['upcoming'][$i]['give'] = $this->transfers->canGive(
                    $this->transfers->redemption($redemptionId),
                    $personId
                );
            }
        }

        return $tickets;
    }

    /**
     * The holders a full task's Take someone's task lists, for a signed-in
     * person who is not on it (TakeoverService::holdersFor()).
     *
     * @return list<array{assignment_id: int, name: string}>
     */
    private function holders(Task $task, int $taken, bool $mine, ?Person $person): array
    {
        if (null === $this->takeovers || null === $person || $mine || $task->hasNoLimit() || $taken < $task->capacity) {
            return [];
        }

        return $this->takeovers->holdersFor($task, $person);
    }

    /**
     * The question the Cancel button asks first, saying whether the cancel
     * would be late, or '' when there is nothing a cancel would record.
     */
    private function cancelAsk(Task $task, int $personId): string
    {
        $outcome = $this->signup->dropOutcome($personId, $task->id);

        if (! in_array($outcome, [AssignmentStatus::CANCELLED, AssignmentStatus::LATE_CANCEL], true)) {
            return '';
        }

        return BoardService::cancelQuestion($task, AssignmentStatus::LATE_CANCEL === $outcome);
    }

    /**
     * The event post's featured image, drawn behind the event's card header.
     *
     * '' when the task has no event post or the post has no featured image:
     * the header is then plain, as before.
     */
    public static function eventImage(?int $eventPostId): string
    {
        if (null === $eventPostId || ! function_exists('get_the_post_thumbnail_url')) {
            return '';
        }

        $url = get_the_post_thumbnail_url($eventPostId, 'large');

        return is_string($url) ? $url : '';
    }

    /**
     * Why this person cannot take this task, or '' if they can.
     *
     * The answer comes from SignupService rather than being worked out again
     * here: the gate rule is the sort that gets edited in one place and
     * silently disagreed with in another, and a board that offers a button the
     * claim will refuse is worse than one that never offered it.
     *
     * The gate is now the only refusal shown here - an overlapping task was
     * dropped along with its rule, and restricted tasks are left off the
     * board - and it costs nothing per row to answer.
     */
    private function blockedReason(Task $task, ?Person $person, string $personRefusal): string
    {
        if (null === $person) {
            return '';
        }

        return $personRefusal;
    }
}
