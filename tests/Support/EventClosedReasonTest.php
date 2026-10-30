<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\EventSource;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Tests\TestCase;

/**
 * A linked EventMesh event that stopped selling closes its night to credits.
 */
final class EventClosedReasonTest extends TestCase
{
    /** @var array<string, string> */
    private array $meta = [];

    private string $status = 'publish';

    protected function setUp(): void
    {
        parent::setUp();

        $this->meta = [];
        $this->status = 'publish';

        Functions\when('post_type_exists')->justReturn(true);
        Functions\when('get_option')->justReturn([]);
        Functions\when('get_post')->alias(
            fn (int $id): ?object => 12 === $id
                ? (object) ['ID' => 12, 'post_type' => 'eventmesh_event', 'post_status' => $this->status]
                : null
        );
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key): string => $this->meta[$key] ?? ''
        );
    }

    public function testAPublishedEventOnSaleIsOpen(): void
    {
        self::assertSame('', EventSource::closedReason(12));
    }

    public function testAnEventRemovedFromTheShopIsClosed(): void
    {
        $this->status = 'draft';

        self::assertSame('unpublished', EventSource::closedReason(12));
    }

    public function testDisabledAndHiddenClose(): void
    {
        $this->meta['_eventmesh_manual_hidden'] = '1';
        self::assertSame('hidden', EventSource::closedReason(12));

        $this->meta['_eventmesh_manual_disabled'] = '1';
        self::assertSame('disabled', EventSource::closedReason(12));
    }

    public function testAManualSoldOutBeatsTheSyncedValue(): void
    {
        $this->meta['_eventmesh_sold_out'] = '1';
        self::assertSame('sold_out', EventSource::closedReason(12));

        $this->meta['_eventmesh_manual_sold_out'] = '0';
        self::assertSame('', EventSource::closedReason(12));

        $this->meta['_eventmesh_sold_out'] = '';
        $this->meta['_eventmesh_manual_sold_out'] = '1';
        self::assertSame('sold_out', EventSource::closedReason(12));
    }

    public function testNothingLinkedOrNoEventMeshIsOpen(): void
    {
        self::assertSame('', EventSource::closedReason(0));
        self::assertSame('', EventSource::closedReason(99));

        Functions\when('post_type_exists')->justReturn(false);
        $this->status = 'draft';
        self::assertSame('', EventSource::closedReason(12));
    }

    public function testTheGateClosesANightWhoseLinkedEventSoldOut(): void
    {
        $this->meta['_eventmesh_sold_out'] = '1';
        $this->wpdb->nextResults[] = [
            ['id' => 1, 'task_date' => '2026-08-01', 'role_slug' => 'clean', 'capacity' => 3, 'event_post_id' => null],
            ['id' => 2, 'task_date' => '2026-08-01', 'role_slug' => 'welcome', 'capacity' => 3, 'event_post_id' => 12],
        ];

        $gate = new FreeEntryGate(new TaskRepository());

        self::assertTrue($gate->isClosed('2026-08-01'));
        self::assertSame('sold_out', $gate->eventReason('2026-08-01'));
        // Closed by the event, not by hand: the organizer toggle stays "open".
        self::assertFalse($gate->isClosedByHand('2026-08-01'));
    }

    public function testTheGateLeavesANightOnSaleOpen(): void
    {
        $this->wpdb->nextResults[] = [
            ['id' => 2, 'task_date' => '2026-08-01', 'role_slug' => 'welcome', 'capacity' => 3, 'event_post_id' => 12],
        ];

        self::assertFalse((new FreeEntryGate(new TaskRepository()))->isClosed('2026-08-01'));
    }
}
