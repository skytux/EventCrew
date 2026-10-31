<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\EventSource;
use EventCrew\Support\LeaderGate;
use EventCrew\Support\Roles;
use EventCrew\Support\TaskTemplateApplier;
use EventCrew\Tests\TestCase;

final class TaskTemplateApplierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // EventSource::describe() reaches these; the roles come from the
        // shipped defaults, so get_option returns null (unconfigured).
        Functions\when('post_type_exists')->justReturn(true);
        Functions\when('get_option')->justReturn(null);
        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('Europe/Helsinki'));
    }

    private function applier(): TaskTemplateApplier
    {
        return new TaskTemplateApplier(new TaskRepository(), new LeaderGate());
    }

    /**
     * Stubs get_post()/get_post_meta() so EventSource::describe() resolves the
     * given event. Meta is keyed by the base key ('starts_at'); the manual
     * override is left empty so the scraped value wins.
     */
    private function stubEvent(int $postId, string $startsAt, string $endsAt): void
    {
        // EventSource::describe() only reads ->post_type/->post_title/->ID off
        // the post; it never type-hints WP_Post, which EventCrew's test
        // harness does not define. A plain object with those properties is
        // exactly what it consumes.
        $post = (object) [
            'ID' => $postId,
            'post_type' => 'eventmesh_event',
            'post_title' => 'Synced Event',
        ];

        Functions\when('get_post')->justReturn($post);
        Functions\when('get_post_meta')->alias(
            static function (int $id, string $key) use ($startsAt, $endsAt): string {
                return match ($key) {
                    '_eventmesh_starts_at' => $startsAt,
                    '_eventmesh_ends_at' => $endsAt,
                    default => '',
                };
            }
        );
    }

    public function testReturnsNullWhenTheEventCannotBeFound(): void
    {
        Functions\when('get_post')->justReturn(null);

        self::assertNull($this->applier()->apply(999));
    }

    public function testReturnsNullWhenTheEventHasNoStartDate(): void
    {
        $this->stubEvent(12, '', '');

        // No tasks read for a date that never gets computed.
        self::assertNull($this->applier()->apply(12));
    }

    /**
     * The happy path: three shipped roles, no existing tasks, so three are
     * created - one per role - plus the organize task.
     */
    public function testCreatesOneTaskPerActiveRoleForANewEvent(): void
    {
        $this->stubEvent(12, '2026-08-01T21:00:00', '2026-08-02T01:00:00');

        // TaskRepository::forDate() runs one get_results() - no existing tasks.
        $this->wpdb->nextResults[] = [];

        $result = $this->applier()->apply(12);

        self::assertNotNull($result);
        self::assertSame(4, $result['created']);
        self::assertSame(0, $result['untimed']);
        self::assertCount(4, $this->wpdb->inserts);
        self::assertSame('organize', $this->wpdb->inserts[3]['data']['role_slug']);
        self::assertSame(12, $this->wpdb->inserts[3]['data']['event_post_id']);
        self::assertNull($this->wpdb->inserts[3]['data']['starts_at']);
    }

    /**
     * A new night gets one task of each restricted role set to "Add to every
     * night", and none of a restricted role with it switched off.
     */
    public function testANewNightGetsTheEveryNightRestrictedRolesOnly(): void
    {
        $this->stubEvent(12, '2026-08-01T21:00:00', '2026-08-02T01:00:00');
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = null): mixed => Roles::OPTION_NAME === $name
                ? [
                    ['slug' => 'decorate', 'label' => 'Decorate'],
                    ['slug' => 'dj', 'label' => 'DJ', 'restricted' => true, 'every_night' => true],
                    ['slug' => 'vj', 'label' => 'VJ', 'restricted' => true, 'every_night' => false],
                ]
                : null
        );
        $this->wpdb->nextResults[] = [];

        $this->applier()->apply(12);

        $slugs = array_map(static fn (array $insert): string => $insert['data']['role_slug'], $this->wpdb->inserts);
        self::assertSame(['decorate', 'dj', 'organize'], $slugs);
    }

    /**
     * Applying again to an event that already has its organize task adds no
     * second one.
     */
    public function testDoesNotAddASecondOrganizeTask(): void
    {
        $this->stubEvent(12, '2026-08-01T21:00:00', '2026-08-02T01:00:00');

        $this->wpdb->nextResults[] = [
            ['id' => 5, 'event_post_id' => 12, 'role_slug' => 'organize', 'task_date' => '2026-08-01'],
        ];

        $result = $this->applier()->apply(12);

        self::assertNotNull($result);
        self::assertSame(3, $result['created']);
        self::assertSame(
            [],
            array_values(array_filter(
                $this->wpdb->inserts,
                static fn (array $insert): bool => 'organize' === $insert['data']['role_slug']
            ))
        );
    }

    /**
     * Applying twice must not duplicate: a role that already has a task for
     * this event is skipped. Here two of the three roles already exist.
     */
    public function testSkipsRolesThatAlreadyHaveATaskForThisEvent(): void
    {
        $this->stubEvent(12, '2026-08-01T21:00:00', '2026-08-02T01:00:00');

        $existingDecorate = ['id' => 1, 'event_post_id' => 12, 'role_slug' => 'decorate', 'task_date' => '2026-08-01'];
        $existingWelcome = ['id' => 2, 'event_post_id' => 12, 'role_slug' => 'welcome', 'task_date' => '2026-08-01'];

        $this->wpdb->nextResults[] = [$existingDecorate, $existingWelcome];

        $result = $this->applier()->apply(12);

        self::assertNotNull($result);
        self::assertSame(2, $result['created']);
        self::assertCount(2, $this->wpdb->inserts);
        self::assertSame('clean', $this->wpdb->inserts[0]['data']['role_slug']);
        self::assertSame('organize', $this->wpdb->inserts[1]['data']['role_slug']);
    }

    /**
     * A task for the same role but belonging to a different event does not
     * count as already-scheduled - each event keeps its own set.
     */
    public function testATaskForAnotherEventDoesNotBlockCreation(): void
    {
        $this->stubEvent(12, '2026-08-01T21:00:00', '2026-08-02T01:00:00');

        $otherEventTask = ['id' => 9, 'event_post_id' => 77, 'role_slug' => 'decorate', 'task_date' => '2026-08-01'];

        $this->wpdb->nextResults[] = [$otherEventTask];

        $result = $this->applier()->apply(12);

        self::assertNotNull($result);
        self::assertSame(4, $result['created']);
    }

    /**
     * Creating tasks marks the Telegram board stale so the listener can refresh
     * it. Both callers - the manual button and the sync listener - go through
     * apply(), so firing it here is what covers them both.
     */
    public function testFiresBoardStaleWhenTasksAreCreated(): void
    {
        $this->stubEvent(12, '2026-08-01T21:00:00', '2026-08-02T01:00:00');
        $this->wpdb->nextResults[] = [];

        $fired = [];
        Functions\when('do_action')->alias(
            static function (string $hook) use (&$fired): void {
                $fired[] = $hook;
            }
        );

        $this->applier()->apply(12);

        self::assertContains('eventcrew/board_stale', $fired);
    }

    public function testDoesNotFireBoardStaleWhenNothingWasCreated(): void
    {
        $this->stubEvent(12, '2026-08-01T21:00:00', '2026-08-02T01:00:00');

        // All three shipped roles and the organize task already exist for this event.
        $this->wpdb->nextResults[] = [
            ['id' => 1, 'event_post_id' => 12, 'role_slug' => 'decorate', 'task_date' => '2026-08-01'],
            ['id' => 2, 'event_post_id' => 12, 'role_slug' => 'welcome', 'task_date' => '2026-08-01'],
            ['id' => 3, 'event_post_id' => 12, 'role_slug' => 'clean', 'task_date' => '2026-08-01'],
            ['id' => 4, 'event_post_id' => 12, 'role_slug' => 'organize', 'task_date' => '2026-08-01'],
        ];

        $fired = [];
        Functions\when('do_action')->alias(
            static function (string $hook) use (&$fired): void {
                $fired[] = $hook;
            }
        );

        $result = $this->applier()->apply(12);

        self::assertSame(0, $result['created']);
        self::assertNotContains('eventcrew/board_stale', $fired);
    }
}
