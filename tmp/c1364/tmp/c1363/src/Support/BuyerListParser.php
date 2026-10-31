<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * Turns a pasted or mailed list of Holvi ticket buyers into names.
 *
 * The format the shop's export will settle on is not decided yet, so for now
 * the list is one name per line, and everything that knows that lives here:
 * when the format changes, this is the one class to change.
 */
final class BuyerListParser
{
    /** Longest name kept, matching the ticket buyers table's name column. */
    public const MAX_LENGTH = 191;

    /**
     * One name per line: each line trimmed, inner whitespace collapsed to one
     * space, and cut to MAX_LENGTH. Empty lines are dropped, so are quoted mail
     * lines (starting with ">") and everything from a mail signature separator
     * ("--" or "-- " alone on a line) down. A line that appears twice is kept
     * once, compared without regard to case.
     *
     * @return array<int, string>
     */
    public static function names(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $names = [];
        $seen = [];

        foreach (false === $lines ? [] : $lines as $line) {
            // The signature separator is checked before trimming: "-- " with
            // its trailing space is the standard form, and a name never is.
            if ('--' === $line || '-- ' === $line) {
                break;
            }

            $line = trim((string) preg_replace('/\s+/u', ' ', $line));

            if ('' === $line || str_starts_with($line, '>')) {
                continue;
            }

            $name = mb_substr($line, 0, self::MAX_LENGTH);
            $key = function_exists('mb_strtolower') ? mb_strtolower($name) : strtolower($name);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $names[] = $name;
        }

        return $names;
    }
}
