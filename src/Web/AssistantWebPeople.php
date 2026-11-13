<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\Roles;
use EventCrew\Telegram\AssignCommand;
use EventCrew\Telegram\CommandFlow;

/**
 * The helper box's changes to one person (AssistantWebChanges), run on
 * Confirm through the Crew app's Admin tab, so the page's forms and the
 * helper never differ:
 *
 * - "menu:gift <person> ; <credits> [; <reason>]": CrewAdminController::giveCredit(),
 *   the member is told.
 * - "menu:roles <add|remove> <role> ; <person>": CrewAdminController::saveRoles(),
 *   the member is told.
 * - "/organizer <on|off> <person>": CrewAdminPeople::setOrganizer(); never
 *   one's own flag off, never the last organizer.
 * - "/deleteperson <person>": CrewAdminPeople::remove(), the data erased;
 *   never oneself, never the last organizer.
 * - "/newcomer <ok|no> [person]": CrewAdminController::decideNewcomer();
 *   without a name, the newcomer waiting (choices when several are).
 *
 * The person is found as /assign finds a member; when several match, the
 * page shows one button each (at most WebChanges::MAX_CHOICES).
 */
final class AssistantWebPeople
{
    public const GIFT = 'menu:gift';
    public const ROLES = 'menu:roles';
    public const ORGANIZER = 'organizer';
    public const DELETE = 'deleteperson';
    public const NEWCOMER = 'newcomer';

    /** The commands this class answers, as AssistantWebChanges splits them. */
    public const COMMANDS = [self::GIFT, self::ROLES, self::ORGANIZER, self::DELETE, self::NEWCOMER];

    public function __construct(
        private readonly PersonRepository $people,
        private readonly CrewAdminController $admin,
        private readonly CrewAdminPeople $peopleScreen
    ) {
    }

    /**
     * The question, the person to choose first, or (ok false) why not.
     *
     * @return array{ok: bool, text: string, state: array<string, mixed>, choices?: list<array{label: string, state: array<string, mixed>}>}
     */
    public function prepare(Person $organizer, string $command, string $rest): array
    {
        $parts = array_map('trim', explode(';', $rest));

        switch ($command) {
            case self::GIFT:
                $credits = (string) ($parts[1] ?? '');

                if ('' === $parts[0] || ! ctype_digit($credits) || strlen($credits) > 4 || (int) $credits < 1) {
                    return self::fail(CrewAdminController::noticeText('admin_bad_credits'));
                }

                $state = ['op' => self::GIFT, 'credits' => (int) $credits, 'reason' => mb_substr((string) ($parts[2] ?? ''), 0, 191)];

                return $this->person($organizer, $parts[0], $this->members(), $state);

            case self::ROLES:
                [$word, $roleQuery] = array_pad(explode(' ', $parts[0], 2), 2, '');
                $word = strtolower($word);
                $role = self::role($roleQuery);

                if (! in_array($word, ['add', 'remove'], true) || '' === ($parts[1] ?? '')) {
                    return self::fail(__('That can no longer be done.', 'eventcrew'));
                }

                if (null === $role) {
                    return self::fail(sprintf(
                        /* translators: %s: role names, comma separated. */
                        __('Choose one of these roles: %s.', 'eventcrew'),
                        implode(', ', array_column(Roles::assignable(), 'label'))
                    ));
                }

                $state = ['op' => self::ROLES, 'add' => 'add' === $word, 'role' => $role['slug']];

                return $this->person($organizer, (string) $parts[1], $this->members(), $state);

            case self::ORGANIZER:
                [$word, $query] = array_pad(explode(' ', $rest, 2), 2, '');
                $word = strtolower($word);

                if (! in_array($word, ['on', 'off'], true) || '' === trim($query)) {
                    return self::fail(__('That can no longer be done.', 'eventcrew'));
                }

                return $this->person($organizer, trim($query), $this->members(), ['op' => self::ORGANIZER, 'on' => 'on' === $word]);

            case self::DELETE:
                return '' === $rest
                    ? self::fail(__('That can no longer be done.', 'eventcrew'))
                    : $this->person($organizer, $rest, $this->members(), ['op' => self::DELETE]);

            case self::NEWCOMER:
                [$word, $query] = array_pad(explode(' ', $rest, 2), 2, '');
                $word = strtolower($word);

                if (! in_array($word, ['ok', 'no'], true)) {
                    return self::fail(__('That can no longer be done.', 'eventcrew'));
                }

                $waiting = array_values(array_filter(
                    $this->people->waitingNewcomers(),
                    static fn (Person $p): bool => ! $p->isAnonymized()
                ));

                if ([] === $waiting) {
                    return self::fail(__('No newcomer is waiting for an OK.', 'eventcrew'));
                }

                $state = ['op' => self::NEWCOMER, 'ok' => 'ok' === $word];
                $query = trim($query);

                if ('' === $query) {
                    return $this->among($organizer, $waiting, $state);
                }

                /* translators: %s: the name the organizer typed. */
                return $this->person($organizer, $query, $waiting, $state, sprintf(__('No newcomer called “%s” is waiting.', 'eventcrew'), $query));
        }

        return self::fail(__('That can no longer be done.', 'eventcrew'));
    }

    /**
     * The question once the person is chosen.
     *
     * @param array<string, mixed> $state
     * @return array{ok: bool, text: string, state: array<string, mixed>}
     */
    public function choose(Person $organizer, array $state): array
    {
        $person = $this->people->find((int) ($state['person'] ?? 0));

        if (null === $person || $person->isAnonymized()) {
            return self::fail(CrewAdminController::noticeText('admin_no_person'));
        }

        $name = $person->name();
        $op = (string) ($state['op'] ?? '');

        switch ($op) {
            case self::GIFT:
                $credits = (int) ($state['credits'] ?? 0);

                return self::ask(sprintf(
                    /* translators: 1: number of credits, 2: a person's name. */
                    _n('Give %1$d credit to %2$s? They are told.', 'Give %1$d credits to %2$s? They are told.', $credits, 'eventcrew'),
                    $credits,
                    $name
                ), $state);

            case self::ROLES:
                $slug = (string) ($state['role'] ?? '');
                $label = self::roleLabel($slug);
                $has = in_array($slug, $person->roleSlugs, true);

                if (! empty($state['add'])) {
                    return $has
                        /* translators: 1: a person's name, 2: a role name. */
                        ? self::fail(sprintf(__('%1$s already has the role %2$s.', 'eventcrew'), $name, $label))
                        /* translators: 1: a person's name, 2: a role name. */
                        : self::ask(sprintf(__('Give %1$s the role %2$s? They are told.', 'eventcrew'), $name, $label), $state);
                }

                return $has
                    /* translators: 1: a role name, 2: a person's name. */
                    ? self::ask(sprintf(__('Take the role %1$s from %2$s? They are told.', 'eventcrew'), $label, $name), $state)
                    /* translators: 1: a person's name, 2: a role name. */
                    : self::fail(sprintf(__('%1$s does not have the role %2$s.', 'eventcrew'), $name, $label));

            case self::ORGANIZER:
                $on = ! empty($state['on']);

                if ($person->isOrganizer === $on) {
                    return self::fail($on
                        /* translators: %s: a person's name. */
                        ? sprintf(__('%s is already an organizer.', 'eventcrew'), $name)
                        /* translators: %s: a person's name. */
                        : sprintf(__('%s is not an organizer.', 'eventcrew'), $name));
                }

                if (! $on && $person->id === $organizer->id) {
                    return self::fail(CrewAdminPeople::noticeText('admin_organizer_self'));
                }

                if (! $on && $this->isLastOrganizer($person->id)) {
                    return self::fail(CrewAdminPeople::noticeText('admin_last_organizer'));
                }

                return self::ask($on
                    /* translators: %s: a person's name. */
                    ? sprintf(__('Make %s an organizer? They are told.', 'eventcrew'), $name)
                    /* translators: %s: a person's name. */
                    : sprintf(__('Take the organizer role from %s?', 'eventcrew'), $name), $state);

            case self::DELETE:
                if ($person->id === $organizer->id) {
                    return self::fail(CrewAdminPeople::noticeText('admin_person_remove_self'));
                }

                if ($person->isOrganizer && $this->isLastOrganizer($person->id)) {
                    return self::fail(CrewAdminPeople::noticeText('admin_last_organizer'));
                }

                return self::ask(sprintf(
                    /* translators: %s: a person's name. */
                    __('Delete %s? Their data and history are erased. This cannot be undone.', 'eventcrew'),
                    $name
                ), $state);

            case self::NEWCOMER:
                return self::ask(! empty($state['ok'])
                    /* translators: %s: a person's name. */
                    ? sprintf(__('Give %s the OK? They can take tasks then, and they are told.', 'eventcrew'), $name)
                    /* translators: %s: a person's name. */
                    : sprintf(__('Say No to %s? They are not told.', 'eventcrew'), $name), $state);
        }

        return self::fail(__('That can no longer be done.', 'eventcrew'));
    }

    /**
     * Confirm: the change, through the Admin tab's own method.
     *
     * @param array<string, mixed> $state
     * @return array{changed: bool, text: string}
     */
    public function run(Person $organizer, array $state): array
    {
        $personId = (int) ($state['person'] ?? 0);
        $op = (string) ($state['op'] ?? '');

        switch ($op) {
            case self::GIFT:
                $code = $this->admin->giveCredit($organizer, $personId, (int) ($state['credits'] ?? 0), (string) ($state['reason'] ?? ''));

                return ['changed' => 'admin_credit_given' === $code, 'text' => CrewAdminController::noticeText($code)];

            case self::ROLES:
                $person = $this->people->find($personId);
                $slug = (string) ($state['role'] ?? '');

                if (null === $person || '' === $slug) {
                    return ['changed' => false, 'text' => CrewAdminController::noticeText('admin_no_person')];
                }

                $code = ! empty($state['add'])
                    ? $this->admin->saveRoles($personId, [$slug], [], $organizer->name())
                    : $this->admin->saveRoles($personId, null, array_values(array_diff($person->roleSlugs, [$slug])), $organizer->name());

                return ['changed' => 'admin_roles_saved' === $code, 'text' => CrewAdminController::noticeText($code)];

            case self::ORGANIZER:
                $on = ! empty($state['on']);
                $before = $this->people->find($personId);
                $code = $this->peopleScreen->setOrganizer($organizer, $personId, $on);
                $changed = null !== $before && $before->isOrganizer !== $on
                    && in_array($code, ['admin_organizer_on', 'admin_organizer_off'], true);

                return ['changed' => $changed, 'text' => CrewAdminPeople::noticeText($code)];

            case self::DELETE:
                $code = $this->peopleScreen->remove($organizer, $personId);

                return ['changed' => 'admin_person_removed' === $code, 'text' => CrewAdminPeople::noticeText($code)];

            case self::NEWCOMER:
                $code = $this->admin->decideNewcomer($organizer, $personId, ! empty($state['ok']) ? 'ok' : 'no');

                return [
                    'changed' => in_array($code, ['admin_newcomer_ok', 'admin_newcomer_no'], true),
                    'text' => CrewAdminController::noticeText($code),
                ];
        }

        return ['changed' => false, 'text' => __('That can no longer be done.', 'eventcrew')];
    }

    /**
     * The person $query names among $pool: the question, the choices, or
     * why not.
     *
     * @param list<Person> $pool
     * @param array<string, mixed> $state
     * @return array{ok: bool, text: string, state: array<string, mixed>, choices?: list<array{label: string, state: array<string, mixed>}>}
     */
    private function person(Person $organizer, string $query, array $pool, array $state, ?string $none = null): array
    {
        $found = AssignCommand::matchPeople($query, $pool);

        if ([] === $found) {
            /* translators: %s: the name the organizer typed. */
            return self::fail($none ?? sprintf(__('No member called “%s” was found.', 'eventcrew'), $query));
        }

        return $this->among($organizer, $found, $state);
    }

    /**
     * @param list<Person> $found
     * @param array<string, mixed> $state
     * @return array{ok: bool, text: string, state: array<string, mixed>, choices?: list<array{label: string, state: array<string, mixed>}>}
     */
    private function among(Person $organizer, array $found, array $state): array
    {
        if (1 === count($found)) {
            return $this->choose($organizer, ['person' => $found[0]->id] + $state);
        }

        $options = CommandFlow::personOptions($found);

        if (count($found) > WebChanges::MAX_CHOICES) {
            return self::fail(AssistantWebChanges::several(array_values($options)));
        }

        $choices = [];

        foreach ($options as $id => $label) {
            $choices[] = ['label' => $label, 'state' => ['person' => $id] + $state];
        }

        return ['ok' => false, 'text' => __('Which member?', 'eventcrew'), 'state' => [], 'choices' => $choices];
    }

    /**
     * Everyone who is a member: not anonymized.
     *
     * @return list<Person>
     */
    private function members(): array
    {
        return array_values(array_filter(
            $this->people->all(['per_page' => 2000, 'orderby' => 'display_name']),
            static fn (Person $p): bool => ! $p->isAnonymized()
        ));
    }

    private function isLastOrganizer(int $personId): bool
    {
        foreach ($this->people->organizers() as $other) {
            if ($other->id !== $personId) {
                return false;
            }
        }

        return true;
    }

    /**
     * The role a person can be given that $query names, by name or slug.
     *
     * @return array<string, mixed>|null
     */
    private static function role(string $query): ?array
    {
        $query = mb_strtolower(trim($query));

        if ('' === $query) {
            return null;
        }

        foreach (Roles::assignable() as $role) {
            if (in_array($query, [mb_strtolower((string) $role['label']), mb_strtolower((string) $role['slug'])], true)) {
                return $role;
            }
        }

        return null;
    }

    private static function roleLabel(string $slug): string
    {
        foreach (Roles::assignable() as $role) {
            if ($role['slug'] === $slug) {
                return (string) $role['label'];
            }
        }

        return $slug;
    }

    /**
     * @param array<string, mixed> $state
     * @return array{ok: bool, text: string, state: array<string, mixed>}
     */
    private static function ask(string $text, array $state): array
    {
        return ['ok' => true, 'text' => $text, 'state' => $state];
    }

    /** @return array{ok: bool, text: string, state: array<string, mixed>} */
    private static function fail(string $text): array
    {
        return ['ok' => false, 'text' => $text, 'state' => []];
    }
}
