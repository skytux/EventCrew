<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * "Allow several free-entry tickets per night" (Settings, on by default):
 * a member may spend a credit on a night they already hold a free-entry
 * ticket for, once for every credit, so they can bring friends or give the
 * extra tickets away (Lou 2026-11-08). Off, a member gets one free-entry
 * ticket per night, as before.
 *
 * Each ticket stays its own: its own link and QR code, its own row on the
 * door list, checked in on its own (CheckinRepository::KIND_CREDIT_EXTRA),
 * given away and cancelled on its own.
 */
final class SeveralTickets
{
    /** The setting: '1' or '0', on when unset. */
    public const OPTION = 'eventcrew_several_tickets';

    /** Whether a member may hold several free-entry tickets for one night. */
    public static function isOn(): bool
    {
        return (bool) get_option(self::OPTION, true);
    }

    /** Saves the OPTION setting. */
    public static function save(bool $on): void
    {
        update_option(self::OPTION, $on ? '1' : '0');
    }
}
