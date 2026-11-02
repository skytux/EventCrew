<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * Turns a pasted list of Holvi ticket buyers into names, and a list mailed in
 * as plain text into guests (names with their plus-ones).
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

    /**
     * The same lines as names(), read as guests: a last word "+N" (as in
     * "Anna Virtanen +2", the /door add rule) is the number of plus-ones and
     * is taken off the name. A line with no such word has 0 plus-ones, and a
     * line that is only "+N" stays a name. Nothing from a signature line ("--"
     * after trailing whitespace is removed) down is read.
     *
     * @return array<int, array{name: string, plus_ones: int}>
     */
    public static function guests(string $text): array
    {
        $guests = [];
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $kept = [];

        // A mail signature ends the list: a line that is "--" once trailing
        // whitespace is taken off (names() only stops at "--" and "-- " exactly).
        foreach (false === $lines ? [] : $lines as $line) {
            if ('--' === rtrim($line)) {
                break;
            }

            $kept[] = $line;
        }

        foreach (self::names(implode("\n", $kept)) as $line) {
            $words = explode(' ', $line);
            $plusOnes = 0;

            if (count($words) > 1 && 1 === preg_match(GuestCommand::PLUS_ONES_TOKEN, (string) end($words), $m)) {
                array_pop($words);
                $plusOnes = (int) $m[1];
            }

            $guests[] = ['name' => implode(' ', $words), 'plus_ones' => $plusOnes];
        }

        return $guests;
    }
}
