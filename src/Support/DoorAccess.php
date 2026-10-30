<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\TaskRepository;

/**
 * Who may see the door side of an event night - who has a ticket, and later the
 * guest list - and which nights to offer.
 *
 * Organizers see every night. The night's leader and its Welcome crew see that
 * one night, because they are the people standing at the door checking names.
 */
final class DoorAccess
{
    /** Upcoming nights offered as buttons. */
    private const UPCOMING_CHOICES = 5;

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments
    ) {
    }

    public function canView(Person $person, string $date): bool
    {
        if ($person->isOrganizer) {
            return true;
        }

        foreach ($this->tasks->forDate($date) as $task) {
            if (! in_array($task->roleSlug, [Roles::LEADER_SLUG, Roles::WELCOME_SLUG], true)) {
                continue;
            }

            $assignment = $this->assignments->findFor($task->id, $person->id);

            if (null !== $assignment && $assignment->isOccupying()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The nearest upcoming day that has tasks, falling back to the most recent
     * past one - the same rule /roster and the wp-admin roster use. '' when no
     * task exists at all.
     */
    public function defaultDate(): string
    {
        $dates = $this->tasks->datesWithTasks(); // most recent first

        if ([] === $dates) {
            return '';
        }

        $upcoming = $this->upcoming($dates);

        return [] !== $upcoming ? $upcoming[0] : $dates[0];
    }

    /**
     * The nights to offer as buttons, oldest first: the most recent past night
     * (for a door list looked at the morning after) and the next few ahead.
     *
     * @return array<int, string>
     */
    public function nearbyDates(): array
    {
        $dates = $this->tasks->datesWithTasks();
        $upcoming = array_slice($this->upcoming($dates), 0, self::UPCOMING_CHOICES);
        $today = current_time('Y-m-d');

        foreach ($dates as $date) {
            if ($date < $today) {
                return array_merge([$date], $upcoming);
            }
        }

        return $upcoming;
    }

    /**
     * @param array<int, string> $dates most recent first
     * @return array<int, string> today and later, nearest first
     */
    private function upcoming(array $dates): array
    {
        $today = current_time('Y-m-d');

        return array_reverse(array_values(array_filter($dates, static fn (string $d): bool => $d >= $today)));
    }
}
