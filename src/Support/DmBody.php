<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;

/**
 * Wraps the Telegram DMs the person did not just ask for.
 *
 * A reply to /me, or to a button the person just tapped, needs no
 * introduction: they acted a second ago and know who is answering. A message
 * that simply arrives is a different thing: a reminder or a call on a
 * schedule, or news caused by someone else (an organizer gave a role, a
 * person asks to take over a task). It may be weeks since they last touched
 * the bot, and the chat is one of dozens, so it opens by saying which crew is
 * writing, greets them by name the way the emails do, and closes with the
 * crew's sign-off (Owner, 2026-11-07). The two channels reading alike is most
 * of what makes them feel like one voice.
 */
final class DmBody
{
    /**
     * Composes an unprompted DM: who it is from, who it is to, what it says,
     * and the crew's sign-off when one is set.
     */
    public static function frame(Person $person, string $body): string
    {
        return self::withSignOff(self::open($person, $body));
    }

    /**
     * The opening alone, header and "Hi Name," above $body, for a sender that
     * adds lines of its own before the sign-off (signOff()).
     */
    public static function open(Person $person, string $body): string
    {
        $parts = [];
        $crew = self::crewName();

        if ('' !== $crew) {
            $parts[] = '💌 ' . $crew;
        }

        $parts[] = sprintf(
            /* translators: %s: the person's first name */
            __('Hi %s,', 'eventcrew'),
            DoorDesk::firstName($person->name())
        );

        $parts[] = trim($body);

        return implode("\n\n", $parts);
    }

    /** $text with the crew's sign-off under it, when one is set. */
    public static function withSignOff(string $text): string
    {
        $signOff = self::signOff();

        return '' === $signOff ? $text : $text . "\n\n" . $signOff;
    }

    /** The crew's sign-off as the DMs end with it, '' when none is set. */
    public static function signOff(): string
    {
        return trim(Signature::text());
    }

    /**
     * Who the message is from: the organization's name (Organization),
     * which is what the crew calls itself everywhere else the plugin shows a
     * name.
     */
    private static function crewName(): string
    {
        return Organization::name();
    }
}
