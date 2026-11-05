<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Task;
use EventCrew\Repositories\TaskRepository;

/**
 * The "Move the tasks too" checkbox on an EventMesh event's edit screen.
 *
 * Without it, changing an event's start time leaves its tasks where they
 * were. With it ticked, every task of the event moves by the same amount of
 * time as the event's start: its start, its end, and the day it is filed
 * under. The box is never remembered, so it is unticked every time the edit
 * screen opens.
 *
 * The event's start is read twice in the same save request: at priority 5 of
 * save_post, before EventMesh (priority 10) writes the typed start, and at
 * priority 20, after it. EventMesh's block editor saves the meta boxes in a
 * request of their own, which is the one that carries the checkbox.
 *
 * Inert without EventMesh: the hooks are for its post type, which then never
 * exists.
 */
final class EventTaskMover
{
    public const FIELD = 'eventcrew_move_tasks';
    private const NONCE_FIELD = 'eventcrew_move_tasks_nonce';
    private const NONCE_ACTION = 'eventcrew_move_tasks_';

    /** @var array<int, string> The event's start before the save, by post id. */
    private array $startBefore = [];

    public function __construct(
        private readonly TaskRepository $tasks
    ) {
    }

    public function boot(): void
    {
        add_action('add_meta_boxes_' . EventSource::POST_TYPE, [$this, 'registerMetaBox']);
        add_action('save_post_' . EventSource::POST_TYPE, [$this, 'rememberStart'], 5);
        add_action('save_post_' . EventSource::POST_TYPE, [$this, 'moveTasks'], 20);
    }

    public function registerMetaBox(): void
    {
        add_meta_box(
            'eventcrew_move_tasks',
            __('EventCrew tasks', 'eventcrew'),
            [$this, 'renderMetaBox'],
            EventSource::POST_TYPE,
            'side',
            'default'
        );
    }

    public function renderMetaBox(\WP_Post $post): void
    {
        wp_nonce_field(self::NONCE_ACTION . $post->ID, self::NONCE_FIELD);

        printf(
            '<p><label><input type="checkbox" name="%s" value="1" /> %s</label></p>',
            esc_attr(self::FIELD),
            esc_html__('Move the tasks too', 'eventcrew')
        );
        echo '<p class="description">'
            . esc_html__('When the start time changes, every task of this event moves by the same amount.', 'eventcrew')
            . '</p>';
    }

    /**
     * Notes the event's start as it is before this save, but only when the
     * box is ticked on a real edit-screen save.
     */
    public function rememberStart(int $postId): void
    {
        if (! $this->isTicked($postId)) {
            return;
        }

        $event = EventSource::describe($postId);

        if (null !== $event && '' !== $event['starts_at']) {
            $this->startBefore[$postId] = $event['starts_at'];
        }
    }

    /** Moves the event's tasks when its start changed in this save. */
    public function moveTasks(int $postId): void
    {
        if (! isset($this->startBefore[$postId])) {
            return;
        }

        $before = $this->startBefore[$postId];
        unset($this->startBefore[$postId]);

        $after = EventSource::describe($postId)['starts_at'] ?? '';

        if ('' === $after || $after === $before) {
            return;
        }

        $beforeAt = strtotime($before);
        $afterAt = strtotime($after);
        $beforeDay = strtotime(substr($before, 0, 10));
        $afterDay = strtotime(substr($after, 0, 10));

        if (false === $beforeAt || false === $afterAt || false === $beforeDay || false === $afterDay) {
            return;
        }

        $seconds = $afterAt - $beforeAt;
        $days = (int) round(($afterDay - $beforeDay) / 86400);
        $moved = 0;

        foreach ($this->tasks->forEvent($postId) as $task) {
            $this->tasks->update($task->id, self::movedTimes($task, $seconds, $days));
            ++$moved;
        }

        if ($moved > 0) {
            do_action('eventcrew/board_stale');
        }
    }

    /**
     * A task's new times: start and end moved by $seconds, the day it is
     * filed under moved by $days (the change of the event's own date). A time
     * the task does not have stays empty.
     *
     * @return array{task_date: string, starts_at: ?string, ends_at: ?string}
     */
    public static function movedTimes(Task $task, int $seconds, int $days): array
    {
        return [
            'task_date' => self::move($task->taskDate, $days * 86400, 'Y-m-d') ?? $task->taskDate,
            'starts_at' => self::move($task->startsAt, $seconds, 'Y-m-d H:i:s'),
            'ends_at' => self::move($task->endsAt, $seconds, 'Y-m-d H:i:s'),
        ];
    }

    /**
     * strtotime and gmdate are paired on purpose, as in TaskTemplate: under
     * WordPress's UTC default timezone the naive string moves by exactly
     * $seconds and is never reread in another zone.
     */
    private static function move(?string $value, int $seconds, string $format): ?string
    {
        if (null === $value || '' === $value) {
            return $value;
        }

        $timestamp = strtotime($value);

        return false === $timestamp ? $value : gmdate($format, $timestamp + $seconds);
    }

    private function isTicked(int $postId): bool
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return false;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce is verified right below.
        if (! isset($_POST[self::FIELD]) || '1' !== (string) $_POST[self::FIELD]) {
            return false;
        }

        $nonce = isset($_POST[self::NONCE_FIELD])
            ? sanitize_text_field(wp_unslash((string) $_POST[self::NONCE_FIELD]))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ('' === $nonce || ! wp_verify_nonce($nonce, self::NONCE_ACTION . $postId)) {
            return false;
        }

        return current_user_can('edit_post', $postId);
    }
}
