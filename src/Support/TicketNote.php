<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The placeholders of the ticket note (Settings > Web page > Ticket note),
 * shown on the ticket page and in the ticket email.
 *
 * A line whose placeholder is empty is left out, so no half sentence is
 * shown.
 */
final class TicketNote
{
    /** Every placeholder, in the order the Settings help lists them. */
    public const TAGS = [Organization::TAG, EventTimes::START_TAG, EventTimes::DOORS_TAG];

    /**
     * $note with its placeholders replaced by $values (placeholder => text).
     * A line that holds a placeholder of $values whose value is empty is left
     * out.
     *
     * @param array<string, string> $values
     */
    public static function fill(string $note, array $values): string
    {
        $values = array_map(static fn (string $value): string => trim($value), $values);
        $lines = [];

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $note)) as $line) {
            foreach ($values as $tag => $value) {
                if ('' === $value && str_contains($line, $tag)) {
                    continue 2;
                }
            }

            $lines[] = strtr($line, $values);
        }

        return trim(implode("\n", $lines));
    }

    /**
     * The values of the note's placeholders on a ticket to the event
     * $eventId (null: no linked event).
     *
     * @return array<string, string>
     */
    public static function values(?int $eventId = null): array
    {
        return [
            Organization::TAG => Organization::name(),
            EventTimes::START_TAG => EventTimes::start($eventId),
            EventTimes::DOORS_TAG => EventTimes::doorsClose($eventId),
        ];
    }

    /**
     * $note, or the built-in note when $note is blank: one line on when the
     * doors close, left out when there is no doors time. In the current
     * language.
     */
    public static function orBuiltIn(string $note): string
    {
        if ('' !== trim($note)) {
            return $note;
        }

        /* translators: %s: the placeholder {doors_close}, which becomes a time like 19:30 */
        return sprintf(__('Doors close at %s.', 'eventcrew'), EventTimes::DOORS_TAG);
    }
}
