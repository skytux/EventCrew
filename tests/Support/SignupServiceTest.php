<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Models\Task;
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

    /**
     * Two jobs whose times run into each other are now allowed. Whether a
     * person can actually do both is theirs to judge, and refusing it cost the
     * organizer a filled slot to prevent something that was often fine.
     */
    public function testClaimAllowsAnOverlappingSlot(): void
    {
        $this->options[SignupService::GATE_OPTION] = '0'; // skip the gate
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

    /**
     * The board asks this to say "you cannot take this" before anyone presses
     * a button. It has to give the same answer claim() would, and it must not
     * write anything on the way to giving it.
     */
    public function testRefusalForAnswersWithoutClaiming(): void
    {
        $this->withCrewLeaderRole();
        $this->wpdb->nextRows[] = $this->taskRow('leader');       // tasks->find
        $this->wpdb->nextRows[] = ['id' => 9, 'role_slugs' => '']; // people->find

        self::assertSame(SignupService::ORGANIZERS_ONLY, $this->service()->refusalFor(9, 5));
        self::assertSame([], $this->wpdb->inserts);
    }

    /**
     * Settings > Roles with Crew leader as a normal restricted role, switched
     * off for new nights, with conditions set. The conditions do not let
     * anyone sign up; only holding the role does.
     */
    private function withCrewLeaderRole(): void
    {
        $this->options['eventcrew_roles'] = [
            ['slug' => 'decorate', 'label' => 'Decorate'],
            [
                'slug' => 'leader',
                'label' => 'Crew leader',
                'restricted' => true,
                'every_night' => false,
                'conditions' => ['min_roles' => 1, 'times_each' => 1],
            ],
        ];
    }

    /**
     * The board decides a whole screen of rows from these two, rather than
     * working the rules out again for itself - which is what it did at first,
     * and the gate is exactly the rule that gets edited in one place and
     * silently disagreed with in another.
     */
    public function testTheBoardsTwoCheapChecksComeFromHere(): void
    {
        $this->withCrewLeaderRole();
        $leaderTask = Task::fromRow($this->taskRow('leader'));
        $person = Person::fromRow(['id' => 9, 'email' => 'sam@example.com']);
        $leader = Person::fromRow(['id' => 9, 'email' => 'sam@example.com', 'role_slugs' => '["leader"]']);

        self::assertTrue(SignupService::blocksRestrictedSlot($leaderTask, $person));
        self::assertFalse(SignupService::blocksRestrictedSlot($leaderTask, $leader));
        self::assertFalse(SignupService::blocksRestrictedSlot(Task::fromRow($this->taskRow('decorate')), $person));

        // The gate off means no person-level refusal, and no standing read.
        $this->options[SignupService::GATE_OPTION] = '0';
        self::assertSame('', $this->service()->personRefusal(9));
    }

    public function testACrewLeaderTaskRefusesSomeoneWithoutTheRole(): void
    {
        $this->withCrewLeaderRole();
        $this->wpdb->nextRows[] = $this->taskRow('leader');        // claim tasks->find
        $this->wpdb->nextRows[] = ['id' => 9, 'role_slugs' => '']; // people->find

        self::assertSame(SignupService::ORGANIZERS_ONLY, $this->service()->claim(9, 5, true));
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testACrewLeaderTaskAcceptsSomeoneInTheCrewLeaderRoleGroup(): void
    {
        $this->withCrewLeaderRole();
        $this->options[SignupService::GATE_OPTION] = '0';
        $this->wpdb->nextRows[] = $this->taskRow('leader');                  // claim tasks->find
        $this->wpdb->nextRows[] = ['id' => 9, 'role_slugs' => '["leader"]']; // people->find
        $this->wpdb->nextVars[] = 1;                                         // taskCapacity
        $this->wpdb->nextRows[] = null;                                      // join findFor
        $this->wpdb->nextQueryResults[] = 1;                                 // insert

        self::assertSame(AssignmentRepository::JOIN_OK, $this->service()->claim(9, 5, true));
    }

    /**
     * An organizer adding someone: no role is needed, the standing gate is
     * not asked, and the person is not an organizer either.
     */
    public function testAnOrganizerCanAddSomeoneWithoutTheRoleToAnyTask(): void
    {
        $this->withCrewLeaderRole();
        $this->wpdb->nextRows[] = $this->taskRow('leader');        // tasks->find
        $this->wpdb->nextRows[] = ['id' => 9, 'role_slugs' => '']; // people->find
        $this->wpdb->nextVars[] = 1;                               // taskCapacity
        $this->wpdb->nextRows[] = null;                            // join findFor
        $this->wpdb->nextQueryResults[] = 1;                       // insert

        self::assertSame(AssignmentRepository::JOIN_OK, $this->service()->claimByOrganizer(9, 5, true));
    }

    public function testAnOrganizerAddStillAsksTheCreditQuestionOnARoleTask(): void
    {
        $this->withCrewLeaderRole();
        $this->wpdb->nextRows[] = $this->taskRow('leader');        // tasks->find
        $this->wpdb->nextRows[] = ['id' => 9, 'role_slugs' => '']; // people->find

        self::assertSame(SignupService::CREDIT_QUESTION, $this->service()->claimByOrganizer(9, 5));
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testAnOrganizerAddSkipsTheStandingGateButNotABan(): void
    {
        // Gate on (setUp): claimByOrganizer reads no history at all.
        $this->wpdb->nextRows[] = $this->taskRow('decorate'); // tasks->find
        $this->wpdb->nextRows[] = ['id' => 9];                // people->find
        $this->wpdb->nextVars[] = 2;                          // taskCapacity
        $this->wpdb->nextRows[] = null;                       // join findFor
        $this->wpdb->nextQueryResults[] = 1;                  // insert

        self::assertSame(AssignmentRepository::JOIN_OK, $this->service()->claimByOrganizer(9, 5));

        $this->wpdb->nextRows[] = $this->taskRow('decorate');                          // tasks->find
        $this->wpdb->nextRows[] = ['id' => 9, 'banned_at' => '2026-07-01 10:00:00'];   // people->find

        self::assertSame(SignupService::GATED, $this->service()->claimByOrganizer(9, 5));
    }

    /**
     * The organize task is for organizers only. Both the web crew page and the
     * Telegram board claim through here, so this refusal covers both.
     */
    public function testOrganizeTaskRefusesSomeoneWhoIsNotAnOrganizer(): void
    {
        $this->options[SignupService::GATE_OPTION] = '0';
        $this->wpdb->nextRows[] = $this->taskRow('organize');         // claim tasks->find
        $this->wpdb->nextRows[] = ['id' => 9, 'is_organizer' => 0];   // people->find

        self::assertSame(SignupService::ORGANIZERS_ONLY, $this->service()->claim(9, 5));
        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], array_filter($this->wpdb->queries, static fn (string $sql): bool => str_starts_with(ltrim($sql), 'INSERT')));
    }

    public function testOrganizeTaskRefusesAnUnknownPerson(): void
    {
        $this->options[SignupService::GATE_OPTION] = '0';
        $this->wpdb->nextRows[] = $this->taskRow('organize'); // claim tasks->find
        $this->wpdb->nextRows[] = null;                       // people->find

        self::assertSame(SignupService::ORGANIZERS_ONLY, $this->service()->claim(9, 5));
    }

    public function testOrganizeTaskAcceptsAnOrganizer(): void
    {
        $this->options[SignupService::GATE_OPTION] = '0';
        $this->wpdb->nextRows[] = $this->taskRow('organize');         // claim tasks->find
        $this->wpdb->nextRows[] = ['id' => 9, 'is_organizer' => 1];   // people->find
        $this->wpdb->nextVars[] = 1;                                  // taskCapacity
        $this->wpdb->nextRows[] = null;                               // join findFor
        $this->wpdb->nextQueryResults[] = 1;                          // insert

        self::assertSame(AssignmentRepository::JOIN_OK, $this->service()->claim(9, 5, true));
    }

    public function testBlocksOrganizerSlotOnlyForTheOrganizeTask(): void
    {
        $organize = Task::fromRow($this->taskRow('organize'));
        $member = Person::fromRow(['id' => 9, 'email' => 'sam@example.com', 'is_organizer' => 0]);
        $organizer = Person::fromRow(['id' => 9, 'email' => 'sam@example.com', 'is_organizer' => 1]);

        self::assertTrue(SignupService::blocksRestrictedSlot($organize, $member));
        self::assertTrue(SignupService::blocksRestrictedSlot($organize, null));
        self::assertFalse(SignupService::blocksRestrictedSlot($organize, $organizer));
        self::assertFalse(SignupService::blocksRestrictedSlot(Task::fromRow($this->taskRow('decorate')), $member));
        self::assertFalse(SignupService::blocksRestrictedSlot(null, $member));
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

        $this->wpdb->nextRows[] = $this->taskRow('decorate'); // claim tasks->find (not a role task)
        $this->wpdb->nextResults[] = $atRisk;                 // gate: historyFor
        $this->wpdb->nextVars[] = 0;                          // gate: countFor redemptions
        $this->wpdb->nextVars[] = 0;                          // gate: sumFor grants
        $this->wpdb->nextRows[] = ['id' => 9, 'at_risk_pass' => 1]; // people->find for the pass
        $this->wpdb->nextVars[] = 2;                          // taskCapacity
        $this->wpdb->nextRows[] = null;                       // join findFor
        $this->wpdb->nextQueryResults[] = 1;                  // insert

        self::assertSame(AssignmentRepository::JOIN_OK, $this->service()->claim(9, 5));
        // The pass was spent - an UPDATE clearing at_risk_pass.
        self::assertNotSame([], $this->wpdb->updates);
    }

    /**
     * Q351: the late cancel itself may have put the member at risk. Signing up
     * again for that same task undoes it, so the gate does not stop it.
     */
    public function testSigningUpAgainAfterALateCancelGetsPastTheGate(): void
    {
        $this->wpdb->nextRows[] = $this->taskRow('decorate'); // evaluate: tasks->find
        $this->wpdb->nextRows[] = ['id' => 9];                // evaluate: people->find, no pass
        $this->queueAtRiskStanding();
        $this->wpdb->nextVars[] = 0;                          // gate: sumFor grants
        $lateRow = ['id' => 3, 'task_id' => 5, 'person_id' => 9, 'status' => AssignmentStatus::LATE_CANCEL];
        $this->wpdb->nextRows[] = $lateRow;                   // undoesLateCancel: findFor
        $this->wpdb->nextVars[] = 2;                          // join: taskCapacity
        $this->wpdb->nextRows[] = $lateRow;                   // join: findFor
        $this->wpdb->nextQueryResults[] = 1;                  // reactivate

        self::assertSame(AssignmentRepository::JOIN_REJOINED, $this->service()->claim(9, 5));
        self::assertStringContainsString("SET target.status = 'signed_up'", $this->wpdb->lastQuery());
        self::assertSame([], $this->wpdb->updates, 'no at-risk pass is spent');
    }

    /** A plain cancel is not undone by signing up again: the gate still holds. */
    public function testSigningUpAgainAfterAnEarlyCancelStillMeetsTheGate(): void
    {
        $this->wpdb->nextRows[] = $this->taskRow('decorate');
        $this->wpdb->nextRows[] = ['id' => 9];
        $this->queueAtRiskStanding();
        $this->wpdb->nextVars[] = 0;
        $this->wpdb->nextRows[] = ['id' => 3, 'task_id' => 5, 'person_id' => 9, 'status' => AssignmentStatus::CANCELLED];

        self::assertSame(SignupService::GATED, $this->service()->claim(9, 5));
        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], array_filter($this->wpdb->queries, static fn (string $sql): bool => str_contains($sql, 'UPDATE')));
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
    /**
     * Queues what cancel() reads: the person's occupying row, then the task's
     * start. The test clock is 2026-07-20 12:00:00.
     */
    private function queueDrop(?string $startsAt): void
    {
        $this->wpdb->nextRows[] = ['id' => 3, 'task_id' => 5, 'person_id' => 9, 'status' => 'signed_up'];
        $this->wpdb->nextRows[] = ['starts_at' => $startsAt, 'task_date' => '2026-07-20'];
    }

    /** Q058: four minutes before the start, sign-out is refused and nothing is written. */
    public function testSignOutIsRefusedInsideTheLockWindow(): void
    {
        $this->queueDrop('2026-07-20 12:04:00');

        self::assertSame(AssignmentRepository::CANCEL_LOCKED, $this->service()->drop(9, 5));
        self::assertSame([], $this->wpdb->updates);
    }

    /** Q058: after the start, sign-out stays refused. */
    public function testSignOutIsRefusedOnceTheTaskHasStarted(): void
    {
        $this->queueDrop('2026-07-20 11:00:00');

        self::assertSame(AssignmentRepository::CANCEL_LOCKED, $this->service()->drop(9, 5));
        self::assertSame([], $this->wpdb->updates);
    }

    /** Six minutes before the start is outside the default 5-minute lock: a late cancel. */
    public function testSignOutJustBeforeTheLockIsALateCancel(): void
    {
        $this->queueDrop('2026-07-20 12:06:00');

        self::assertSame(AssignmentStatus::LATE_CANCEL, $this->service()->drop(9, 5));
        self::assertNotSame([], $this->wpdb->updates);
    }

    /** The window is the setting: 10 minutes locks a task six minutes away. */
    public function testTheLockWindowIsTheSetting(): void
    {
        $this->options[SignupService::SIGNOUT_LOCK_OPTION] = '10';
        $this->queueDrop('2026-07-20 12:06:00');

        self::assertSame(AssignmentRepository::CANCEL_LOCKED, $this->service()->drop(9, 5));
    }

    /** 0 turns the lock off. */
    public function testZeroTurnsTheLockOff(): void
    {
        $this->options[SignupService::SIGNOUT_LOCK_OPTION] = '0';
        $this->queueDrop('2026-07-20 12:04:00');

        self::assertSame(AssignmentStatus::LATE_CANCEL, $this->service()->drop(9, 5));
    }

    /** A task without a start time is never locked. */
    public function testATaskWithoutAStartTimeIsNeverLocked(): void
    {
        $this->queueDrop(null);

        self::assertSame(AssignmentStatus::LATE_CANCEL, $this->service()->drop(9, 5));
    }

    /** Taken inside the second thoughts window: the cancel is never late. */
    public function testACancelInsideTheSecondThoughtsWindowAfterATakeIsNotLate(): void
    {
        Functions\when('wp_next_scheduled')->alias(
            static fn (string $hook, array $args): int|false => [9, 5, 0] === $args ? 1784548800 : false
        );
        $this->queueDrop('2026-07-20 12:06:00');

        self::assertSame(AssignmentStatus::CANCELLED, $this->service()->drop(9, 5));
    }

    /** A window opened by a cancel (then a take again) does not make the next cancel free. */
    public function testAWindowOpenedByACancelKeepsTheLateCancel(): void
    {
        Functions\when('wp_next_scheduled')->alias(
            static fn (string $hook, array $args): int|false => [9, 5, 1] === $args ? 1784548800 : false
        );
        $this->queueDrop('2026-07-20 12:06:00');

        self::assertSame(AssignmentStatus::LATE_CANCEL, $this->service()->drop(9, 5));
    }

    /**
     * Q331: taken 1 minute before the lock and cancelled 2 minutes later,
     * inside the lock: the 5-minute window is still running, so it works.
     */
    public function testACancelInsideTheFiveMinutesGetsThroughTheLock(): void
    {
        // Taken 2 minutes ago: the take's window ends in 3 minutes.
        $ends = time() + 3 * 60;
        Functions\when('wp_next_scheduled')->alias(
            static fn (string $hook, array $args): int|false => [9, 5, 0] === $args ? $ends : false
        );
        $this->queueDrop('2026-07-20 12:04:00');

        self::assertSame(AssignmentStatus::CANCELLED, $this->service()->drop(9, 5));
        self::assertNotSame([], $this->wpdb->updates);
    }

    /**
     * Q331: taken 10 minutes ago and cancelled inside the lock is refused,
     * even while WP-Cron has not yet run the window's event.
     */
    public function testACancelAfterTheFiveMinutesIsStillLocked(): void
    {
        $ends = time() - 5 * 60;
        Functions\when('wp_next_scheduled')->alias(
            static fn (string $hook, array $args): int|false => [9, 5, 0] === $args ? $ends : false
        );
        $this->queueDrop('2026-07-20 12:04:00');

        self::assertSame(AssignmentRepository::CANCEL_LOCKED, $this->service()->drop(9, 5));
        self::assertSame([], $this->wpdb->updates);
    }

    /** A window opened by a cancel (then a take again) does not open the lock. */
    public function testAWindowOpenedByACancelKeepsTheLock(): void
    {
        $ends = time() + 3 * 60;
        Functions\when('wp_next_scheduled')->alias(
            static fn (string $hook, array $args): int|false => [9, 5, 1] === $args ? $ends : false
        );
        $this->queueDrop('2026-07-20 12:04:00');

        self::assertSame(AssignmentRepository::CANCEL_LOCKED, $this->service()->drop(9, 5));
    }

    /** Someone not on the task is told so, not that sign-out is closed. */
    public function testSomeoneNotSignedUpGetsNothingEvenInsideTheLock(): void
    {
        $this->wpdb->nextRows[] = null;

        self::assertSame('', $this->service()->drop(9, 5));
    }

    public function testTheLockedAnswerNamesTheMinutes(): void
    {
        self::assertStringContainsString('5 minutes', SignupService::signoutLockedText());
    }

    /**
     * A newcomer who signed themselves up cannot take a task until the team
     * says OK, and not at all after a No. The board asks personRefusal().
     */
    public function testANewcomerWaitingForTheTeamsOkIsRefused(): void
    {
        $this->options[SignupService::GATE_OPTION] = '0';
        $this->wpdb->nextRows[] = $this->taskRow('decorate');               // tasks->find
        $this->wpdb->nextRows[] = ['id' => 9, 'approval' => 'waiting'];     // people->find

        self::assertSame(SignupService::WAITING, $this->service()->claim(9, 5));
        self::assertSame([], $this->wpdb->inserts);

        $this->wpdb->nextRows[] = ['id' => 9, 'approval' => 'waiting'];
        self::assertSame(SignupService::WAITING, $this->service()->personRefusal(9));
    }

    public function testANewcomerTheTeamSaidNoToIsRefused(): void
    {
        $this->options[SignupService::GATE_OPTION] = '0';
        $this->wpdb->nextRows[] = $this->taskRow('decorate');
        $this->wpdb->nextRows[] = ['id' => 9, 'approval' => 'no'];

        self::assertSame(SignupService::TURNED_DOWN, $this->service()->claim(9, 5));

        $this->wpdb->nextRows[] = ['id' => 9, 'approval' => 'no'];
        self::assertSame(SignupService::TURNED_DOWN, $this->service()->personRefusal(9));
    }

    /** Everyone already in the list has approval '' after schema 33, which is OK. */
    public function testAnEmptyApprovalIsOk(): void
    {
        $this->options[SignupService::GATE_OPTION] = '0';
        $this->wpdb->nextRows[] = ['id' => 9, 'approval' => ''];

        self::assertSame('', $this->service()->personRefusal(9));
        self::assertSame('', SignupService::approvalRefusal(Person::fromRow(['id' => 9, 'approval' => 'ok'])));
    }
}
