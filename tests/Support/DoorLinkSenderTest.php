<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\DoorLinkSender;

/**
 * The automatic sales desk links. The clock reads 12:00 on the night.
 * Organizers get theirs 30 minutes (the default) before the night's first
 * task starts; the leader and Welcome crew get theirs once they are marked
 * arrived and it is 10 minutes or less before their task. Nobody gets the
 * same night's link twice.
 */
final class DoorLinkSenderTest extends SalesDeskTestCase
{
    /**
     * Queues the reads run() makes for organizers: tonight's tasks (one,
     * not a door task), the first task start, the organizer list, and the
     * ledger answer for organizer 1.
     */
    private function queueOrganizerRun(string $firstStart, ?string $sentAt = null): void
    {
        $this->wpdb->nextResults[] = [$this->taskRow(5, 'decorate', '13:00', '14:00')];
        $this->wpdb->nextVars[] = self::NIGHT . ' ' . $firstStart . ':00';
        $this->wpdb->nextResults[] = [$this->personRow(1, true)];
        $this->wpdb->nextVars[] = $sentAt;
    }

    private function dmsSent(): int
    {
        return count(array_filter($this->calledMethods(), static fn (string $m): bool => 'sendMessage' === $m));
    }

    public function testOrganizersGetNothingBeforeTheLead(): void
    {
        // First task 12:31, so the organizer link is due at 12:01.
        $this->wpdb->nextResults[] = [$this->taskRow(5, 'decorate', '12:31', '14:00')];
        $this->wpdb->nextVars[] = self::NIGHT . ' 12:31:00';

        $this->doorLinkSender()->run();

        self::assertSame(0, $this->dmsSent());
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testOrganizersGetTheLinkOnceTheLeadIsReachedAndOnlyOnce(): void
    {
        $sender = $this->doorLinkSender();

        // First task 12:30: the link is due at 12:00, which is now.
        $this->queueOrganizerRun('12:30');
        $sender->run();

        self::assertSame(1, $this->dmsSent());
        self::assertSame(501, $this->lastCallTo('sendMessage')['chat_id']);
        self::assertStringContainsString(
            'action=eventcrew_door',
            $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'][0][0]['url']
        );
        self::assertStringContainsString("'door_link'", implode("\n", $this->wpdb->queries));

        // The next run finds the ledger row and sends nothing. Every queued
        // answer of the first run was read, so none can leak into this one.
        self::assertSame([[], [], []], [$this->wpdb->nextResults, $this->wpdb->nextVars, $this->wpdb->nextRows]);
        $this->queueOrganizerRun('12:30', self::NIGHT . ' 12:00:00');
        $sender->run();

        self::assertSame(1, $this->dmsSent());
        self::assertCount(1, $this->wpdb->inserts);
    }

    public function testARunWhileAnotherHoldsTheLockSendsNothing(): void
    {
        $this->wpdb->heldLocks['eventcrew_door_link'] = true;
        $this->queueOrganizerRun('12:30');

        $this->doorLinkSender()->run();

        self::assertSame(0, $this->dmsSent());
        self::assertSame([], $this->wpdb->inserts);
        // The other run still holds it: this one did not release it.
        self::assertArrayHasKey('eventcrew_door_link', $this->wpdb->heldLocks);
    }

    public function testARunReleasesTheLock(): void
    {
        $this->queueOrganizerRun('12:30');

        $this->doorLinkSender()->run();

        self::assertSame(1, $this->dmsSent());
        self::assertSame([], $this->wpdb->heldLocks);
    }

    public function testTheOrganizerLeadComesFromTheSetting(): void
    {
        $this->options[DoorLinkSender::LEAD_OPTION] = 60;
        $this->queueOrganizerRun('13:00');

        $this->doorLinkSender()->run();

        self::assertSame(1, $this->dmsSent());
    }

    /**
     * Queues a run for a night whose only door task is task 6 ($role,
     * starting at $start) with person 8 on it as $status. Organizers are not
     * due (the first task is late in the day by the stubbed answer).
     */
    private function queueCrewRun(string $role, string $start, string $status): void
    {
        $tasks = [$this->taskRow(6, $role, $start, '14:00'), $this->taskRow(7, 'clean', '23:00')];

        $this->wpdb->nextResults[] = $tasks;                           // run: tonight's tasks
        $this->wpdb->nextVars[] = self::NIGHT . ' 20:00:00';           // organizers not due yet
        $this->wpdb->nextResults[] = [$this->assignmentRow(30, 6, 8, $status)]; // forTask(6)

        if ('signed_up' === $status) {
            return;
        }

        $this->wpdb->nextRows[] = $this->personRow(8);                  // people->find
        $this->wpdb->nextResults[] = $tasks;                            // gate: tasks
        $this->wpdb->nextRows[] = $this->assignmentRow(30, 6, 8, $status); // gate: findFor
        $this->wpdb->nextVars[] = null;                                 // ledger: not sent
        $this->wpdb->nextResults[] = $tasks;                            // secondsLeft: endOf
    }

    public function testArrivedWelcomeCrewGetTheLinkTenMinutesBeforeTheirTask(): void
    {
        $this->queueCrewRun('welcome', '12:10', 'arrived');

        $this->doorLinkSender()->run();

        self::assertSame(1, $this->dmsSent());
        self::assertSame(508, $this->lastCallTo('sendMessage')['chat_id']);
    }

    public function testTheLeaderGetsItUnderTheSameRule(): void
    {
        $this->queueCrewRun('leader', '12:05', 'arrived_late');

        $this->doorLinkSender()->run();

        self::assertSame(1, $this->dmsSent());
    }

    public function testWelcomeCrewNotMarkedArrivedGetNothing(): void
    {
        $this->queueCrewRun('welcome', '12:05', 'signed_up');

        $this->doorLinkSender()->run();

        self::assertSame(0, $this->dmsSent());
    }

    public function testWelcomeCrewGetNothingMoreThanTenMinutesBefore(): void
    {
        $tasks = [$this->taskRow(6, 'welcome', '12:11', '14:00')];

        $this->wpdb->nextResults[] = $tasks;
        $this->wpdb->nextVars[] = self::NIGHT . ' 20:00:00';
        $this->wpdb->nextResults[] = [$this->assignmentRow(30, 6, 8, 'arrived')];
        $this->wpdb->nextRows[] = $this->personRow(8);
        $this->wpdb->nextResults[] = $tasks;
        $this->wpdb->nextRows[] = $this->assignmentRow(30, 6, 8, 'arrived');

        $this->doorLinkSender()->run();

        self::assertSame(0, $this->dmsSent());
    }

    public function testBeingMarkedArrivedSendsTheLinkThere(): void
    {
        $tasks = [$this->taskRow(6, 'welcome', '12:05', '14:00')];

        $this->wpdb->nextRows[] = $this->assignmentRow(30, 6, 8, 'arrived'); // assignments->find
        $this->wpdb->nextRows[] = $this->taskRow(6, 'welcome', '12:05', '14:00'); // tasks->find
        $this->wpdb->nextRows[] = $this->personRow(8);
        $this->wpdb->nextResults[] = $tasks;
        $this->wpdb->nextRows[] = $this->assignmentRow(30, 6, 8, 'arrived');
        $this->wpdb->nextVars[] = null;
        $this->wpdb->nextResults[] = $tasks;

        $this->doorLinkSender()->onStatusChanged(30, 'arrived');

        self::assertSame(1, $this->dmsSent());
        self::assertSame(508, $this->lastCallTo('sendMessage')['chat_id']);
    }

    public function testAStatusOtherThanArrivedSendsNothing(): void
    {
        $this->doorLinkSender()->onStatusChanged(30, 'no_show');

        self::assertSame(0, $this->dmsSent());
        self::assertSame([], $this->wpdb->queries);
    }

    public function testRunIfDueWaitsFiveMinutesBetweenRuns(): void
    {
        $sender = $this->doorLinkSender();

        self::assertTrue($sender->runIfDue());
        self::assertFalse($sender->runIfDue());
    }
}
