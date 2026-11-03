<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\Task;
use EventCrew\Support\TakeoverDeadline;
use EventCrew\Tests\TestCase;

/**
 * The answer deadline: 24 h when the task is less than 48 h away, 48 h when
 * it is less than 7 days away, 72 h further ahead, and never later than 1 h
 * before the start.
 */
final class TakeoverDeadlineTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
    }

    private static function at(string $mysql): int
    {
        return TakeoverDeadline::toTimestamp($mysql);
    }

    private static function deadline(string $now, string $start): ?string
    {
        $deadline = TakeoverDeadline::deadline(self::at($now), self::at($start));

        return null === $deadline ? null : TakeoverDeadline::toMysql($deadline);
    }

    public function testATaskLessThan48HoursAwayGives24Hours(): void
    {
        self::assertSame('2026-07-21 12:00:00', self::deadline('2026-07-20 12:00:00', '2026-07-22 11:00:00'));
    }

    public function testATaskExactly48HoursAwayGives48Hours(): void
    {
        // 48 h is not "less than 48 h", so the next band applies, capped 1 h
        // before the start.
        self::assertSame('2026-07-22 11:00:00', self::deadline('2026-07-20 12:00:00', '2026-07-22 12:00:00'));
    }

    public function testATaskLessThan7DaysAwayGives48Hours(): void
    {
        self::assertSame('2026-07-22 12:00:00', self::deadline('2026-07-20 12:00:00', '2026-07-25 20:00:00'));
    }

    public function testATask7DaysOrMoreAwayGives72Hours(): void
    {
        self::assertSame('2026-07-23 12:00:00', self::deadline('2026-07-20 12:00:00', '2026-07-27 12:00:00'));
        self::assertSame('2026-07-23 12:00:00', self::deadline('2026-07-20 12:00:00', '2026-08-30 12:00:00'));
    }

    public function testTheDeadlineIsNeverLaterThanOneHourBeforeTheStart(): void
    {
        // 10 h away: 24 h would run past the start, so it stops 1 h before.
        self::assertSame('2026-07-20 21:00:00', self::deadline('2026-07-20 12:00:00', '2026-07-20 22:00:00'));
    }

    public function testNoDeadlineOnceTheCapHasPassed(): void
    {
        self::assertNull(self::deadline('2026-07-20 12:00:00', '2026-07-20 13:00:00'), 'exactly 1 h before');
        self::assertNull(self::deadline('2026-07-20 12:00:00', '2026-07-20 12:30:00'));
        self::assertNull(self::deadline('2026-07-20 12:00:00', '2026-07-20 10:00:00'), 'already started');
        self::assertSame('2026-07-20 12:01:00', self::deadline('2026-07-20 12:00:00', '2026-07-20 13:01:00'));
    }

    public function testEveryNumberComesFromTheSettings(): void
    {
        $this->options = [
            TakeoverDeadline::NEAR_HOURS_OPTION => '12',
            TakeoverDeadline::NEAR_ANSWER_OPTION => '2',
            TakeoverDeadline::WEEK_DAYS_OPTION => '3',
            TakeoverDeadline::WEEK_ANSWER_OPTION => '6',
            TakeoverDeadline::FAR_ANSWER_OPTION => '10',
            TakeoverDeadline::CUTOFF_HOURS_OPTION => '4',
        ];

        self::assertSame('2026-07-20 14:00:00', self::deadline('2026-07-20 12:00:00', '2026-07-20 23:00:00'));
        self::assertSame('2026-07-20 18:00:00', self::deadline('2026-07-20 12:00:00', '2026-07-22 12:00:00'));
        self::assertSame('2026-07-20 22:00:00', self::deadline('2026-07-20 12:00:00', '2026-07-23 12:00:00'));
        self::assertNull(self::deadline('2026-07-20 12:00:00', '2026-07-20 16:00:00'), 'inside the 4 h cutoff');
    }

    public function testAnAnswerTimeOfZeroIsReadAsOneHour(): void
    {
        $this->options = [TakeoverDeadline::FAR_ANSWER_OPTION => '0'];

        self::assertSame('2026-07-20 13:00:00', self::deadline('2026-07-20 12:00:00', '2026-08-30 12:00:00'));
    }

    public function testAnUntimedTaskCountsFromTheStartOfItsDay(): void
    {
        $task = new Task(1, '2026-07-22', 'decorate', 2);

        self::assertSame(self::at('2026-07-22 00:00:00'), TakeoverDeadline::startOf($task));

        $timed = new Task(2, '2026-07-22', 'decorate', 2, null, '', '2026-07-22 19:30:00');
        self::assertSame(self::at('2026-07-22 19:30:00'), TakeoverDeadline::startOf($timed));
    }

    public function testTheLabelKeepsTheStoredDigits(): void
    {
        self::assertSame('Thu 23 Jul 12:00', TakeoverDeadline::label('2026-07-23 12:00:00'));
    }
}
