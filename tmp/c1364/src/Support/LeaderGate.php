<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * Whether the reserved crew-leader slot is offered on a given event date.
 *
 * A global default (off unless an organizer turns it on) with a per-date
 * override, the same date-keyed shape FreeEntryGate uses. Task generation and
 * the Roster page's enable/disable control both consult this to decide whether
 * a date carries a leader task at all.
 */
final class LeaderGate
{
    /** Whether a new event carries a leader slot unless told otherwise. */
    public const ENABLED_OPTION = 'eventcrew_leader_enabled';

    /** Per-date overrides: an array of 'Y-m-d' => bool. */
    public const DATES_OPTION = 'eventcrew_leader_dates';

    public function isEnabled(string $date): bool
    {
        $overrides = $this->overrides();

        return array_key_exists($date, $overrides)
            ? $overrides[$date]
            : $this->enabledByDefault();
    }

    public function enabledByDefault(): bool
    {
        return (bool) get_option(self::ENABLED_OPTION, false);
    }

    public function enable(string $date): void
    {
        $this->setOverride($date, true);
    }

    public function disable(string $date): void
    {
        $this->setOverride($date, false);
    }

    private function setOverride(string $date, bool $on): void
    {
        $overrides = $this->overrides();
        $overrides[$date] = $on;

        update_option(self::DATES_OPTION, $overrides);
    }

    /**
     * @return array<string, bool>
     */
    private function overrides(): array
    {
        $stored = get_option(self::DATES_OPTION, []);

        if (! is_array($stored)) {
            return [];
        }

        $overrides = [];

        foreach ($stored as $date => $on) {
            if (is_string($date)) {
                $overrides[$date] = (bool) $on;
            }
        }

        return $overrides;
    }
}
