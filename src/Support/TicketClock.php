<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * "Show a ticking clock on the ticket" (Settings, off by default): the
 * ticket page shows the phone's own time, ticking every second, on its own
 * line under the issued time, so the door can tell a live page from a
 * screenshot (Owner 2026-11-08: optional, off by default). Off, the ticket
 * shows no clock and runs no clock script.
 */
final class TicketClock
{
    /** The setting: '1' or '0', off when unset. */
    public const OPTION = 'eventcrew_ticket_clock';

    /** Whether the ticket page shows the ticking clock. */
    public static function isOn(): bool
    {
        return (bool) get_option(self::OPTION, false);
    }

    /** Saves the OPTION setting. */
    public static function save(bool $on): void
    {
        update_option(self::OPTION, $on ? '1' : '0');
    }
}
