<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Task;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\Dates;
use EventCrew\Support\EventSource;
use EventCrew\Support\MarkedBy;
use EventCrew\Support\OpenTaskCall;
use EventCrew\Support\Roles;
use EventCrew\Support\TaskTemplateApplier;

/**
 * The Tasks screen of the crew page's Admin tab, made to match the wp-admin
 * Tasks page: the tasks of one date with who is signed up, adding, editing
 * and deleting a task, "Create an event's tasks", the open-task email, and
 * editing one role with the fields of the wp-admin Settings > Roles form.
 *
 * Adding a role is left out: on the crew page a role is picked and edited,
 * one at a time.
 *
 * The task save repeats TasksPage's own checks and clean-up of the times
 * (normalizeDateTime, nextDay, eventPostIdFrom) instead of sharing them: those
 * are private to TasksPage and have no tests there, so moving them could not be
 * proven safe. Keep the two in step.
 *
 * CrewAdminController guards every action (session, organizer flag, CSRF,
 * POST only) before it calls a method here. The methods only check their own
 * input and answer with an 'admin_' notice code.
 *
 * @phpstan-import-type Role from Roles
 */
final class CrewAdminTasks
{
    /** The event picker's "not one of these" option, as on wp-admin. */
    public const EVENT_OTHER = 'other';

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly PersonRepository $people,
        private readonly TaskTemplateApplier $templateApplier,
        private readonly OpenTaskCall $openTaskCall
    ) {
    }

    /**
     * The Tasks screen for the template: the dates to pick from, the tasks of
     * the picked date with their people, the task open for editing, the
     * events, the open-task email count and the role open for editing.
     *
     * $requested is shown whenever it is a real date, with or without tasks,
     * so tasks can be added on a new date. Otherwise the nearest upcoming
     * date with tasks, then the most recent past one, then today.
     *
     * @return array<string, mixed>
     */
    public function view(string $requested, int $taskId, string $roleSlug): array
    {
        $dates = $this->tasks->datesWithTasks(); // most recent first
        $today = (string) current_time('Y-m-d');

        $upcoming = array_reverse(array_values(array_filter($dates, static fn (string $d): bool => $d >= $today)));
        $past = array_values(array_filter($dates, static fn (string $d): bool => $d < $today));

        $date = CrewAdminRoster::isValidDate($requested) ? $requested : ($upcoming[0] ?? ($past[0] ?? $today));

        $editing = $taskId > 0 ? $this->tasks->find($taskId) : null;
        $roles = Roles::all();
        $roleEditing = '' === $roleSlug ? null : Roles::find($roleSlug);
        $events = EventSource::upcoming();

        return [
            'date' => $date,
            'label' => Dates::dayLabel($date),
            'upcoming' => self::labelled($upcoming),
            'past' => self::labelled($past),
            'list' => $this->listFor($date),
            'editing' => $editing,
            'signed_up' => null === $editing ? [] : $this->signedUp($editing->id),
            'roles' => $this->rolesForForm($editing),
            'events' => $events,
            'events_available' => EventSource::isAvailable(),
            // A new task starts on the shown date's event, with its start time.
            'new_task_event' => null === $editing ? EventSource::forNewTask($events, $today, $date) : null,
            'event_other' => self::EVENT_OTHER,
            'active_recipients' => count($this->people->activeEmailRecipients()),
            'role_list' => $roles,
            'role_editing' => $roleEditing,
        ];
    }

    /**
     * "Add task" ($post has no task_id) or "Update task": the wp-admin save,
     * with the same checks, the same handling of the times, and the board
     * refreshed afterwards.
     *
     * @param array<string, mixed> $post The request body, unslashed.
     */
    public function save(array $post): string
    {
        $id = (int) ($post['task_id'] ?? 0);
        $roleSlug = sanitize_key((string) ($post['role_slug'] ?? ''));
        $taskDate = sanitize_text_field((string) ($post['task_date'] ?? ''));
        $startsAt = sanitize_text_field((string) ($post['starts_at'] ?? ''));
        $endsAt = sanitize_text_field((string) ($post['ends_at'] ?? ''));
        $eventChoice = sanitize_text_field((string) ($post['event_choice'] ?? ''));
        $eventLabel = sanitize_text_field((string) ($post['event_label'] ?? ''));
        $capacity = isset($post['capacity']) ? (int) $post['capacity'] : 1;
        // Empty means "the role's default", which only a new task can use.
        $plusOnes = trim(sanitize_text_field((string) ($post['plus_ones'] ?? '')));
        $notes = sanitize_textarea_field((string) ($post['notes'] ?? ''));

        if ($id > 0 && null === $this->tasks->find($id)) {
            return 'admin_task_gone';
        }

        $eventPostId = self::eventPostIdFrom($eventChoice);

        if (! CrewAdminRoster::isValidDate($taskDate)) {
            return 'admin_task_bad_date';
        }

        if (! Roles::exists($roleSlug)) {
            return 'admin_task_bad_role';
        }

        $start = self::normalizeDateTime($startsAt, $taskDate);
        $end = self::normalizeDateTime($endsAt, $taskDate);

        // A task must have a start time (Q229), as on wp-admin.
        if (null === $start) {
            return 'admin_task_no_start';
        }

        // An end before the start is a crossing of midnight, as on wp-admin.
        if (null !== $start && null !== $end && $end < $start) {
            $end = self::nextDay($end);
        }

        $data = [
            'role_slug' => $roleSlug,
            'task_date' => $taskDate,
            'starts_at' => $start,
            'ends_at' => $end,
            'event_label' => null === $eventPostId ? $eventLabel : '',
            'event_post_id' => $eventPostId,
            'capacity' => max(0, $capacity),
            'notes' => $notes,
        ];

        if (is_numeric($plusOnes)) {
            $data['plus_ones'] = Task::clampPlusOnes((int) $plusOnes);
        } elseif ($id <= 0) {
            $data['plus_ones'] = Roles::defaultPlusOnes($roleSlug);
        }

        if ($id > 0) {
            $this->tasks->update($id, $data);
            do_action('eventcrew/board_stale');

            return 'admin_task_updated';
        }

        $this->tasks->create($data);
        do_action('eventcrew/board_stale');

        return 'admin_task_added';
    }

    /**
     * "Delete": the wp-admin delete. The task is hidden everywhere and
     * everyone signed up for it is taken off; their tickets show DISABLED.
     * Each of them gets an email (TaskCancelledNotice), as on wp-admin.
     */
    public function delete(int $taskId): string
    {
        if ($taskId <= 0 || null === $this->tasks->find($taskId)) {
            return 'admin_task_gone';
        }

        $this->tasks->delete($taskId);
        do_action('eventcrew/board_stale');

        return 'admin_task_deleted';
    }

    /** "Create tasks": one task per active role for the event, as on wp-admin. */
    public function applyTemplate(int $eventPostId): string
    {
        $result = $this->templateApplier->apply($eventPostId);

        if (null === $result) {
            return 'admin_template_no_start';
        }

        if (0 === $result['created']) {
            return 'admin_template_none';
        }

        return sprintf('admin_template_created_%d_%d', $result['created'], $result['untimed']);
    }

    /** "Send open-task email now", for the nearest date with open tasks. */
    public function sendOpenTaskEmail(): string
    {
        $sent = $this->openTaskCall->sendForNextOpenDate();

        return 0 === $sent ? 'admin_open_task_none' : 'admin_open_task_sent_' . $sent;
    }

    /**
     * Saves one role with the fields of the wp-admin Settings > Roles form.
     * The slug cannot change, as on wp-admin. Every other role is saved as it
     * was.
     *
     * @param array<string, mixed> $post The request body, unslashed.
     */
    public function saveRole(array $post): string
    {
        $slug = sanitize_key((string) ($post['role'] ?? ''));
        $roles = Roles::all();
        $index = null;

        foreach ($roles as $i => $role) {
            if ($role['slug'] === $slug) {
                $index = $i;
            }
        }

        if (null === $index) {
            return 'admin_role_gone';
        }

        $counts = is_array($post['role_count'] ?? null) ? $post['role_count'] : [];

        $roles[$index] = [
            'slug' => $slug,
            'label' => sanitize_text_field((string) ($post['role_label'] ?? '')),
            'emoji' => sanitize_text_field((string) ($post['role_emoji'] ?? '')),
            'capacity' => (int) ($post['role_capacity'] ?? 1),
            'archived' => isset($post['role_archived']),
            'restricted' => isset($post['role_restricted']),
            'every_night' => isset($post['role_every_night']),
            'sales_desk' => isset($post['role_sales_desk']),
            'on_board' => isset($post['role_on_board']),
            'title_contains' => sanitize_text_field((string) ($post['role_title_contains'] ?? '')),
            'plus_ones' => (int) ($post['role_plus_ones'] ?? 0),
            'conditions' => [
                'counts' => array_map('intval', $counts),
                'min_roles' => (int) ($post['role_min_roles'] ?? 0),
                'times_each' => (int) ($post['role_times_each'] ?? 0),
            ],
            'anchor' => sanitize_key((string) ($post['role_anchor'] ?? Roles::ANCHOR_START)),
            'start_offset' => self::offset(sanitize_text_field((string) ($post['role_start_offset'] ?? ''))),
            'end_offset' => self::offset(sanitize_text_field((string) ($post['role_end_offset'] ?? ''))),
            'description' => sanitize_textarea_field((string) ($post['role_description'] ?? '')),
        ];

        Roles::save($roles);

        return 'admin_role_saved';
    }

    /**
     * The text for one of this screen's notice codes, or '' when the code is
     * not one of them. The texts are the wp-admin Tasks and Settings pages'
     * own.
     */
    public static function noticeText(string $code): string
    {
        // phpcs:disable Generic.Files.LineLength.TooLong -- gettext literals; splitting one breaks extraction.
        $map = [
            'admin_task_bad_date' => __('That date could not be read. Use the date picker and try again.', 'eventcrew'),
            'admin_task_bad_role' => __('Pick a role that still exists in Settings.', 'eventcrew'),
            'admin_task_no_start' => __('Set a start time. A task cannot be saved without one.', 'eventcrew'),
            'admin_task_added' => __('Task added.', 'eventcrew'),
            'admin_task_updated' => __('Task updated.', 'eventcrew'),
            'admin_task_deleted' => __('Task deleted.', 'eventcrew'),
            'admin_task_gone' => __('That task was not found.', 'eventcrew'),
            'admin_template_no_start' => __('That event has no start date recorded, so its tasks cannot be scheduled. Set one on the event and try again.', 'eventcrew'),
            'admin_template_none' => __('Every active role already has a task for that event.', 'eventcrew'),
            'admin_open_task_none' => __('Nothing to send: no open tasks, or everyone eligible already has it.', 'eventcrew'),
            'admin_role_saved' => __('Settings saved.', 'eventcrew'),
            'admin_role_gone' => __('That role was not found.', 'eventcrew'),
        ];
        // phpcs:enable Generic.Files.LineLength.TooLong

        if (isset($map[$code])) {
            return $map[$code];
        }

        if (1 === preg_match('/^admin_template_created_(\d+)_(\d+)$/', $code, $m)) {
            return self::templateText((int) $m[1], (int) $m[2]);
        }

        if (1 === preg_match('/^admin_open_task_sent_(\d+)$/', $code, $m)) {
            return sprintf(
                /* translators: %d: number of people emailed */
                _n(
                    'Open-task email sent to %d person.',
                    'Open-task email sent to %d people.',
                    (int) $m[1],
                    'eventcrew'
                ),
                (int) $m[1]
            );
        }

        return '';
    }

    /** TasksPage::templateNotice(), word for word. */
    private static function templateText(int $created, int $untimed): string
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

    /**
     * The tasks of $date with how full each is and who is on it.
     *
     * @return list<array{
     *     task: Task,
     *     time: string,
     *     filled: string,
     *     people: list<array{name: string, status: string, marked_by: string}>
     * }>
     */
    private function listFor(string $date): array
    {
        $tasks = $this->tasks->forDate($date);
        $taken = $this->tasks->occupancyFor(array_map(static fn (Task $task): int => $task->id, $tasks));
        $list = [];

        foreach ($tasks as $task) {
            $count = $taken[$task->id] ?? 0;

            $list[] = [
                'task' => $task,
                'time' => $task->timeRange(),
                'filled' => $task->hasNoLimit()
                    /* translators: %d: people signed up so far, for a task with no limit on people */
                    ? sprintf(__('%d / no limit', 'eventcrew'), $count)
                    : sprintf('%d / %d', $count, $task->capacity),
                'people' => $this->signedUp($task->id),
            ];
        }

        return $list;
    }

    /**
     * Everyone on a task, as the wp-admin edit form lists them under "Signed
     * up": name, status and who marked them.
     *
     * @return list<array{name: string, status: string, marked_by: string}>
     */
    private function signedUp(int $taskId): array
    {
        $people = [];

        foreach ($this->assignments->forTask($taskId) as $assignment) {
            $person = $this->people->find($assignment->personId);

            $people[] = [
                'name' => null === $person ? __('(deleted person)', 'eventcrew') : $person->name(),
                'status' => $assignment->statusLabel(),
                'marked_by' => MarkedBy::line($assignment, $this->people),
            ];
        }

        return $people;
    }

    /**
     * The roles to pick from: the active ones, plus the edited task's own role
     * when it is archived or Organize, so saving does not move the task to
     * another role. TasksPage::rolesForForm().
     *
     * @return array<int, array{slug: string, label: string, archived: bool, capacity: int}>
     */
    private function rolesForForm(?Task $editing): array
    {
        $roles = Roles::active();

        if (null === $editing) {
            return $roles;
        }

        foreach ($roles as $role) {
            if ($role['slug'] === $editing->roleSlug) {
                return $roles;
            }
        }

        if (Roles::ORGANIZE_SLUG === $editing->roleSlug) {
            $roles[] = [
                'slug' => Roles::ORGANIZE_SLUG,
                'label' => Roles::label(Roles::ORGANIZE_SLUG),
                'archived' => false,
                'capacity' => $editing->capacity,
            ];

            return $roles;
        }

        $archived = Roles::find($editing->roleSlug);

        if (null !== $archived) {
            $roles[] = $archived;
        }

        return $roles;
    }

    /**
     * @param list<string> $dates
     * @return array<string, string>
     */
    private static function labelled(array $dates): array
    {
        $labelled = [];

        foreach ($dates as $date) {
            $labelled[$date] = Dates::dayLabel($date);
        }

        return $labelled;
    }

    /** TasksPage::eventPostIdFrom(): only a real event post becomes a link. */
    private static function eventPostIdFrom(string $choice): ?int
    {
        if (self::EVENT_OTHER === $choice || '' === $choice) {
            return null;
        }

        $postId = (int) $choice;

        return null === EventSource::describe($postId) ? null : $postId;
    }

    /**
     * TasksPage::normalizeDateTime(): what a datetime-local field sends, or a
     * bare H:i dated to the task's day. Empty, or not readable, is null.
     */
    private static function normalizeDateTime(string $value, string $taskDate): ?string
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

        if (! CrewAdminRoster::isValidDate($matches[1]) || (int) $matches[2] > 23 || (int) $matches[3] > 59) {
            return null;
        }

        return sprintf('%s %s:%s:00', $matches[1], $matches[2], $matches[3]);
    }

    /** TasksPage::nextDay(): the same time one day later. */
    private static function nextDay(string $dateTime): string
    {
        $timestamp = strtotime($dateTime);

        if (false === $timestamp) {
            return $dateTime;
        }

        return gmdate('Y-m-d H:i:s', $timestamp + 86400);
    }

    /** SettingsPage::offset(): blank or not a whole number is "no offset". */
    private static function offset(string $value): ?int
    {
        $value = trim($value);

        return '' === $value || 1 !== preg_match('/^-?\d+$/', $value) ? null : (int) $value;
    }
}
