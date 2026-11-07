<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CheckinRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;

/**
 * A person's door tickets - the rostered-slot ones and the free-entry credit
 * ones - split into upcoming and past, each with its signed ticket link and
 * when the door marked it in.
 *
 * Shared by the bot's /me tickets and the web profile so the two show the same
 * list. Dependency-light (stateless repositories), so it is newed where
 * needed rather than threaded through a constructor.
 */
final class TicketList
{
    public function __construct(
        private readonly AssignmentRepository $assignments,
        private readonly TaskRepository $tasks,
        private readonly RedemptionRepository $redemptions,
        private readonly CheckinRepository $checkins = new CheckinRepository(),
        private readonly ?TicketTransferService $transfers = null
    ) {
    }

    /**
     * "Already used · 21:05": what a ticket the door has marked in says, on
     * the ticket page and in the ticket lists. $checkedInAt is site time.
     */
    public static function usedText(string $checkedInAt): string
    {
        return sprintf(
            /* translators: %s: the time the ticket was checked in at the door, like 21:05 */
            __('Already used · %s', 'eventcrew'),
            Dates::wallClock($checkedInAt, 'H:i')
        );
    }

    /**
     * $withTasks false leaves out the tickets that come with a task, for the web
     * page's Me tab, which shows each of those on its task's own row. 'used'
     * is when the door marked the ticket in (site time), null while it is not.
     *
     * @return array{upcoming: array<int, array{label: string, when: string, url: string, used: ?string}>, past: array<int, array{label: string, when: string, url: string, used: ?string}>}
     */
    public function forPerson(int $personId, bool $withTasks = true): array
    {
        $today = (string) current_time('Y-m-d');
        $upcoming = [];
        $past = [];

        foreach ($withTasks ? $this->assignments->forPerson($personId) : [] as $assignment) {
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
                'url' => self::taskTicketUrl($assignment->id),
                'kinds' => CheckinRepository::TASK_TICKET_KINDS,
            ];

            $task->taskDate >= $today ? $upcoming[] = $item : $past[] = $item;
        }

        foreach ($this->redemptions->forPerson($personId) as $redemption) {
            if (null === $redemption->redeemedFor) {
                continue;
            }

            $moved = null === $this->transfers ? null : $this->transfers->movedTo($redemption);
            $item = [
                'label' => null === $moved
                    ? __('Free entry', 'eventcrew')
                    : __('Free entry', 'eventcrew') . ' · ' . sprintf(
                        /* translators: %s: the name of the person the ticket was given to */
                        __('Given to %s', 'eventcrew'),
                        $this->transfers?->receiverName($moved)
                    ),
                'when' => $redemption->redeemedFor,
                'url' => self::url('credit_ticket', $redemption->id),
                'kinds' => CheckinRepository::CREDIT_TICKET_KINDS,
                'redemption_id' => $redemption->id,
            ];

            $redemption->redeemedFor >= $today ? $upcoming[] = $item : $past[] = $item;
        }

        // The tickets other members gave them. Each is checked in under its
        // own row (CheckinRepository::KIND_TRANSFER), so its time is read here.
        foreach (null === $this->transfers ? [] : $this->transfers->heldBy($personId) as $transfer) {
            $from = $this->transfers->nameOf($transfer->giverPersonId);
            $item = [
                'label' => '' === $from
                    ? __('Free entry', 'eventcrew')
                    /* translators: %s: the name of the member who gave the ticket away */
                    : sprintf(__('Free entry · from %s', 'eventcrew'), $from),
                'when' => $transfer->eventDate,
                'url' => TicketMail::transferUrl($transfer->id),
                'kinds' => [],
                'used' => $this->checkins->inAt($transfer->eventDate, [CheckinRepository::KIND_TRANSFER], $transfer->id),
            ];

            $transfer->eventDate >= $today ? $upcoming[] = $item : $past[] = $item;
        }

        // When the door marked each ticket in, under the check-in kinds it is
        // marked as: one read answers every ticket.
        $times = [] === $upcoming && [] === $past ? [] : $this->checkins->personTimes($personId);
        $fill = static function (array $item) use ($times): array {
            $item['used'] = array_key_exists('used', $item)
                ? $item['used']
                : CheckinRepository::inAtFrom($times, $item['when'], $item['kinds']);
            unset($item['kinds']);

            return $item;
        };
        $upcoming = array_map($fill, $upcoming);
        $past = array_map($fill, $past);

        usort($upcoming, static fn (array $a, array $b): int => strcmp($a['when'], $b['when']));
        usort($past, static fn (array $a, array $b): int => strcmp($b['when'], $a['when']));

        return ['upcoming' => $upcoming, 'past' => $past];
    }

    /** @var array<int, string> assignment statuses that carry a door ticket */
    private const TICKETED_STATUSES = [
        AssignmentStatus::SIGNED_UP,
        AssignmentStatus::ARRIVED,
        AssignmentStatus::COMPLETED,
        AssignmentStatus::ARRIVED_LATE,
        AssignmentStatus::NO_SHOW,
    ];

    /** The signed ticket link for one sign-up (assignment). */
    public static function taskTicketUrl(int $assignmentId): string
    {
        return self::url('ticket', $assignmentId);
    }

    private static function url(string $purpose, int $id): string
    {
        return add_query_arg(
            ['token' => SignedLink::sign($purpose, $id)],
            rest_url('eventcrew/v1/ticket')
        );
    }
}
