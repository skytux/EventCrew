<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Repositories\TaskRepository;

/**
 * Which event dates are closed to free entry - a sold-out night, or a special
 * event that earned credits can't be spent on.
 *
 * Free entry is keyed on the event date throughout (the door list, the
 * redemptions table), so closing is too: a small set of Y-m-d strings in one
 * option. Both the organizer's "Redeem a credit" control and the self-service
 * 🎟 ticket flow consult this before letting a credit be spent.
 *
 * A date also closes on its own when a task that night is linked to an
 * EventMesh event that stopped selling (see EventSource::closedReason()).
 * That only blocks credits: signing up for a task stays open, so crew can
 * still join a sold-out night.
 */
final class FreeEntryGate
{
    /** The set of closed dates: an array of 'Y-m-d' strings. */
    public const OPTION = 'eventcrew_ticket_closed_dates';

    /** @var array<string, string> Event reasons already looked up, by date. */
    private array $eventReasons = [];

    public function __construct(private readonly ?TaskRepository $tasks = null)
    {
    }

    public function isClosed(string $date): bool
    {
        return $this->isClosedByHand($date) || '' !== $this->eventReason($date);
    }

    /**
     * Whether an organizer closed the date - the part close() and open()
     * change, as opposed to a linked event closing it.
     */
    public function isClosedByHand(string $date): bool
    {
        return in_array($date, $this->closedDates(), true);
    }

    /**
     * Why a linked event closes the date ('unpublished', 'disabled', 'hidden',
     * 'sold_out'), or '' when no linked event does.
     */
    public function eventReason(string $date): string
    {
        if (null === $this->tasks) {
            return '';
        }

        if (! isset($this->eventReasons[$date])) {
            $this->knowTasks($date, $this->tasks->forDate($date));
        }

        return $this->eventReasons[$date];
    }

    /**
     * The date's tasks, read by a caller that had them already (several
     * dates in one query), so eventReason() does not read them again.
     *
     * @param array<int, \EventCrew\Models\Task> $tasks
     */
    public function knowTasks(string $date, array $tasks): void
    {
        if (isset($this->eventReasons[$date])) {
            return;
        }

        $reason = '';

        foreach ($tasks as $task) {
            $reason = EventSource::closedReason((int) $task->eventPostId);

            if ('' !== $reason) {
                break;
            }
        }

        $this->eventReasons[$date] = $reason;
    }

    public function close(string $date): void
    {
        $dates = $this->closedDates();

        if (! in_array($date, $dates, true)) {
            $dates[] = $date;
            update_option(self::OPTION, array_values($dates));
        }
    }

    public function open(string $date): void
    {
        $dates = array_values(array_filter(
            $this->closedDates(),
            static fn (string $d): bool => $d !== $date
        ));

        update_option(self::OPTION, $dates);
    }

    /**
     * @return array<int, string>
     */
    private function closedDates(): array
    {
        $stored = get_option(self::OPTION, []);

        if (! is_array($stored)) {
            return [];
        }

        return array_values(array_filter($stored, 'is_string'));
    }
}
