<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;

/**
 * What a newcomer says about themselves when they join (Owner 2026-11-07):
 * whether they have worked on the crew here before, about how many times,
 * and how many free-entry tickets they think they should have.
 *
 * Asked on the crew page's email form and in the bot after the email, before
 * the confirmation link goes out. Every answer is optional: a skipped or
 * out-of-range one is left out and the person joins all the same. The
 * organizers read the answers in the "wants to join" notice
 * ({join_answers}, NewcomerApproval).
 *
 * The answers live in the person's role_notices record, with the rest of the
 * newcomer request (KEY_*). They are personal data: the privacy export lists
 * them, anonymizing removes them (PersonEraser), and erasing removes the row.
 */
final class JoinAnswers
{
    /** role_notices: 'yes' or 'no'. Missing: not answered. */
    public const KEY_BEFORE = 'newcomer:before';

    /** role_notices: about how many times they worked here, MIN_TIMES..MAX_TIMES. */
    public const KEY_TIMES = 'newcomer:times';

    /** role_notices: the free-entry tickets they think they should have, MIN_TICKETS..MAX_TICKETS. */
    public const KEY_TICKETS = 'newcomer:tickets';

    /** Every key, for replacing and erasing the answers. */
    public const KEYS = [self::KEY_BEFORE, self::KEY_TIMES, self::KEY_TICKETS];

    public const YES = 'yes';
    public const NO = 'no';

    public const MIN_TIMES = 1;
    public const MAX_TIMES = 50;
    public const MIN_TICKETS = 0;
    public const MAX_TICKETS = 50;

    /** The posted names of the crew page's fields. */
    public const FIELD_BEFORE = 'join_before';
    public const FIELD_TIMES = 'join_times';
    public const FIELD_TICKETS = 'join_tickets';

    /**
     * The role_notices entries of a set of answers: [] when the yes/no was
     * not answered. A No keeps no numbers; a number out of range is left out.
     *
     * @return array<string, string>
     */
    public static function entries(?string $before, ?int $times = null, ?int $tickets = null): array
    {
        if (self::NO === $before) {
            return [self::KEY_BEFORE => self::NO];
        }

        if (self::YES !== $before) {
            return [];
        }

        $entries = [self::KEY_BEFORE => self::YES];

        if (null !== $times && $times >= self::MIN_TIMES && $times <= self::MAX_TIMES) {
            $entries[self::KEY_TIMES] = (string) $times;
        }

        if (null !== $tickets && $tickets >= self::MIN_TICKETS && $tickets <= self::MAX_TICKETS) {
            $entries[self::KEY_TICKETS] = (string) $tickets;
        }

        return $entries;
    }

    /**
     * The entries of the crew page's fields (unslashed $_POST values).
     *
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    public static function fromPost(array $post): array
    {
        $before = is_string($post[self::FIELD_BEFORE] ?? null) ? strtolower(trim($post[self::FIELD_BEFORE])) : '';

        return self::entries(
            $before,
            self::number($post[self::FIELD_TIMES] ?? null),
            self::number($post[self::FIELD_TICKETS] ?? null)
        );
    }

    /**
     * A whole number typed by a person ("5", " 12 "), null for anything else
     * (empty, words, a fraction, a minus sign).
     */
    public static function number(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        $value = is_string($value) ? trim($value) : '';

        return 1 === preg_match('/^\d{1,4}$/', $value) ? (int) $value : null;
    }

    /**
     * The answers stored on $person.
     *
     * @return array{before: ?string, times: ?int, tickets: ?int}
     */
    public static function of(Person $person): array
    {
        $before = $person->roleNotices[self::KEY_BEFORE] ?? '';
        $before = in_array($before, [self::YES, self::NO], true) ? $before : null;
        $yes = self::YES === $before;

        return [
            'before' => $before,
            'times' => $yes ? self::number($person->roleNotices[self::KEY_TIMES] ?? null) : null,
            'tickets' => $yes ? self::number($person->roleNotices[self::KEY_TICKETS] ?? null) : null,
        ];
    }

    /**
     * The answers as the organizers read them: "Worked on the crew here
     * before: yes, about 5 times. Thinks they should have 3 free-entry
     * tickets.", "First time on the crew." or, when the question was not
     * answered, "Did not say whether they have worked on the crew here before."
     */
    public static function line(Person $person): string
    {
        $answers = self::of($person);

        if (self::NO === $answers['before']) {
            return __('First time on the crew.', 'eventcrew');
        }

        if (self::YES !== $answers['before']) {
            return __('Did not say whether they have worked on the crew here before.', 'eventcrew');
        }

        $line = null === $answers['times']
            ? __('Worked on the crew here before: yes.', 'eventcrew')
            : sprintf(
                /* translators: %d: about how many times the person has worked on the crew */
                _n(
                    'Worked on the crew here before: yes, about %d time.',
                    'Worked on the crew here before: yes, about %d times.',
                    $answers['times'],
                    'eventcrew'
                ),
                $answers['times']
            );

        if (null !== $answers['tickets']) {
            $line .= ' ' . sprintf(
                /* translators: %d: how many free-entry tickets the person thinks they should have */
                _n(
                    'Thinks they should have %d free-entry ticket.',
                    'Thinks they should have %d free-entry tickets.',
                    $answers['tickets'],
                    'eventcrew'
                ),
                $answers['tickets']
            );
        }

        return $line;
    }

    /**
     * Whether $person may still give or change their answers: a newcomer
     * waiting for the OK whose email is not confirmed yet, so the organizers
     * have not been asked.
     */
    public static function canAnswer(Person $person): bool
    {
        return $person->isWaitingForOk()
            && ! $person->isEmailVerified()
            && ! $person->isAnonymized()
            && ! NewcomerApproval::wasAsked($person);
    }
}
