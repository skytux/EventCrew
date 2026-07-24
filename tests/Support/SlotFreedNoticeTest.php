<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->mails = [];
        Functions\when('get_option')->justReturn(false);
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
            new AssignmentRepository(),
            new PersonRepository(),
            new Mailer(new Logger()),
            new TelegramClient(new Logger(), new DohResolver(new Logger()))
        );
    }

    public function testBroadcastsToEligibleCrewExceptTheCancellerAndTheBusy(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 5, 'task_date' => '2026-08-01', 'role_slug' => 'clean', 'capacity' => 3,
            'event_post_id' => null, 'event_label' => 'Party', 'starts_at' => null, 'ends_at' => null,
        ]; // tasks->find
        $this->wpdb->nextCols[] = [8]; // personIdsAssignedOn: person 8 is already on that day
        $this->wpdb->nextResults[] = [ // activeEmailRecipients
            ['id' => 7, 'email' => 'canceller@example.com', 'email_verified_at' => '2026-07-01 00:00:00'],
            ['id' => 8, 'email' => 'busy@example.com', 'email_verified_at' => '2026-07-01 00:00:00'],
            ['id' => 9, 'email' => 'free@example.com', 'email_verified_at' => '2026-07-01 00:00:00'],
        ];

        $this->notice()->announce(5, 7); // canceller is person 7

        // Only person 9 is told - 7 just cancelled, 8 is already working that day.
        self::assertCount(1, $this->mails);
        self::assertSame('free@example.com', $this->mails[0]['to']);
    }
}
