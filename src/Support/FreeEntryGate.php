<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * Which event dates are closed to free entry - a sold-out night, or a special
 * event that earned credits can't be spent on.
 *
 * Free entry is keyed on the event date throughout (the door list, the
 * redemptions table), so closing is too: a small set of Y-m-d strings in one
 * option. Both the organizer's "Redeem a credit" control and the self-service
 * /ticket flow consult this before letting a credit be spent.
 */
final class FreeEntryGate
{
    /** The set of closed dates: an array of 'Y-m-d' strings. */
    public const OPTION = 'eventcrew_ticket_closed_dates';

    public function isClosed(string $date): bool
    {
        return in_array($date, $this->closedDates(), true);
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
