<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Models\Person;
use EventCrew\Support\JoinAnswers;
use EventCrew\Tests\TestCase;

/**
 * What a newcomer says when joining: parsed from the crew page's form,
 * checked, and read back for the organizers.
 */
final class JoinAnswersTest extends TestCase
{
    /** @param array<string, mixed> $row */
    private static function person(array $row): Person
    {
        return Person::fromRow($row + ['id' => 8, 'email' => 'sam@example.com', 'approval' => 'waiting']);
    }

    public function testYesWithNumbersFromTheForm(): void
    {
        self::assertSame(
            ['newcomer:before' => 'yes', 'newcomer:times' => '5', 'newcomer:tickets' => '0'],
            JoinAnswers::fromPost(['join_before' => ' Yes ', 'join_times' => '5', 'join_tickets' => '0'])
        );
    }

    public function testNoDropsTheNumbers(): void
    {
        self::assertSame(
            ['newcomer:before' => 'no'],
            JoinAnswers::fromPost(['join_before' => 'no', 'join_times' => '5', 'join_tickets' => '3'])
        );
    }

    public function testUnansweredIsNothing(): void
    {
        self::assertSame([], JoinAnswers::fromPost([]));
        self::assertSame([], JoinAnswers::fromPost(['join_before' => '', 'join_times' => '5']));
        self::assertSame([], JoinAnswers::fromPost(['join_before' => 'maybe']));
        self::assertSame([], JoinAnswers::fromPost(['join_before' => ['yes']]));
    }

    public function testBadOrOutOfRangeNumbersAreLeftOut(): void
    {
        self::assertSame(
            ['newcomer:before' => 'yes'],
            JoinAnswers::fromPost(['join_before' => 'yes', 'join_times' => '0', 'join_tickets' => '51'])
        );
        self::assertSame(
            ['newcomer:before' => 'yes', 'newcomer:tickets' => '50'],
            JoinAnswers::fromPost(['join_before' => 'yes', 'join_times' => 'lots', 'join_tickets' => '50'])
        );
        self::assertSame(
            ['newcomer:before' => 'yes', 'newcomer:times' => '50'],
            JoinAnswers::fromPost(['join_before' => 'yes', 'join_times' => '50', 'join_tickets' => '-1'])
        );
    }

    public function testNumber(): void
    {
        self::assertSame(12, JoinAnswers::number(' 12 '));
        self::assertSame(3, JoinAnswers::number(3));
        self::assertNull(JoinAnswers::number(''));
        self::assertNull(JoinAnswers::number('2.5'));
        self::assertNull(JoinAnswers::number('-2'));
        self::assertNull(JoinAnswers::number('12345'));
        self::assertNull(JoinAnswers::number(null));
    }

    public function testTheLineForEachAnswer(): void
    {
        self::assertSame(
            'Worked on the crew here before: yes, about 5 times. Thinks they should have 3 free-entry tickets.',
            JoinAnswers::line(self::person(['role_notices' => '{"newcomer:before":"yes","newcomer:times":"5","newcomer:tickets":"3"}']))
        );
        self::assertSame(
            'Worked on the crew here before: yes. Thinks they should have 0 free-entry tickets.',
            JoinAnswers::line(self::person(['role_notices' => '{"newcomer:before":"yes","newcomer:tickets":"0"}']))
        );
        self::assertSame(
            'Worked on the crew here before: yes.',
            JoinAnswers::line(self::person(['role_notices' => '{"newcomer:before":"yes"}']))
        );
        self::assertSame(
            'First time on the crew.',
            JoinAnswers::line(self::person(['role_notices' => '{"newcomer:before":"no","newcomer:times":"5"}']))
        );
        self::assertSame(
            'Did not say whether they have worked on the crew here before.',
            JoinAnswers::line(self::person([]))
        );
    }

    public function testOnlyAnUnconfirmedNewcomerMayAnswer(): void
    {
        self::assertTrue(JoinAnswers::canAnswer(self::person([])));
        self::assertFalse(JoinAnswers::canAnswer(self::person(['approval' => ''])));
        self::assertFalse(JoinAnswers::canAnswer(self::person(['email_verified_at' => '2026-11-01 00:00:00'])));
        self::assertFalse(JoinAnswers::canAnswer(self::person(['anonymized_at' => '2026-11-01 00:00:00'])));
        self::assertFalse(JoinAnswers::canAnswer(self::person(['role_notices' => '{"newcomer:asked":"x"}'])));
    }
}
