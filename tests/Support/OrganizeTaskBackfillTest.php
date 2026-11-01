<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\OrganizeTaskBackfill;
use EventCrew\Support\Roles;
use EventCrew\Tests\TestCase;

final class OrganizeTaskBackfillTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
    }

    private function backfill(): OrganizeTaskBackfill
    {
        return new OrganizeTaskBackfill(new TaskRepository());
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(int $id, ?int $eventPostId, string $role, string $date = '2026-11-07', string $label = ''): array
    {
        return [
            'id' => $id,
            'task_date' => $date,
            'role_slug' => $role,
            'capacity' => 2,
            'event_post_id' => $eventPostId,
            'event_label' => $label,
            'starts_at' => null,
            'ends_at' => null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function organizeInserts(): array
    {
        return array_values(array_filter(
            $this->wpdb->inserts,
            static fn (array $insert): bool => 'organize' === $insert['data']['role_slug']
        ));
    }

    /**
     * Two upcoming nights without an organize task, one with: each of the two
     * gets exactly one, and the third is left alone.
     */
    public function testAddsOneOrganizeTaskToEachUpcomingNightThatHasNone(): void
    {
        $this->wpdb->nextResults[] = [
            $this->taskRow(1, 100, 'decorate'),
            $this->taskRow(2, 100, 'clean'),
            $this->taskRow(3, 200, 'decorate', '2026-11-14'),
            $this->taskRow(4, 300, 'welcome', '2026-11-21'),
            $this->taskRow(5, 300, 'organize', '2026-11-21'),
        ];

        self::assertSame(2, $this->backfill()->run());

        $inserts = $this->organizeInserts();
        self::assertCount(2, $inserts);
        self::assertSame(100, $inserts[0]['data']['event_post_id']);
        self::assertSame('2026-11-07', $inserts[0]['data']['task_date']);
        self::assertSame(200, $inserts[1]['data']['event_post_id']);
        self::assertSame('2026-11-14', $inserts[1]['data']['task_date']);
        self::assertNull($inserts[0]['data']['starts_at']);
        self::assertSame(1, $inserts[0]['data']['capacity']);
    }

    /**
     * A night typed without an event is told apart by its label and date, the
     * way the boards group it, and its organize task carries the same label.
     */
    public function testANightWithoutAnEventIsGroupedByLabelAndDate(): void
    {
        $this->wpdb->nextResults[] = [
            $this->taskRow(1, null, 'decorate', '2026-11-07', 'Party'),
            $this->taskRow(2, null, 'clean', '2026-11-07', 'Party'),
            $this->taskRow(3, null, 'clean', '2026-11-07', 'Workshop'),
        ];

        self::assertSame(2, $this->backfill()->run());

        $inserts = $this->organizeInserts();
        self::assertSame(['Party', 'Workshop'], array_map(static fn (array $i): string => $i['data']['event_label'], $inserts));
        self::assertNull($inserts[0]['data']['event_post_id']);
    }

    /** Only nights from today on are read: past nights are not changed. */
    public function testReadsOnlyUpcomingNights(): void
    {
        $this->backfill()->run();

        self::assertStringContainsString('task_date >=', $this->wpdb->queries[0]);
    }

    public function testANightThatAlreadyHasOneGetsNoSecond(): void
    {
        $this->wpdb->nextResults[] = [
            $this->taskRow(1, 100, 'organize'),
            $this->taskRow(2, 100, 'decorate'),
        ];

        self::assertSame(0, $this->backfill()->run());
        self::assertSame([], $this->wpdb->inserts);
    }

    /**
     * The first run adds the tasks and records that it ran; the second run
     * reads the record and adds nothing, without even reading the tasks.
     */
    public function testMaybeRunAddsOnceAndASecondRunAddsNothing(): void
    {
        $this->wpdb->nextResults[] = [$this->taskRow(1, 100, 'decorate')];

        $this->backfill()->maybeRun();

        self::assertCount(1, $this->organizeInserts());
        self::assertSame('1', $this->options[OrganizeTaskBackfill::DONE_OPTION]);
        self::assertSame([], $this->wpdb->heldLocks);

        $queriesBefore = count($this->wpdb->queries);
        $this->wpdb->nextResults[] = [$this->taskRow(1, 100, 'decorate')];

        $this->backfill()->maybeRun();

        self::assertCount(1, $this->organizeInserts());
        self::assertCount($queriesBefore, $this->wpdb->queries);
    }

    /** While another request holds the lock, this one does nothing. */
    public function testMaybeRunDoesNothingWhileAnotherRequestHoldsTheLock(): void
    {
        $this->wpdb->heldLocks['eventcrew_organize_backfill'] = true;
        $this->wpdb->nextResults[] = [$this->taskRow(1, 100, 'decorate')];

        $this->backfill()->maybeRun();

        self::assertSame([], $this->wpdb->inserts);
        self::assertArrayNotHasKey(OrganizeTaskBackfill::DONE_OPTION, $this->options);
    }

    /**
     * Saves Settings > Roles with a DJ role set to "Only people with this
     * role", with "Add to every night" on or off.
     */
    private function saveDjRole(bool $everyNight): void
    {
        Roles::save([
            ['slug' => 'decorate', 'label' => 'Decorate'],
            ['slug' => 'dj', 'label' => 'DJ', 'restricted' => true, 'every_night' => $everyNight],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function djInserts(): array
    {
        return array_values(array_filter(
            $this->wpdb->inserts,
            static fn (array $insert): bool => 'dj' === $insert['data']['role_slug']
        ));
    }

    /**
     * Ticking "Add to every night" on a restricted role adds one task of it to
     * every upcoming night that exists already, once: a night that has one
     * gets no second, and a later request adds nothing more.
     */
    public function testANewEveryNightRoleIsAddedToTheExistingNightsOnce(): void
    {
        $this->options[OrganizeTaskBackfill::DONE_OPTION] = '1';
        $this->saveDjRole(true);

        self::assertSame(['dj'], $this->options[OrganizeTaskBackfill::PENDING_OPTION]);

        $this->wpdb->nextResults[] = [
            $this->taskRow(1, null, 'decorate', '2026-11-07', 'Party'),
            $this->taskRow(2, null, 'decorate', '2026-11-14', 'Party'),
            $this->taskRow(3, null, 'dj', '2026-11-14', 'Party'),
        ];

        $this->backfill()->maybeRun();

        $inserts = $this->djInserts();
        self::assertCount(1, $inserts);
        self::assertSame('2026-11-07', $inserts[0]['data']['task_date']);
        self::assertSame([], $this->options[OrganizeTaskBackfill::PENDING_OPTION]);

        // Saving the settings again with nothing changed queues nothing, so
        // the next request reads no tasks and adds none.
        $this->saveDjRole(true);
        $queriesBefore = count($this->wpdb->queries);
        $this->backfill()->maybeRun();

        self::assertCount(1, $this->djInserts());
        self::assertCount($queriesBefore, $this->wpdb->queries);
    }

    /**
     * An every-night role with title text is added only to the nights whose
     * event title (here the typed label) contains it.
     */
    public function testAnEveryNightRoleWithTitleTextSkipsOtherNights(): void
    {
        $this->options[OrganizeTaskBackfill::DONE_OPTION] = '1';
        Roles::save([
            ['slug' => 'decorate', 'label' => 'Decorate'],
            ['slug' => 'dj', 'label' => 'DJ', 'restricted' => true, 'every_night' => true, 'title_contains' => 'cacao'],
        ]);
        $this->wpdb->nextResults[] = [
            $this->taskRow(1, null, 'decorate', '2026-11-07', 'Party'),
            $this->taskRow(2, null, 'decorate', '2026-11-14', 'Cacao Party'),
        ];

        $this->backfill()->maybeRun();

        $inserts = $this->djInserts();
        self::assertCount(1, $inserts);
        self::assertSame('2026-11-14', $inserts[0]['data']['task_date']);
    }

    public function testARestrictedRoleWithEveryNightOffIsNotAdded(): void
    {
        $this->options[OrganizeTaskBackfill::DONE_OPTION] = '1';
        $this->saveDjRole(false);

        self::assertSame([], $this->options[OrganizeTaskBackfill::PENDING_OPTION] ?? []);

        // Switched on, then off again before the next request ran it.
        $this->saveDjRole(true);
        $this->saveDjRole(false);
        $this->wpdb->nextResults[] = [$this->taskRow(1, null, 'decorate', '2026-11-07', 'Party')];

        $this->backfill()->maybeRun();

        self::assertSame([], $this->djInserts());
    }

    /**
     * Unticking "Add to every night" deletes nothing: the tasks already made
     * stay.
     */
    public function testSwitchingEveryNightOffDeletesNothing(): void
    {
        $this->saveDjRole(true);
        $this->saveDjRole(false);

        self::assertSame([], $this->wpdb->deletes);
        self::assertStringNotContainsString('DELETE', implode("\n", $this->wpdb->queries));
    }
}
