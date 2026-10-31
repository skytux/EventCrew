<?php

declare(strict_types=1);

namespace EventCrew\Tests\Models;

use Brain\Monkey\Functions;
use EventCrew\Models\Task;
use EventCrew\Tests\TestCase;

final class TaskTest extends TestCase
{
    public function testHydratesFromADatabaseRow(): void
    {
        $task = Task::fromRow([
            'id' => '4',
            'task_date' => '2026-08-01',
            'role_slug' => 'decorate',
            'capacity' => '2',
            'starts_at' => '18:00:00',
            'ends_at' => '20:00:00',
            'event_label' => 'Summer Salsa',
        ]);

        self::assertSame(4, $task->id);
        self::assertSame('2026-08-01', $task->taskDate);
        self::assertSame(2, $task->capacity);
        self::assertNull($task->eventPostId);
    }

    public function testRendersATimeRangeWithoutSeconds(): void
    {
        $task = Task::fromRow([
            'starts_at' => '18:00:00',
            'ends_at' => '20:30:00',
        ]);

        self::assertSame('18:00–20:30', $task->timeRange());
    }

    public function testRendersOnlyTheStartWhenThereIsNoEndTime(): void
    {
        $task = Task::fromRow(['starts_at' => '18:00:00']);

        self::assertSame('18:00', $task->timeRange());
    }

    public function testRendersNoTimeRangeWhenTimesAreUndecided(): void
    {
        $task = Task::fromRow(['task_date' => '2026-08-01']);

        self::assertSame('', $task->timeRange());
    }

    public function testRendersDatetimesAsPlainClockTimesWithinTheTaskDay(): void
    {
        $task = Task::fromRow([
            'task_date' => '2026-08-01',
            'starts_at' => '2026-08-01 18:00:00',
            'ends_at' => '2026-08-01 20:30:00',
        ]);

        self::assertSame('18:00–20:30', $task->timeRange());
    }

    /**
     * The cleaning task is the whole reason starts_at and ends_at became
     * datetimes: it runs after midnight and so falls on a different day from
     * the one it is filed under. Rendering it as a bare "01:00" would leave
     * the organizer guessing which night was meant.
     */
    public function testMarksTimesThatFallOnAnotherDay(): void
    {
        $task = Task::fromRow([
            'task_date' => '2026-08-01',
            'starts_at' => '2026-08-02 00:00:00',
            'ends_at' => '2026-08-02 01:00:00',
        ]);

        self::assertSame('00:00 (+1)–01:00 (+1)', $task->timeRange());
    }

    public function testMarksOnlyTheEndWhenTheTaskItselfCrossesMidnight(): void
    {
        $task = Task::fromRow([
            'task_date' => '2026-08-01',
            'starts_at' => '2026-08-01 23:00:00',
            'ends_at' => '2026-08-02 01:00:00',
        ]);

        self::assertSame('23:00–01:00 (+1)', $task->timeRange());
    }

    /**
     * Rows written before the columns were widened hold a bare time. They are
     * read, not rewritten, so the model has to keep rendering them.
     */
    public function testStillRendersRowsStoredAsBareTimes(): void
    {
        $task = Task::fromRow([
            'task_date' => '2026-08-01',
            'starts_at' => '18:00:00',
            'ends_at' => '20:30:00',
        ]);

        self::assertSame('18:00–20:30', $task->timeRange());
    }

    public function testPrefersTheLinkedEventPostTitle(): void
    {
        Functions\when('get_the_title')->justReturn('Synced Summer Salsa');

        $task = Task::fromRow([
            'event_post_id' => '12',
            'event_label' => 'Typed label',
            'task_date' => '2026-08-01',
        ]);

        self::assertSame('Synced Summer Salsa', $task->eventName());
    }

    /**
     * EventCrew has to work with EventMesh absent, and with a linked post that
     * has since been deleted, so the name falls back rather than rendering
     * blank on the roster.
     */
    public function testFallsBackToTheTypedLabelWhenNoPostTitleIsAvailable(): void
    {
        Functions\when('get_the_title')->justReturn('');

        $task = Task::fromRow([
            'event_post_id' => '12',
            'event_label' => 'Typed label',
            'task_date' => '2026-08-01',
        ]);

        self::assertSame('Typed label', $task->eventName());
    }

    public function testFallsBackToTheDateWhenNothingElseNamesTheEvent(): void
    {
        $task = Task::fromRow(['task_date' => '2026-08-01']);

        self::assertSame('2026-08-01', $task->eventName());
    }
}
