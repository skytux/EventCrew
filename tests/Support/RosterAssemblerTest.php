<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\RosterAssembler;
use EventCrew\Support\StandingCalculator;
use EventCrew\Tests\TestCase;

final class RosterAssemblerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Each rostered person's standing is now composed too, which reads the
        // reputation threshold option; the default is fine for these cases.
        Functions\when('get_option')->justReturn(false);
    }

    private function assembler(): RosterAssembler
    {
        return new RosterAssembler(
            new TaskRepository(),
            new AssignmentRepository(),
            new PersonRepository(),
            new StandingCalculator(new AssignmentRepository(), new RedemptionRepository(), new CreditGrantRepository())
        );
    }

    public function testForDateComposesEachTaskWithItsPeople(): void
    {
        // TaskRepository::forDate() -> one task.
        $this->wpdb->nextResults[] = [
            ['id' => 5, 'task_date' => '2026-08-01', 'role_slug' => 'clean', 'capacity' => 3],
        ];
        // AssignmentRepository::forTask(5) -> two assignments.
        $this->wpdb->nextResults[] = [
            ['id' => 11, 'task_id' => 5, 'person_id' => 7, 'status' => 'arrived'],
            ['id' => 12, 'task_id' => 5, 'person_id' => 8, 'status' => 'no_show'],
        ];
        // PersonRepository::find() for each, in order.
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam'];
        $this->wpdb->nextRows[] = ['id' => 8, 'email' => 'jo@example.com', 'display_name' => 'Jo'];

        $roster = $this->assembler()->forDate('2026-08-01');

        self::assertCount(1, $roster);
        self::assertSame(5, $roster[0]['task']->id);
        self::assertCount(2, $roster[0]['people']);

        self::assertSame(11, $roster[0]['people'][0]['assignment_id']);
        self::assertSame('Sam', $roster[0]['people'][0]['name']);
        self::assertSame('arrived', $roster[0]['people'][0]['status']);
        self::assertTrue($roster[0]['people'][0]['occupying']);

        // A no-show keeps its row but no longer occupies the slot.
        self::assertFalse($roster[0]['people'][1]['occupying']);
    }

    public function testForDateFallsBackForADeletedPerson(): void
    {
        $this->wpdb->nextResults[] = [
            ['id' => 5, 'task_date' => '2026-08-01', 'role_slug' => 'clean', 'capacity' => 3],
        ];
        $this->wpdb->nextResults[] = [
            ['id' => 11, 'task_id' => 5, 'person_id' => 99, 'status' => 'signed_up'],
        ];
        // find(99) -> null (no row queued): the person is gone.

        $roster = $this->assembler()->forDate('2026-08-01');

        self::assertSame('(deleted person)', $roster[0]['people'][0]['name']);
    }

    public function testForDateIsEmptyWhenNoTasks(): void
    {
        $this->wpdb->nextResults[] = [];

        self::assertSame([], $this->assembler()->forDate('2026-08-01'));
    }
}
