<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\EventMeshSyncListener;
use EventCrew\Support\TaskTemplateApplier;
use EventCrew\Tests\TestCase;

/**
 * TaskTemplateApplier is final, so rather than mock it these tests wire the
 * real one over the fake $wpdb and watch whether it reaches the database.
 * The listener's only job is deciding whether to call through, and "a task
 * got inserted" is the truest evidence of that decision either way.
 */
final class EventMeshSyncListenerTest extends TestCase
{
    private function listener(): EventMeshSyncListener
    {
        return new EventMeshSyncListener(new TaskTemplateApplier(new TaskRepository()));
    }

    /**
     * Makes EventSource::describe() resolve a real event so that, if the
     * listener does call through, the applier has something to act on and
     * the inserts become observable.
     */
    private function stubResolvableEvent(): void
    {
        Functions\when('post_type_exists')->justReturn(true);
        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('Europe/Helsinki'));

        $post = (object) [
            'ID' => 42,
            'post_type' => 'eventmesh_event',
            'post_title' => 'Synced Event',
        ];

        Functions\when('get_post')->justReturn($post);
        Functions\when('get_post_meta')->alias(
            static fn (int $id, string $key): string => match ($key) {
                '_eventmesh_starts_at' => '2026-08-01T21:00:00',
                '_eventmesh_ends_at' => '2026-08-02T01:00:00',
                default => '',
            }
        );

        // TaskRepository::forDate() - no existing tasks for this event.
        $this->wpdb->nextResults[] = [];
    }

    public function testActsOnANewEventWhenTheSettingIsOn(): void
    {
        // get_option is called for both the roles list (null -> defaults) and
        // the toggle; alias lets the toggle read true while roles read null.
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed
                => EventMeshSyncListener::OPTION_NAME === $name ? true : null
        );

        $this->stubResolvableEvent();

        $this->listener()->onEventSynced(42, true);

        // Three shipped roles, no existing tasks: three inserts.
        self::assertCount(3, $this->wpdb->inserts);
    }

    public function testIgnoresAReSyncEvenWhenTheSettingIsOn(): void
    {
        Functions\when('get_option')->justReturn(true);

        $this->listener()->onEventSynced(42, false);

        self::assertCount(0, $this->wpdb->inserts);
    }

    public function testIgnoresANewEventWhenTheSettingIsOff(): void
    {
        Functions\when('get_option')->justReturn(false);

        $this->listener()->onEventSynced(42, true);

        self::assertCount(0, $this->wpdb->inserts);
    }

    /**
     * The option defaults to false, so an install that has never visited the
     * settings page does nothing - a fresh install of both plugins together
     * must not silently start generating tasks.
     */
    public function testDoesNothingWhenTheOptionWasNeverSet(): void
    {
        Functions\when('get_option')->alias(static fn (string $name, mixed $default = false): mixed => $default);

        $this->listener()->onEventSynced(42, true);

        self::assertCount(0, $this->wpdb->inserts);
    }

    public function testBootRegistersTheActionAndTheIntegrationsFilter(): void
    {
        $listener = $this->listener();

        Functions\expect('add_action')
            ->once()
            ->with('eventmesh/event_synced', [$listener, 'onEventSynced'], 10, 2);

        Functions\expect('add_filter')
            ->once()
            ->with('eventmesh/integrations', [$listener, 'announce']);

        $listener->boot();

        // The expectations above are the real assertions; this satisfies
        // PHPUnit's own "did the test assert anything" check, matching the
        // pattern in SchemaTest::testSkipsMigrationWhenTheStoredVersionIsCurrent.
        self::assertTrue(true);
    }

    public function testAnnounceReportsAutoCreatingWhenTheToggleIsOn(): void
    {
        Functions\when('get_option')->justReturn(true);

        $entries = $this->listener()->announce([]);

        self::assertCount(1, $entries);
        self::assertSame('eventcrew', $entries[0]['id']);
        self::assertStringContainsString('auto-creating', $entries[0]['status']);
    }

    public function testAnnounceReportsManualWhenTheToggleIsOff(): void
    {
        Functions\when('get_option')->justReturn(false);

        $entries = $this->listener()->announce([]);

        self::assertStringContainsString('manually', $entries[0]['status']);
    }

    /**
     * The filter is additive: whatever other consumers already put on the
     * list is preserved, and a non-array (a misbehaving earlier filter) is
     * tolerated rather than fatal.
     */
    public function testAnnounceAppendsWithoutDiscardingExistingEntries(): void
    {
        Functions\when('get_option')->justReturn(false);

        $existing = [['id' => 'other', 'label' => 'Other', 'status' => 'here first']];

        $entries = $this->listener()->announce($existing);

        self::assertCount(2, $entries);
        self::assertSame('other', $entries[0]['id']);
        self::assertSame('eventcrew', $entries[1]['id']);

        self::assertCount(1, $this->listener()->announce('not an array'));
    }
}
