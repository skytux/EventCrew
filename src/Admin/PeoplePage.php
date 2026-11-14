<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\AccessNotifier;
use EventCrew\Support\CreditGrantNotifier;
use EventCrew\Support\Dates;
use EventCrew\Support\NewcomerApproval;
use EventCrew\Support\OrganizerSync;
use EventCrew\Support\PersonEraser;
use EventCrew\Support\RoleConditions;
use EventCrew\Support\RoleNotifier;
use EventCrew\Support\Roles;
use EventCrew\Support\StandingCalculator;

final class PeoplePage
{
    public const PAGE_SLUG = 'eventcrew-people';
    private const NONCE_ACTION = 'eventcrew_person';

    public function __construct(
        private readonly View $view,
        private readonly PersonRepository $people,
        private readonly AssignmentRepository $assignments,
        private readonly StandingCalculator $standing,
        private readonly CreditGrantRepository $grants,
        private readonly CreditGrantNotifier $notifier,
        private readonly RoleConditions $conditions,
        private readonly PersonEraser $eraser,
        private readonly ?NewcomerApproval $newcomers = null
    ) {
    }

    public function render(): void
    {
        if (! class_exists('WP_List_Table')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }

        $table = new PeopleListTable($this->people, $this->assignments, $this->standing);
        $table->prepare_items();

        $editing = $this->personBeingEdited();

        $this->view->render(
            'people',
            [
                'table' => $table,
                'editing' => $editing,
                'editing_standing' => null === $editing ? null : $this->standing->for($editing->id),
                'credit_grants' => $this->creditGrantRows(),
                'nonce_action' => self::NONCE_ACTION,
                'page_slug' => self::PAGE_SLUG,
                // The Role conditions view, shown as a second tab on this page:
                // for each role with conditions, who meets them.
                'condition_roles' => $this->conditionRows(),
                // The Turned down tab: refused and timed-out newcomer
                // requests, each with an Accept button.
                'turned_down' => null === $this->newcomers ? null : $this->turnedDownRows(),
            ]
        );
    }

    /**
     * For each role with conditions: its name, the conditions in one line, and
     * who meets them now, with whether they already hold the role.
     *
     * @return list<array{label: string, conditions: string, people: list<array{id: int, name: string, holds: bool}>}>
     */
    private function conditionRows(): array
    {
        $report = $this->conditions->report();
        $rows = [];

        foreach (RoleConditions::rolesWithConditions() as $role) {
            $people = [];

            foreach ($report[$role['slug']] ?? [] as $row) {
                $people[] = ['id' => $row['person']->id, 'name' => $row['person']->name(), 'holds' => $row['holds']];
            }

            $rows[] = [
                'label' => Roles::display($role['slug']),
                'conditions' => RoleConditions::describe($role['conditions']),
                'people' => $people,
            ];
        }

        return $rows;
    }

    /**
     * The newcomers who were turned down or whose request timed out, newest
     * first: when, who, the email, who decided and the reason.
     *
     * @return list<array{id: int, name: string, email: string, when: string, by: string, reason: string}>
     */
    public function turnedDownRows(): array
    {
        $rows = [];

        foreach ($this->people->turnedDownNewcomers() as $person) {
            $reason = NewcomerApproval::reason($person);
            $at = NewcomerApproval::decidedAt($person);
            $timeout = NewcomerApproval::REASON_TIMEOUT === $reason;

            $rows[] = [
                'id' => $person->id,
                'name' => $person->name(),
                'email' => $person->email,
                'when' => '' === $at ? '' : Dates::signedUpLabel($at),
                'by' => $timeout ? __('Nobody (timed out)', 'eventcrew') : $person->approvalBy,
                'reason' => match ($reason) {
                    NewcomerApproval::REASON_TIMEOUT => __('No organizer answered in 48 hours. They were told.', 'eventcrew'),
                    NewcomerApproval::REASON_UNKNOWN => __('Unknown to the organizers. They were told.', 'eventcrew'),
                    NewcomerApproval::REASON_NONE => __('No reason. They were not told.', 'eventcrew'),
                    default => '',
                },
            ];
        }

        return $rows;
    }

    /**
     * Accept on the Turned down tab: the person gets the OK exactly as with
     * the ✅ OK button, and the same message.
     */
    public function acceptNewcomer(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $personId = isset($_POST['person_id']) ? (int) $_POST['person_id'] : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $by = (string) wp_get_current_user()->display_name;

        if (null === $this->newcomers || ! $this->newcomers->accept($personId, $by)) {
            Admin::redirectTo(self::PAGE_SLUG, __('That person could not be accepted. Someone may have done it already.', 'eventcrew'), 'error');
        }

        Admin::redirectTo(self::PAGE_SLUG, __('Accepted. They can take tasks now, and they are told.', 'eventcrew'));
    }

    /**
     * The recent credit-grant ledger for the audit panel: each grant with the
     * recipient's and granter's names resolved for display.
     *
     * @return array<int, array{person: string, credits: int, note: string, granted_by: string, granted_at: string}>
     */
    private function creditGrantRows(): array
    {
        $rows = [];

        foreach ($this->grants->recent(20) as $grant) {
            $person = $this->people->find($grant['person_id']);

            $rows[] = [
                'person' => null === $person ? __('(deleted person)', 'eventcrew') : $person->name(),
                'credits' => $grant['credits'],
                'note' => $grant['note'],
                'granted_by' => $this->granterName($grant['granted_by_person_id'], $grant['granted_by']),
                'granted_at' => $grant['granted_at'],
            ];
        }

        return $rows;
    }

    /**
     * A granter's display name, in order of specificity: the granting person
     * (🎁 Give credits in Telegram), a WordPress user (a wp-admin grant), or "the
     * system" only when neither was recorded.
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

    public function save(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $id = isset($_POST['person_id']) ? (int) $_POST['person_id'] : 0;
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $displayName = isset($_POST['display_name'])
            ? sanitize_text_field(wp_unslash($_POST['display_name']))
            : '';
        $notes = isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '';
        $ticked = isset($_POST['roles']) && is_array($_POST['roles'])
            ? array_map('sanitize_key', wp_unslash($_POST['roles']))
            : [];
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ('' === $email || ! is_email($email)) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('A valid email address is required - it is how a person is identified.', 'eventcrew'),
                'error'
            );
        }

        $existing = $this->people->findByEmail($email);

        if (null !== $existing && $existing->id !== $id) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('Another person already uses that email address.', 'eventcrew'),
                'error'
            );
        }

        $previous = $id > 0 ? $this->people->find($id) : null;
        $before = $previous?->roleSlugs ?? [];
        $roles = $this->rolesAfterSave($id, $ticked);

        // Organize is one of the role ticks: holding it, or another role
        // with Admin access, is what makes an organizer. No save may leave
        // the site without one.
        if (
            null !== $previous
            && OrganizerSync::personWouldLeaveNone($id, $previous->isOrganizer, $roles)
        ) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('That would leave no organizer: at least one person has to hold a role with Admin access.', 'eventcrew'),
                'error'
            );
        }

        $data = [
            'email' => $email,
            'display_name' => $displayName,
            'notes' => $notes,
            'role_slugs' => PersonRepository::encodeRoles($roles),
        ];

        // The wp-admin user's own name stands for who made the change.
        $giverName = (string) wp_get_current_user()->display_name;

        // The repository refreshes the command menu and announces a new
        // organizer when the roles change their organizer status.
        if ($id > 0) {
            $this->people->update($id, $data, $giverName, null === $previous ? null : $previous->isOrganizer);
            RoleNotifier::rolesChanged($id, $before, $roles, $giverName);

            Admin::redirectTo(self::PAGE_SLUG, __('Person updated.', 'eventcrew'));
        }

        // Deliberately left unverified and un-opted-in. An organizer typing
        // someone's address is not that person confirming it, and consent to
        // open-task email cannot be given on another person's behalf - both
        // only happen when the person acts themselves.
        // create() encodes the role list itself, so it gets the plain list:
        // the already-encoded "dj,vj" would lose its comma and become "djvj".
        $newId = $this->people->create(['role_slugs' => $roles] + $data, $giverName);

        if ($newId > 0) {
            RoleNotifier::rolesChanged($newId, [], $roles, $giverName);
        }

        Admin::redirectTo(self::PAGE_SLUG, __('Person added.', 'eventcrew'));
    }

    /**
     * The person's roles once the form's role checkboxes are applied. Only the
     * roles the form shows (Roles::assignable(): the active "Only people with
     * this role" roles and those with Admin access, Organize among them) are
     * changed; a role that is not shown, such as an archived one, is kept.
     *
     * @param array<int, string> $ticked
     * @return array<int, string>
     */
    private function rolesAfterSave(int $id, array $ticked): array
    {
        $shown = array_column(Roles::assignable(), 'slug');
        $person = $id > 0 ? $this->people->find($id) : null;
        $kept = null === $person ? [] : array_diff($person->roleSlugs, $shown);

        return array_values(array_merge($kept, array_intersect($shown, $ticked)));
    }

    /**
     * Hands a person one bonus free-entry credit by hand - for a spontaneous
     * task outside the usual earn-per-two-completed rule. The grant is a ledger
     * row, so the balance on the People list and in /me rises the same way an
     * earned credit does.
     */
    public function grantCredit(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $personId = isset($_POST['person_id']) ? (int) $_POST['person_id'] : 0;
        $note = isset($_POST['grant_note']) ? sanitize_text_field(wp_unslash($_POST['grant_note'])) : '';
        $credits = isset($_POST['grant_credits']) ? max(1, (int) $_POST['grant_credits']) : 1;
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $person = $personId > 0 ? $this->people->find($personId) : null;

        if (null === $person) {
            Admin::redirectTo(self::PAGE_SLUG, __('That credit could not be granted.', 'eventcrew'), 'error');
        }

        $this->grants->record($personId, $credits, $note, get_current_user_id());

        // Tell them on both channels, the same as a gift from the bot.
        $this->notifier->notify($person, $credits);

        Admin::redirectTo(
            self::PAGE_SLUG,
            __('Credit granted.', 'eventcrew'),
            'success',
            ['person' => (string) $personId]
        );
    }

    /**
     * Gives a person a one-time pass to sign up despite being at risk - the
     * wp-admin mirror of the bot's pass (/people). Spent on their next successful
     * signup (SignupService::claim).
     */
    public function grantPass(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $personId = isset($_POST['person_id']) ? (int) $_POST['person_id'] : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ($personId <= 0 || null === $this->people->find($personId)) {
            Admin::redirectTo(self::PAGE_SLUG, __('That pass could not be granted.', 'eventcrew'), 'error');
        }

        $this->people->grantAtRiskPass($personId);
        AccessNotifier::passGranted($personId);

        Admin::redirectTo(
            self::PAGE_SLUG,
            __('One-time sign-up pass granted.', 'eventcrew'),
            'success',
            ['person' => (string) $personId]
        );
    }

    /**
     * Lifts a block set after a forged admin request on the crew page. Their
     * web sessions stay signed out; they sign in again with a fresh link.
     */
    public function unban(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $personId = isset($_POST['person_id']) ? (int) $_POST['person_id'] : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ($personId <= 0 || null === $this->people->find($personId)) {
            Admin::redirectTo(self::PAGE_SLUG, __('That person could not be unbanned.', 'eventcrew'), 'error');
        }

        $this->people->unban($personId);

        Admin::redirectTo(
            self::PAGE_SLUG,
            __('Unbanned. They can sign in and sign up again.', 'eventcrew'),
            'success',
            ['person' => (string) $personId]
        );
    }

    public function delete(): void
    {
        if (! current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to do this.', 'eventcrew'));
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $id = isset($_GET['person']) ? (int) $_GET['person'] : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        check_admin_referer('eventcrew_delete_person_' . $id);

        if ($id > 0) {
            $this->eraser->erase($id);
        }

        Admin::redirectTo(self::PAGE_SLUG, __('Person deleted.', 'eventcrew'));
    }

    /**
     * @return \EventCrew\Models\Person|null
     */
    private function personBeingEdited(): ?object
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $id = isset($_GET['person']) ? (int) $_GET['person'] : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return $id > 0 ? $this->people->find($id) : null;
    }
}
