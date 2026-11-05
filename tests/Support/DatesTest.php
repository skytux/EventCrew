<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\Dates;
use EventCrew\Support\Language;
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

    /**
     * Language switches only the plugin's text domain, so WordPress's day and
     * month names stay in the site's core language. Dates writes its own names
     * from the plugin's catalog instead, so Finnish reads Finnish - and leaving
     * the language puts English back, so nothing leaks into the next message.
     */
    public function testLabelsAreFinnishInsideFinnishAndEnglishAgainAfter(): void
    {
        $english = [
            'day' => 'Sat 14 Nov',
            'heading' => 'Saturday 14 November',
            'long' => 'Saturday 14 November 2026',
            'signed' => '2 Nov 14:05',
            'wall' => '21:05, Sat 14 Nov',
            'dayTime' => 'Sat 14 Nov 18:00',
        ];
        $finnish = [
            'day' => 'la 14.11.',
            'heading' => 'lauantai 14. marraskuuta',
            'long' => 'lauantai 14. marraskuuta 2026',
            'signed' => '2.11. 14:05',
            'wall' => '21:05, la 14.11.',
            'dayTime' => 'la 14.11. 18:00',
        ];
        $labels = static fn (): array => [
            'day' => Dates::dayLabel('2026-11-14'),
            'heading' => Dates::dayHeading('2026-11-14'),
            'long' => Dates::longDayLabel('2026-11-14'),
            'signed' => Dates::signedUpLabel('2026-11-02 14:05:00'),
            'wall' => Dates::wallClock('2026-11-14 21:05:00', _x('H:i, D j M', 'date format', 'eventcrew')),
            'dayTime' => Dates::dayTimeLabel((int) strtotime('2026-11-14 18:00:00 UTC')),
        ];

        // Outside any language (WordPress's own), inside English, and in a
        // loop of readers alternating languages - each gets their own.
        self::assertSame($english, $labels());

        foreach ([Language::FINNISH, Language::ENGLISH, Language::FINNISH, Language::ENGLISH] as $language) {
            Language::enter($language);

            try {
                self::assertSame(Language::FINNISH === $language ? $finnish : $english, $labels());
            } finally {
                Language::leave();
            }
        }

        self::assertSame($english, $labels());

        // A nested English run (the group board inside a Finnish tap) ends back in Finnish.
        Language::run(Language::FINNISH, static function () use ($finnish, $english, $labels): void {
            self::assertSame($english, Language::run(Language::ENGLISH, $labels));
            self::assertSame($finnish, $labels());
        });
    }

    public function testEveryFinnishDayAndMonthName(): void
    {
        $days = Language::run(Language::FINNISH, static fn (): array => array_map(
            static fn (string $ymd): string => Dates::longDayLabel($ymd),
            ['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07']
        ));
        self::assertSame([
            'sunnuntai 1. marraskuuta 2026', 'maanantai 2. marraskuuta 2026', 'tiistai 3. marraskuuta 2026',
            'keskiviikko 4. marraskuuta 2026', 'torstai 5. marraskuuta 2026', 'perjantai 6. marraskuuta 2026',
            'lauantai 7. marraskuuta 2026',
        ], $days);

        $months = Language::run(Language::FINNISH, static fn (): array => array_map(
            static fn (int $month): string => Dates::format('F M', (int) gmmktime(12, 0, 0, $month, 1, 2026)),
            range(1, 12)
        ));
        self::assertSame([
            'tammikuuta tammi', 'helmikuuta helmi', 'maaliskuuta maalis', 'huhtikuuta huhti',
            'toukokuuta touko', 'kesäkuuta kesä', 'heinäkuuta heinä', 'elokuuta elo',
            'syyskuuta syys', 'lokakuuta loka', 'marraskuuta marras', 'joulukuuta joulu',
        ], $months);
        self::assertSame(['su', 'ma', 'ti', 'ke', 'to', 'pe', 'la'], Language::run(
            Language::FINNISH,
            static fn (): array => array_map(
                static fn (int $day): string => Dates::format('D', (int) gmmktime(12, 0, 0, 11, $day, 2026)),
                range(1, 7)
            )
        ));
    }

    public function testFormatKeepsEscapedLettersAndOtherLetters(): void
    {
        $at = (int) strtotime('2026-11-14 18:05:00 UTC');

        self::assertSame('la \\ klo 18.05 2026-11-14', Language::run(
            Language::FINNISH,
            static fn (): string => Dates::format('D \\\\ \\k\\l\\o H.i Y-m-d', $at)
        ));
        self::assertSame('Sat \\ klo 18.05 2026-11-14', Language::run(
            Language::ENGLISH,
            static fn (): string => Dates::format('D \\\\ \\k\\l\\o H.i Y-m-d', $at)
        ));
    }
}
