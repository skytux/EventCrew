<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\OpenTaskCall;
use EventCrew\Tests\TestCase;

final class OpenTaskCallTest extends TestCase
{
    /** @var array<int, array{to: string, subject: string}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mails = [];

        // Roles::all() (via Task::roleLabel) and the mailer footer read options.
        Functions\when('get_option')->justReturn(null);
        Functions\when('get_the_title')->justReturn('');

        Functions\when('wp_mail')->alias(function (string $to, string $subject): bool {
            $this->mails[] = ['to' => $to, 'subject' => $subject];

            return true;
        });
        Functions\when('rest_url')->alias(static fn (string $path = ''): string => 'https://example.test/wp-json/' . $path);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
    }

    private function call(): OpenTaskCall
    {
        return new OpenTaskCall(
            new TaskRepository(),
            new AssignmentRepository(),
            new PersonRepository(),
            new NotificationsRepository(),
            new Mailer(new Logger())
        );
    }

    /**
     * @param int $taken occupancy to report for the task
     * @return array<int, array<string, mixed>>
     */
    private function occupancyRows(int $taken): array
    {
        return $taken > 0 ? [['task_id' => 5, 'taken' => $taken]] : [];
    }

    private function taskRow(): array
    {
        return [
            'id' => 5,
            'task_date' => '2026-08-01',
            'role_slug' => 'decorate',
            'capacity' => 2,
            'event_post_id' => null,
            'event_label' => 'Party',
            'starts_at' => '2026-08-01 20:00:00',
            'ends_at' => null,
        ];
    }

    private function activePerson(): array
    {
        return [
            'id' => 7,
            'email' => 'sam@example.com',
            'email_verified_at' => '2026-07-01 00:00:00',
            'disabled_at' => null,
        ];
    }

    public function testMailsAnActiveRecipientAndRecordsTheSend(): void
    {
        // hasOpenSlotsOn: forDate, occupancyFor (0 taken -> open)
        $this->wpdb->nextResults[] = [$this->taskRow()];
        $this->wpdb->nextResults[] = $this->occupancyRows(0);
        // personIdsAssignedOn: nobody on yet
        $this->wpdb->nextCols[] = [];
        // openTasksText: forDate, occupancyFor
        $this->wpdb->nextResults[] = [$this->taskRow()];
        $this->wpdb->nextResults[] = $this->occupancyRows(0);
        // activeEmailRecipients
        $this->wpdb->nextResults[] = [$this->activePerson()];
        // ledger.hasSent -> not sent; countCompletedFor -> 0
        $this->wpdb->nextVars[] = null;
        $this->wpdb->nextVars[] = 0;
        // historyFor -> none
        $this->wpdb->nextResults[] = [];

        $sent = $this->call()->sendForDate('2026-08-01');

        self::assertSame(1, $sent);
        self::assertCount(1, $this->mails);
        self::assertSame('sam@example.com', $this->mails[0]['to']);
        // Recorded in the send-once ledger.
        self::assertNotSame([], $this->wpdb->inserts);
    }

    public function testSkipsSomeoneAlreadySignedUpForThatDate(): void
    {
        $this->wpdb->nextResults[] = [$this->taskRow()];
        $this->wpdb->nextResults[] = $this->occupancyRows(0);
        // personIdsAssignedOn: person 7 is already on
        $this->wpdb->nextCols[] = [7];
        $this->wpdb->nextResults[] = [$this->taskRow()];
        $this->wpdb->nextResults[] = $this->occupancyRows(0);
        $this->wpdb->nextResults[] = [$this->activePerson()];

        $sent = $this->call()->sendForDate('2026-08-01');

        self::assertSame(0, $sent);
        self::assertSame([], $this->mails);
    }

    public function testSendsNothingWhenEverythingIsStaffed(): void
    {
        // hasOpenSlotsOn: the one task is full (2 of 2)
        $this->wpdb->nextResults[] = [$this->taskRow()];
        $this->wpdb->nextResults[] = $this->occupancyRows(2);

        $sent = $this->call()->sendForDate('2026-08-01');

        self::assertSame(0, $sent);
        self::assertSame([], $this->mails);
    }

    public function testSkipsAnyoneAlreadyMailedForThatDate(): void
    {
        $this->wpdb->nextResults[] = [$this->taskRow()];
        $this->wpdb->nextResults[] = $this->occupancyRows(0);
        $this->wpdb->nextCols[] = [];
        $this->wpdb->nextResults[] = [$this->taskRow()];
        $this->wpdb->nextResults[] = $this->occupancyRows(0);
        $this->wpdb->nextResults[] = [$this->activePerson()];
        // ledger.hasSent -> already sent (an id comes back)
        $this->wpdb->nextVars[] = 3;

        $sent = $this->call()->sendForDate('2026-08-01');

        self::assertSame(0, $sent);
        self::assertSame([], $this->mails);
    }
}
