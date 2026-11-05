<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\SlotFreedNotice;
use EventCrew\Telegram\DohResolver;
use EventCrew\Telegram\TelegramClient;
use EventCrew\Tests\TestCase;

final class SlotFreedNoticeTest extends TestCase
{
    /** @var array<int, array{to: string, body: string}> */
    private array $mails = [];

    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mails = [];
        $this->options = [];
        Functions\when('get_option')->alias(
            fn (string $key, mixed $default = false): mixed => $this->options[$key] ?? $default
        );
        Functions\when('update_option')->alias(function (string $key, mixed $value): bool {
            $this->options[$key] = $value;

            return true;
        });
        Functions\when('get_the_title')->justReturn('');
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = ['to' => $to, 'body' => $body];

            return true;
        });
    }

    private function notice(): SlotFreedNotice
    {
        return new SlotFreedNotice(
            new TaskRepository(),
            new PersonRepository(),
            new Mailer(new Logger(), new EmailTemplate(new Logger())),
            new TelegramClient(new Logger(), new DohResolver(new Logger()))
        );
    }

    /** @param array<string, mixed> $overrides */
    private function taskRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 5, 'task_date' => '2026-08-01', 'role_slug' => 'clean', 'capacity' => 3,
            'event_post_id' => null, 'event_label' => 'Party', 'starts_at' => null, 'ends_at' => null,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function person(int $id, string $email, array $overrides = []): array
    {
        return array_merge(
            ['id' => $id, 'email' => $email, 'email_verified_at' => '2026-07-01 00:00:00'],
            $overrides
        );
    }

    /** Queues task 5 as freed by $cancellerId, then sets up one send run. */
    private function queueAndPrepare(array $taskRow, int $taken, array $recipients, int $cancellerId = 7): void
    {
        $this->notice()->queue(5, $cancellerId);

        $this->wpdb->nextRows[] = $taskRow; // tasks->findLive
        $this->wpdb->nextResults[] = [['task_id' => 5, 'taken' => $taken]]; // occupancyFor
        $this->wpdb->nextResults[] = $recipients; // activeEmailRecipients
    }

    public function testBootQueuesOnTheSlotFreedAction(): void
    {
        $notice = $this->notice();
        Actions\expectAdded('eventcrew/slot_freed')->once()->with([$notice, 'queue'], 10, 2);

        $notice->boot();

        self::assertTrue(true);
    }

    public function testACancellationQueuesTheTaskAndSendsNothing(): void
    {
        $this->notice()->queue(5, 7);

        self::assertSame([], $this->mails);
        self::assertSame([], $this->wpdb->queries);
        $queue = $this->options[SlotFreedNotice::QUEUE_OPTION];
        self::assertSame([5], array_keys($queue));
        self::assertSame([7], $queue[5]['cancellers']);
        self::assertIsInt($queue[5]['queued_at']);
    }

    public function testTheRunSendsToEveryoneExceptTheCancellerAndEmptiesTheQueue(): void
    {
        $this->queueAndPrepare($this->taskRow(), 2, [
            $this->person(7, 'canceller@example.com'),
            $this->person(8, 'working@example.com'),
            $this->person(9, 'free@example.com'),
        ]);

        $this->notice()->sendDue(25);

        // Everyone but the canceller is told - including person 8, who is already
        // working that day and may want a second, non-overlapping task.
        $recipients = array_column($this->mails, 'to');
        self::assertSame(['working@example.com', 'free@example.com'], $recipients);
        self::assertStringContainsString('A spot just opened: ', $this->mails[0]['body']);
        self::assertSame([], $this->options[SlotFreedNotice::QUEUE_OPTION]);
    }

    public function testABlockedPersonIsNotTold(): void
    {
        $this->queueAndPrepare($this->taskRow(), 2, [
            $this->person(8, 'blocked@example.com', ['banned_at' => '2026-07-10 10:00:00']),
            $this->person(9, 'free@example.com'),
        ]);

        $this->notice()->sendDue(25);

        self::assertSame(['free@example.com'], array_column($this->mails, 'to'));
    }

    public function testATaskFullAgainDropsTheNotice(): void
    {
        $this->queueAndPrepare($this->taskRow(), 3, [$this->person(9, 'free@example.com')]);

        $this->notice()->sendDue(25);

        self::assertSame([], $this->mails);
        self::assertSame([], $this->options[SlotFreedNotice::QUEUE_OPTION]);
    }

    public function testADeletedTaskDropsTheNotice(): void
    {
        $this->notice()->queue(5, 7);
        // findLive finds no row: the task was deleted.

        $this->notice()->sendDue(25);

        self::assertSame([], $this->mails);
        self::assertStringContainsString('deleted_at IS NULL', $this->wpdb->queries[0]);
        self::assertSame([], $this->options[SlotFreedNotice::QUEUE_OPTION]);
    }

    public function testACancelledOrganizeSlotIsNotAnnounced(): void
    {
        $this->queueAndPrepare(
            $this->taskRow(['role_slug' => 'organize', 'capacity' => 1]),
            0,
            [$this->person(8, 'member@example.com')]
        );

        $this->notice()->sendDue(25);

        self::assertSame([], $this->mails);
    }

    /** The test clock is 2026-07-20 12:00. */
    public function testATaskThatHasStartedIsNotAnnounced(): void
    {
        $this->queueAndPrepare(
            $this->taskRow([
                'task_date' => '2026-07-20',
                'starts_at' => '2026-07-20 11:30:00', 'ends_at' => '2026-07-20 14:00:00',
            ]),
            0,
            [$this->person(8, 'member@example.com')]
        );

        $this->notice()->sendDue(25);

        self::assertSame([], $this->mails);
        self::assertSame([], $this->options[SlotFreedNotice::QUEUE_OPTION]);
    }

    public function testAnUntimedTaskOnAPastDateIsNotAnnounced(): void
    {
        $this->queueAndPrepare(
            $this->taskRow(['task_date' => '2026-07-19']),
            0,
            [$this->person(8, 'member@example.com')]
        );

        $this->notice()->sendDue(25);

        self::assertSame([], $this->mails);
    }

    public function testATaskStartingLaterTodayIsStillAnnounced(): void
    {
        $this->queueAndPrepare(
            $this->taskRow([
                'task_date' => '2026-07-20',
                'starts_at' => '2026-07-20 18:00:00', 'ends_at' => '2026-07-20 20:00:00',
            ]),
            0,
            [$this->person(8, 'member@example.com')]
        );

        $this->notice()->sendDue(25);

        self::assertCount(1, $this->mails);
    }

    /** A batch limit leaves the rest in the queue; the next run tells only them. */
    public function testTheBatchLimitCarriesTheRestToTheNextRun(): void
    {
        $people = [
            $this->person(8, 'a@example.com'),
            $this->person(9, 'b@example.com'),
            $this->person(10, 'c@example.com'),
        ];
        $this->queueAndPrepare($this->taskRow(), 0, $people);

        $this->notice()->sendDue(2);

        self::assertSame(['a@example.com', 'b@example.com'], array_column($this->mails, 'to'));
        self::assertSame([5], array_keys($this->options[SlotFreedNotice::QUEUE_OPTION]));

        $this->mails = [];
        $this->wpdb->nextRows[] = $this->taskRow();
        $this->wpdb->nextResults[] = [['task_id' => 5, 'taken' => 0]];
        $this->wpdb->nextResults[] = $people;

        $this->notice()->sendDue(2);

        self::assertSame(['c@example.com'], array_column($this->mails, 'to'));
        self::assertSame([], $this->options[SlotFreedNotice::QUEUE_OPTION]);
    }

    public function testNothingQueuedQueriesNothing(): void
    {
        $this->notice()->sendDue(25);

        self::assertSame([], $this->wpdb->queries);
        self::assertSame([], $this->mails);
    }
}
