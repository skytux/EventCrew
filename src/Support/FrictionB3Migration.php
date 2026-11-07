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

    /** The step that drops stored copies of the old free-ticket texts. */
    public const WARM_STEP = 'free_ticket_warm';

    /**
     * Built-in English texts that are no longer built in: kind id (a
     * message, or 'ticket_reason_<kind>') => the old texts, as stored after
     * the steps above. A stored copy of one of them with nothing written by
     * hand only froze the old text, so it is dropped and the new built-in
     * text applies (the warm free-entry texts, Owner 2026-11-07).
     *
     * @return array<string, list<string>>
     */
    public static function oldBuiltIns(): array
    {
        return [
            'free_ticket_dm' => [
                "✨ Your free-entry ticket ✨\n\n{event}\n{date}\n{venue}\n{event_times}\n\n💫 Show it at the door.",
                "{event}\n🎟 Your free-entry ticket for {date} — show it at the door.\n{venue}\n{event_times}",
            ],
            'ticket_reason_' . TicketReasons::TASK => ['Thank you for taking a task!'],
            'ticket_reason_' . TicketReasons::CREDIT => ['You spent a free-entry credit on this night.'],
        ];
    }

    /** Runs every step not yet done. Does nothing once all have run. */
    public static function run(): void
    {
        FrictionB1Migration::runSteps(self::OPTION, self::steps());

        $done = get_option(self::OPTION, []);
        $done = is_array($done) ? $done : [];

        if (! empty($done[self::WARM_STEP])) {
            return;
        }

        foreach (self::oldBuiltIns() as $id => $old) {
            if (str_starts_with($id, 'ticket_reason_')) {
                $option = TicketReasons::option(substr($id, strlen('ticket_reason_')));

                if (self::isOldCopy(get_option($option, null), $old)) {
                    delete_option($option);
                }

                continue;
            }

            $option = MessageTemplates::OPTION_PREFIX . $id;
            $raw = get_option($option, null);

            if (! is_array($raw) || ! self::isOldCopy($raw[MessageTemplates::BODY] ?? null, $old)) {
                continue;
            }

            unset($raw[MessageTemplates::BODY]);
            $raw = array_filter($raw, static fn (mixed $value): bool => null !== $value && [] !== $value);

            if ([] === $raw) {
                delete_option($option);
            } else {
                update_option($option, $raw, false);
            }
        }

        $done[self::WARM_STEP] = 1;
        update_option(self::OPTION, $done, false);
    }

    /**
     * Whether $value (a plain string or a TranslatedText value) is one of
     * the $old English texts with no language text written by hand.
     *
     * @param list<string> $old
     */
    private static function isOldCopy(mixed $value, array $old): bool
    {
        $value = TranslatedText::normalize($value);

        return [] === array_filter($value['manual']) && in_array(trim($value['source']), $old, true);
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
