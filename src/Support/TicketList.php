<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;

/**
 * A person's door tickets - the rostered-slot ones and the free-entry credit
 * ones - split into upcoming and past, each with its signed ticket link.
 *
 * Shared by the bot's /mytickets and the web profile so the two show the same
 * list. Dependency-light (three stateless repositories), so it is newed where
 * needed rather than threaded through a constructor.
 */
final class TicketList
{
    public function __construct(
        private readonly AssignmentRepository $assignments,
        private readonly TaskRepository $tasks,
        private readonly RedemptionRepository $redemptions
    ) {
    }

    /**
     * @return array{upcoming: array<int, array{label: string, when: string, url: string}>, past: array<int, array{label: string, when: string, url: string}>}
     */
    public function forPerson(int $personId): array
    {
        $today = (string) current_time('Y-m-d');
        $upcoming = [];
        $past = [];

        foreach ($this->assignments->forPerson($personId) as $assignment) {
            // A ticket exists for a slot they hold or held; a cancelled/replaced
            // slot never became one.
            if (! in_array($assignment->status, self::TICKETED_STATUSES, true)) {
                continue;
            }

            $task = $this->tasks->find($assignment->taskId);

            if (null === $task) {
                continue;
            }

            $item = [
                'label' => $task->roleLabel() . ' · ' . $task->eventName(),
                'when' => $task->taskDate,
                'url' => $this->url('ticket', $assignment->id),
            ];

            $task->taskDate >= $today ? $upcoming[] = $item : $past[] = $item;
        }

        foreach ($this->redemptions->forPerson($personId) as $redemption) {
            if (null === $redemption->redeemedFor) {
                continue;
            }

            $item = [
                'label' => __('Free entry', 'eventcrew'),
                'when' => $redemption->redeemedFor,
                'url' => $this->url('credit_ticket', $redemption->id),
            ];

            $redemption->redeemedFor >= $today ? $upcoming[] = $item : $past[] = $item;
        }

        usort($upcoming, static fn (array $a, array $b): int => strcmp($a['when'], $b['when']));
        usort($past, static fn (array $a, array $b): int => strcmp($b['when'], $a['when']));

        return ['upcoming' => $upcoming, 'past' => $past];
    }

    /** @var array<int, string> assignment statuses that carry a door ticket */
    private const TICKETED_STATUSES = [
        AssignmentStatus::SIGNED_UP,
        AssignmentStatus::ARRIVED,
        AssignmentStatus::COMPLETED,
        AssignmentStatus::NO_SHOW,
    ];

    private function url(string $purpose, int $id): string
    {
        return add_query_arg(
            ['token' => SignedLink::sign($purpose, $id)],
            rest_url('eventcrew/v1/ticket')
        );
    }
}
