<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\ShiftRepository;
use EventCrew\Repositories\VolunteerRepository;
use EventCrew\Support\TaskTypes;

final class ShiftsPage
{
    public const PAGE_SLUG = 'eventcrew';
    private const NONCE_ACTION = 'eventcrew_shift';

    public function __construct(
        private readonly View $view,
        private readonly ShiftRepository $shifts,
        private readonly AssignmentRepository $assignments,
        private readonly VolunteerRepository $volunteers
    ) {
    }

    public function render(): void
    {
        // WP_List_Table lives in a file wp-admin loads on demand rather than
        // one that is always present, so it has to be pulled in before the
        // subclass below is autoloaded - referencing the class first would
        // fatal on the missing parent.
        if (! class_exists('WP_List_Table')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }

        $table = new ShiftsListTable($this->shifts);
        $table->prepare_items();

        $this->view->render(
            'shifts',
            [
                'table' => $table,
                'editing' => $this->shiftBeingEdited(),
                'task_types' => TaskTypes::all(),
                'roster' => $this->rosterForEditedShift(),
                'nonce_action' => self::NONCE_ACTION,
                'page_slug' => self::PAGE_SLUG,
            ]
        );
    }

    public function save(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $id = isset($_POST['shift_id']) ? (int) $_POST['shift_id'] : 0;

        $taskSlug = isset($_POST['task_slug']) ? sanitize_key(wp_unslash($_POST['task_slug'])) : '';
        $shiftDate = isset($_POST['shift_date']) ? sanitize_text_field(wp_unslash($_POST['shift_date'])) : '';
        $startsAt = isset($_POST['starts_at']) ? sanitize_text_field(wp_unslash($_POST['starts_at'])) : '';
        $endsAt = isset($_POST['ends_at']) ? sanitize_text_field(wp_unslash($_POST['ends_at'])) : '';
        $eventLabel = isset($_POST['event_label']) ? sanitize_text_field(wp_unslash($_POST['event_label'])) : '';
        $eventPostId = isset($_POST['event_post_id']) ? (int) $_POST['event_post_id'] : 0;
        $capacity = isset($_POST['capacity']) ? (int) $_POST['capacity'] : 1;
        $notes = isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if (! $this->isValidDate($shiftDate)) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('That date could not be read. Use the date picker and try again.', 'eventcrew'),
                'error'
            );
        }

        if (! TaskTypes::exists($taskSlug)) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('Pick a task group that still exists in Settings.', 'eventcrew'),
                'error'
            );
        }

        $data = [
            'task_slug' => $taskSlug,
            'shift_date' => $shiftDate,
            'starts_at' => $this->normalizeTime($startsAt),
            'ends_at' => $this->normalizeTime($endsAt),
            'event_label' => $eventLabel,
            'event_post_id' => $eventPostId > 0 ? $eventPostId : null,
            'capacity' => max(1, $capacity),
            'notes' => $notes,
        ];

        if ($id > 0) {
            $this->shifts->update($id, $data);

            Admin::redirectTo(self::PAGE_SLUG, __('Shift updated.', 'eventcrew'));
        }

        $this->shifts->create($data);

        Admin::redirectTo(self::PAGE_SLUG, __('Shift added.', 'eventcrew'));
    }

    public function delete(): void
    {
        if (! current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to do this.', 'eventcrew'));
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $id = isset($_GET['shift']) ? (int) $_GET['shift'] : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        check_admin_referer('eventcrew_delete_shift_' . $id);

        if ($id > 0) {
            $this->shifts->delete($id);
        }

        Admin::redirectTo(self::PAGE_SLUG, __('Shift deleted.', 'eventcrew'));
    }

    /**
     * @return \EventCrew\Models\Shift|null
     */
    private function shiftBeingEdited(): ?object
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $id = isset($_GET['shift']) ? (int) $_GET['shift'] : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return $id > 0 ? $this->shifts->find($id) : null;
    }

    /**
     * Who is signed up for the shift currently open in the editor, so the
     * organizer can see the consequences of changing or deleting it.
     *
     * @return array<int, array{name: string, status: string}>
     */
    private function rosterForEditedShift(): array
    {
        $shift = $this->shiftBeingEdited();

        if (null === $shift) {
            return [];
        }

        $roster = [];

        foreach ($this->assignments->forShift($shift->id) as $assignment) {
            $volunteer = $this->volunteers->find($assignment->volunteerId);

            $roster[] = [
                'name' => null === $volunteer
                    ? __('(deleted volunteer)', 'eventcrew')
                    : $volunteer->name(),
                'status' => $assignment->statusLabel(),
            ];
        }

        return $roster;
    }

    /**
     * Accepts only what an <input type="date"> actually submits, and rejects
     * impossible dates like 2026-02-31 that would otherwise be silently
     * rewritten by MySQL into something the organizer never chose.
     */
    private function isValidDate(string $date): bool
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return checkdate($month, $day, $year);
    }

    /**
     * An empty time field is a legitimate "not decided yet", stored as NULL
     * rather than 00:00, which would read as midnight on the roster.
     */
    private function normalizeTime(string $time): ?string
    {
        if (1 !== preg_match('/^(\d{2}):(\d{2})$/', $time, $matches)) {
            return null;
        }

        if ((int) $matches[1] > 23 || (int) $matches[2] > 59) {
            return null;
        }

        return $time . ':00';
    }
}
