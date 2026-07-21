<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\AssignmentStatus;
use EventCrew\Tests\TestCase;

final class AssignmentStatusTest extends TestCase
{
    /**
     * Capacity is counted from the occupying statuses, so anything wrongly
     * listed here permanently blocks a slot that is in fact free - a no-show
     * would keep their place forever.
     */
    public function testOnlyLiveCommitmentsOccupyASlot(): void
    {
        $occupying = AssignmentStatus::occupying();

        self::assertContains(AssignmentStatus::SIGNED_UP, $occupying);
        self::assertContains(AssignmentStatus::ARRIVED, $occupying);
        self::assertContains(AssignmentStatus::COMPLETED, $occupying);

        self::assertNotContains(AssignmentStatus::NO_SHOW, $occupying);
        self::assertNotContains(AssignmentStatus::LATE_CANCEL, $occupying);
        self::assertNotContains(AssignmentStatus::CANCELLED, $occupying);
        // A replaced person freed the slot for their replacement, so their
        // row must not keep counting against capacity.
        self::assertNotContains(AssignmentStatus::REPLACED, $occupying);
    }

    public function testEveryOccupyingStatusIsAKnownStatus(): void
    {
        foreach (AssignmentStatus::occupying() as $status) {
            self::assertTrue(
                AssignmentStatus::isValid($status),
                sprintf('Occupying status "%s" is not in the canonical list.', $status)
            );
        }
    }

    public function testRejectsUnknownStatuses(): void
    {
        self::assertFalse(AssignmentStatus::isValid('maybe'));
        self::assertFalse(AssignmentStatus::isValid(''));
    }

    public function testFallsBackToTheRawValueWhenLabellingSomethingUnknown(): void
    {
        self::assertSame('mystery', AssignmentStatus::label('mystery'));
    }
}
