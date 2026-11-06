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
 * Each organizer chooses the channels in their notification settings: "met"
 * is the ROLE_MET row, "given" and "organizer" share the ROLE_NEWS row.
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
        private readonly TelegramClient $telegram,
        private readonly ?StandingCalculator $standing = null
    ) {
    }

    /**
     * "Anna has done 5 tasks · Good standing · 82%": the {record} of the
     * role_met notice, so the organizer can decide without looking.
     */
    public static function record(string $name, Standing $standing): string
    {
        return rtrim(TakeoverService::coverRecord($name, $standing->completedCount), '.')
            . ' · ' . $standing->ratedSummary();
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
        // The texts are the Messages page templates (MessageTemplates).
        $template = match ($kind) {
            'organizer' => 'organizer_made',
            'met' => 'role_met',
            default => 'role_given',
        };
        $context = ['member' => $person, 'role' => $slug, 'giver' => $giverName];

        if ('met' === $kind && null !== $this->standing) {
            $context['record'] = self::record($person->name(), $this->standing->for($person->id));
        }
        // The organizer's own rows in the notification settings.
        $type = 'met' === $kind ? NotificationPreferences::ROLE_MET : NotificationPreferences::ROLE_NEWS;
        $prefs = new NotificationPreferences();

        if ($prefs->dmAllowed($organizer, $type)) {
            $this->telegram->sendMessage(
                $organizer->telegramChatId,
                DmBody::frame($organizer, MessageTemplates::body($template . '_dm', $context)),
                MessageActions::replyMarkup(MessageActions::of($template . '_dm', $context, $this->mailer))
            );
        }

        if (! $prefs->emailAllowed($organizer, $type)) {
            return;
        }

        $this->mailer->toPerson(
            $organizer->id,
            $organizer->email,
            MessageTemplates::subject($template, $context),
            MessageTemplates::body($template, $context),
            MessageActions::email(MessageActions::of($template, $context, $this->mailer))
        );
    }
}
