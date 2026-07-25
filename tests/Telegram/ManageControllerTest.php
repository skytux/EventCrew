<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Telegram\ManageController;
use EventCrew\Tests\TestCase;

final class ManageControllerTest extends TestCase
{
    private function controller(): ManageController
    {
        return new ManageController(
            new PersonRepository(),
            new AssignmentRepository(),
            new RedemptionRepository(),
            new CreditGrantRepository()
        );
    }

    public function testDeleteRemovesThePersonTheirAssignmentsAndRedemptions(): void
    {
        $outcome = $this->controller()->apply(7, ManageController::DELETE);

        self::assertSame(ManageController::DELETE, $outcome);
        // The assignments, the redemptions, the credit grants and the person
        // row are all deleted.
        self::assertCount(4, $this->wpdb->deletes);
    }

    public function testAnUnknownActionDoesNothing(): void
    {
        $outcome = $this->controller()->apply(7, 'whatever');

        self::assertSame('', $outcome);
        self::assertSame([], $this->wpdb->updates);
        self::assertSame([], $this->wpdb->deletes);
    }
}
