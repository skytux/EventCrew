<?php

declare(strict_types=1);

namespace EventCrew\Support;

use Closure;

/**
 * Puts the event's details into the message texts: {event_description} (the
 * short description of the event, EventDescription) and {venue} ("📍 venue",
 * EventVenue), and {venue} into the ticket email's text (TicketText).
 *
 * The with…() helpers place a placeholder in a text. The built-in texts use
 * them, and run() uses them once for the texts organizers saved on the
 * Messages page before the placeholder existed. Each step of run() runs once
 * per site; the finished steps are kept in OPTION, so a step added in a later
 * release still runs after an earlier one did. A step changes the source and
 * every language text of the stored body, and only a text that does not have
 * the placeholder yet.
 */
final class EventDetailsText
{
    /** The finished steps: step name => 1. */
    public const OPTION = 'eventcrew_migr_desc_1106';

    /**
     * The kinds that show the event's description. Not the sign-up email
     * (signup_confirmed): it shows the task's description instead (Owner,
     * 2026-11-07), and the step "signup_email_no_description" takes the
     * event's out of a stored copy.
     */
    public const DESCRIPTION_KINDS = [
        'reminder',
        'reminder_dm',
        'signup_confirmed_dm',
        'slot_freed',
        'slot_freed_dm',
        'followup_came',
        'followup_missed',
    ];

    /** The kinds that show the event's venue. */
    public const VENUE_KINDS = [
        'reminder',
        'reminder_dm',
        'signup_confirmed',
        'signup_confirmed_dm',
        'slot_freed',
        'slot_freed_dm',
        'free_ticket_dm',
    ];

    /**
     * Placeholders that bring their own empty line and are written right
     * after the text before them: a new line goes in front of them.
     */
    private const GLUED = ['{task_details}', '{other_tasks}', '{free_cancel}', '{about}'];

    /** Placeholders that stand on a row of their own under the {when} line. */
    private const ROWS = ['{event_times}', '{venue}'];

    /**
     * The steps: step name => kind id => what it does to one text.
     *
     * @return array<string, array<string, Closure(string): string>>
     */
    private static function steps(): array
    {
        return [
            'description' => array_fill_keys(self::DESCRIPTION_KINDS, self::withEventDescription(...)),
            'venue' => array_fill_keys(self::VENUE_KINDS, self::withVenue(...)),
            // The sign-up email is a thank-you about the task: the event's
            // description, which the "description" step gave a stored copy,
            // goes again.
            'signup_email_no_description' => ['signup_confirmed' => self::withoutEventDescription(...)],
        ];
    }

    /** Runs every step not yet done. Does nothing once all have run. */
    public static function run(): void
    {
        $done = get_option(self::OPTION, []);
        $done = is_array($done) ? $done : [];
        $changed = false;

        foreach (self::steps() as $step => $kinds) {
            if (! empty($done[$step])) {
                continue;
            }

            foreach ($kinds as $id => $change) {
                self::change($id, $change);
            }

            if ('venue' === $step) {
                self::changeTicketText();
            }

            $done[$step] = 1;
            $changed = true;
        }

        if ($changed) {
            update_option(self::OPTION, $done, false);
        }
    }

    /**
     * $text with "\n\n{event_description}" after the line that has
     * {description} (the task's), else the line that has {when}, else the
     * line that has {event}, and after the rows under that line. A text that
     * has {event_description} already, or none of these, comes back as it is.
     */
    public static function withEventDescription(string $text): string
    {
        if (str_contains($text, '{event_description}')) {
            return $text;
        }

        foreach (['{description}', '{when}', '{event}'] as $anchor) {
            $at = self::afterLine($text, $anchor);

            if (null !== $at) {
                return substr($text, 0, $at) . "\n\n{event_description}" . substr($text, $at);
            }
        }

        return $text;
    }

    /**
     * $text without {event_description}: its line, and the line breaks
     * before it, go (at the very start, the ones after it), as
     * MessageTemplate::fill() takes out an empty own-line placeholder. A
     * text without it comes back as it is.
     */
    public static function withoutEventDescription(string $text): string
    {
        if (! str_contains($text, '{event_description}')) {
            return $text;
        }

        $text = (string) preg_replace('/(?:\r?\n)*[ \t]*\{event_description\}[ \t]*/', '', $text);

        return ltrim($text, "\r\n");
    }

    /**
     * $text with "\n{venue}" on a row under the line that has {when}, else
     * {date}, else {event}, after the {event_times} row there. A text that
     * has {venue} already, or none of these, comes back as it is.
     */
    public static function withVenue(string $text): string
    {
        if (str_contains($text, '{venue}')) {
            return $text;
        }

        foreach (['{when}', '{date}', '{event}'] as $anchor) {
            $at = self::afterLine($text, $anchor);

            if (null !== $at) {
                return substr($text, 0, $at) . "\n{venue}" . substr($text, $at);
            }
        }

        return $text;
    }

    /**
     * The ticket email's text (TicketText) with a "{venue}" paragraph after
     * the paragraph that has {event} or {date}: the stored source and each
     * language text. Nothing is stored when the built-in text is in use.
     */
    private static function changeTicketText(): void
    {
        $raw = get_option(TicketText::OPTION, null);

        if (! is_array($raw)) {
            return;
        }

        $value = self::changed($raw, static function (string $text): string {
            if (str_contains($text, TicketText::VENUE_TAG)) {
                return $text;
            }

            $paragraphs = preg_split('/\n\s*\n/', $text) ?: [$text];

            foreach ($paragraphs as $i => $paragraph) {
                if (str_contains($paragraph, TicketText::EVENT_TAG) || str_contains($paragraph, TicketText::DATE_TAG)) {
                    array_splice($paragraphs, $i + 1, 0, [TicketText::VENUE_TAG]);

                    return implode("\n\n", $paragraphs);
                }
            }

            return $text;
        });

        if ($value !== $raw) {
            update_option(TicketText::OPTION, $value);
        }
    }

    /**
     * Where the line that holds $anchor first ends: before a GLUED
     * placeholder on it, else at its end, and ($rows) past the ROWS lines
     * right under it. Null when $text does not have $anchor.
     */
    private static function afterLine(string $text, string $anchor, bool $rows = true): ?int
    {
        $at = strpos($text, $anchor);

        if (false === $at) {
            return null;
        }

        $end = strpos($text, "\n", $at);
        $end = false === $end ? strlen($text) : $end;

        foreach (self::GLUED as $glued) {
            $g = strpos($text, $glued, $at + strlen($anchor));

            if (false !== $g && $g < $end) {
                return $g;
            }
        }

        while ($rows && $end < strlen($text)) {
            $next = strpos($text, "\n", $end + 1);
            $next = false === $next ? strlen($text) : $next;

            if (! in_array(trim(substr($text, $end + 1, $next - $end - 1)), self::ROWS, true)) {
                break;
            }

            $end = $next;
        }

        return $end;
    }

    /**
     * Applies $change to the stored body of kind $id: a plain string, or a
     * TranslatedText value (its source and each language text).
     *
     * @param Closure(string): string $change
     */
    private static function change(string $id, Closure $change): void
    {
        $key = MessageTemplates::OPTION_PREFIX . $id;
        $raw = get_option($key, null);

        if (! is_array($raw) || ! isset($raw[MessageTemplates::BODY])) {
            return;
        }

        $body = self::changed($raw[MessageTemplates::BODY], $change);

        if ($body !== $raw[MessageTemplates::BODY]) {
            $raw[MessageTemplates::BODY] = $body;
            update_option($key, $raw);
        }
    }

    /**
     * $value with $change applied to it (a string), or to its source and
     * each language text (a TranslatedText value). An empty text stays empty.
     *
     * @param Closure(string): string $change
     */
    private static function changed(mixed $value, Closure $change): mixed
    {
        $apply = static fn (string $text): string => '' === trim($text) ? $text : $change($text);

        if (is_string($value)) {
            return $apply($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (is_string($value['source'] ?? null)) {
            $value['source'] = $apply($value['source']);
        }

        foreach (is_array($value['texts'] ?? null) ? $value['texts'] : [] as $lang => $text) {
            if (is_string($text)) {
                $value['texts'][$lang] = $apply($text);
            }
        }

        return $value;
    }
}
