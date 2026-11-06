<?php

declare(strict_types=1);

namespace EventCrew\Support;

use Closure;

/**
 * One-time changes to the message texts organizers saved on the Messages
 * page before a placeholder existed, for the "less friction" messages
 * (cancel, late cancel, no-show, sign-up, cancelled task). Each step runs
 * once per site; the finished steps are kept in OPTION, so a step added in a
 * later release still runs after an earlier one did.
 *
 * A step changes the source and every language text of the stored body, and
 * only when the text does not have the placeholder yet. Built-in texts are
 * never stored, so they need nothing.
 */
final class FrictionB1Migration
{
    /** The finished steps: step name => 1. */
    public const OPTION = 'eventcrew_migr_fric_b1';

    /**
     * The steps: step name => kind id => what it does to one text.
     *
     * @return array<string, array<string, Closure(string): string>>
     */
    private static function steps(): array
    {
        $standing = static fn (string $text): string => self::appended($text, '{standing}');
        // {description} (the task's, else the role's) takes the place of
        // {about} (the role's), so a text never shows both.
        $signup = static function (string $text): string {
            $text = MessageTemplates::withDescription($text);

            return self::withEventTimes(
                str_contains($text, '{description}') ? (string) preg_replace('/\n*\{about\}/', '', $text) : $text
            );
        };

        return [
            'standing' => [
                'late_cancel' => $standing,
                'late_cancel_dm' => $standing,
                'no_show' => $standing,
                'no_show_dm' => $standing,
            ],
            'signup' => [
                'signup_confirmed' => $signup,
                'signup_confirmed_dm' => $signup,
            ],
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

            $done[$step] = 1;
            $changed = true;
        }

        if ($changed) {
            update_option(self::OPTION, $done, false);
        }
    }

    /**
     * $text with "\n{event_times}" on the line after the first line that has
     * {when} in it. A text that has {event_times} already, or no {when},
     * comes back as it is.
     */
    public static function withEventTimes(string $text): string
    {
        $at = strpos($text, '{when}');

        if (false === $at || str_contains($text, '{event_times}')) {
            return $text;
        }

        $end = strpos($text, "\n", $at);

        return false === $end
            ? $text . "\n{event_times}"
            : substr($text, 0, $end) . "\n{event_times}" . substr($text, $end);
    }

    /** $text with "\n\n" and $placeholder after it, unless it has it already or is empty. */
    public static function appended(string $text, string $placeholder): string
    {
        if ('' === trim($text) || str_contains($text, $placeholder)) {
            return $text;
        }

        return rtrim($text) . "\n\n" . $placeholder;
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

        $body = $raw[MessageTemplates::BODY];

        if (is_string($body)) {
            $body = $change($body);
        } elseif (is_array($body)) {
            if (is_string($body['source'] ?? null)) {
                $body['source'] = $change($body['source']);
            }

            foreach (is_array($body['texts'] ?? null) ? $body['texts'] : [] as $lang => $text) {
                if (is_string($text)) {
                    $body['texts'][$lang] = $change($text);
                }
            }
        }

        if ($body !== $raw[MessageTemplates::BODY]) {
            $raw[MessageTemplates::BODY] = $body;
            update_option($key, $raw);
        }
    }
}
