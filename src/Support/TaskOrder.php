<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Task;

/**
 * The one order a night's tasks are listed in: the order of the roles.
 *
 * Organizers put the roles in order once (wp-admin Roles, the Crew app's
 * Admin tab, the bot's /roleorder) and every list of a night's tasks - the
 * group board, the open tasks call, reminders, the Crew app, wp-admin -
 * follows it. The order is the stored role list itself (Roles::slugs(), the
 * option's array order), so it needs no field of its own: moving a
 * role is moving its entry in that list (Roles::move()).
 *
 * Within one role the tasks go by start time, then id. The reserved Organize
 * role is not in the list and goes after it; a task whose role is unknown
 * goes last. Across nights the date always comes first.
 */
final class TaskOrder
{
    /**
     * Every slug's place: the listed roles 0..n-1, Organize n. An unknown slug
     * has no entry and ranks n + 1.
     *
     * @return array<string, int>
     */
    public static function ranks(): array
    {
        $ranks = [];

        foreach (Roles::slugs() as $index => $slug) {
            $ranks[$slug] ??= $index;
        }

        $ranks[Roles::ORGANIZE_SLUG] ??= count($ranks);

        return $ranks;
    }

    /**
     * @param array<string, int> $ranks
     */
    private static function rankIn(array $ranks, string $slug): int
    {
        return $ranks[$slug] ?? count($ranks);
    }

    public static function rank(string $slug): int
    {
        return self::rankIn(self::ranks(), $slug);
    }

    /**
     * Sorts tasks: date, role order, start time (untimed first), id.
     *
     * @template T of Task
     * @param array<int, T> $tasks
     * @return array<int, T>
     */
    public static function sort(array $tasks): array
    {
        if (count($tasks) < 2) {
            return array_values($tasks);
        }

        $ranks = self::ranks();
        usort(
            $tasks,
            static fn (Task $a, Task $b): int => self::keyIn($ranks, $a) <=> self::keyIn($ranks, $b)
        );

        return $tasks;
    }

    /**
     * A string that sorts the way sort() does, for lists of rows built from
     * tasks.
     */
    public static function key(Task $task): string
    {
        return self::keyIn(self::ranks(), $task);
    }

    /**
     * @param array<string, int> $ranks
     */
    private static function keyIn(array $ranks, Task $task): string
    {
        return sprintf(
            '%s %04d %s %010d',
            $task->taskDate,
            self::rankIn($ranks, $task->roleSlug),
            (string) $task->startsAt,
            $task->id
        );
    }

    /**
     * The role order as an SQL expression over role_slug, for queries that
     * page (LIMIT) and so cannot sort afterwards. Slugs are sanitize_key()ed,
     * so quoting them is enough.
     */
    public static function sqlRank(string $column = 'role_slug'): string
    {
        $ranks = self::ranks();
        $cases = '';

        foreach ($ranks as $slug => $rank) {
            $cases .= " WHEN '" . esc_sql(sanitize_key((string) $slug)) . "' THEN " . (int) $rank;
        }

        return 'CASE ' . $column . $cases . ' ELSE ' . count($ranks) . ' END';
    }
}
