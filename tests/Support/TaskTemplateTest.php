<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\Roles;
use EventCrew\Support\TaskTemplate;
use EventCrew\Tests\TestCase;

final class TaskTemplateTest extends TestCase
{
    /**
     * The shape of an actual evening: decorate up to the doors, welcome
     * across them, clean afterwards into the next morning.
     *
     * @return array<int, array<string, mixed>>
     */
    private function danceEventRoles(): array
    {
        return [
            [
                'slug' => 'decorate',
                'label' => 'Decorate',
                'emoji' => '🎈',
                'capacity' => 2,
                'archived' => false,
                'anchor' => Roles::ANCHOR_START,
                'start_offset' => -120,
                'end_offset' => 0,
            ],
            [
                'slug' => 'clean',
                'label' => 'Clean',
                'emoji' => '🧹',
                'capacity' => 3,
                'archived' => false,
                'anchor' => Roles::ANCHOR_END,
                'start_offset' => 0,
                'end_offset' => 60,
            ],
        ];
    }

    public function testSchedulesAStartAnchoredRoleBackwardsFromTheEventStart(): void
    {
        $tasks = TaskTemplate::build(
            '2026-08-01',
            '2026-08-01 21:00:00',
            '2026-08-02 01:00:00',
            $this->danceEventRoles()
        );

        self::assertSame('2026-08-01 19:00:00', $tasks[0]['starts_at']);
        self::assertSame('2026-08-01 21:00:00', $tasks[0]['ends_at']);
    }

    /**
     * The case the end anchor exists for. Cleaning happens entirely on the
     * Sunday, but it is Saturday's event and belongs to Saturday's board.
     */
    public function testSchedulesAnEndAnchoredRolePastMidnightWhileKeepingTheTaskDate(): void
    {
        $tasks = TaskTemplate::build(
            '2026-08-01',
            '2026-08-01 21:00:00',
            '2026-08-02 01:00:00',
            $this->danceEventRoles()
        );

        self::assertSame('2026-08-02 01:00:00', $tasks[1]['starts_at']);
        self::assertSame('2026-08-02 02:00:00', $tasks[1]['ends_at']);
        self::assertSame('2026-08-01', $tasks[1]['task_date']);
    }

    public function testARoleWithoutOffsetsProducesATaskWithNoTimes(): void
    {
        $tasks = TaskTemplate::build(
            '2026-08-01',
            '2026-08-01 21:00:00',
            '2026-08-02 01:00:00',
            [[
                'slug' => 'bar',
                'label' => 'Bar',
                'emoji' => '',
                'capacity' => 1,
                'archived' => false,
                'anchor' => Roles::ANCHOR_START,
                'start_offset' => null,
                'end_offset' => null,
            ]]
        );

        self::assertNull($tasks[0]['starts_at']);
        self::assertNull($tasks[0]['ends_at']);
        self::assertSame('bar', $tasks[0]['role_slug']);
    }

    /**
     * An end-anchored role cannot be placed against an event with no end
     * recorded. Guessing one would schedule people for a time nobody agreed
     * to, so the task is created untimed for a human to finish.
     */
    public function testAnEndAnchoredRoleIsLeftUntimedWhenTheEventHasNoEnd(): void
    {
        $tasks = TaskTemplate::build(
            '2026-08-01',
            '2026-08-01 21:00:00',
            '',
            $this->danceEventRoles()
        );

        self::assertSame('2026-08-01 19:00:00', $tasks[0]['starts_at']);
        self::assertNull($tasks[1]['starts_at']);
        self::assertNull($tasks[1]['ends_at']);
    }

    /**
     * A zero offset means "exactly on the anchor", which is a real schedule -
     * decorating ends as the doors open. Treating it as "no offset set" would
     * silently drop that.
     */
    public function testAZeroOffsetIsAScheduleRatherThanAnAbsentOne(): void
    {
        $tasks = TaskTemplate::build(
            '2026-08-01',
            '2026-08-01 21:00:00',
            '2026-08-02 01:00:00',
            $this->danceEventRoles()
        );

        self::assertSame('2026-08-01 21:00:00', $tasks[0]['ends_at']);
        self::assertSame('2026-08-02 01:00:00', $tasks[1]['starts_at']);
    }

    public function testArchivedRolesAreNotScheduled(): void
    {
        $roles = $this->danceEventRoles();
        $roles[1]['archived'] = true;

        $tasks = TaskTemplate::build(
            '2026-08-01',
            '2026-08-01 21:00:00',
            '2026-08-02 01:00:00',
            $roles
        );

        self::assertCount(1, $tasks);
        self::assertSame('decorate', $tasks[0]['role_slug']);
    }

    public function testCarriesTheEventLinkOntoEveryTask(): void
    {
        $tasks = TaskTemplate::build(
            '2026-08-01',
            '2026-08-01 21:00:00',
            '2026-08-02 01:00:00',
            $this->danceEventRoles(),
            42
        );

        foreach ($tasks as $task) {
            self::assertSame(42, $task['event_post_id']);
        }
    }

    /**
     * An event running past midnight is still that evening's event to
     * everyone organizing it, so the day comes from the start and never the
     * end.
     */
    public function testFilesAnEventUnderTheDayItStarts(): void
    {
        self::assertSame('2026-08-01', TaskTemplate::dateFor('2026-08-01 21:00:00'));
        self::assertSame('', TaskTemplate::dateFor(''));
    }
}
