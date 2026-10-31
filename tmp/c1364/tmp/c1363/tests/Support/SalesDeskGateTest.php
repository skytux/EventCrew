<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Support\SalesDeskGate;

/**
 * Who may open the sales desk, and until when. The clock reads 12:00 on the
 * night; the leader and Welcome crew may open it 10 minutes (the default)
 * before their task, once marked arrived, until the event ends. Organizers may
 * at any time until 06:00 the next morning.
 */
final class SalesDeskGateTest extends SalesDeskTestCase
{
    private function gate(): SalesDeskGate
    {
        return $this->salesDeskGate();
    }

    private function person(int $id, bool $organizer = false): Person
    {
        return Person::fromRow($this->personRow($id, $organizer));
    }

    /**
     * Queues the night's tasks and one assignment for person 8 on the door
     * task (id 6) - the two reads check() makes for a non-organizer.
     */
    private function queueDoorTask(string $role, string $start, string $status, string $cleanStart = '23:00'): void
    {
        $this->wpdb->nextResults[] = [
            $this->taskRow(5, 'decorate', '10:00', '11:00'),
            $this->taskRow(6, $role, $start, '14:00'),
            $this->taskRow(7, 'clean', $cleanStart, '23:59'),
        ];
        $this->wpdb->nextRows[] = $this->assignmentRow(30, 6, 8, $status);
    }

    public function testAnOrganizerMayOpenItAnyTimeBefore0600NextDay(): void
    {
        self::assertSame(SalesDeskGate::OK, $this->gate()->check($this->person(1, true), self::NIGHT));
        // Yesterday's night ended at 06:00 this morning.
        self::assertSame(SalesDeskGate::CLOSED, $this->gate()->check($this->person(1, true), '2026-07-19'));
        // An organizer's check reads nothing: no task or assignment is needed.
        self::assertSame([], $this->wpdb->queries);
    }

    public function testArrivedWelcomeCrewMayOpenItTenMinutesBeforeTheirTask(): void
    {
        $this->queueDoorTask('welcome', '12:10', 'arrived');

        self::assertSame(SalesDeskGate::OK, $this->gate()->check($this->person(8), self::NIGHT));
    }

    public function testArrivedLateCountsAsArrived(): void
    {
        $this->queueDoorTask('welcome', '12:05', 'arrived_late');

        self::assertSame(SalesDeskGate::OK, $this->gate()->check($this->person(8), self::NIGHT));
    }

    public function testTheLeaderIsAllowedUnderTheSameRule(): void
    {
        $this->queueDoorTask('leader', '12:05', 'arrived');

        self::assertSame(SalesDeskGate::OK, $this->gate()->check($this->person(8), self::NIGHT));
    }

    public function testMoreThanTenMinutesBeforeTheTaskIsNotYet(): void
    {
        $this->queueDoorTask('welcome', '12:11', 'arrived');

        self::assertSame(SalesDeskGate::NOT_YET, $this->gate()->check($this->person(8), self::NIGHT));
    }

    public function testNotMarkedArrivedIsNotYet(): void
    {
        $this->queueDoorTask('welcome', '12:05', 'signed_up');

        self::assertSame(SalesDeskGate::NOT_YET, $this->gate()->check($this->person(8), self::NIGHT));
        self::assertSame(
            'The sales desk link opens 10 minutes before your task, once you are marked arrived.',
            SalesDeskGate::message(SalesDeskGate::NOT_YET)
        );
    }

    public function testTheMinutesComeFromTheSetting(): void
    {
        $this->options[SalesDeskGate::CREW_MINUTES_OPTION] = 30;
        $this->queueDoorTask('welcome', '12:25', 'arrived');

        self::assertSame(SalesDeskGate::OK, $this->gate()->check($this->person(8), self::NIGHT));
    }

    public function testOtherCrewMayNotOpenIt(): void
    {
        $this->wpdb->nextResults[] = [$this->taskRow(5, 'decorate', '12:00', '13:00')];

        self::assertSame(SalesDeskGate::NOT_CREW, $this->gate()->check($this->person(8), self::NIGHT));
    }

    public function testAfterTheEventEndsTheDoorCrewIsRefusedButAnOrganizerIsNot(): void
    {
        // Clean starts at 11:30: the event ended half an hour ago.
        $this->queueDoorTask('welcome', '11:00', 'arrived', '11:30');

        self::assertSame(SalesDeskGate::CLOSED, $this->gate()->check($this->person(8), self::NIGHT));
        self::assertSame(SalesDeskGate::OK, $this->gate()->check($this->person(1, true), self::NIGHT));
        self::assertSame('The sales desk for this night has closed.', SalesDeskGate::message(SalesDeskGate::CLOSED));
    }

    public function testNoNightIsSaidPlainly(): void
    {
        self::assertSame(SalesDeskGate::NO_NIGHT, $this->gate()->check($this->person(1, true), ''));
    }

    public function testTheNextNightIsTheNearestDateTodayOrLater(): void
    {
        $this->wpdb->nextCols[] = ['2026-08-01', '2026-07-20', '2026-07-10'];

        self::assertSame(self::NIGHT, $this->gate()->nextNight());
    }

    public function testAnOrganizerSessionOutlivesTheEventButAWelcomeSessionDoesNot(): void
    {
        $ended = [$this->taskRow(6, 'welcome', '10:00', '11:00'), $this->taskRow(7, 'clean', '11:30', '13:00')];
        $this->wpdb->nextResults[] = $ended; // endOf for the Welcome member

        self::assertFalse($this->gate()->sessionOpen($this->person(8), self::NIGHT));
        self::assertTrue($this->gate()->sessionOpen($this->person(1, true), self::NIGHT));
        self::assertGreaterThan(0, $this->gate()->secondsLeft($this->person(1, true), self::NIGHT));

        $this->wpdb->nextResults[] = $ended; // endOf again
        self::assertSame(0, $this->gate()->secondsLeft($this->person(8), self::NIGHT));
    }

    public function testTheEndIsTheLinkedEventsEndFirst(): void
    {
        Functions\when('post_type_exists')->justReturn(true);
        Functions\when('get_post')->justReturn(
            (object) ['ID' => 12, 'post_type' => 'eventmesh_event', 'post_title' => 'Party']
        );
        Functions\when('get_post_meta')->alias(
            static fn (int $id, string $key): string => '_eventmesh_ends_at' === $key ? '2026-07-21 02:00:00' : ''
        );

        $linked = $this->taskRow(6, 'welcome', '20:00', '21:00') + ['event_post_id' => 12];

        self::assertSame('2026-07-21 02:00:00', $this->gate()->endOf(self::NIGHT, [
            Task::fromRow($linked),
            Task::fromRow($this->taskRow(7, 'clean', '23:00', '23:59')),
        ]));
    }

    public function testWithoutALinkedEventTheEndIsTheEndAnchoredTasksStart(): void
    {
        self::assertSame(self::NIGHT . ' 23:00:00', $this->gate()->endOf(self::NIGHT, [
            Task::fromRow($this->taskRow(6, 'welcome', '20:00', '21:00')),
            Task::fromRow($this->taskRow(7, 'clean', '23:00', '23:59')),
        ]));
    }

    public function testWithoutAnEndAnchoredTaskTheEndIsTheLatestTaskEnd(): void
    {
        self::assertSame(self::NIGHT . ' 22:30:00', $this->gate()->endOf(self::NIGHT, [
            Task::fromRow($this->taskRow(5, 'decorate', '18:00', '22:30')),
            Task::fromRow($this->taskRow(6, 'welcome', '20:00', '21:00')),
        ]));
    }

    public function testWithNoTimesAtAllTheNightRunsUntil0600(): void
    {
        self::assertSame('2026-07-21 06:00:00', $this->gate()->endOf(self::NIGHT, [
            Task::fromRow($this->taskRow(6, 'welcome')),
        ]));
    }
}
