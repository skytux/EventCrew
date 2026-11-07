<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\Task;
use EventCrew\Support\CancelNote;
use EventCrew\Support\SignupService;
use EventCrew\Tests\TestCase;

/**
 * Q351: what a cancel means for the standing, in the person's words. The test
 * clock is 2026-07-20 12:00:00.
 */
final class CancelNoteTest extends TestCase
{
    private function notice(int $hours): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed
                => SignupService::NOTICE_HOURS_OPTION === $name ? $hours : $default
        );
    }

    private function task(?string $startsAt, string $date = '2026-08-01'): Task
    {
        return Task::fromRow(['id' => 5, 'task_date' => $date, 'role_slug' => 'door', 'starts_at' => $startsAt]);
    }

    public function testAnEarlyCancelNamesTheConfiguredHours(): void
    {
        $this->notice(36);

        self::assertSame(
            'This is an early cancellation: it is more than 36 hours before the task, so it has no effect on your standing.',
            CancelNote::early($this->task('2026-08-01 18:00:00'))
        );
        self::assertTrue(CancelNote::isEarly($this->task('2026-08-01 18:00:00')));
    }

    public function testOneHourIsSingular(): void
    {
        $this->notice(1);

        self::assertStringContainsString('more than 1 hour before', CancelNote::early($this->task('2026-08-01 18:00:00')));
    }

    /** An untimed task counts from 00:00 of its date, as the cancel itself does. */
    public function testAnUntimedTaskCountsFromMidnight(): void
    {
        $this->notice(48);

        self::assertTrue(CancelNote::isEarly($this->task(null, '2026-07-23')));
        self::assertFalse(CancelNote::isEarly($this->task(null, '2026-07-21')));
    }

    /**
     * A free cancel close to the task only happens inside the second thoughts
     * window, so it does not claim to be more than N hours before.
     */
    public function testAFreeCancelCloseToTheTaskSaysItWasJustTaken(): void
    {
        $this->notice(48);
        $task = $this->task('2026-07-21 10:00:00');

        self::assertFalse(CancelNote::isEarly($task));
        self::assertSame(
            'You took this task only minutes ago, so cancelling it has no effect on your standing.',
            CancelNote::early($task)
        );
    }

    public function testANoticeOfZeroHoursSaysOnlyThatItCostsNothing(): void
    {
        $this->notice(0);

        self::assertSame('It has no effect on your standing.', CancelNote::early($this->task('2026-08-01 18:00:00')));
        self::assertFalse(CancelNote::isEarly($this->task('2026-08-01 18:00:00')));
    }

    /** With no task (the Messages page preview, the web notice): the hours sentence. */
    public function testWithoutATaskItIsTheHoursSentence(): void
    {
        $this->notice(48);

        self::assertStringStartsWith('This is an early cancellation: it is more than 48 hours', CancelNote::early());
    }

    public function testTheUndoSentencesNameSignUpAgainAndTheMark(): void
    {
        self::assertStringContainsString('Tap Sign up again', CancelNote::undo());
        self::assertStringContainsString('late cancellation is removed', CancelNote::undo());
        self::assertStringContainsString('late cancellation is removed', CancelNote::undoShort());
    }
}
