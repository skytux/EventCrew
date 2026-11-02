<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

/**
 * The one reply for a date the bot could not read, shared by /roster,
 * the door list and the guest list so all three say the same thing.
 */
final class UnreadableDate
{
    public static function text(): string
    {
        return __('I could not read that date. Write it like 1.8. or 2026-08-01.', 'eventcrew');
    }
}
