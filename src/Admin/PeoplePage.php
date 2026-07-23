<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
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
        private readonly StandingCalculator $standing
    ) {
    }

    public function render(): void
    {
        if (! class_exists('WP_List_Table')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }

        $table = new PeopleListTable($this->people, $this->assignments, $this->standing);
        $table->prepare_items();

        $this->view->render(
            'people',
            [
                'table' => $table,
                'editing' => $this->personBeingEdited(),
                'nonce_action' => self::NONCE_ACTION,
                'page_slug' => self::PAGE_SLUG,
            ]
        );
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
        $notifyMuted = isset($_POST['notify_muted']) && '1' === (string) $_POST['notify_muted'];
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
            'notify_muted' => $notifyMuted ? 1 : 0,
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
