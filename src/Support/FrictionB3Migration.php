<?php

declare(strict_types=1);

namespace EventCrew\Support;

use Closure;

/**
 * One-time changes to the stored texts of the takeover and free-entry
 * messages, for the placeholders they gained in the "less friction" batch
 * B3. The same runner as FrictionB1Migration, with its own option: each
 * step runs once per site, and only adds a placeholder a text lacks.
 */
final class FrictionB3Migration
{
    /** The finished steps: step name => 1. */
    public const OPTION = 'eventcrew_migr_fric_b3';

    /**
     * The steps: step name => kind id => what it does to one text.
     *
     * @return array<string, array<string, Closure(string): string>>
     */
    private static function steps(): array
    {
        return [
            'takeover_ask' => [
                'takeover_ask' => self::withAskDetails(...),
                'takeover_ask_dm' => self::withAskDetails(...),
            ],
            'takeover_other_tasks' => array_fill_keys(
                [
                    'takeover_refused', 'takeover_refused_dm', 'takeover_expired', 'takeover_expired_dm',
                    'takeover_failed', 'takeover_failed_dm', 'takeover_closed', 'takeover_closed_dm',
                ],
                FrictionB1Migration::withOtherTasks(...)
            ),
            'credit_granted' => [
                'credit_granted' => static fn (string $text): string => self::appended($text, ["\n{balance}", "\n{nights}"]),
                'credit_granted_dm' => static fn (string $text): string => self::appended($text, ["\n{balance}"]),
            ],
            'free_ticket_dm' => [
                'free_ticket_dm' => static fn (string $text): string => self::appended($text, ["\n{event}", "\n{event_times}"]),
            ],
            // Owner, Q344 2026-11-07: the event first, then the day, venue, times.
            'free_ticket_event_first' => [
                'free_ticket_dm' => self::eventFirst(...),
            ],
        ];
    }

    /** Runs every step not yet done. Does nothing once all have run. */
    public static function run(): void
    {
        FrictionB1Migration::runSteps(self::OPTION, self::steps());
    }

    /**
     * $text with "\n{cover_record}" (who is asking) and "\n\n{description}"
     * (what the task is) after the first line that has {when}, else at the
     * end; each only when the text does not have it yet. An empty text
     * comes back as it is.
     */
    public static function withAskDetails(string $text): string
    {
        if ('' === trim($text)) {
            return $text;
        }

        $add = (str_contains($text, '{cover_record}') ? '' : "\n{cover_record}")
            . (str_contains($text, '{description}') ? '' : "\n\n{description}");

        if ('' === $add) {
            return $text;
        }

        $at = strpos($text, '{when}');
        $end = false === $at ? false : strpos($text, "\n", $at);

        return false === $end
            ? rtrim($text) . $add
            : substr($text, 0, $end) . $add . substr($text, $end);
    }

    /**
     * $text with its line that is only {event} moved up, to just before the
     * first line that has {date}. A text with no such lines, or with {event}
     * already before {date}, comes back as it is.
     */
    public static function eventFirst(string $text): string
    {
        $lines = explode("\n", $text);
        $event = null;
        $date = null;

        foreach ($lines as $i => $line) {
            if (null === $event && '{event}' === trim($line)) {
                $event = $i;
            }

            if (null === $date && str_contains($line, '{date}')) {
                $date = $i;
            }
        }

        if (null === $event || null === $date || $event < $date) {
            return $text;
        }

        $moved = $lines[$event];
        unset($lines[$event]);
        array_splice($lines, $date, 0, [$moved]);

        return implode("\n", $lines);
    }

    /**
     * $text with each of $parts (a placeholder, maybe after line breaks) at
     * the end, when the text does not have that placeholder yet. An empty
     * text comes back as it is.
     *
     * @param list<string> $parts
     */
    public static function appended(string $text, array $parts): string
    {
        if ('' === trim($text)) {
            return $text;
        }

        foreach ($parts as $part) {
            if (! str_contains($text, trim($part))) {
                $text = rtrim($text) . $part;
            }
        }

        return $text;
    }
}
