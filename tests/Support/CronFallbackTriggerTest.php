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
use EventCrew\Support\CronFallbackTrigger;
use EventCrew\Support\EmailTemplate;
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

final class CronFallbackTriggerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->transients = [];

        Functions\when('get_transient')->alias(fn (string $key): mixed => $this->transients[$key] ?? false);
        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->transients[$key] = $value;

            return true;
        });
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);

            return true;
        });
    }

    private function trigger(): CronFallbackTrigger
    {
        $tasks = new TaskRepository();
        $assignments = new AssignmentRepository();
        $people = new PersonRepository();
        $mailer = new Mailer(new Logger(), new EmailTemplate(new Logger()));
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

        $scheduler = new Scheduler(
            new ReminderCall($tasks, $assignments, $people, $telegram, $mailer),
            new OpenTaskCall($tasks, $assignments, $people, $ledger, $mailer, $telegram),
            new StandingNotice($tasks, $assignments, $people, $ledger, $telegram, $mailer),
            new BoardPush($tasks, $ledger, $board),
            $board,
            new LeaderEligibilityNotifier(new LeaderEligibility($assignments, $people), $people, $mailer, $telegram)
        );

        return new CronFallbackTrigger($scheduler);
    }

    public function testBootHooksNothingWhenTheOptionIsOff(): void
    {
        Functions\when('get_option')->justReturn(false);
        Functions\expect('add_action')->never();

        $this->trigger()->boot();

        self::assertTrue(true);
    }

    public function testBootHooksInitWhenEnabled(): void
    {
        Functions\when('get_option')->justReturn(true);
        Functions\expect('add_action')->once();

        $this->trigger()->boot();

        self::assertTrue(true);
    }

    public function testMaybeRunRunsTheBatchWhenDueAndUnlocked(): void
    {
        // Last run long ago -> due.
        Functions\when('get_option')->alias(
            static fn (string $key, mixed $default = false): mixed =>
                Scheduler::LAST_RUN_OPTION === $key ? 0 : $default
        );
        $ran = false;
        Functions\when('update_option')->alias(function (string $key) use (&$ran): bool {
            if (Scheduler::LAST_RUN_OPTION === $key) {
                $ran = true;
            }

            return true;
        });
        $this->wpdb->nextResults[] = []; // startingBetween

        $this->trigger()->maybeRun();

        self::assertTrue($ran);
        // The lock was taken and released.
        self::assertSame([], $this->transients);
    }

    public function testMaybeRunDoesNothingWhenTheLockIsHeld(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $key, mixed $default = false): mixed =>
                Scheduler::LAST_RUN_OPTION === $key ? 0 : $default
        );
        $this->transients['eventcrew_cron_fallback_lock'] = 1;
        $ran = false;
        Functions\when('update_option')->alias(function (string $key) use (&$ran): bool {
            if (Scheduler::LAST_RUN_OPTION === $key) {
                $ran = true;
            }

            return true;
        });

        $this->trigger()->maybeRun();

        self::assertFalse($ran);
    }

    public function testMaybeRunDoesNothingWhenNotDue(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $key, mixed $default = false): mixed =>
                Scheduler::LAST_RUN_OPTION === $key ? time() : $default
        );
        $ran = false;
        Functions\when('update_option')->alias(function (string $key) use (&$ran): bool {
            if (Scheduler::LAST_RUN_OPTION === $key) {
                $ran = true;
            }

            return true;
        });

        $this->trigger()->maybeRun();

        self::assertFalse($ran);
    }
}
