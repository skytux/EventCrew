<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\BoardPush;
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\LeaderEligibility;
use EventCrew\Support\LeaderEligibilityNotifier;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\OpenTaskCall;
use EventCrew\Support\ReminderCall;
use EventCrew\Support\Scheduler;
use EventCrew\Support\SignupService;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\StandingNotice;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\DohResolver;
use EventCrew\Telegram\TelegramClient;
use EventCrew\Tests\TestCase;

final class SchedulerTest extends TestCase
{
    private function scheduler(): Scheduler
    {
        $tasks = new TaskRepository();
        $assignments = new AssignmentRepository();
        $people = new PersonRepository();
        $mailer = new Mailer(new Logger());
        $telegram = new TelegramClient(new Logger(), new DohResolver(new Logger()));

        $ledger = new NotificationsRepository();
        $board = new BoardService(
            $tasks,
            $assignments,
            $people,
            $telegram,
            new Logger(),
            new ClaimNotifier($tasks, $assignments, $mailer, $telegram, new StandingCalculator($assignments, new RedemptionRepository(), new CreditGrantRepository())),
            new SignupService($assignments, new StandingCalculator($assignments, new RedemptionRepository(), new CreditGrantRepository()), new PersonRepository(), new TaskRepository())
        );

        return new Scheduler(
            new ReminderCall($tasks, $assignments, $people, $telegram, $mailer),
            new OpenTaskCall($tasks, $assignments, $people, $ledger, $mailer),
            new StandingNotice($tasks, $assignments, $people, $ledger, $telegram, $mailer),
            new BoardPush($tasks, $ledger, $board),
            $board,
            new LeaderEligibilityNotifier(new LeaderEligibility($assignments, $people), $people, $mailer, $telegram)
        );
    }

    /**
     * With no tasks or dates queued both sends do nothing, but the run must
     * still stamp the last-run time so the throttle and the Settings readout
     * know it happened.
     */
    public function testRunStampsTheLastRunTime(): void
    {
        $this->wpdb->nextResults[] = []; // startingBetween -> no tasks
        // upcomingDates get_col defaults to [] -> no open-task dates
        Functions\when('get_option')->returnArg(2); // options fall back to defaults

        $captured = null;
        Functions\when('update_option')->alias(function (string $key, mixed $value) use (&$captured): bool {
            if (Scheduler::LAST_RUN_OPTION === $key) {
                $captured = $value;
            }

            return true;
        });

        $this->scheduler()->run();

        self::assertIsInt($captured);
    }

    public function testBootSchedulesTheHourlyEventWhenNoneExists(): void
    {
        Functions\expect('add_action')->once()->with(Scheduler::HOOK, \Mockery::type('array'));
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\expect('wp_schedule_event')->once();

        $this->scheduler()->boot();

        self::assertTrue(true);
    }

    public function testBootDoesNotRescheduleWhenAlreadyScheduled(): void
    {
        Functions\expect('add_action')->once();
        Functions\when('wp_next_scheduled')->justReturn(1_900_000_000);
        Functions\expect('wp_schedule_event')->never();

        $this->scheduler()->boot();

        self::assertTrue(true);
    }

    public function testRunIfDueSkipsWhenTheLastRunIsRecent(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $key, mixed $default = false): mixed =>
                Scheduler::LAST_RUN_OPTION === $key ? time() : $default
        );

        self::assertFalse($this->scheduler()->runIfDue());
    }

    public function testRunIfDueRunsWhenOverdue(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $key, mixed $default = false): mixed =>
                Scheduler::LAST_RUN_OPTION === $key ? 0 : $default
        );
        Functions\when('update_option')->justReturn(true);
        $this->wpdb->nextResults[] = []; // startingBetween

        self::assertTrue($this->scheduler()->runIfDue());
    }
}
