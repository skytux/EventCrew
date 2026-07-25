<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Support\CreditGrantNotifier;
use EventCrew\Support\LeaderEligibility;
use EventCrew\Support\LeaderGate;
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
        private readonly RedemptionRepository $redemptions,
        private readonly StandingCalculator $standing,
        private readonly CreditGrantRepository $grants,
        private readonly CreditGrantNotifier $notifier,
        private readonly LeaderEligibility $eligibility,
        private readonly LeaderGate $leaderGate
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
                // The Leadership view, shown as a second tab on this page: who has
                // earned crew-leader eligibility and who has been granted it.
                'lead_roles' => array_map(
                    static fn (array $role): array => ['slug' => $role['slug'], 'label' => $role['label']],
                    Roles::active()
                ),
                'lead_eligible' => $this->eligibleRows(),
                'lead_allowed' => $this->allowedRows(),
                'lead_threshold' => $this->eligibility->threshold(),
                'lead_default' => $this->leaderGate->enabledByDefault(),
            ]
        );
    }

    /**
     * People who meet the crew-leader bar, with their per-role completion counts.
     *
     * @return array<int, array{id: int, name: string, can_lead: bool, by_role: array<string, int>}>
     */
    private function eligibleRows(): array
    {
        $rows = [];

        foreach ($this->eligibility->eligiblePeople() as $person) {
            $rows[] = [
                'id' => $person->id,
                'name' => $person->name(),
                'can_lead' => $person->canLead(),
                'by_role' => $this->eligibility->byActiveRole($person->id),
            ];
        }

        return $rows;
    }

    /**
     * Everyone who currently holds leader permission.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function allowedRows(): array
    {
        $rows = [];

        foreach ($this->people->all(['per_page' => 1000]) as $person) {
            if ($person->canLead()) {
                $rows[] = ['id' => $person->id, 'name' => $person->name()];
            }
        }

        return $rows;
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
     * (a /gift from Telegram), a WordPress user (a wp-admin grant), or "the
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
        $isOrganizer = isset($_POST['is_organizer']) && '1' === (string) $_POST['is_organizer'];
        $canLead = isset($_POST['can_lead']) && '1' === (string) $_POST['can_lead'];
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

        $data = [
            'email' => $email,
            'display_name' => $displayName,
            'notes' => $notes,
            'is_organizer' => $isOrganizer,
            'can_lead' => $canLead ? 1 : 0,
        ];

        if ($id > 0) {
            $this->people->update($id, $data);

            Admin::redirectTo(self::PAGE_SLUG, __('Person updated.', 'eventcrew'));
        }

        // Deliberately left unverified and un-opted-in. An organizer typing
        // someone's address is not that person confirming it, and consent to
        // open-task email cannot be given on another person's behalf - both
        // only happen when the person acts themselves.
        $this->people->create($data);

        Admin::redirectTo(self::PAGE_SLUG, __('Person added.', 'eventcrew'));
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

        // Tell them on both channels, the same as a gift from the bot's /gift.
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
     * wp-admin mirror of the bot's /allow pass. Spent on their next successful
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

        Admin::redirectTo(
            self::PAGE_SLUG,
            __('One-time sign-up pass granted.', 'eventcrew'),
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
            $this->assignments->deleteForPerson($id);
            $this->redemptions->deleteForPerson($id);
            $this->grants->deleteForPerson($id);
            $this->people->delete($id);
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
