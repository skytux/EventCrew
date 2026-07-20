<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\EventSource;
use EventCrew\Tests\TestCase;

final class EventSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('post_type_exists')->justReturn(true);

        // Deliberately a timezone that is NOT UTC: if any conversion crept
        // back into the consumption path, a +03:00 shift would show up in the
        // asserted times and fail the test. That it does not is the point.
        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('Europe/Helsinki'));
    }

    private function stubEvent(string $startsAt, string $endsAt): void
    {
        $post = (object) [
            'ID' => 12,
            'post_type' => 'eventmesh_event',
            'post_title' => 'Synced Event',
        ];

        Functions\when('get_post')->justReturn($post);
        Functions\when('get_post_meta')->alias(
            static fn (int $id, string $key): string => match ($key) {
                '_eventmesh_starts_at' => $startsAt,
                '_eventmesh_ends_at' => $endsAt,
                default => '',
            }
        );
    }

    /**
     * EventMesh stores a naive wall-clock; EventCrew must take it verbatim.
     * A source "doors open 21:00" stays 21:00 - never shifted to 00:00 by a
     * timezone conversion of a value that was never in UTC to begin with.
     */
    public function testTakesTheStoredWallClockVerbatim(): void
    {
        $this->stubEvent('2026-08-01T21:00:00', '2026-08-02T01:00:00');

        $event = EventSource::describe(12);

        self::assertNotNull($event);
        self::assertSame('2026-08-01 21:00:00', $event['starts_at']);
        self::assertSame('2026-08-02 01:00:00', $event['ends_at']);
        self::assertSame('2026-08-01', $event['date']);
    }

    /**
     * A value still stored in the shorter "…T18:00" form (no seconds) is
     * normalised to the MySQL DATETIME shape without moving the clock.
     */
    public function testNormalisesAShortFormWithoutShifting(): void
    {
        $this->stubEvent('2026-08-01T18:00', '');

        $event = EventSource::describe(12);

        self::assertNotNull($event);
        self::assertSame('2026-08-01 18:00:00', $event['starts_at']);
        self::assertSame('', $event['ends_at']);
    }

    /**
     * A hand-typed override on the event still wins over the scraped value,
     * and is likewise taken verbatim.
     */
    public function testAManualOverrideStillWinsAndIsNotShifted(): void
    {
        $post = (object) ['ID' => 12, 'post_type' => 'eventmesh_event', 'post_title' => 'E'];

        Functions\when('get_post')->justReturn($post);
        Functions\when('get_post_meta')->alias(
            static fn (int $id, string $key): string => match ($key) {
                '_eventmesh_manual_starts_at' => '2026-08-01T19:30:00',
                '_eventmesh_starts_at' => '2026-08-01T21:00:00',
                default => '',
            }
        );

        $event = EventSource::describe(12);

        self::assertNotNull($event);
        self::assertSame('2026-08-01 19:30:00', $event['starts_at']);
    }
}
