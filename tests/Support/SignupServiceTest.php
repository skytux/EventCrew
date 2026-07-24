<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\SignupService;
use EventCrew\Support\StandingCalculator;
use EventCrew\Tests\TestCase;

final class SignupServiceTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Gate on by default here, unlike the bot tests, so the gate branch is
        // exercised; individual tests flip it.
        $this->options = [SignupService::GATE_OPTION => '1'];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
    }

    private function service(): SignupService
    {
        return new SignupService(
            new AssignmentRepository(),
            new StandingCalculator(new AssignmentRepository(), new RedemptionRepository(), new CreditGrantRepository()),
            new PersonRepository(),
            new TaskRepository()
        );
    }

    private function queueAtRiskStanding(): void
    {
        // Three completions and three no-shows: rated, score 0.5, under 0.6.
        $rows = [];
        $id = 1;
        foreach (['completed', 'completed', 'completed', 'no_show', 'no_show', 'no_show'] as $status) {
            $rows[] = ['id' => $id, 'task_id' => $id, 'person_id' => 9, 'status' => $status, 'task_date' => '2026-07-20'];
            ++$id;
        }
        $this->wpdb->nextResults[] = $rows; // historyFor
        $this->wpdb->nextVars[] = 0;        // countFor redemptions
    }

    public function testClaimRefusesAnAtRiskMemberWhenTheGateIsOn(): void
    {
        $this->queueAtRiskStanding();

        self::assertSame(SignupService::GATED, $this->service()->claim(9, 5));
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testClaimRefusesAnOverlappingSlot(): void
    {
        $this->options[SignupService::GATE_OPTION] = '0'; // skip the gate
        $this->wpdb->nextVars[] = 1; // hasOverlapping count > 0

        self::assertSame(SignupService::OVERLAP, $this->service()->claim(9, 5));
    }

    public function testClaimJoinsWhenClear(): void
    {
        $this->options[SignupService::GATE_OPTION] = '0';
        $this->wpdb->nextVars[] = 0; // hasOverlapping
        $this->wpdb->nextVars[] = 2; // taskCapacity
        $this->wpdb->nextRows[] = null; // join findFor
        $this->wpdb->nextQueryResults[] = 1; // conditional insert

        self::assertSame(AssignmentRepository::JOIN_OK, $this->service()->claim(9, 5));
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(string $roleSlug): array
    {
        return [
            'id' => 5, 'task_date' => '2026-08-01', 'role_slug' => $roleSlug, 'capacity' => 2,
            'event_post_id' => null, 'event_label' => 'Party', 'starts_at' => null, 'ends_at' => null,
        ];
    }

    public function testLeaderSlotRefusesAMemberWithoutLeaderPermission(): void
    {
        $this->wpdb->nextRows[] = $this->taskRow('leader');       // claim tasks->find
        $this->wpdb->nextRows[] = ['id' => 9, 'can_lead' => 0];   // people->find

        self::assertSame(SignupService::LEADER_ONLY, $this->service()->claim(9, 5));
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testLeaderSlotAcceptsClearedCrew(): void
    {
        $this->options[SignupService::GATE_OPTION] = '0';
        $this->wpdb->nextRows[] = $this->taskRow('leader');       // claim tasks->find
        $this->wpdb->nextRows[] = ['id' => 9, 'can_lead' => 1];   // people->find
        $this->wpdb->nextVars[] = 0;                              // hasOverlapping
        $this->wpdb->nextVars[] = 1;                              // taskCapacity
        $this->wpdb->nextRows[] = null;                           // join findFor
        $this->wpdb->nextQueryResults[] = 1;                      // insert

        self::assertSame(AssignmentRepository::JOIN_OK, $this->service()->claim(9, 5));
    }

    public function testAnAtRiskPassWavesThroughOnceAndIsSpent(): void
    {
        // Gate on (setUp), the member is at risk, but holds a one-time pass.
        $atRisk = [];
        $id = 1;
        foreach (['completed', 'completed', 'completed', 'no_show', 'no_show', 'no_show'] as $status) {
            $atRisk[] = ['id' => $id, 'task_id' => $id, 'person_id' => 9, 'status' => $status, 'task_date' => '2026-07-20'];
            ++$id;
        }

        $this->wpdb->nextRows[] = $this->taskRow('decorate'); // claim tasks->find (not the leader slot)
        $this->wpdb->nextResults[] = $atRisk;                 // gate: historyFor
        $this->wpdb->nextVars[] = 0;                          // gate: countFor redemptions
        $this->wpdb->nextVars[] = 0;                          // gate: sumFor grants
        $this->wpdb->nextRows[] = ['id' => 9, 'at_risk_pass' => 1]; // people->find for the pass
        $this->wpdb->nextVars[] = 0;                          // hasOverlapping
        $this->wpdb->nextVars[] = 2;                          // taskCapacity
        $this->wpdb->nextRows[] = null;                       // join findFor
        $this->wpdb->nextQueryResults[] = 1;                  // insert

        self::assertSame(AssignmentRepository::JOIN_OK, $this->service()->claim(9, 5));
        // The pass was spent - an UPDATE clearing at_risk_pass.
        self::assertNotSame([], $this->wpdb->updates);
    }

    public function testDropRecordsACancellation(): void
    {
        // cancel(): findFor -> an occupying row, then the task's start time.
        $this->wpdb->nextRows[] = ['id' => 3, 'task_id' => 5, 'person_id' => 9, 'status' => 'signed_up'];
        $this->wpdb->nextRows[] = ['starts_at' => '2026-09-01 20:00:00', 'task_date' => '2026-09-01'];

        $status = $this->service()->drop(9, 5);

        self::assertContains($status, [AssignmentStatus::CANCELLED, AssignmentStatus::LATE_CANCEL]);
        self::assertNotSame([], $this->wpdb->updates);
    }
}
