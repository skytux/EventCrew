<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\DoorList;
use EventCrew\Support\StandingCalculator;
use EventCrew\Tests\TestCase;

final class DoorListTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed =>
                StandingCalculator::THRESHOLD_OPTION === $name ? 0.6 : $default
        );
        Functions\when('get_the_title')->justReturn('');
    }

    private function doorList(): DoorList
    {
        return new DoorList(
            new TaskRepository(),
            new AssignmentRepository(),
            new PersonRepository(),
            new RedemptionRepository(),
            new StandingCalculator(new AssignmentRepository(), new RedemptionRepository(), new CreditGrantRepository())
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(int $id): array
    {
        return [
            'id' => $id,
            'task_date' => '2026-08-01',
            'role_slug' => 'decorate',
            'capacity' => 2,
            'event_post_id' => null,
            'event_label' => 'Dance',
            'starts_at' => null,
            'ends_at' => null,
            'notes' => '',
            'created_at' => '2026-07-01 00:00:00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function personRow(int $id): array
    {
        return ['id' => $id, 'email' => sprintf('p%d@example.com', $id), 'display_name' => 'Person ' . $id];
    }

    public function testUnionsWorkersAndRedeemers(): void
    {
        // get_results, in call order:
        $this->wpdb->nextResults[] = [$this->taskRow(5)];                          // tasks->forDate
        $this->wpdb->nextResults[] = [['id' => 1, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up']]; // forTask
        $this->wpdb->nextResults[] = [];                                           // standing(7) history
        $this->wpdb->nextResults[] = [['id' => 2, 'person_id' => 9, 'redeemed_for' => '2026-08-01']]; // redemptions->forDate
        $this->wpdb->nextResults[] = [];                                           // standing(9) history
        $this->wpdb->nextResults[] = [$this->personRow(7), $this->personRow(9)];   // people->all (both free -> no candidates)

        // get_row, in call order: worker find(7), redeemer find(9)
        $this->wpdb->nextRows[] = $this->personRow(7);
        $this->wpdb->nextRows[] = $this->personRow(9);

        // get_var: countFor(7), countFor(9)
        $this->wpdb->nextVars[] = 0;
        $this->wpdb->nextVars[] = 0;

        $door = $this->doorList()->forDate('2026-08-01');

        self::assertCount(2, $door['entrants']);
        self::assertSame([], $door['candidates']);

        $byName = [];
        foreach ($door['entrants'] as $entrant) {
            $byName[$entrant['name']] = $entrant;
        }

        self::assertNull($byName['Person 7']['redemption_id']);      // worker
        self::assertStringContainsString('Working', $byName['Person 7']['detail']);
        self::assertSame(2, $byName['Person 9']['redemption_id']);   // redeemer
    }

    public function testDeduplicatesSomeoneWhoWorksAndAlsoRedeemed(): void
    {
        $this->wpdb->nextResults[] = [$this->taskRow(5)];                          // tasks->forDate
        $this->wpdb->nextResults[] = [['id' => 1, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up']]; // forTask
        $this->wpdb->nextResults[] = [];                                           // standing(7) history
        $this->wpdb->nextResults[] = [['id' => 2, 'person_id' => 7, 'redeemed_for' => '2026-08-01']]; // redemption for the same person
        $this->wpdb->nextResults[] = [$this->personRow(7)];                        // people->all

        $this->wpdb->nextRows[] = $this->personRow(7);                             // worker find(7)
        $this->wpdb->nextVars[] = 0;                                               // countFor(7)

        $door = $this->doorList()->forDate('2026-08-01');

        // The worker row wins; the redemption does not add a second entrant.
        self::assertCount(1, $door['entrants']);
        self::assertNull($door['entrants'][0]['redemption_id']);
    }

    public function testListsPeopleWithCreditsAsRedemptionCandidates(): void
    {
        $this->wpdb->nextResults[] = [];                    // tasks->forDate (no tasks)
        $this->wpdb->nextResults[] = [];                    // redemptions->forDate (none)
        $this->wpdb->nextResults[] = [$this->personRow(13)]; // people->all
        // standing(13) history: two completions -> one credit
        $this->wpdb->nextResults[] = [
            ['id' => 1, 'task_id' => 1, 'person_id' => 13, 'status' => 'completed', 'task_date' => '2026-07-20'],
            ['id' => 2, 'task_id' => 2, 'person_id' => 13, 'status' => 'completed', 'task_date' => '2026-07-20'],
        ];
        $this->wpdb->nextVars[] = 0;                        // countFor(13)

        $door = $this->doorList()->forDate('2026-08-01');

        self::assertSame([], $door['entrants']);
        self::assertCount(1, $door['candidates']);
        self::assertSame(13, $door['candidates'][0]['person_id']);
        self::assertSame(1, $door['candidates'][0]['credit_balance']);
    }
}
