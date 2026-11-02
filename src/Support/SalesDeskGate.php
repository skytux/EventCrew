<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\TaskRepository;

/**
 * Who may open the sales desk (the door page) for a night, and until when.
 *
 * Organizers may, for any night, until 06:00 the morning after it. People on
 * that night's task of a role with "Sales desk" ticked in Settings (Welcome
 * ships with it) may from a few minutes before their own task starts, once
 * they are marked arrived, and only until the event ends. The same rule decides the automatic link, the /sales command, the button on the
 * member's web page, and - for the end time - every request the page makes.
 */
final class SalesDeskGate
{
    /** check() answers. */
    public const OK = 'ok';
    public const NOT_CREW = 'not_crew';
    public const NOT_YET = 'not_yet';
    public const CLOSED = 'closed';
    public const NO_NIGHT = 'no_night';

    /** Option and default for "minutes before their task" (sales-desk roles). */
    public const CREW_MINUTES_OPTION = 'eventcrew_door_link_welcome_minutes';
    public const DEFAULT_CREW_MINUTES = 10;

    /** Option and default for "ticket scans open N minutes before the event starts". */
    public const SCAN_OPEN_OPTION = 'eventcrew_door_scan_open_minutes';
    public const DEFAULT_SCAN_OPEN_MINUTES = 60;

    /** Option and default for "ticket scans close N minutes after the event ends". */
    public const SCAN_CLOSE_OPTION = 'eventcrew_door_scan_close_minutes';
    public const DEFAULT_SCAN_CLOSE_MINUTES = 0;

    /** The start used for a night where no task has a start time. */
    private const FALLBACK_START = '17:00:00';

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments
    ) {
    }

    public static function crewMinutes(): int
    {
        return max(0, (int) get_option(self::CREW_MINUTES_OPTION, self::DEFAULT_CREW_MINUTES));
    }

    public static function scanOpenMinutes(): int
    {
        return max(0, (int) get_option(self::SCAN_OPEN_OPTION, self::DEFAULT_SCAN_OPEN_MINUTES));
    }

    public static function scanCloseMinutes(): int
    {
        return max(0, (int) get_option(self::SCAN_CLOSE_OPTION, self::DEFAULT_SCAN_CLOSE_MINUTES));
    }

    /**
     * When ticket QR scans are taken on $date, as local "Y-m-d H:i:s" times.
     * 'opens' is the event's start minus the set minutes, or '' when the
     * start is not known (then there is no "too early"). 'closes' is the
     * event's end (endOf()) plus the set minutes.
     *
     * @return array{opens: string, closes: string}
     */
    public function scanWindow(string $date): array
    {
        $tasks = $this->tasks->forDate($date);
        $start = $this->startOf($tasks);

        return [
            'opens' => '' === $start
                ? ''
                : gmdate('Y-m-d H:i:s', self::toTime($start) - self::scanOpenMinutes() * MINUTE_IN_SECONDS),
            'closes' => gmdate(
                'Y-m-d H:i:s',
                self::toTime($this->endOf($date, $tasks)) + self::scanCloseMinutes() * MINUTE_IN_SECONDS
            ),
        ];
    }

    /**
     * When the event of a night starts, as a local "Y-m-d H:i:s": the linked
     * EventMesh event's start, when a task that night is linked to one; else
     * the earliest start of the night's sales-desk tasks; else ''.
     *
     * @param array<int, Task> $tasks the night's tasks
     */
    public function startOf(array $tasks): string
    {
        foreach ($tasks as $task) {
            if (null === $task->eventPostId) {
                continue;
            }

            $event = EventSource::describe($task->eventPostId);

            if (null !== $event && '' !== $event['starts_at']) {
                return $event['starts_at'];
            }
        }

        $starts = [];

        foreach ($tasks as $task) {
            if (null !== $task->startsAt && Roles::opensSalesDesk($task->roleSlug)) {
                $starts[] = $task->startsAt;
            }
        }

        return [] === $starts ? '' : min($starts);
    }

    /**
     * The next event night: the nearest day, today or later, that has tasks.
     * '' when there is none.
     */
    public function nextNight(): string
    {
        $today = (string) current_time('Y-m-d');
        $upcoming = array_filter($this->tasks->datesWithTasks(), static fn (string $d): bool => $d >= $today);

        return [] === $upcoming ? '' : (string) min($upcoming);
    }

    /**
     * Whether $person may open the sales desk for $date right now: one of the
     * class constants.
     */
    public function check(Person $person, string $date): string
    {
        if ('' === $date) {
            return self::NO_NIGHT;
        }

        $now = (string) current_time('mysql');

        if ($person->isOrganizer) {
            return $now < DoorSession::expiryFor($date) ? self::OK : self::CLOSED;
        }

        $tasks = $this->tasks->forDate($date);
        $onDoor = false;

        foreach ($tasks as $task) {
            if (! self::isDoorRole($task->roleSlug)) {
                continue;
            }

            $assignment = $this->assignments->findFor($task->id, $person->id);

            if (null === $assignment || ! $assignment->isOccupying()) {
                continue;
            }

            $onDoor = true;

            if (self::hasArrived($assignment->status) && $now >= $this->opensAt($task, $tasks)) {
                return $now < $this->endOf($date, $tasks) ? self::OK : self::CLOSED;
            }
        }

        return $onDoor ? self::NOT_YET : self::NOT_CREW;
    }

    /**
     * Whether a door session for $person on $date is still good right now.
     * Asked on every request the page makes, with the person read fresh, so
     * someone made or unmade an organizer moves to the other lifetime at once.
     */
    public function sessionOpen(Person $person, string $date): bool
    {
        $now = (string) current_time('mysql');

        if ($now >= DoorSession::expiryFor($date)) {
            return false;
        }

        return $person->isOrganizer || $now < $this->endOf($date);
    }

    /**
     * Seconds a fresh link or session for $person on $date has left; 0 when
     * none. The token and the browser cookie are given this lifetime.
     */
    public function secondsLeft(Person $person, string $date): int
    {
        $until = DoorSession::expiryFor($date);

        if (! $person->isOrganizer) {
            $until = min($until, $this->endOf($date));
        }

        return max(0, self::toTime($until) - self::toTime((string) current_time('mysql')));
    }

    /**
     * When the event on $date ends, as a local "Y-m-d H:i:s". In order: the
     * linked EventMesh event's end, when a task that night is linked to one;
     * else the start of the night's end-anchored task (Clean starts when the
     * event ends); else the latest task end. When none of these is known, the
     * night runs until 06:00 the morning after, like an organizer's session.
     *
     * @param array<int, Task>|null $tasks the night's tasks, when the caller has them
     */
    public function endOf(string $date, ?array $tasks = null): string
    {
        $tasks ??= $this->tasks->forDate($date);

        foreach ($tasks as $task) {
            if (null === $task->eventPostId) {
                continue;
            }

            $event = EventSource::describe($task->eventPostId);

            if (null !== $event && '' !== $event['ends_at']) {
                return $event['ends_at'];
            }
        }

        $anchored = [];
        $ends = [];

        foreach ($tasks as $task) {
            $role = Roles::find($task->roleSlug);

            if (null !== $task->startsAt && null !== $role && Roles::ANCHOR_END === $role['anchor']) {
                $anchored[] = $task->startsAt;
            }

            if (null !== $task->endsAt) {
                $ends[] = $task->endsAt;
            }
        }

        if ([] !== $anchored) {
            return min($anchored);
        }

        return [] !== $ends ? max($ends) : DoorSession::expiryFor($date);
    }

    /**
     * When a door task's link becomes available: its start minus the set
     * minutes. A task with no start time counts as starting at the night's
     * first start, or 17:00 when no task has one.
     *
     * @param array<int, Task> $tasks the night's tasks
     */
    public function opensAt(Task $task, array $tasks): string
    {
        $start = $task->startsAt;

        if (null === $start) {
            $starts = array_filter(array_map(static fn (Task $t): ?string => $t->startsAt, $tasks));
            $start = [] === $starts ? $task->taskDate . ' ' . self::FALLBACK_START : min($starts);
        }

        return gmdate('Y-m-d H:i:s', self::toTime($start) - self::crewMinutes() * MINUTE_IN_SECONDS);
    }

    /**
     * The plain sentence for a refusal.
     */
    public static function message(string $code): string
    {
        return match ($code) {
            self::NOT_YET => sprintf(
                /* translators: %d: minutes */
                __('The sales desk link opens %d minutes before your task, once you are marked arrived.', 'eventcrew'),
                self::crewMinutes()
            ),
            self::CLOSED => __('The sales desk for this night has closed.', 'eventcrew'),
            self::NO_NIGHT => __('There is no upcoming event night yet.', 'eventcrew'),
            default => __('Only organizers and tonight’s welcome crew can open it.', 'eventcrew'),
        };
    }

    /**
     * Whether people on a task of this role open the sales desk: the role's
     * "Sales desk" setting.
     */
    public static function isDoorRole(string $slug): bool
    {
        return Roles::opensSalesDesk($slug);
    }

    public static function hasArrived(string $status): bool
    {
        return in_array($status, [AssignmentStatus::ARRIVED, AssignmentStatus::ARRIVED_LATE], true);
    }

    /** A naive local datetime as seconds, worked in UTC like every other one here. */
    private static function toTime(string $datetime): int
    {
        return (int) strtotime($datetime . ' UTC');
    }
}
