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
 *   page, the People page, or by being added to one of its tasks.
 *
 * The record of what was sent is the person's role_notices column
 * ("met:<slug>", "given:<slug>").
 */
final class RoleNotifier
{
    /** Fired with (int $personId, string $roleSlug) when someone is given a role. */
    public const GIVEN_HOOK = 'eventcrew/role_given';

    public function __construct(
        private readonly RoleConditions $conditions,
        private readonly PersonRepository $people,
        private readonly Mailer $mailer,
        private readonly TelegramClient $telegram
    ) {
    }

    /**
     * Fires GIVEN_HOOK for every role in $after that was not in $before.
     *
     * @param array<int, string> $before
     * @param array<int, string> $after
     */
    public static function rolesChanged(int $personId, array $before, array $after): void
    {
        foreach (array_diff($after, $before) as $slug) {
            if ('' !== $slug && Roles::ORGANIZE_SLUG !== $slug) {
                do_action(self::GIVEN_HOOK, $personId, $slug);
            }
        }
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
    public function roleGiven(int $personId, string $slug): void
    {
        $person = $this->people->find($personId);
        $key = 'given:' . $slug;

        if (null === $person || $person->hasRoleNotice($key)) {
            return;
        }

        $organizers = $this->people->organizers();

        if ([] !== $organizers) {
            $this->announce($organizers, $person, $slug, 'given');
        }

        $this->people->markRoleNotice($person->id, $key);
    }

    /**
     * @param array<int, Person> $organizers
     */
    private function announce(array $organizers, Person $person, string $slug, string $kind): void
    {
        foreach ($organizers as $organizer) {
            Language::forPerson($organizer, fn () => $this->tell($organizer, $person, $slug, $kind));
        }
    }

    /** One organizer, in the language they chose. */
    private function tell(Person $organizer, Person $person, string $slug, string $kind): void
    {
        if ('met' === $kind) {
            $line = sprintf(
                /* translators: 1: the person's name, 2: the role */
                __('%1$s now meets the conditions for the role %2$s. You can give them the role with /people.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                $person->name(),
                Roles::display($slug)
            );
            $subject = __('Someone meets the conditions for a role', 'eventcrew');
        } else {
            $line = sprintf(
                /* translators: 1: the person's name, 2: the role */
                __('%1$s was given the role %2$s.', 'eventcrew'),
                $person->name(),
                Roles::display($slug)
            );
            $subject = __('Someone was given a role', 'eventcrew');
        }

        if (null !== $organizer->telegramChatId && $organizer->wantsBotDms()) {
            $this->telegram->sendMessage($organizer->telegramChatId, DmBody::frame($organizer, $line));
        }

        $this->mailer->toPerson($organizer->id, $organizer->email, $subject, $line);
    }
}
