<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\Task;
use EventCrew\Support\Roles;
use EventCrew\Support\TaskOrder;
use EventCrew\Tests\TestCase;

/**
 * The stored role order decides the order of a night's tasks everywhere.
 */
final class TaskOrderTest extends TestCase
{
    /**
     * @param list<string> $slugs
     */
    private function rolesInOrder(array $slugs): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => Roles::OPTION_NAME === $name
                ? array_map(static fn (string $slug): array => ['slug' => $slug, 'label' => ucfirst($slug)], $slugs)
                : $default
        );
    }

    private function task(int $id, string $role, string $date = '2026-11-20', ?string $startsAt = null): Task
    {
        return new Task($id, $date, $role, 2, null, '', $startsAt);
    }

    public function testRanksFollowTheStoredListWithOrganizeAfterIt(): void
    {
        $this->rolesInOrder(['clean', 'decorate', 'welcome']);

        self::assertSame(0, TaskOrder::rank('clean'));
        self::assertSame(2, TaskOrder::rank('welcome'));
        self::assertSame(3, TaskOrder::rank(Roles::ORGANIZE_SLUG));
        self::assertSame(4, TaskOrder::rank('unknown'));
    }

    /**
     * The order is read from the slugs alone, so no role name is translated:
     * the boot's backfill sorts tasks before init.
     */
    public function testTheSlugsFollowAllWithoutTranslatingAnything(): void
    {
        Functions\when('get_option')->justReturn(null);
        self::assertSame([...array_column(Roles::defaults(), 'slug'), Roles::ORGANIZE_SLUG], Roles::slugs());
        self::assertSame([...Roles::DEFAULT_SLUGS, Roles::ORGANIZE_SLUG], Roles::slugs());

        Functions\when('get_option')->justReturn([['slug' => 'Bar'], 'junk', ['label' => 'No slug'], ['slug' => 'door']]);
        self::assertSame(array_column(Roles::all(), 'slug'), Roles::slugs());
        self::assertSame(['bar', 'door', Roles::ORGANIZE_SLUG], Roles::slugs());

        // Once stored, Organize keeps its place.
        Functions\when('get_option')->justReturn([['slug' => 'organize'], ['slug' => 'door']]);
        self::assertSame(['organize', 'door'], Roles::slugs());
    }

    public function testSortsByDateThenRoleOrderThenStartTimeThenId(): void
    {
        $this->rolesInOrder(['clean', 'decorate', 'welcome']);

        $sorted = TaskOrder::sort([
            $this->task(1, 'decorate'),
            $this->task(2, 'welcome'),
            $this->task(3, 'clean', '2026-11-20', '22:00:00'),
            $this->task(4, 'clean', '2026-11-20', '20:00:00'),
            $this->task(5, 'clean'),
            $this->task(6, 'welcome', '2026-11-19'),
            $this->task(7, 'unknown'),
            $this->task(8, Roles::ORGANIZE_SLUG),
        ]);

        self::assertSame([6, 5, 4, 3, 1, 2, 8, 7], array_map(static fn (Task $t): int => $t->id, $sorted));
    }

    public function testKeySortsTheWaySortDoes(): void
    {
        $this->rolesInOrder(['welcome', 'decorate']);

        self::assertLessThan(
            0,
            strcmp(TaskOrder::key($this->task(9, 'welcome')), TaskOrder::key($this->task(1, 'decorate')))
        );
    }

    public function testSqlRankListsEverySlugInOrder(): void
    {
        $this->rolesInOrder(['clean', 'decorate']);

        self::assertSame(
            "CASE role_slug WHEN 'clean' THEN 0 WHEN 'decorate' THEN 1 WHEN '"
            . Roles::ORGANIZE_SLUG . "' THEN 2 ELSE 3 END",
            TaskOrder::sqlRank()
        );
    }

    /**
     * @return list<string>
     */
    private function moveAndCapture(string $slug, string $where, ?bool &$moved): array
    {
        $saved = [];
        $this->rolesInOrder(['decorate', 'welcome', 'clean']);
        Functions\when('update_option')->alias(
            static function (string $name, mixed $value) use (&$saved): bool {
                $saved = array_column($value, 'slug');

                return true;
            }
        );

        $moved = Roles::move($slug, $where);

        return $saved;
    }

    public function testMovingARoleSavesTheNewOrder(): void
    {
        // Organize is in the list too (at the end until it is stored), and
        // is saved with the rest.
        self::assertSame(['welcome', 'decorate', 'clean', 'organize'], $this->moveAndCapture('welcome', 'up', $moved));
        self::assertTrue($moved);
        self::assertSame(['decorate', 'clean', 'welcome', 'organize'], $this->moveAndCapture('welcome', 'down', $moved));
        self::assertSame(['clean', 'decorate', 'welcome', 'organize'], $this->moveAndCapture('clean', 'first', $moved));
        self::assertSame(['welcome', 'clean', 'organize', 'decorate'], $this->moveAndCapture('decorate', 'last', $moved));
        self::assertSame(['organize', 'decorate', 'welcome', 'clean'], $this->moveAndCapture('organize', 'first', $moved));
    }

    public function testAMoveThatChangesNothingSavesNothing(): void
    {
        self::assertSame([], $this->moveAndCapture('decorate', 'up', $moved));
        self::assertFalse($moved);
        self::assertSame([], $this->moveAndCapture('organize', 'last', $moved));
        self::assertSame([], $this->moveAndCapture('nobody', 'up', $moved));
        self::assertSame([], $this->moveAndCapture('clean', 'sideways', $moved));
        self::assertFalse($moved);
    }

    public function testOrderTextNumbersTheRoles(): void
    {
        $this->rolesInOrder(['clean', 'decorate']);

        self::assertSame("1. Clean (clean)\n2. Decorate (decorate)\n3. ⭐ Organize (organize)", Roles::orderText());
    }
}
