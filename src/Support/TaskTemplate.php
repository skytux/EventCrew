<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * Turns an event plus the active roles into the set of tasks that event
 * needs.
 *
 * Deliberately a pure function of its arguments - no database, no options, no
 * current time. Everything interesting about scheduling lives here (which day
 * a task is filed under, what happens when a role has no offsets, how a task
 * crosses midnight), and all of it is worth testing without a WordPress or a
 * MySQL in the way.
 *
 * @phpstan-import-type Role from Roles
 */
final class TaskTemplate
{
    /**
     * The tasks an event implies, ready to hand to TaskRepository::create().
     *
     * A role with no offsets yields a task with no times, which is the same
     * thing an organizer gets when adding a task by hand and leaving the time
     * fields blank - something to fill in later, not an error.
     *
     * @param array<int, Role> $roles
     * @return array<int, array<string, mixed>>
     */
    public static function build(
        string $taskDate,
        string $eventStartsAt,
        string $eventEndsAt,
        array $roles,
        ?int $eventPostId = null,
        string $eventLabel = ''
    ): array {
        $tasks = [];

        foreach ($roles as $role) {
            if (! empty($role['archived'])) {
                continue;
            }

            $anchor = Roles::ANCHOR_END === ($role['anchor'] ?? Roles::ANCHOR_START)
                ? $eventEndsAt
                : $eventStartsAt;

            $tasks[] = [
                'role_slug' => $role['slug'],
                'task_date' => $taskDate,
                'starts_at' => self::offsetFrom($anchor, $role['start_offset'] ?? null),
                'ends_at' => self::offsetFrom($anchor, $role['end_offset'] ?? null),
                'capacity' => max(1, (int) ($role['capacity'] ?? 1)),
                'event_post_id' => $eventPostId,
                'event_label' => $eventLabel,
                'notes' => '',
            ];
        }

        return $tasks;
    }

    /**
     * Which day an event's tasks are filed under.
     *
     * The start's date, not the end's. An event running 21:00 Saturday to
     * 02:00 Sunday is Saturday's event to everyone involved, and its cleaning
     * task - which happens entirely on Sunday - still belongs on Saturday's
     * board.
     */
    public static function dateFor(string $eventStartsAt): string
    {
        return '' === $eventStartsAt ? '' : substr($eventStartsAt, 0, 10);
    }

    /**
     * An anchor moved by a number of minutes, as a naive local datetime.
     *
     * Returns null when there is no offset to apply or nothing to apply it
     * to - a role anchored to the event's end is unschedulable if the event
     * has no end recorded, and guessing one would put people somewhere they
     * were never asked to be.
     */
    private static function offsetFrom(string $anchor, ?int $offsetMinutes): ?string
    {
        if ('' === $anchor || null === $offsetMinutes) {
            return null;
        }

        // strtotime/gmdate are paired on purpose. WordPress forces PHP's
        // default timezone to UTC, so strtotime reads this naive string as
        // UTC and gmdate writes it back as UTC - the two cancel, and the
        // clock arithmetic happens without the string ever being reinterpreted
        // in another zone. Using date() here instead would displace every
        // result by the server's offset.
        $timestamp = strtotime($anchor);

        if (false === $timestamp) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $timestamp + ($offsetMinutes * 60));
    }
}
