<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Person;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Support\AccessNotifier;
use EventCrew\Support\Dates;
use EventCrew\Support\OrganizerSync;
use EventCrew\Support\PersonEraser;
use EventCrew\Support\RoleNotifier;
use EventCrew\Support\Roles;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\ExtraTaskCredits;

/**
 * The People screen of the crew page's Admin tab, made to match the wp-admin
 * People page: the list of people with search, adding and editing a person
 * (email, name, roles, notes), the one-time sign-up pass, Unban, the
 * person's credit log, the organizer flag and deleting a person.
 *
 * The save never writes is_organizer, whatever the request holds: the flag
 * has its own action, setOrganizer(), which does what the bot's /people
 * does. remove() is wp-admin's Delete (PersonEraser::erase()). Neither lets
 * an organizer act on themselves, and neither leaves the team with no
 * organizer.
 *
 * CrewAdminController guards every action (session, organizer flag, CSRF,
 * POST only) before it calls a method here. The methods only check their own
 * input and answer with an 'admin_' notice code.
 */
final class CrewAdminPeople
{
    /** People per page of the list: short enough to scroll on a phone. */
    public const PER_PAGE = 20;

    /** How many lines of a person's credit log are shown. */
    private const LOG_LINES = 20;

    public function __construct(
        private readonly PersonRepository $people,
        private readonly CreditGrantRepository $grants,
        private readonly RedemptionRepository $redemptions,
        private readonly StandingCalculator $standing,
        private readonly ?PersonEraser $eraser = null
    ) {
    }

    /**
     * The People screen for the template: one page of the list, matching
     * $search, and the person open for editing ($personId), or null.
     *
     * @return array{
     *     search: string,
     *     page: int,
     *     pages: int,
     *     total: int,
     *     list: list<array{id: int, name: string, email: string, verified: bool,
     *         organizer: bool, banned: bool, anonymized: bool}>,
     *     editing: ?array<string, mixed>
     * }
     */
    public function view(string $search, int $page, int $personId): array
    {
        $search = trim($search);
        $total = $this->people->count($search);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $pages));
        $list = [];

        foreach (
            $this->people->all([
                'search' => $search,
                'orderby' => 'display_name',
                'order' => 'asc',
                'per_page' => self::PER_PAGE,
                'page' => $page,
            ]) as $person
        ) {
            $list[] = [
                'id' => $person->id,
                'name' => $person->name(),
                'email' => $person->email,
                'verified' => $person->isEmailVerified(),
                'organizer' => $person->isOrganizer,
                'banned' => $person->isBanned(),
                'anonymized' => $person->isAnonymized(),
            ];
        }

        return [
            'search' => $search,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'list' => $list,
            'editing' => $personId > 0 ? $this->editing($personId) : null,
        ];
    }

    /**
     * "Add person" ($personId 0) or "Update person": the wp-admin save, without
     * the organizer flag. Email is required and must not belong to anyone
     * else. Only the active restricted roles are changed; a role not shown,
     * such as an archived one, is kept. A new person is left unverified, as in
     * wp-admin: typing someone's address is not that person confirming it.
     *
     * @param array<int, string> $ticked The role slugs ticked on the form.
     */
    public function save(
        int $personId,
        string $email,
        string $name,
        string $notes,
        array $ticked,
        string $giverName = ''
    ): string {
        if ($personId < 0) {
            return 'admin_no_person';
        }

        $before = null;

        if ($personId > 0) {
            $before = $this->people->find($personId);

            if (null === $before || $before->isAnonymized()) {
                return 'admin_no_person';
            }
        }

        if ('' === $email || ! is_email($email)) {
            return 'admin_person_bad_email';
        }

        $existing = $this->people->findByEmail($email);

        if (null !== $existing && $existing->id !== $personId) {
            return 'admin_person_email_taken';
        }

        $shown = array_column(Roles::assignable(), 'slug');
        $kept = null === $before ? [] : array_diff($before->roleSlugs, $shown);
        $roles = array_values(array_merge($kept, array_intersect($shown, $ticked)));

        // A role with Admin access ticked or unticked here can change who is
        // an organizer, so the lockout guard applies (Organize itself is not
        // on the form: it has its own action, setOrganizer()).
        if (null !== $before && OrganizerSync::personWouldLeaveNone($before->id, $before->isOrganizer, $roles)) {
            return 'admin_last_organizer';
        }

        $data = [
            'email' => $email,
            'display_name' => $name,
            'notes' => $notes,
            'role_slugs' => PersonRepository::encodeRoles($roles),
        ];

        if (null !== $before) {
            $this->people->update($before->id, $data, $giverName, $before->isOrganizer);
            RoleNotifier::rolesChanged($before->id, $before->roleSlugs, $roles, $giverName);

            return 'admin_person_updated';
        }

        // create() encodes the roles itself; handing it the encoded string
        // would run "dj,vj" through sanitize_key() as one slug, "djvj".
        $newId = $this->people->create(['role_slugs' => $roles] + $data, $giverName);

        if ($newId > 0) {
            RoleNotifier::rolesChanged($newId, [], $roles, $giverName);
        }

        return 'admin_person_added';
    }

    /**
     * "Grant a one-time pass": lets the person sign up once even if their
     * standing is at risk, as on the wp-admin People page and the bot's /people.
     */
    public function grantPass(int $personId): string
    {
        $person = $personId > 0 ? $this->people->find($personId) : null;

        if (null === $person || $person->isAnonymized()) {
            return 'admin_no_person';
        }

        $this->people->grantAtRiskPass($person->id);
        AccessNotifier::passGranted($person->id);

        return 'admin_pass_granted';
    }

    /** "Unban": lifts a block, as on the wp-admin People page. */
    public function unban(int $personId): string
    {
        $person = $personId > 0 ? $this->people->find($personId) : null;

        if (null === $person || $person->isAnonymized()) {
            return 'admin_no_person';
        }

        $this->people->unban($person->id);

        return 'admin_person_unbanned';
    }

    /**
     * "Delete this person": wp-admin's Delete, the same PersonEraser::erase()
     * (their tasks, credits, redemptions, sign-in links, push subscriptions
     * and the rest go with them). Never the organizer themselves, and never
     * the last organizer.
     */
    public function remove(Person $organizer, int $personId): string
    {
        $person = $personId > 0 ? $this->people->find($personId) : null;

        if (null === $this->eraser || null === $person || $person->isAnonymized()) {
            return 'admin_no_person';
        }

        if ($person->id === $organizer->id) {
            return 'admin_person_remove_self';
        }

        if ($person->isOrganizer && $this->isLastOrganizer($person->id)) {
            return 'admin_last_organizer';
        }

        $this->eraser->erase($person->id);

        return 'admin_person_removed';
    }

    /**
     * Makes the person an organizer ($on) or takes the flag away, as the
     * bot's /people button does: the flag, the Telegram command menu
     * refreshed, and the welcome to a new organizer. Never takes the flag
     * from the organizer themselves, and never from the last organizer.
     */
    public function setOrganizer(Person $organizer, int $personId, bool $on): string
    {
        $person = $personId > 0 ? $this->people->find($personId) : null;

        if (null === $person || $person->isAnonymized()) {
            return 'admin_no_person';
        }

        if ($person->isOrganizer === $on) {
            return $on ? 'admin_organizer_on' : 'admin_organizer_off';
        }

        if (! $on && $person->id === $organizer->id) {
            return 'admin_organizer_self';
        }

        if (! $on && $this->isLastOrganizer($person->id)) {
            return 'admin_last_organizer';
        }

        // Gives Organize or takes every Admin access role; the repository
        // refreshes the command menu and welcomes a new organizer.
        $this->people->setOrganizerFor($person, $on, $organizer->name());

        return $on ? 'admin_organizer_on' : 'admin_organizer_off';
    }

    /** Whether $personId is the only organizer left. */
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
     * The text for one of this screen's notice codes, or '' when the code is
     * not one of them. The texts are the wp-admin People page's own where it
     * has one.
     */
    public static function noticeText(string $code): string
    {
        $map = [
            'admin_person_bad_email' => __(
                'A valid email address is required - it is how a person is identified.',
                'eventcrew'
            ),
            'admin_person_email_taken' => __('Another person already uses that email address.', 'eventcrew'),
            'admin_person_updated' => __('Person updated.', 'eventcrew'),
            'admin_person_added' => __('Person added.', 'eventcrew'),
            'admin_pass_granted' => __('One-time sign-up pass granted.', 'eventcrew'),
            'admin_person_unbanned' => __('Unbanned. They can sign in and sign up again.', 'eventcrew'),
            'admin_person_removed' => __('Person deleted.', 'eventcrew'),
            'admin_person_remove_self' => __('Nothing was deleted: you cannot delete yourself here.', 'eventcrew'),
            'admin_last_organizer' => __('Nothing was changed: the team needs at least one organizer.', 'eventcrew'),
            'admin_organizer_on' => __('They are now an organizer.', 'eventcrew'),
            'admin_organizer_off' => __('They are no longer an organizer.', 'eventcrew'),
            'admin_organizer_self' => __('Nothing was changed: you cannot take away your own organizer flag.', 'eventcrew'),
        ];

        return $map[$code] ?? '';
    }

    /**
     * The person open for editing, with their standing and credit log, or
     * null when they are not found or their record is anonymised.
     *
     * @return array<string, mixed>|null
     */
    private function editing(int $personId): ?array
    {
        $person = $this->people->find($personId);

        if (null === $person || $person->isAnonymized()) {
            return null;
        }

        $standing = $this->standing->for($person->id);
        $given = [];
        $spent = [];

        foreach ($this->grants->forPerson($person->id, self::LOG_LINES) as $grant) {
            $given[] = [
                'credits' => $grant['credits'],
                'note' => $grant['note'],
                'granted_by' => $this->granterName($grant['granted_by_person_id'], $grant['granted_by']),
                'granted_at' => $grant['granted_at'],
            ];
        }

        foreach (array_slice($this->redemptions->forPerson($person->id), 0, self::LOG_LINES) as $redemption) {
            $spent[] = [
                'day' => null === $redemption->redeemedFor ? '' : Dates::dayLabel($redemption->redeemedFor),
                'event' => $redemption->eventLabel,
                'redeemed_at' => $redemption->redeemedAt,
            ];
        }

        return [
            'person' => $person,
            'standing' => $standing->ratedSummary(),
            'at_risk' => $standing->isAtRisk(),
            'credits' => $standing->creditBalance,
            // Credits for extra tasks on the same night, not yet added.
            'pending' => (new ExtraTaskCredits())->pendingFor($person->id),
            'given' => $given,
            'spent' => $spent,
        ];
    }

    /**
     * A granter's name, as the wp-admin People page shows it: the granting
     * person, a WordPress user, or "the system" when neither was recorded.
     */
    private function granterName(?int $personId, ?int $userId): string
    {
        if (null !== $personId && $personId > 0) {
            $person = $this->people->find($personId);

            return null === $person ? __('(deleted person)', 'eventcrew') : $person->name();
        }

        if (null !== $userId && 0 !== $userId) {
            $user = get_userdata($userId);

            return false === $user ? __('(unknown)', 'eventcrew') : (string) $user->display_name;
        }

        return __('the system', 'eventcrew');
    }
}
