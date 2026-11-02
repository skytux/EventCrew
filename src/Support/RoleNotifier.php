<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Telegram\TelegramClient;

/**
 * Tells the organizers, by Telegram and email, about two moments in a
 * person's history with a role - each only the first time, once per person
 * per role:
 * - "met": the person meets the role's conditions (checked on the hourly
 *   tick; someone who already holds the role is not announced);
 * - "given": the person is given the role, from the bot's /people, the crew
 *   page, the People page, or by being added to one of its tasks;
 * - "organizer": the person is made an organizer, from the bot's /people or
 *   the People page.
 *
 * The "given" and "organizer" notices name who did it, when that is known.
 *
 * The record of what was sent is the person's role_notices column
 * ("met:<slug>", "given:<slug>", "made:organizer").
 */
final class RoleNotifier
{
    /** Fired with (int $personId, string $roleSlug, string $giverName) when someone is given a role. */
    public const GIVEN_HOOK = 'eventcrew/role_given';

    /** Fired with (int $personId, string $giverName) when someone is made an organizer. */
    public const ORGANIZER_HOOK = 'eventcrew/organizer_given';

    private const ORGANIZER_KEY = 'made:organizer';

    public function __construct(
        private readonly RoleConditions $conditions,
        private readonly PersonRepository $people,
        private readonly Mailer $mailer,
        private readonly TelegramClient $telegram
    ) {
    }

    /**
     * Fires GIVEN_HOOK for every role in $after that was not in $before.
     * $giverName is the name of whoever gave the roles, or '' when unknown.
     *
     * @param array<int, string> $before
     * @param array<int, string> $after
     */
    public static function rolesChanged(int $personId, array $before, array $after, string $giverName = ''): void
    {
        foreach (array_diff($after, $before) as $slug) {
            if ('' !== $slug && Roles::ORGANIZE_SLUG !== $slug) {
                do_action(self::GIVEN_HOOK, $personId, $slug, $giverName);
            }
        }
    }

    /** Fires ORGANIZER_HOOK. Call it only when the person was not an organizer before. */
    public static function madeOrganizer(int $personId, string $giverName = ''): void
    {
        do_action(self::ORGANIZER_HOOK, $personId, $giverName);
    }

    /** The hourly check: who newly meets a role's conditions. */
    public function run(): void
    {
        $organizers = $this->people->organizers();

        if ([] === $organizers || [] === RoleConditions::rolesWithConditions()) {
            return;
        }

        foreach ($this->conditions->report() as $slug => $rows) {
            foreach ($rows as $row) {
                $person = $row['person'];
                $key = 'met:' . $slug;

                if ($row['holds'] || $person->hasRoleNotice($key)) {
                    continue;
                }

                $this->announce($organizers, $person, (string) $slug, 'met');
                $this->people->markRoleNotice($person->id, $key);
            }
        }
    }

    /** The GIVEN_HOOK listener. */
    public function roleGiven(int $personId, string $slug, string $giverName = ''): void
    {
        $this->given($personId, 'given:' . $slug, $slug, 'given', $giverName);
    }

    /** The ORGANIZER_HOOK listener. */
    public function organizerGiven(int $personId, string $giverName = ''): void
    {
        $this->given($personId, self::ORGANIZER_KEY, '', 'organizer', $giverName);
    }

    private function given(int $personId, string $key, string $slug, string $kind, string $giverName): void
    {
        $person = $this->people->find($personId);

        if (null === $person || $person->hasRoleNotice($key)) {
            return;
        }

        // The new organizer is on the organizers' list by now, and does not
        // need to be told about themselves.
        $organizers = array_values(array_filter(
            $this->people->organizers(),
            static fn (Person $organizer): bool => 'organizer' !== $kind || $organizer->id !== $person->id
        ));

        if ([] !== $organizers) {
            $this->announce($organizers, $person, $slug, $kind, $giverName);
        }

        $this->people->markRoleNotice($person->id, $key);
    }

    /**
     * @param array<int, Person> $organizers
     */
    private function announce(
        array $organizers,
        Person $person,
        string $slug,
        string $kind,
        string $giverName = ''
    ): void {
        foreach ($organizers as $organizer) {
            Language::forPerson($organizer, fn () => $this->tell($organizer, $person, $slug, $kind, $giverName));
        }
    }

    /** One organizer, in the language they chose. */
    private function tell(Person $organizer, Person $person, string $slug, string $kind, string $giverName): void
    {
        if ('organizer' === $kind) {
            $line = '' === $giverName
                ? sprintf(
                    /* translators: %s: the person's name */
                    __('%s was made an organizer.', 'eventcrew'),
                    $person->name()
                )
                : sprintf(
                    /* translators: 1: the person's name, 2: the name of the organizer who did it */
                    __('%1$s was made an organizer by %2$s.', 'eventcrew'),
                    $person->name(),
                    $giverName
                );
            $subject = __('Someone was made an organizer', 'eventcrew');
        } elseif ('met' === $kind) {
            $line = sprintf(
                /* translators: 1: the person's name, 2: the role */
                __('%1$s now meets the conditions for the role %2$s. You can give them the role with /people.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                $person->name(),
                Roles::display($slug)
            );
            $subject = __('Someone meets the conditions for a role', 'eventcrew');
        } elseif ('' === $giverName) {
            $line = sprintf(
                /* translators: 1: the person's name, 2: the role */
                __('%1$s was given the role %2$s.', 'eventcrew'),
                $person->name(),
                Roles::display($slug)
            );
            $subject = __('Someone was given a role', 'eventcrew');
        } else {
            $line = sprintf(
                /* translators: 1: the person's name, 2: the role, 3: the name of the organizer who gave it */
                __('%1$s was given the role %2$s by %3$s.', 'eventcrew'),
                $person->name(),
                Roles::display($slug),
                $giverName
            );
            $subject = __('Someone was given a role', 'eventcrew');
        }

        if (null !== $organizer->telegramChatId && $organizer->wantsBotDms()) {
            $this->telegram->sendMessage($organizer->telegramChatId, DmBody::frame($organizer, $line));
        }

        $this->mailer->toPerson($organizer->id, $organizer->email, $subject, $line);
    }
}
