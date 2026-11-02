<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\BuyerListParser;
use EventCrew\Tests\TestCase;

final class BuyerListParserTest extends TestCase
{
    public function testOneNamePerLineTrimmedWithWhitespaceCollapsed(): void
    {
        $names = BuyerListParser::names("  Anna   Virtanen \r\nBo\tLind\n\n   \nCecilia");

        self::assertSame(['Anna Virtanen', 'Bo Lind', 'Cecilia'], $names);
    }

    public function testQuotedLinesAreDropped(): void
    {
        $names = BuyerListParser::names("Anna\n> On Monday Bo wrote:\n  > Bo\nCecilia");

        self::assertSame(['Anna', 'Cecilia'], $names);
    }

    public function testEverythingFromTheSignatureSeparatorDownIsDropped(): void
    {
        self::assertSame(['Anna'], BuyerListParser::names("Anna\n-- \nskytux\nOrganizer"));
        self::assertSame(['Anna'], BuyerListParser::names("Anna\r\n--\r\nskytux"));
    }

    public function testADashedNameIsNotASignatureSeparator(): void
    {
        self::assertSame(['Anna', '--- Bo', 'Cecilia'], BuyerListParser::names("Anna\n--- Bo\nCecilia"));
    }

    public function testDuplicatesAreKeptOnceIgnoringCase(): void
    {
        $names = BuyerListParser::names("Anna Virtanen\nanna  virtanen\nÄlli\nälli");

        self::assertSame(['Anna Virtanen', 'Älli'], $names);
    }

    public function testALongNameIsCutTo191Characters(): void
    {
        $names = BuyerListParser::names(str_repeat('ä', 300));

        self::assertSame(BuyerListParser::MAX_LENGTH, mb_strlen($names[0]));
    }

    public function testAnEmptyTextGivesNoNames(): void
    {
        self::assertSame([], BuyerListParser::names(''));
    }

    public function testGuestsTakeALastPlusNWordAsPlusOnes(): void
    {
        $guests = BuyerListParser::guests(
            "Anna Virtanen +2\n\n> quoted +1\nBo +12\nCy\n+3\nDee +1 late\nEd +1000\n-- \nSig +1"
        );

        self::assertSame(
            [
                ['name' => 'Anna Virtanen', 'plus_ones' => 2],
                ['name' => 'Bo', 'plus_ones' => 12],
                ['name' => 'Cy', 'plus_ones' => 0],
                ['name' => '+3', 'plus_ones' => 0],
                ['name' => 'Dee +1 late', 'plus_ones' => 0],
                ['name' => 'Ed +1000', 'plus_ones' => 0],
            ],
            $guests
        );
    }
}
