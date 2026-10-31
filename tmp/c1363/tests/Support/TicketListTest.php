<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\TicketList;
use EventCrew\Tests\TestCase;

final class TicketListTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_option')->justReturn(false);
        Functions\when('get_the_title')->justReturn('');
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
    }

    private function list(): TicketList
    {
        return new TicketList(new AssignmentRepository(), new TaskRepository(), new RedemptionRepository());
    }

    public function testSplitsTicketsIntoUpcomingAndPast(): void
    {
        // Today is 2026-07-20 (base current_time stub).
        $this->wpdb->nextResults[] = [ // assignments->forPerson
            ['id' => 1, 'task_id' => 5, 'person_id' => 9, 'status' => 'signed_up'],  // future -> upcoming
            ['id' => 2, 'task_id' => 6, 'person_id' => 9, 'status' => 'completed'],  // past
            ['id' => 3, 'task_id' => 7, 'person_id' => 9, 'status' => 'cancelled'],  // no ticket
        ];
        // tasks->find for the two ticketed assignments (the cancelled one is skipped first).
        $this->wpdb->nextRows[] = ['id' => 5, 'task_date' => '2026-08-01', 'role_slug' => 'clean', 'capacity' => 3, 'event_label' => 'Party'];
        $this->wpdb->nextRows[] = ['id' => 6, 'task_date' => '2026-07-01', 'role_slug' => 'clean', 'capacity' => 3, 'event_label' => 'Old Party'];
        $this->wpdb->nextResults[] = [ // redemptions->forPerson
            ['id' => 10, 'person_id' => 9, 'redeemed_for' => '2026-08-05', 'redeemed_at' => '2026-07-10 10:00:00'],
        ];

        $out = $this->list()->forPerson(9);

        // upcoming: assignment 1 + redemption 10; past: assignment 2.
        self::assertCount(2, $out['upcoming']);
        self::assertCount(1, $out['past']);
        self::assertStringContainsString('wp-json/eventcrew/v1/ticket?token=', $out['upcoming'][0]['url']);
    }
}
