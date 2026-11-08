<?php

declare(strict_types=1);

namespace EventCrew\Tests\Repositories;

use EventCrew\Repositories\DoorNoteRepository;
use EventCrew\Tests\TestCase;

final class DoorNoteRepositoryTest extends TestCase
{
    public function testForDateGroupsTheNotesByEntry(): void
    {
        $this->wpdb->nextResults[] = [
            ['id' => 3, 'kind' => 'guest', 'ref_id' => 4, 'role' => 'plus', 'name' => 'Aino Testinen', 'email' => null, 'newsletter' => 0],
            ['id' => 5, 'kind' => 'transfer', 'ref_id' => 9, 'role' => 'receiver', 'name' => '', 'email' => 'aino@example.org', 'newsletter' => 1],
        ];

        self::assertSame(
            [
                'guest:4' => [['id' => 3, 'role' => 'plus', 'name' => 'Aino Testinen', 'email' => '', 'newsletter' => false]],
                'transfer:9' => [
                    ['id' => 5, 'role' => 'receiver', 'name' => '', 'email' => 'aino@example.org', 'newsletter' => true],
                ],
            ],
            (new DoorNoteRepository())->forDate('2026-08-01')
        );
        self::assertStringContainsString("event_date = '2026-08-01'", $this->wpdb->lastQuery());
    }

    public function testAddStoresNoEmailAsNull(): void
    {
        $id = (new DoorNoteRepository())->add('2026-08-01', 'guest', 4, 'plus', 'Aino Testinen', '', 1);

        self::assertSame(1, $id);
        self::assertSame('wp_eventcrew_door_notes', $this->wpdb->inserts[0]['table']);
        self::assertNull($this->wpdb->inserts[0]['data']['email']);
        self::assertSame(1, $this->wpdb->inserts[0]['data']['added_by']);
        self::assertSame(0, $this->wpdb->inserts[0]['data']['newsletter']);
    }

    public function testChangeReplacesNameAndEmail(): void
    {
        self::assertTrue((new DoorNoteRepository())->change(5, 'Aino Testinen', 'aino@example.org'));
        self::assertSame(
            ['name' => 'Aino Testinen', 'email' => 'aino@example.org', 'newsletter' => 0],
            $this->wpdb->updates[0]['data']
        );
        self::assertSame(['id' => 5], $this->wpdb->updates[0]['where']);
    }

    public function testAnEmptyAddressFindsAndErasesNothing(): void
    {
        $repo = new DoorNoteRepository();

        self::assertSame([], $repo->rowsForEmail(''));
        self::assertSame(0, $repo->eraseEmail(''));
        self::assertSame([], $this->wpdb->queries);
    }
}
