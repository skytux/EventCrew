<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\Task;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\EventTaskMover;
use EventCrew\Tests\TestCase;

/**
 * "Move the tasks too": the event's start is read before and after
 * EventMesh writes the new one, and the tasks move by the difference only
 * when the box was ticked.
 */
final class EventTaskMoverTest extends TestCase
{
    private string $start = '2026-08-01T21:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('post_type_exists')->justReturn(true);
        Functions\when('get_post')->justReturn((object) [
            'ID' => 42,
            'post_type' => 'eventmesh_event',
            'post_title' => 'Event',
        ]);
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key): string => '_eventmesh_starts_at' === $key ? $this->start : ''
        );
        Functions\when('wp_unslash')->returnArg();
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('do_action')->justReturn(null);

        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];

        parent::tearDown();
    }

    /** Runs one save: before EventMesh, EventMesh writes $newStart, after it. */
    private function save(EventTaskMover $mover, string $newStart): void
    {
        $mover->rememberStart(42);
        $this->start = $newStart;
        $mover->moveTasks(42);
    }

    private function taskRows(): array
    {
        return [
            [
                'id' => 6,
                'task_date' => '2026-08-01',
                'role_slug' => 'door',
                'event_post_id' => 42,
                'starts_at' => '2026-08-01 20:30:00',
                'ends_at' => '2026-08-01 23:00:00',
            ],
            [
                'id' => 7,
                'task_date' => '2026-08-01',
                'role_slug' => 'clean',
                'event_post_id' => 42,
                'starts_at' => '2026-08-02 00:30:00',
                'ends_at' => null,
            ],
        ];
    }

    public function testTickedMovesEveryTaskByTheSameAmount(): void
    {
        $_POST = [EventTaskMover::FIELD => '1', 'eventcrew_move_tasks_nonce' => 'n'];
        $this->wpdb->nextResults[] = $this->taskRows();

        $this->save(new EventTaskMover(new TaskRepository()), '2026-08-01T22:30:00');

        self::assertCount(2, $this->wpdb->updates);
        self::assertSame(
            ['task_date' => '2026-08-01', 'starts_at' => '2026-08-01 22:00:00', 'ends_at' => '2026-08-02 00:30:00'],
            $this->wpdb->updates[0]['data']
        );
        self::assertSame(['id' => 6], $this->wpdb->updates[0]['where']);
        self::assertSame(
            ['task_date' => '2026-08-01', 'starts_at' => '2026-08-02 02:00:00', 'ends_at' => null],
            $this->wpdb->updates[1]['data']
        );
        self::assertStringContainsString('event_post_id = 42', (string) end($this->wpdb->queries));
    }

    public function testAMoveToAnotherDayMovesTheFilingDay(): void
    {
        $_POST = [EventTaskMover::FIELD => '1', 'eventcrew_move_tasks_nonce' => 'n'];
        $this->wpdb->nextResults[] = $this->taskRows();

        $this->save(new EventTaskMover(new TaskRepository()), '2026-08-08T21:00:00');

        self::assertSame('2026-08-08', $this->wpdb->updates[0]['data']['task_date']);
        self::assertSame('2026-08-08 20:30:00', $this->wpdb->updates[0]['data']['starts_at']);
        self::assertSame('2026-08-09 00:30:00', $this->wpdb->updates[1]['data']['starts_at']);
    }

    public function testUntickedLeavesTheTasksWhereTheyWere(): void
    {
        $this->wpdb->nextResults[] = $this->taskRows();

        $this->save(new EventTaskMover(new TaskRepository()), '2026-08-01T22:30:00');

        self::assertSame([], $this->wpdb->updates);
        self::assertSame([], $this->wpdb->queries);
    }

    public function testABadNonceLeavesTheTasksWhereTheyWere(): void
    {
        $_POST = [EventTaskMover::FIELD => '1', 'eventcrew_move_tasks_nonce' => 'n'];
        Functions\when('wp_verify_nonce')->justReturn(false);
        $this->wpdb->nextResults[] = $this->taskRows();

        $this->save(new EventTaskMover(new TaskRepository()), '2026-08-01T22:30:00');

        self::assertSame([], $this->wpdb->updates);
    }

    public function testAnUnchangedStartMovesNothing(): void
    {
        $_POST = [EventTaskMover::FIELD => '1', 'eventcrew_move_tasks_nonce' => 'n'];
        $this->wpdb->nextResults[] = $this->taskRows();

        $this->save(new EventTaskMover(new TaskRepository()), '2026-08-01T21:00:00');

        self::assertSame([], $this->wpdb->updates);
    }

    public function testTheBoxIsRenderedUnticked(): void
    {
        Functions\when('wp_nonce_field')->justReturn('');
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_html__')->returnArg();

        ob_start();
        (new EventTaskMover(new TaskRepository()))->renderMetaBox(new \WP_Post());
        $html = (string) ob_get_clean();

        self::assertStringContainsString('name="eventcrew_move_tasks"', $html);
        self::assertStringNotContainsString('checked', $html);
        self::assertStringContainsString('Move the tasks too', $html);
    }

    public function testMovedTimesKeepsAnEmptyTimeEmpty(): void
    {
        $task = new Task(1, '2026-08-01', 'door', 1, 42, '', null, null);

        self::assertSame(
            ['task_date' => '2026-08-01', 'starts_at' => null, 'ends_at' => null],
            EventTaskMover::movedTimes($task, 3600, 0)
        );
    }
}
