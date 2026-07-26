<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;

/**
 * Wraps the Telegram DMs nobody asked for.
 *
 * A reply to /me or /ticket needs no introduction: the person typed the command
 * a second ago and knows who is answering. A message that simply arrives is a
 * different thing - it may be weeks since they last touched the bot, and the
 * chat is one of dozens - so it opens by saying which crew is writing, and
 * greets them by name the way the emails do. The two channels reading alike is
 * most of what makes them feel like one voice.
 *
 * The sign-off is deliberately not on everything. It belongs on the messages
 * that go out to everybody - the open-task call, the reminder - and would be
 * tone-deaf under "you were marked as a no-show", so each caller decides.
 */
final class DmBody
{
    /**
     * Composes an unprompted DM: who it is from, who it is to, what it says,
     * and optionally the crew's sign-off.
     */
    public static function frame(Person $person, string $body, bool $signed = false): string
    {
        $parts = [];
        $crew = self::crewName();

        if ('' !== $crew) {
            $parts[] = '📣 ' . $crew;
        }

        $parts[] = sprintf(
            /* translators: %s: the person's first name */
            __('Hi %s,', 'eventcrew'),
            $person->name()
        );

        $parts[] = trim($body);

        if ($signed && Signature::isSet()) {
            $parts[] = Signature::text();
        }

        return implode("\n\n", $parts);
    }

    /**
     * Who the message is from. The site's name, which is what the crew calls
     * itself everywhere else the plugin shows a name.
     */
    private static function crewName(): string
    {
        return trim((string) get_bloginfo('name'));
    }
}
