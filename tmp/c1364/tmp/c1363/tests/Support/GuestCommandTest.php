<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\GuestCommand;
use PHPUnit\Framework\TestCase;

final class GuestCommandTest extends TestCase
{
    private const TODAY = '2026-07-20';

    public function testNothingShowsTheNextNight(): void
    {
        $command = GuestCommand::parse('', self::TODAY);

        self::assertSame(GuestCommand::SHOW, $command['action']);
        self::assertSame('', $command['date']);
        self::assertSame('', $command['error']);
    }

    public function testADateAloneShowsThatNight(): void
    {
        $command = GuestCommand::parse('1.8.', self::TODAY);

        self::assertSame(GuestCommand::SHOW, $command['action']);
        self::assertSame('2026-08-01', $command['date']);
    }

    public function testAddReadsNamePlusOnesAndNote(): void
    {
        $command = GuestCommand::parse('add 1.8. Anna Virtanen +2 ; DJ’s partner', self::TODAY);

        self::assertSame(GuestCommand::ADD, $command['action']);
        self::assertSame('2026-08-01', $command['date']);
        self::assertSame('Anna Virtanen', $command['name']);
        self::assertSame(2, $command['plus_ones']);
        self::assertSame('DJ’s partner', $command['note']);
        self::assertSame('', $command['error']);
    }

    public function testTextAfterPlusOnesIsTheNoteWithoutASemicolon(): void
    {
        $command = GuestCommand::parse('add Anna +1 press', self::TODAY);

        self::assertSame('Anna', $command['name']);
        self::assertSame(1, $command['plus_ones']);
        self::assertSame('press', $command['note']);
    }

    public function testAddWithoutANameIsAnError(): void
    {
        self::assertSame('name', GuestCommand::parse('add +2', self::TODAY)['error']);
    }

    public function testEditKeepsWhatIsNotGiven(): void
    {
        $command = GuestCommand::parse('change 2 +3', self::TODAY);

        self::assertSame(GuestCommand::EDIT, $command['action']);
        self::assertSame(2, $command['number']);
        self::assertNull($command['name']);
        self::assertSame(3, $command['plus_ones']);
        self::assertNull($command['note']);
    }

    public function testEditWithNothingToChangeIsAnError(): void
    {
        self::assertSame('nothing', GuestCommand::parse('edit 2', self::TODAY)['error']);
    }

    public function testRemoveNeedsANumber(): void
    {
        self::assertSame('number', GuestCommand::parse('remove Anna', self::TODAY)['error']);

        $command = GuestCommand::parse('rm 1.8. 3', self::TODAY);
        self::assertSame(GuestCommand::REMOVE, $command['action']);
        self::assertSame('2026-08-01', $command['date']);
        self::assertSame(3, $command['number']);
    }

    public function testAnImpossibleDateIsAnError(): void
    {
        self::assertSame('date', GuestCommand::parse('add 31.2. Anna', self::TODAY)['error']);
    }

    public function testUnknownWordsAreAUsageError(): void
    {
        self::assertSame('usage', GuestCommand::parse('please show', self::TODAY)['error']);
    }
}
