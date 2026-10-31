<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Models\Task;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Telegram\CalendarController;
use EventCrew\Tests\TestCase;

final class CalendarControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_option')->justReturn(false);
        Functions\when('get_the_title')->justReturn('');
        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('UTC'));
        Functions\when('home_url')->alias(static fn (string $p = '/'): string => 'https://site.test' . $p);
        Functions\when('wp_parse_url')->alias(static fn (string $u, int $c): mixed => parse_url($u, $c));
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
    }

    private function controller(): CalendarController
    {
        return new CalendarController(new TaskRepository());
    }

    public function testTimedTaskBecomesAUtcAnchoredEvent(): void
    {
        $task = new Task(5, '2026-08-01', 'bar', 2, null, 'Summer Party', '2026-08-01 20:00:00', '2026-08-01 23:00:00');

        $ics = $this->controller()->ics($task);

        self::assertStringContainsString('BEGIN:VEVENT', $ics);
        self::assertStringContainsString('END:VCALENDAR', $ics);
        self::assertStringContainsString('SUMMARY:', $ics);
        self::assertStringContainsString('Summer Party', $ics);
        // UTC timezone in the stub, so the wall-clock time carries through as Z.
        self::assertStringContainsString('DTSTART:20260801T200000Z', $ics);
        self::assertStringContainsString('DTEND:20260801T230000Z', $ics);
    }

    public function testUntimedTaskBecomesAnAllDayEvent(): void
    {
        $task = new Task(6, '2026-08-01', 'bar', 2, null, 'Summer Party');

        $ics = $this->controller()->ics($task);

        self::assertStringContainsString('DTSTART;VALUE=DATE:20260801', $ics);
        // iCalendar's DTEND is exclusive, so an all-day event ends the next day.
        self::assertStringContainsString('DTEND;VALUE=DATE:20260802', $ics);
    }

    public function testUrlSignsTheCalendarPurpose(): void
    {
        $url = CalendarController::url(5);

        self::assertStringStartsWith('https://site.test/wp-json/eventcrew/v1/calendar?token=', $url);
    }
}
