<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * Reads what follows "/door" in a chat message.
 *
 *   /door [date]                                  show the list
 *   /door add [date] Name [+N] [; note]           add a guest
 *   /door edit [date] <number> [Name] [+N] [; note]
 *                                                 change only the parts given
 *   /door remove [date] <number>                  take a guest off
 *
 * The date is optional everywhere ("1.8." or "2026-08-01"); without one the
 * caller uses the next event. <number> is the guest's number in the shown list.
 * Text after a +N with no ";" is the note too, so "Anna +2 DJ's partner" works.
 *
 * In "add", a word with an "@" before the ";" is the guest's email address,
 * so "/door add Anna anna@example.com +1 ; DJ" works; the guest is then
 * emailed their ticket. A word with an "@" that is not an email address is
 * the error 'email'.
 */
final class GuestCommand
{
    public const SHOW = 'show';
    public const ADD = 'add';
    public const EDIT = 'edit';
    public const REMOVE = 'remove';

    /** One "+N" token: the number of people a guest brings, 1 to 3 digits. */
    public const PLUS_ONES_TOKEN = '#^\+(\d{1,3})$#';

    /**
     * @return array{
     *     action: string, date: string, number: int,
     *     name: ?string, plus_ones: ?int, note: ?string, email: string, error: string
     * }
     *     date is '' when none was given; name/plus_ones/note are null when not
     *     given; email is '' when none was given (only "add" reads one); error
     *     is '' when the command is usable.
     */
    public static function parse(string $arguments, string $today): array
    {
        $result = [
            'action' => self::SHOW,
            'date' => '',
            'number' => 0,
            'name' => null,
            'plus_ones' => null,
            'note' => null,
            'email' => '',
            'error' => '',
        ];

        $note = null;
        $semicolon = strpos($arguments, ';');

        if (false !== $semicolon) {
            $note = trim(substr($arguments, $semicolon + 1));
            $arguments = substr($arguments, 0, $semicolon);
        }

        $tokens = preg_split('/\s+/', trim($arguments), -1, PREG_SPLIT_NO_EMPTY);
        $tokens = false === $tokens ? [] : $tokens;

        $verb = strtolower($tokens[0] ?? '');
        $action = match ($verb) {
            'add' => self::ADD,
            'edit', 'change' => self::EDIT,
            'remove', 'rm', 'delete', 'del' => self::REMOVE,
            'show', 'list' => self::SHOW,
            default => '',
        };

        if ('' !== $action) {
            array_splice($tokens, 0, 1);
        } else {
            $action = self::SHOW;
        }

        $result['action'] = $action;

        // A leading date token, recognised by its shape so a name never reads as one.
        if (isset($tokens[0]) && 1 === preg_match('#^\d{1,4}[.-]\d{1,2}([.-]\d{0,4})?$#', $tokens[0])) {
            $date = Dates::parseUserDate($tokens[0], $today);

            if ('' === $date) {
                return ['error' => 'date'] + $result;
            }

            $result['date'] = $date;
            array_splice($tokens, 0, 1);
        }

        if (self::SHOW === $action) {
            return [] === $tokens ? $result : ['error' => 'usage'] + $result;
        }

        if (self::EDIT === $action || self::REMOVE === $action) {
            if (! isset($tokens[0]) || 1 !== preg_match('#^\d+$#', $tokens[0]) || (int) $tokens[0] < 1) {
                return ['error' => 'number'] + $result;
            }

            $result['number'] = (int) $tokens[0];
            array_splice($tokens, 0, 1);

            if (self::REMOVE === $action) {
                return $result;
            }
        }

        $nameTokens = [];
        $afterPlus = [];
        $plusOnes = null;

        foreach ($tokens as $index => $token) {
            if (self::ADD !== $action || ! str_contains($token, '@')) {
                continue;
            }

            if ('' !== $result['email'] || false === filter_var($token, FILTER_VALIDATE_EMAIL)) {
                return ['error' => 'email'] + $result;
            }

            $result['email'] = $token;
            unset($tokens[$index]);
        }

        foreach ($tokens as $token) {
            if (null === $plusOnes && 1 === preg_match(self::PLUS_ONES_TOKEN, $token, $m)) {
                $plusOnes = (int) $m[1];

                continue;
            }

            if (null === $plusOnes) {
                $nameTokens[] = $token;
            } else {
                $afterPlus[] = $token;
            }
        }

        if (null === $note && [] !== $afterPlus) {
            $note = implode(' ', $afterPlus);
        } elseif ([] !== $afterPlus) {
            $nameTokens = array_merge($nameTokens, $afterPlus);
        }

        $name = trim(implode(' ', $nameTokens));
        $result['name'] = '' === $name ? null : $name;
        $result['plus_ones'] = $plusOnes;
        $result['note'] = $note;

        if (self::ADD === $action && null === $result['name']) {
            return ['error' => 'name'] + $result;
        }

        if (self::EDIT === $action && null === $result['name'] && null === $plusOnes && null === $note) {
            return ['error' => 'nothing'] + $result;
        }

        return $result;
    }
}
