<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Telegram\ManageController;
use EventCrew\Tests\TestCase;

final class ManageControllerTest extends TestCase
{
    private function controller(): ManageController
    {
        return new ManageController(new PersonRepository(), new AssignmentRepository());
    }

    public function testDisableSwitchesTheAccountOff(): void
    {
        $outcome = $this->controller()->apply(7, ManageController::DISABLE);

        self::assertSame(ManageController::DISABLE, $outcome);
        self::assertNotEmpty($this->wpdb->updates[0]['data']['disabled_at']);
        self::assertSame(['id' => 7], $this->wpdb->updates[0]['where']);
    }

    public function testDeleteRemovesThePersonAndTheirAssignments(): void
    {
        $outcome = $this->controller()->apply(7, ManageController::DELETE);

        self::assertSame(ManageController::DELETE, $outcome);
        // Both the assignments and the person row are deleted.
        self::assertCount(2, $this->wpdb->deletes);
    }

    public function testAnUnknownActionDoesNothing(): void
    {
        $outcome = $this->controller()->apply(7, 'whatever');

        self::assertSame('', $outcome);
        self::assertSame([], $this->wpdb->updates);
        self::assertSame([], $this->wpdb->deletes);
    }
}
