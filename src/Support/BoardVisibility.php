<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Models\Task;

/**
 * Which tasks a board shows, in one place for the Telegram group board and
 * the web board.
 *
 * The guest board (the Telegram group, and the web page when signed out)
 * shows a task only when its role is not restricted and is ticked "Show on
 * the guest board". Signed in on the web, a person also sees the tasks of the
 * roles they hold; an organizer sees every task. A task of an archived role
 * is shown to nobody (Owner 2026-11-07).
 */
final class BoardVisibility
{
    /** Whether the guest board shows this task. */
    public static function onGuestBoard(Task $task): bool
    {
        return ! $task->isRestricted() && Roles::onBoard($task->roleSlug) && ! Roles::isArchived($task->roleSlug);
    }

    /** Whether the web board shows this task to this person (null: signed out). */
    public static function visibleTo(Task $task, ?Person $person): bool
    {
        if (self::onGuestBoard($task)) {
            return true;
        }

        if (null === $person || Roles::isArchived($task->roleSlug)) {
            return false;
        }

        return $person->isOrganizer || $person->hasRole($task->roleSlug);
    }

    /**
     * True for a task an organizer sees but may not take: a restricted task
     * of a role they do not hold. The board shows a disabled "DJ only" button.
     */
    public static function roleOnly(Task $task, ?Person $person): bool
    {
        return null !== $person && SignupService::blocksRestrictedSlot($task, $person);
    }
}
