<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Database\Schema;
use EventCrew\Support\ExtraTaskCredits;
use EventCrew\Tests\TestCase;

/**
 * The credit for extra tasks on the same night: pending while the night is
 * ahead, added once per completed extra task after it, never for nights
 * before the setting appeared.
 */
final class ExtraTaskCreditsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [ExtraTaskCredits::OPTION => '1'];
        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('current_time')->alias(
            static fn (string $type): string => 'Y-m-d' === $type ? '2026-11-20' : '2026-11-20 12:00:00'
        );
    }

    public function testEveryCompletedTaskOfTheNightButTheFirstGetsOneCredit(): void
    {
        $this->wpdb->nextCols[] = [21, 25, 30];
        $this->wpdb->nextQueryResults = [1, 1];

        self::assertSame(2, (new ExtraTaskCredits())->release(7, '2026-11-14'));

        $inserts = array_values(array_filter(
            $this->wpdb->queries,
            static fn (string $q): bool => str_contains($q, 'INSERT IGNORE')
        ));
        self::assertCount(2, $inserts);
        self::assertStringContainsString("'Extra task on 2026-11-14', NULL, NULL, '2026-11-20 12:00:00', 'extra_task', 25)", $inserts[0]);
        self::assertStringContainsString("'extra_task', 30)", $inserts[1]);
        // Completed or arrived late, and only tasks that count toward credit.
        self::assertStringContainsString("a.status IN ('completed','arrived_late')", $this->wpdb->queries[0]);
        self::assertStringContainsString('a.counts_credit = 1', $this->wpdb->queries[0]);
    }

    public function testPendingAndLateSweepCountOnlyTasksThatCountTowardCredit(): void
    {
        $this->wpdb->nextVars[] = '0';
        (new ExtraTaskCredits())->pendingOn(7, '2026-11-21');
        self::assertStringContainsString('a.counts_credit = 1', $this->wpdb->lastQuery());

        $this->wpdb->nextResults[] = [];
        (new ExtraTaskCredits())->releaseLate('2026-11-19 12:00:00', 'task_thanks', 5);
        self::assertStringContainsString('a.counts_credit = 1', $this->wpdb->lastQuery());
        self::assertStringContainsString('a2.counts_credit = 1', $this->wpdb->lastQuery());
    }

    /**
     * Three completed tasks gave two credits (for 25 and 30); task 25 is
     * then marked no-show, so only 21 and 30 are completed: 30 still earns
     * one, the credit for 25 is taken back.
     */
    public function testACreditWhoseTaskIsNoLongerCompletedIsTakenBack(): void
    {
        // extraTaskAssignmentsOn, then creditedIdsOn.
        $this->wpdb->nextCols[] = [25, 30];
        $this->wpdb->nextCols[] = [21, 30];

        self::assertSame(1, (new ExtraTaskCredits())->revoke(7, '2026-11-14'));

        self::assertCount(1, $this->wpdb->deletes);
        self::assertSame(
            ['person_id' => 7, 'source' => 'extra_task', 'assignment_id' => 25],
            $this->wpdb->deletes[0]['where']
        );
    }

    /** The night's first task marked no-show: one task is left, no extra. */
    public function testOnlyOneCompletedTaskLeftTakesTheExtraCreditBack(): void
    {
        $this->wpdb->nextCols[] = [25];
        $this->wpdb->nextCols[] = [25];

        self::assertSame(1, (new ExtraTaskCredits())->revoke(7, '2026-11-14'));
        self::assertSame(25, $this->wpdb->deletes[0]['where']['assignment_id']);
    }

    public function testNothingIsTakenBackWhileEveryCreditIsStillEarned(): void
    {
        $this->wpdb->nextCols[] = [25];
        $this->wpdb->nextCols[] = [21, 25];

        self::assertSame(0, (new ExtraTaskCredits())->revoke(7, '2026-11-14'));
        self::assertSame([], $this->wpdb->deletes);
    }

    public function testANightWithNoExtraCreditLooksNoFurther(): void
    {
        $this->wpdb->nextCols[] = [];

        self::assertSame(0, (new ExtraTaskCredits())->revoke(7, '2026-11-14'));
        self::assertCount(1, $this->wpdb->queries);
    }

    public function testASecondRunAddsNothing(): void
    {
        $this->wpdb->nextCols[] = [21, 25];
        $this->wpdb->nextQueryResults = [0];

        self::assertSame(0, (new ExtraTaskCredits())->release(7, '2026-11-14'));
    }

    public function testNightsBeforeTheSettingAppearedGetNothing(): void
    {
        $this->options[Schema::EXTRA_TASK_CREDIT_SINCE_OPTION] = '2026-11-15';

        self::assertSame(0, (new ExtraTaskCredits())->release(7, '2026-11-14'));
        self::assertSame(0, (new ExtraTaskCredits())->pendingOn(7, '2026-11-14'));
        self::assertSame([], $this->wpdb->queries);
    }

    public function testTheSettingOffAddsAndShowsNothing(): void
    {
        $this->options[ExtraTaskCredits::OPTION] = '0';

        self::assertSame(0, (new ExtraTaskCredits())->release(7, '2026-11-14'));
        self::assertSame(0, (new ExtraTaskCredits())->pendingFor(7));
        self::assertSame([], (new ExtraTaskCredits())->releaseLate('2026-11-19 12:00:00', 'task_thanks', 5));
        self::assertSame([], $this->wpdb->queries);
    }

    public function testPendingLooksAWeekBackButNotBeforeTheSetting(): void
    {
        $this->wpdb->nextVars = ['2', '1'];

        self::assertSame(2, (new ExtraTaskCredits())->pendingFor(7));
        self::assertStringContainsString("s.task_date >= '2026-11-13'", $this->wpdb->queries[0]);
        // Held tasks minus one, minus the credits already added that night.
        self::assertStringContainsString('GREATEST(0, n.held - 1 - n.added)', $this->wpdb->queries[0]);
        self::assertStringContainsString("g.source = 'extra_task'", $this->wpdb->queries[0]);
        self::assertStringContainsString("a.status IN ('signed_up','arrived','completed','arrived_late')", $this->wpdb->queries[0]);

        $this->options[Schema::EXTRA_TASK_CREDIT_SINCE_OPTION] = '2026-11-18';
        self::assertSame(1, (new ExtraTaskCredits())->pendingFor(7));
        self::assertStringContainsString("s.task_date >= '2026-11-18'", $this->wpdb->queries[1]);
    }

    public function testThePendingCountReadsLikeCreditsPlusPending(): void
    {
        self::assertSame('', ExtraTaskCredits::pendingSuffix(0));
        self::assertSame(' (+1 pending)', ExtraTaskCredits::pendingSuffix(1));
        self::assertSame(
            'Your extra tasks that night earned 2 more free-entry credits.',
            ExtraTaskCredits::addedLine(2)
        );
    }
}
