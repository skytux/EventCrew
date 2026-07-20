<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\VolunteerRepository;

final class VolunteersPage
{
    public const PAGE_SLUG = 'eventcrew-volunteers';
    private const NONCE_ACTION = 'eventcrew_volunteer';

    public function __construct(
        private readonly View $view,
        private readonly VolunteerRepository $volunteers,
        private readonly AssignmentRepository $assignments
    ) {
    }

    public function render(): void
    {
        if (! class_exists('WP_List_Table')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }

        $table = new VolunteersListTable($this->volunteers, $this->assignments);
        $table->prepare_items();

        $this->view->render(
            'volunteers',
            [
                'table' => $table,
                'editing' => $this->volunteerBeingEdited(),
                'nonce_action' => self::NONCE_ACTION,
                'page_slug' => self::PAGE_SLUG,
            ]
        );
    }

    public function save(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $id = isset($_POST['volunteer_id']) ? (int) $_POST['volunteer_id'] : 0;
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $displayName = isset($_POST['display_name'])
            ? sanitize_text_field(wp_unslash($_POST['display_name']))
            : '';
        $notes = isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '';
        $isOrganizer = isset($_POST['is_organizer']) && '1' === (string) $_POST['is_organizer'];
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ('' === $email || ! is_email($email)) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('A valid email address is required - it is how a volunteer is identified.', 'eventcrew'),
                'error'
            );
        }

        $existing = $this->volunteers->findByEmail($email);

        if (null !== $existing && $existing->id !== $id) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('Another volunteer already uses that email address.', 'eventcrew'),
                'error'
            );
        }

        $data = [
            'email' => $email,
            'display_name' => $displayName,
            'notes' => $notes,
            'is_organizer' => $isOrganizer,
        ];

        if ($id > 0) {
            $this->volunteers->update($id, $data);

            Admin::redirectTo(self::PAGE_SLUG, __('Volunteer updated.', 'eventcrew'));
        }

        // Deliberately left unverified and un-opted-in. An organizer typing
        // someone's address is not that person confirming it, and consent to
        // open-shift email cannot be given on another person's behalf - both
        // only happen when the volunteer acts themselves.
        $this->volunteers->create($data);

        Admin::redirectTo(self::PAGE_SLUG, __('Volunteer added.', 'eventcrew'));
    }

    public function delete(): void
    {
        if (! current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to do this.', 'eventcrew'));
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $id = isset($_GET['volunteer']) ? (int) $_GET['volunteer'] : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        check_admin_referer('eventcrew_delete_volunteer_' . $id);

        if ($id > 0) {
            $this->assignments->deleteForVolunteer($id);
            $this->volunteers->delete($id);
        }

        Admin::redirectTo(self::PAGE_SLUG, __('Volunteer deleted.', 'eventcrew'));
    }

    /**
     * @return \EventCrew\Models\Volunteer|null
     */
    private function volunteerBeingEdited(): ?object
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $id = isset($_GET['volunteer']) ? (int) $_GET['volunteer'] : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return $id > 0 ? $this->volunteers->find($id) : null;
    }
}
