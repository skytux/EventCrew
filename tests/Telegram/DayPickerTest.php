<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use EventCrew\Telegram\BoardRenderer;
use EventCrew\Telegram\DayPicker;
use EventCrew\Tests\TestCase;

/**
 * The date selector: one row of [◀ previous] [📅 shown ✓] [next ▶], with
 * blank buttons at the ends, and the next day picked by default. "Today"
 * is 2026-07-20 in the tests.
 */
final class DayPickerTest extends TestCase
{
    private const DATES = ['2026-08-03', '2026-07-13', '2026-07-27', '2026-07-20']; // any order

    public function testOneRowWithTheShownDayMarkedBetweenTheArrows(): void
    {
        self::assertSame([[
            ['text' => '◀ Mon 20 Jul', 'callback_data' => 'dl:2026-07-20'],
            ['text' => '📅 Mon 27 Jul ✓', 'callback_data' => 'dl:2026-07-27'],
            ['text' => 'Mon 3 Aug ▶', 'callback_data' => 'dl:2026-08-03'],
        ]], DayPicker::rows(self::DATES, '2026-07-27', 'dl:'));
    }

    /** Owner 2026-11-06: at an end, a blank button keeps the shown day in the middle. */
    public function testAnEndHasABlankButtonInsteadOfTheArrow(): void
    {
        $blank = ['text' => DayPicker::BLANK, 'callback_data' => BoardRenderer::SPACER_DATA];

        self::assertSame([[
            ['text' => '◀ Mon 27 Jul', 'callback_data' => 'dl:2026-07-27'],
            ['text' => '📅 Mon 3 Aug ✓', 'callback_data' => 'dl:2026-08-03'],
            $blank,
        ]], DayPicker::rows(self::DATES, '2026-08-03', 'dl:'));

        self::assertSame([[
            $blank,
            ['text' => '📅 Mon 13 Jul ✓', 'callback_data' => 'dl:2026-07-13'],
            ['text' => 'Mon 20 Jul ▶', 'callback_data' => 'dl:2026-07-20'],
        ]], DayPicker::rows(self::DATES, '2026-07-13', 'dl:'));

        // One day: nothing to step to, so no row.
        self::assertSame([], DayPicker::rows(['2026-07-20'], '2026-07-20', 'dl:'));
        self::assertSame([], DayPicker::rows([], '', 'dl:'));
    }

    public function testTheArrowsSkipDaysThePersonMayNotSee(): void
    {
        $asked = [];
        $canView = static function (string $d) use (&$asked): bool {
            $asked[] = $d;

            return '2026-07-13' === $d;
        };

        $rows = DayPicker::rows(self::DATES, '2026-07-27', 'dl:', $canView);

        self::assertSame(
            ['◀ Mon 13 Jul', '📅 Mon 27 Jul ✓', DayPicker::BLANK],
            array_column($rows[0], 'text')
        );
        // Outward from the shown day, stopping at the first allowed one.
        self::assertSame(['2026-07-20', '2026-07-13', '2026-08-03'], $asked);
    }

    public function testTheDefaultIsTodayOrTheNextDayThenTheLatestPastOne(): void
    {
        self::assertSame('2026-07-20', DayPicker::defaultDate(self::DATES, '2026-07-20'));
        self::assertSame('2026-07-27', DayPicker::defaultDate(self::DATES, '2026-07-21'));
        self::assertSame('2026-08-03', DayPicker::defaultDate(self::DATES, '2026-08-10'));
        self::assertSame('', DayPicker::defaultDate([], '2026-07-20'));
    }
}
