<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\RosterAssembler;

/**
 * Attendance: who turned up for a given day's tasks, and marking how it went.
 *
 * The read side is the shared RosterAssembler; this page adds the date picker
 * and the two write actions - a per-person status change and a per-task "mark
 * the whole crew arrived/done" shortcut - both recording which organizer made
 * the change.
 */
final class RosterPage
{
    public const PAGE_SLUG = 'eventcrew-roster';
    private const NONCE_ACTION = 'eventcrew_attendance';

    public function __construct(
        private readonly View $view,
        private readonly RosterAssembler $assembler,
        private readonly AssignmentRepository $assignments,
        private readonly TaskRepository $tasks
    ) {
    }

    public function render(): void
    {
        $dates = $this->tasks->datesWithTasks(); // most recent first
        $today = current_time('Y-m-d');

        // Upcoming nearest-first, past most-recent-first - and the roster
        // defaults to the nearest upcoming date, since that's the crew you're
        // about to run; past dates sit below a separator for marking after.
        $upcoming = array_reverse(array_values(array_filter($dates, static fn (string $d): bool => $d >= $today)));
        $past = array_values(array_filter($dates, static fn (string $d): bool => $d < $today));

        $selected = $this->selectedDate($upcoming, $past);

        $this->view->render(
            'roster',
            [
                'upcoming_dates' => $upcoming,
                'past_dates' => $past,
                'selected_date' => $selected,
                'roster' => '' === $selected ? [] : $this->assembler->forDate($selected),
                'statuses' => $this->statusChoices(),
                'nonce_action' => self::NONCE_ACTION,
                'page_slug' => self::PAGE_SLUG,
            ]
        );
    }

    public function markAttendance(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $assignmentId = isset($_POST['assignment_id']) ? (int) $_POST['assignment_id'] : 0;
        $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : '';
        $date = isset($_POST['roster_date']) ? sanitize_text_field(wp_unslash($_POST['roster_date'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ($assignmentId <= 0 || ! AssignmentStatus::isValid($status)) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('That status could not be set.', 'eventcrew'),
                'error',
                $this->dateArg($date)
            );
        }

        $this->assignments->setStatus($assignmentId, $status, get_current_user_id());

        Admin::redirectTo(
            self::PAGE_SLUG,
            __('Attendance updated.', 'eventcrew'),
            'success',
            $this->dateArg($date)
        );
    }

    public function markAllForTask(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $taskId = isset($_POST['task_id']) ? (int) $_POST['task_id'] : 0;
        $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : '';
        $date = isset($_POST['roster_date']) ? sanitize_text_field(wp_unslash($_POST['roster_date'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        // Bulk marking only ever sets a whole crew arrived or completed - the
        // outcomes that apply to everyone. Every other status is per-person.
        if ($taskId <= 0 || ! in_array($status, [AssignmentStatus::ARRIVED, AssignmentStatus::COMPLETED], true)) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('That bulk update could not be applied.', 'eventcrew'),
                'error',
                $this->dateArg($date)
            );
        }

        $changedBy = get_current_user_id();
        $updated = 0;

        foreach ($this->assignments->forTask($taskId) as $assignment) {
            // Skip anyone already cancelled or marked absent - bulk "arrived"
            // is about the crew who turned up, not overwriting a recorded
            // absence.
            if (in_array($assignment->status, [AssignmentStatus::SIGNED_UP, AssignmentStatus::ARRIVED], true)) {
                $this->assignments->setStatus($assignment->id, $status, $changedBy);
                ++$updated;
            }
        }

        Admin::redirectTo(
            self::PAGE_SLUG,
            sprintf(
                /* translators: %d: number of people updated */
                _n('%d person updated.', '%d people updated.', $updated, 'eventcrew'),
                $updated
            ),
            'success',
            $this->dateArg($date)
        );
    }

    /**
     * The requested date when it's a real date with tasks, otherwise the
     * default: the nearest upcoming date, or the most recent past one when
     * nothing is upcoming.
     *
     * @param array<int, string> $upcoming Nearest-first.
     * @param array<int, string> $past     Most-recent-first.
     */
    private function selectedDate(array $upcoming, array $past): string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $requested = isset($_GET['roster_date'])
            ? sanitize_text_field(wp_unslash($_GET['roster_date']))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if (in_array($requested, $upcoming, true) || in_array($requested, $past, true)) {
            return $requested;
        }

        return $upcoming[0] ?? ($past[0] ?? '');
    }

    /**
     * @return array<string, string>
     */
    private function statusChoices(): array
    {
        $choices = [];

        foreach (AssignmentStatus::all() as $status) {
            $choices[$status] = AssignmentStatus::label($status);
        }

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    private function dateArg(string $date): array
    {
        return $this->isValidDate($date) ? ['roster_date' => $date] : [];
    }

    private function isValidDate(string $date): bool
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return checkdate($month, $day, $year);
    }
}
