<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\Dates;
use EventCrew\Tests\TestCase;

final class DatesTest extends TestCase
{
    public function testWallClockKeepsTheStoredTimeExactly(): void
    {
        // The regression guard for the v1.3.1 fix: a stored 20:00 must read back
        // as 20:00, never moved by the site's offset.
        self::assertSame('20:00', Dates::wallClock('2026-07-24 20:00:00', 'H:i'));
        self::assertStringContainsString('24 Jul', Dates::wallClock('2026-07-24 20:00:00', 'H:i, D j M'));
    }

    public function testWallClockFallsBackToTheRawStringOnBadInput(): void
    {
        self::assertSame('not a date', Dates::wallClock('not a date', 'H:i'));
    }

    public function testParseUserDateReadsIsoAndFinnishStyleDates(): void
    {
        // "Today" in the tests is 2026-07-20.
        self::assertSame('2026-08-01', Dates::parseUserDate('2026-08-01', '2026-07-20'));
        self::assertSame('2026-08-01', Dates::parseUserDate('1.8.', '2026-07-20'));
        self::assertSame('2026-08-01', Dates::parseUserDate('1.8', '2026-07-20'));
        self::assertSame('2027-08-01', Dates::parseUserDate('1.8.2027', '2026-07-20'));
        // A recent past night keeps this year...
        self::assertSame('2026-07-10', Dates::parseUserDate('10.7.', '2026-07-20'));
        // ...but a day more than six months back means next year's.
        self::assertSame('2027-01-05', Dates::parseUserDate('5.1.', '2026-07-20'));
    }

    public function testParseUserDateRejectsWhatIsNotADate(): void
    {
        self::assertSame('', Dates::parseUserDate('30.2.', '2026-07-20'));
        self::assertSame('', Dates::parseUserDate('friday', '2026-07-20'));
        self::assertSame('', Dates::parseUserDate('2026-13-01', '2026-07-20'));
    }

    public function testDayLabelFormatsABareDate(): void
    {
        // No wp_date in the unit environment, so the gmdate path renders it -
        // pinned at noon UTC, so the day never rolls to a neighbour.
        self::assertStringContainsString('1 Aug', Dates::dayLabel('2026-08-01'));
    }
}
