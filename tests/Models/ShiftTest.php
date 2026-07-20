<?php

declare(strict_types=1);

namespace EventCrew\Tests\Models;

use Brain\Monkey\Functions;
use EventCrew\Models\Shift;
use EventCrew\Tests\TestCase;

final class ShiftTest extends TestCase
{
    public function testHydratesFromADatabaseRow(): void
    {
        $shift = Shift::fromRow([
            'id' => '4',
            'shift_date' => '2026-08-01',
            'task_slug' => 'decorate',
            'capacity' => '2',
            'starts_at' => '18:00:00',
            'ends_at' => '20:00:00',
            'event_label' => 'Summer Salsa',
        ]);

        self::assertSame(4, $shift->id);
        self::assertSame('2026-08-01', $shift->shiftDate);
        self::assertSame(2, $shift->capacity);
        self::assertNull($shift->eventPostId);
    }

    public function testRendersATimeRangeWithoutSeconds(): void
    {
        $shift = Shift::fromRow([
            'starts_at' => '18:00:00',
            'ends_at' => '20:30:00',
        ]);

        self::assertSame('18:00–20:30', $shift->timeRange());
    }

    public function testRendersOnlyTheStartWhenThereIsNoEndTime(): void
    {
        $shift = Shift::fromRow(['starts_at' => '18:00:00']);

        self::assertSame('18:00', $shift->timeRange());
    }

    public function testRendersNoTimeRangeWhenTimesAreUndecided(): void
    {
        $shift = Shift::fromRow(['shift_date' => '2026-08-01']);

        self::assertSame('', $shift->timeRange());
    }

    public function testPrefersTheLinkedEventPostTitle(): void
    {
        Functions\when('get_the_title')->justReturn('Synced Summer Salsa');

        $shift = Shift::fromRow([
            'event_post_id' => '12',
            'event_label' => 'Typed label',
            'shift_date' => '2026-08-01',
        ]);

        self::assertSame('Synced Summer Salsa', $shift->eventName());
    }

    /**
     * EventCrew has to work with EventMesh absent, and with a linked post that
     * has since been deleted, so the name falls back rather than rendering
     * blank on the roster.
     */
    public function testFallsBackToTheTypedLabelWhenNoPostTitleIsAvailable(): void
    {
        Functions\when('get_the_title')->justReturn('');

        $shift = Shift::fromRow([
            'event_post_id' => '12',
            'event_label' => 'Typed label',
            'shift_date' => '2026-08-01',
        ]);

        self::assertSame('Typed label', $shift->eventName());
    }

    public function testFallsBackToTheDateWhenNothingElseNamesTheEvent(): void
    {
        $shift = Shift::fromRow(['shift_date' => '2026-08-01']);

        self::assertSame('2026-08-01', $shift->eventName());
    }
}
