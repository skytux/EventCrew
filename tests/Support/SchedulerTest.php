<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\BoardPush;
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\OpenTaskCall;
use EventCrew\Support\PersonEraser;
use EventCrew\Support\ReminderCall;
use EventCrew\Support\RetentionPurge;
use EventCrew\Support\RoleConditions;
use EventCrew\Support\RoleNotifier;
use EventCrew\Support\Scheduler;
use EventCrew\Support\SendWindow;
use EventCrew\Support\SignupService;
use EventCrew\Support\SlotFreedNotice;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\StandingNotice;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\DohResolver;
use EventCrew\Telegram\TelegramClient;
use EventCrew\Tests\TestCase;

final class SchedulerTest extends TestCase
{
    private function scheduler(?SlotFreedNotice $slotFreed = null): Scheduler
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

        return new Scheduler(
            new ReminderCall($tasks, $assignments, $people, $telegram, $mailer),
            new OpenTaskCall($tasks, $assignments, $people, $ledger, $mailer, $telegram),
            new StandingNotice($tasks, $assignments, $people, $ledger, $telegram, $mailer),
            new BoardPush($tasks, $ledger, $board),
            $board,
            new RoleNotifier(new RoleConditions($assignments, $people), $people, $mailer, $telegram),
            new RetentionPurge(
                $people,
                new PersonEraser(
                    $people,
                    $assignments,
                    new RedemptionRepository(),
                    new CreditGrantRepository(),
                    new AuthTokenRepository(),
                    $ledger
                )
            ),
            new \EventCrew\Support\WelcomeSender($tasks, $people, $ledger, $mailer, $telegram),
            new \EventCrew\Telegram\CommandMenu(
                $people,
                $assignments,
                $tasks,
                new \EventCrew\Support\DoorAccess($tasks, $assignments),
                $telegram
            ),
            null,
            null,
            null,
            $slotFreed
        );
    }

    private function slotFreedNotice(): SlotFreedNotice
    {
        return new SlotFreedNotice(
            new TaskRepository(),
            new PersonRepository(),
            new Mailer(new Logger(), new EmailTemplate(new Logger())),
            new TelegramClient(new Logger(), new DohResolver(new Logger()))
        );
    }

    /**
     * Runs the scheduler with task 5 queued as freed, the window opening at
     * $openHour (the test clock is 12:00). Returns the addresses mailed and
     * the queue as the run left it.
     *
     * @return array{0: list<string>, 1: mixed}
     */
    private function runWithFreedSlot(int $openHour): array
    {
        $options = [
            SendWindow::OPTION => $openHour,
            SlotFreedNotice::QUEUE_OPTION => [5 => ['queued_at' => 1, 'cancellers' => [7], 'told' => []]],
        ];
        Functions\when('get_option')->alias(
            static function (string $key, mixed $default = false) use (&$options): mixed {
                return $options[$key] ?? $default;
            }
        );
        Functions\when('update_option')->alias(static function (string $key, mixed $value) use (&$options): bool {
            $options[$key] = $value;

            return true;
        });
        Functions\when('get_the_title')->justReturn('');
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        $mailed = [];
        Functions\when('wp_mail')->alias(static function (string $to) use (&$mailed): bool {
            $mailed[] = $to;

            return true;
        });

        $this->wpdb->nextResults[] = []; // reminders: startingBetween -> no tasks
        $this->wpdb->nextRows[] = [ // slot freed: findLive
            'id' => 5, 'task_date' => '2026-08-01', 'role_slug' => 'clean', 'capacity' => 3,
            'event_post_id' => null, 'event_label' => 'Party', 'starts_at' => null, 'ends_at' => null,
        ];
        $this->wpdb->nextResults[] = [['task_id' => 5, 'taken' => 2]]; // occupancyFor
        $this->wpdb->nextResults[] = [ // activeEmailRecipients
            ['id' => 8, 'email' => 'member@example.com', 'email_verified_at' => '2026-07-01 00:00:00'],
        ];

        $this->scheduler($this->slotFreedNotice())->run();

        return [$mailed, $options[SlotFreedNotice::QUEUE_OPTION]];
    }

    /** Window 09:00-21:00, clock 12:00: the queued notice goes out. */
    public function testRunSendsAQueuedFreedSlotInsideTheSendWindow(): void
    {
        [$mailed, $queue] = $this->runWithFreedSlot(9);

        self::assertSame(['member@example.com'], $mailed);
        self::assertSame([], $queue);
    }

    /** Window 13:00-01:00, clock 12:00: the notice waits in the queue. */
    public function testRunKeepsAQueuedFreedSlotOutsideTheSendWindow(): void
    {
        [$mailed, $queue] = $this->runWithFreedSlot(13);

        self::assertSame([], $mailed);
        self::assertSame([5], array_keys($queue));
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

    /**
     * The hourly run deletes expired ticket buyer emails, with the crew purge
     * setting at its default (off).
     */
    public function testRunPurgesExpiredTicketBuyerEmails(): void
    {
        $this->wpdb->nextResults[] = [];
        Functions\when('get_option')->returnArg(2);
        Functions\when('update_option')->justReturn(true);

        $this->scheduler()->run();

        $purges = array_filter(
            $this->wpdb->queries,
            static fn (string $sql): bool => str_starts_with($sql, 'UPDATE wp_eventcrew_ticket_buyers SET email = NULL')
        );

        self::assertCount(1, $purges);
    }

    /**
     * WP-Cron and the visitor fallback can start a run at the same moment; the
     * second must not send anything, or every due message goes out twice.
     */
    public function testRunDoesNothingWhileAnotherRunHoldsTheLock(): void
    {
        $this->wpdb->heldLocks['eventcrew_heartbeat'] = true;
        Functions\when('get_option')->returnArg(2);

        $captured = null;
        Functions\when('update_option')->alias(function (string $key, mixed $value) use (&$captured): bool {
            if (Scheduler::LAST_RUN_OPTION === $key) {
                $captured = $value;
            }

            return true;
        });

        $this->scheduler()->run();

        self::assertNull($captured);
        self::assertCount(1, $this->wpdb->queries);
    }

    public function testRunReleasesTheLockWhenItFinishes(): void
    {
        $this->wpdb->nextResults[] = [];
        Functions\when('get_option')->returnArg(2);
        Functions\when('update_option')->justReturn(true);

        $this->scheduler()->run();

        self::assertSame([], $this->wpdb->heldLocks);
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

    /**
     * Deactivating the plugin clears the hourly event that runs the buyer
     * purge, along with the other scheduled events.
     */
    public function testUnscheduleClearsTheHourlyEvent(): void
    {
        $cleared = [];
        Functions\when('wp_clear_scheduled_hook')->alias(static function (string $hook) use (&$cleared): int {
            $cleared[] = $hook;

            return 1;
        });
        // A waiting ticket send is cancelled too.
        Functions\when('get_option')->justReturn(false);
        Functions\when('delete_option')->justReturn(true);

        Scheduler::unschedule();

        self::assertContains(Scheduler::HOOK, $cleared);
        self::assertContains(\EventCrew\Support\TicketMail::START_HOOK, $cleared);
    }
}
