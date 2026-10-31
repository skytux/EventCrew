<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Support\PersonEraser;
use EventCrew\Telegram\ManageController;
use EventCrew\Tests\TestCase;

final class ManageControllerTest extends TestCase
{
    private function controller(): ManageController
    {
        return new ManageController(
            new PersonRepository(),
            new PersonEraser(
                new PersonRepository(),
                new AssignmentRepository(),
                new RedemptionRepository(),
                new CreditGrantRepository(),
                new AuthTokenRepository(),
                new NotificationsRepository()
            )
        );
    }

    public function testDeleteRemovesThePersonTheirAssignmentsAndRedemptions(): void
    {
        $outcome = $this->controller()->apply(7, ManageController::DELETE);

        self::assertSame(ManageController::DELETE, $outcome);
        // Sign-in tokens, the send ledger, the assignments, the redemptions,
        // the credit grants and the person row are all deleted. The first two
        // were missed before PersonEraser: an "erased" person kept a live magic
        // link and a record of every notice they had been sent.
        self::assertCount(6, $this->wpdb->deletes);
    }

    public function testAnUnknownActionDoesNothing(): void
    {
        $outcome = $this->controller()->apply(7, 'whatever');

        self::assertSame('', $outcome);
        self::assertSame([], $this->wpdb->updates);
        self::assertSame([], $this->wpdb->deletes);
    }
}
