<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\DoorQuota;

/**
 * The max people per night (wp-admin Settings): what the desk refuses, what
 * it lets through with a warning, and how many people each action adds.
 */
final class DoorQuotaTest extends SalesDeskTestCase
{
    /**
     * A desk state with one crew row (person 8), one credit row (person 9),
     * one guest (ref 3, may bring 1) and one ticket buyer (ref 4, may bring 2).
     *
     * @return array{sections: array<int, array{rows: array<int, array<string, mixed>>}>}
     */
    private function state(): array
    {
        return ['sections' => [
            ['rows' => [
                ['kind' => 'crew', 'ref' => 8, 'plus' => 0, 'max' => 1],
                ['kind' => 'credit', 'ref' => 9, 'plus' => 0, 'max' => 0],
            ]],
            ['rows' => [
                ['kind' => 'guest', 'ref' => 3, 'plus' => 0, 'max' => 1],
                ['kind' => 'buyer', 'ref' => 4, 'plus' => 0, 'max' => 2],
            ]],
        ]];
    }

    public function testWithNoMaxEverythingGoesThrough(): void
    {
        self::assertSame(0, DoorQuota::max());
        self::assertSame(DoorQuota::OK, DoorQuota::verdict(5000, 1));
    }

    public function testAtTheMaxAnAddIsRefused(): void
    {
        $this->options[DoorQuota::MAX_OPTION] = '100';

        self::assertSame(DoorQuota::OK, DoorQuota::verdict(99, 1));
        self::assertSame(DoorQuota::REFUSED, DoorQuota::verdict(100, 1));
        self::assertSame(DoorQuota::REFUSED, DoorQuota::verdict(99, 2));
        // Taking people off, or a change that adds nobody, is never refused.
        self::assertSame(DoorQuota::OK, DoorQuota::verdict(120, 0));
    }

    public function testWithGoingOverTickedAnAddPastTheMaxIsAWarning(): void
    {
        $this->options[DoorQuota::MAX_OPTION] = '100';
        $this->options[DoorQuota::OVER_OPTION] = '1';

        self::assertSame(DoorQuota::WARN, DoorQuota::verdict(100, 1));
        self::assertSame(DoorQuota::OK, DoorQuota::verdict(99, 1));
    }

    public function testHowManyEachActionAdds(): void
    {
        $state = $this->state();

        self::assertSame(1, DoorQuota::adding($state, 'ticket', []));
        self::assertSame(1, DoorQuota::adding($state, 'holvi', []));
        self::assertSame(3, DoorQuota::adding($state, 'guest', ['plus' => 2]));
        self::assertSame(1, DoorQuota::adding($state, 'count', ['step' => 1]));
        self::assertSame(0, DoorQuota::adding($state, 'count', ['step' => -1]));
        // Someone already on the list as crew or with a credit adds nobody.
        self::assertSame(0, DoorQuota::adding($state, 'give', ['person' => 8]));
        self::assertSame(0, DoorQuota::adding($state, 'work', ['person' => 9]));
        self::assertSame(1, DoorQuota::adding($state, 'give', ['person' => 20]));
        self::assertSame(0, DoorQuota::adding($state, 'unknown', []));
    }

    public function testAPlusOneAddsOnlyPastWhatTheRowAlreadyCounts(): void
    {
        $state = $this->state();

        // The buyer counts 1 now; +2 (their max) adds 2, +5 is held to the max.
        self::assertSame(2, DoorQuota::adding($state, 'plus', ['kind' => 'buyer', 'ref' => 4, 'plus' => 2]));
        self::assertSame(2, DoorQuota::adding($state, 'plus', ['kind' => 'buyer', 'ref' => 4, 'plus' => 5]));
        // The guest already counts the one they may bring.
        self::assertSame(0, DoorQuota::adding($state, 'plus', ['kind' => 'guest', 'ref' => 3, 'plus' => 1]));
        // Marking in adds the plus-ones sent with it; marking out adds nobody.
        self::assertSame(1, DoorQuota::adding($state, 'mark', ['kind' => 'crew', 'ref' => 8, 'plus' => 1, 'in' => true]));
        self::assertSame(0, DoorQuota::adding($state, 'mark', ['kind' => 'crew', 'ref' => 8, 'plus' => 1, 'in' => false]));
    }

    public function testTheTextsNameTheNumbers(): void
    {
        $this->options[DoorQuota::MAX_OPTION] = '100';

        self::assertSame('The max of 100 people is reached. Nobody more can be added.', DoorQuota::refusedText());
        self::assertSame('Over the max: 103 people, max 100.', DoorQuota::warningText(103));
    }
}
