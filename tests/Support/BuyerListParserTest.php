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
                ['name' => 'Anna Virtanen', 'plus_ones' => 2, 'email' => ''],
                ['name' => 'Bo', 'plus_ones' => 12, 'email' => ''],
                ['name' => 'Cy', 'plus_ones' => 0, 'email' => ''],
                ['name' => '+3', 'plus_ones' => 0, 'email' => ''],
                ['name' => 'Dee +1 late', 'plus_ones' => 0, 'email' => ''],
                ['name' => 'Ed +1000', 'plus_ones' => 0, 'email' => ''],
            ],
            $guests
        );
    }

    /**
     * The three shapes of a guest line with an email: the address is taken
     * out of the name and saved with the guest, and +N still counts.
     */
    public function testGuestsTakeAnEmailAddressOutOfTheName(): void
    {
        $guests = BuyerListParser::guests(
            "Anna Virtanen <Anna.V@Example.fi> +2\nBo Berg, bo@example.fi\nCy Cole cy.cole@mail.example.fi +1"
        );

        self::assertSame(
            [
                ['name' => 'Anna Virtanen', 'plus_ones' => 2, 'email' => 'anna.v@example.fi'],
                ['name' => 'Bo Berg', 'plus_ones' => 0, 'email' => 'bo@example.fi'],
                ['name' => 'Cy Cole', 'plus_ones' => 1, 'email' => 'cy.cole@mail.example.fi'],
            ],
            $guests
        );
    }

    /** A line that is only an address keeps the address's first part as the name. */
    public function testAGuestLineWithOnlyAnEmailUsesItsFirstPartAsTheName(): void
    {
        self::assertSame(
            [['name' => 'dee', 'plus_ones' => 0, 'email' => 'dee@example.fi']],
            BuyerListParser::guests('dee@example.fi')
        );
    }

    /** A line with no address works as before and saves no email. */
    public function testAGuestLineWithNoEmailSavesNone(): void
    {
        self::assertSame(
            [['name' => 'Ed Ek', 'plus_ones' => 3, 'email' => '']],
            BuyerListParser::guests('Ed Ek +3')
        );
    }
}
