<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\DoorList;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\LeaderGate;
use EventCrew\Support\Roles;
use EventCrew\Support\RosterAssembler;
use EventCrew\Support\StandingCalculator;

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
        private readonly TaskRepository $tasks,
        private readonly DoorList $doorList,
        private readonly RedemptionRepository $redemptions,
        private readonly StandingCalculator $standing,
        private readonly FreeEntryGate $freeEntry,
        private readonly LeaderGate $leaderGate
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

        $door = '' === $selected
            ? ['entrants' => [], 'candidates' => []]
            : $this->doorList->forDate($selected);

        $this->view->render(
            'roster',
            [
                'upcoming_dates' => $upcoming,
                'past_dates' => $past,
                'selected_date' => $selected,
                'roster' => '' === $selected ? [] : $this->assembler->forDate($selected),
                'door' => $door,
                'free_entry_closed' => '' !== $selected && $this->freeEntry->isClosed($selected),
                'leader_enabled' => '' !== $selected && $this->leaderGate->isEnabled($selected),
                'statuses' => $this->statusChoices(),
                'nonce_action' => self::NONCE_ACTION,
                'page_slug' => self::PAGE_SLUG,
            ]
        );
    }

    /**
     * Records a credit spent for free entry on the roster's date. The balance
     * is re-checked here, not trusted from the rendered pick-list, so a stale
     * page or a double submit cannot spend a credit that is not there.
     */
    public function redeemCredit(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $personId = isset($_POST['person_id']) ? (int) $_POST['person_id'] : 0;
        $date = isset($_POST['roster_date']) ? sanitize_text_field(wp_unslash($_POST['roster_date'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ($personId <= 0 || ! $this->isValidDate($date)) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('That credit could not be redeemed.', 'eventcrew'),
                'error',
                $this->dateArg($date)
            );
        }

        if ($this->freeEntry->isClosed($date)) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('Free entry is closed for this date.', 'eventcrew'),
                'error',
                $this->dateArg($date)
            );
        }

        if ($this->standing->for($personId)->creditBalance < 1) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('They have no credit left to redeem.', 'eventcrew'),
                'error',
                $this->dateArg($date)
            );
        }

        [$eventPostId, $eventLabel] = $this->eventContext($date);
        $this->redemptions->record($personId, $date, $eventPostId, $eventLabel);

        Admin::redirectTo(
            self::PAGE_SLUG,
            __('Credit redeemed — they’re on the door list.', 'eventcrew'),
            'success',
            $this->dateArg($date)
        );
    }

    /**
     * Undoes a redemption - a mistake at a busy door - handing the credit back.
     */
    public function removeRedemption(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $redemptionId = isset($_POST['redemption_id']) ? (int) $_POST['redemption_id'] : 0;
        $date = isset($_POST['roster_date']) ? sanitize_text_field(wp_unslash($_POST['roster_date'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ($redemptionId > 0) {
            $this->redemptions->delete($redemptionId);
        }

        Admin::redirectTo(
            self::PAGE_SLUG,
            __('Credit handed back.', 'eventcrew'),
            'success',
            $this->dateArg($date)
        );
    }

    /**
     * Closes or reopens free entry for the roster's date - a sold-out night, or
     * a special event that credits can't be spent on. While closed, neither this
     * page's "Redeem a credit" nor the self-service /ticket flow will spend a
     * credit for that date.
     */
    public function toggleTicketClosed(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $date = isset($_POST['roster_date']) ? sanitize_text_field(wp_unslash($_POST['roster_date'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if (! $this->isValidDate($date)) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('That change could not be applied.', 'eventcrew'),
                'error'
            );
        }

        if ($this->freeEntry->isClosed($date)) {
            $this->freeEntry->open($date);
            $message = __('Free entry reopened for this date.', 'eventcrew');
        } else {
            $this->freeEntry->close($date);
            $message = __('Free entry closed for this date.', 'eventcrew');
        }

        Admin::redirectTo(self::PAGE_SLUG, $message, 'success', $this->dateArg($date));
    }

    /**
     * Turns the reserved leader slot on or off for the roster's date. Enabling
     * gives the leader task capacity 1 (creating it if the event has none);
     * disabling sets it to 0, which drops it off both boards while keeping its
     * row and whoever was signed up.
     */
    public function toggleLeader(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $date = isset($_POST['roster_date']) ? sanitize_text_field(wp_unslash($_POST['roster_date'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if (! $this->isValidDate($date)) {
            Admin::redirectTo(self::PAGE_SLUG, __('That change could not be applied.', 'eventcrew'), 'error');
        }

        if ($this->leaderGate->isEnabled($date)) {
            $this->leaderGate->disable($date);
            $this->applyLeaderTask($date, false);
            $message = __('Leader turned off for this event.', 'eventcrew');
        } else {
            $this->leaderGate->enable($date);
            $this->applyLeaderTask($date, true);
            $message = __('Leader turned on for this event.', 'eventcrew');
        }

        do_action('eventcrew/board_stale');

        Admin::redirectTo(self::PAGE_SLUG, $message, 'success', $this->dateArg($date));
    }

    /**
     * Brings the day's leader task in line with the toggle: set an existing
     * one's capacity, or create a fresh capacity-1 slot when turning it on for
     * an event that has none.
     */
    private function applyLeaderTask(string $date, bool $enabled): void
    {
        foreach ($this->tasks->forDate($date) as $task) {
            if (Roles::LEADER_SLUG === $task->roleSlug) {
                $this->tasks->update($task->id, ['capacity' => $enabled ? 1 : 0]);

                return;
            }
        }

        if ($enabled) {
            [$eventPostId, $eventLabel] = $this->eventContext($date);
            $this->tasks->create([
                'event_post_id' => $eventPostId,
                'event_label' => null === $eventPostId ? $eventLabel : '',
                'task_date' => $date,
                'starts_at' => null,
                'ends_at' => null,
                'role_slug' => Roles::LEADER_SLUG,
                'capacity' => 1,
                'notes' => '',
            ]);
        }
    }

    /**
     * The event a redemption on $date is recorded against: the first task's
     * event that day, so the record carries which event the credit bought.
     *
     * @return array{0: ?int, 1: string}
     */
    private function eventContext(string $date): array
    {
        foreach ($this->tasks->forDate($date) as $task) {
            return [$task->eventPostId, $task->eventName()];
        }

        return [null, ''];
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
