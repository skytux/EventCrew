<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\EventSource;
use EventCrew\Support\Roles;
use EventCrew\Support\TaskTemplateApplier;

/**
 * @phpstan-import-type Role from Roles
 */
final class TasksPage
{
    public const PAGE_SLUG = 'eventcrew';
    private const NONCE_ACTION = 'eventcrew_task';
    private const TEMPLATE_NONCE_ACTION = 'eventcrew_apply_template';

    /** The event picker's "not one of these" option. */
    public const EVENT_OTHER = 'other';

    public function __construct(
        private readonly View $view,
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly PersonRepository $people,
        private readonly TaskTemplateApplier $templateApplier
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

        $table = new TasksListTable($this->tasks);
        $table->prepare_items();

        $editing = $this->taskBeingEdited();

        $this->view->render(
            'tasks',
            [
                'table' => $table,
                'editing' => $editing,
                // The form lists active roles to pick from, but an edited task
                // may sit on an archived one - that role is appended so
                // reopening an old task does not silently move it elsewhere.
                'roles' => $this->rolesForForm($editing),
                'events' => EventSource::upcoming(),
                'events_available' => EventSource::isAvailable(),
                'roster' => $this->rosterForEditedTask(),
                'nonce_action' => self::NONCE_ACTION,
                'template_nonce_action' => self::TEMPLATE_NONCE_ACTION,
                'page_slug' => self::PAGE_SLUG,
                'event_other' => self::EVENT_OTHER,
            ]
        );
    }

    public function save(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $id = isset($_POST['task_id']) ? (int) $_POST['task_id'] : 0;

        $roleSlug = isset($_POST['role_slug']) ? sanitize_key(wp_unslash($_POST['role_slug'])) : '';
        $taskDate = isset($_POST['task_date']) ? sanitize_text_field(wp_unslash($_POST['task_date'])) : '';
        $startsAt = isset($_POST['starts_at']) ? sanitize_text_field(wp_unslash($_POST['starts_at'])) : '';
        $endsAt = isset($_POST['ends_at']) ? sanitize_text_field(wp_unslash($_POST['ends_at'])) : '';
        $eventChoice = isset($_POST['event_choice']) ? sanitize_text_field(wp_unslash($_POST['event_choice'])) : '';
        $eventLabel = isset($_POST['event_label']) ? sanitize_text_field(wp_unslash($_POST['event_label'])) : '';
        $capacity = isset($_POST['capacity']) ? (int) $_POST['capacity'] : 1;
        $notes = isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $eventPostId = $this->eventPostIdFrom($eventChoice);

        if (! $this->isValidDate($taskDate)) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('That date could not be read. Use the date picker and try again.', 'eventcrew'),
                'error'
            );
        }

        if (! Roles::exists($roleSlug)) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('Pick a role that still exists in Settings.', 'eventcrew'),
                'error'
            );
        }

        $start = $this->normalizeDateTime($startsAt, $taskDate);
        $end = $this->normalizeDateTime($endsAt, $taskDate);

        // A task ending before it starts is almost always a crossing of
        // midnight typed without changing the date, so it is corrected rather
        // than rejected - refusing it would just teach the organizer to type
        // the date twice.
        if (null !== $start && null !== $end && $end < $start) {
            $end = $this->nextDay($end);
        }

        $data = [
            'role_slug' => $roleSlug,
            'task_date' => $taskDate,
            'starts_at' => $start,
            'ends_at' => $end,
            // A linked event supplies the name; the typed label is only kept
            // when there is no link, so the two can never disagree on screen.
            'event_label' => null === $eventPostId ? $eventLabel : '',
            'event_post_id' => $eventPostId,
            'capacity' => max(1, $capacity),
            'notes' => $notes,
        ];

        if ($id > 0) {
            $this->tasks->update($id, $data);

            // A hand-edit - a changed time or capacity - can change what the
            // board should show; the listener refreshes it if the bot is on.
            do_action('eventcrew/board_stale');

            Admin::redirectTo(self::PAGE_SLUG, __('Task updated.', 'eventcrew'));
        }

        $this->tasks->create($data);

        do_action('eventcrew/board_stale');

        Admin::redirectTo(self::PAGE_SLUG, __('Task added.', 'eventcrew'));
    }

    /**
     * Creates every task an event needs in one go, from the active roles and
     * their offsets.
     *
     * Roles already scheduled for that event are skipped rather than
     * duplicated, so this is safe to run twice - including after a new role
     * has been added, which is the obvious second use for it.
     */
    public function applyTemplate(): void
    {
        Admin::assertCanSave(self::TEMPLATE_NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $eventPostId = isset($_POST['template_event']) ? (int) $_POST['template_event'] : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $result = $this->templateApplier->apply($eventPostId);

        if (null === $result) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                __('That event has no start date recorded, so its tasks cannot be scheduled. Set one on the event and try again.', 'eventcrew'),
                'error'
            );
        }

        if (0 === $result['created']) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('Every active role already has a task for that event.', 'eventcrew')
            );
        }

        Admin::redirectTo(self::PAGE_SLUG, $this->templateNotice($result['created'], $result['untimed']));
    }

    private function templateNotice(int $created, int $untimed): string
    {
        if (0 === $untimed) {
            return sprintf(
                /* translators: %d: number of tasks created */
                _n('%d task created.', '%d tasks created.', $created, 'eventcrew'),
                $created
            );
        }

        // phpcs:disable Generic.Files.LineLength.TooLong -- gettext literals; splitting one breaks extraction.
        return sprintf(
            /* translators: 1: number of tasks created, 2: how many of them have no times */
            _n(
                '%1$d task created, %2$d without times - the role has no offsets, or the event has no end recorded.',
                '%1$d tasks created, %2$d without times - those roles have no offsets, or the event has no end recorded.',
                $created,
                'eventcrew'
            ),
            $created,
            $untimed
        );
        // phpcs:enable Generic.Files.LineLength.TooLong
    }

    public function delete(): void
    {
        if (! current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to do this.', 'eventcrew'));
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $id = isset($_GET['task']) ? (int) $_GET['task'] : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        check_admin_referer('eventcrew_delete_task_' . $id);

        if ($id > 0) {
            $this->tasks->delete($id);

            do_action('eventcrew/board_stale');
        }

        Admin::redirectTo(self::PAGE_SLUG, __('Task deleted.', 'eventcrew'));
    }

    /**
     * The event picker submits either a post id or the "other" sentinel. Only
     * a value that resolves to a real event post becomes a link, so a stale
     * option in a form left open overnight cannot point a task at a deleted
     * post.
     */
    private function eventPostIdFrom(string $choice): ?int
    {
        if (self::EVENT_OTHER === $choice || '' === $choice) {
            return null;
        }

        $postId = (int) $choice;

        return null === EventSource::describe($postId) ? null : $postId;
    }

    /**
     * @return array<int, Role>
     */
    private function rolesForForm(?object $editing): array
    {
        $roles = Roles::active();

        if (! $editing instanceof \EventCrew\Models\Task) {
            return $roles;
        }

        foreach ($roles as $role) {
            if ($role['slug'] === $editing->roleSlug) {
                return $roles;
            }
        }

        $archived = Roles::find($editing->roleSlug);

        if (null !== $archived) {
            $roles[] = $archived;
        }

        return $roles;
    }

    /**
     * @return \EventCrew\Models\Task|null
     */
    private function taskBeingEdited(): ?object
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $id = isset($_GET['task']) ? (int) $_GET['task'] : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return $id > 0 ? $this->tasks->find($id) : null;
    }

    /**
     * Who is signed up for the task currently open in the editor, so the
     * organizer can see the consequences of changing or deleting it.
     *
     * @return array<int, array{name: string, status: string}>
     */
    private function rosterForEditedTask(): array
    {
        $task = $this->taskBeingEdited();

        if (null === $task) {
            return [];
        }

        $roster = [];

        foreach ($this->assignments->forTask($task->id) as $assignment) {
            $person = $this->people->find($assignment->personId);

            $roster[] = [
                'name' => null === $person
                    ? __('(deleted person)', 'eventcrew')
                    : $person->name(),
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
     * Reads what <input type="datetime-local"> submits (Y-m-d\TH:i), and also
     * a bare H:i, which is what the field degrades to on a browser without
     * datetime-local support. A bare time is dated to the task's own day.
     *
     * An empty field is a legitimate "not decided yet", stored as NULL rather
     * than midnight, which would read as a real 00:00 start on the roster.
     */
    private function normalizeDateTime(string $value, string $taskDate): ?string
    {
        $value = trim($value);

        if ('' === $value) {
            return null;
        }

        if (1 === preg_match('/^(\d{2}):(\d{2})$/', $value, $timeOnly)) {
            if ((int) $timeOnly[1] > 23 || (int) $timeOnly[2] > 59) {
                return null;
            }

            return $taskDate . ' ' . $value . ':00';
        }

        if (1 !== preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})(?::\d{2})?$/', $value, $matches)) {
            return null;
        }

        if (! $this->isValidDate($matches[1]) || (int) $matches[2] > 23 || (int) $matches[3] > 59) {
            return null;
        }

        return sprintf('%s %s:%s:00', $matches[1], $matches[2], $matches[3]);
    }

    private function nextDay(string $dateTime): string
    {
        $timestamp = strtotime($dateTime);

        if (false === $timestamp) {
            return $dateTime;
        }

        // Paired with strtotime under WordPress's UTC default timezone, so
        // the naive string moves by exactly one day and is not reread in
        // another zone. See TaskTemplate::offsetFrom().
        return gmdate('Y-m-d H:i:s', $timestamp + 86400);
    }
}
