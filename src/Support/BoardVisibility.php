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
 *
 * Three ticks per role decide what a guest gets (Owner 2026-11-07). A guest,
 * for a task, is anyone who is not an organizer and does not hold its role:
 * the Telegram group, the web page signed out, and a signed-in person or a
 * private copy of the board for someone without the role.
 * - Guest display (on_board): guests see the role's tasks.
 * - Guest sign up (guest_signup): guests may sign up for them. Ignored while
 *   Guest display is off. Off, guests see the task and its places but get no
 *   sign-up button, and SignupService refuses them (CREW_ONLY).
 * - Anonymous display (anonymous): guests see how many are signed up, not
 *   who.
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
     * Whether this person counts as the task's own crew, not a guest: an
     * organizer, or someone holding its role.
     */
    public static function isCrewFor(Task $task, ?Person $person): bool
    {
        return null !== $person && ($person->isOrganizer || $person->hasRole($task->roleSlug));
    }

    /**
     * True when this person may not sign up for this task because they are a
     * guest for it and its role has Guest display on and Guest sign up off.
     * A restricted task is left to SignupService::blocksRestrictedSlot(); a
     * role with Guest display off keeps the rules it had before the tick.
     * null: signed out, a guest.
     */
    public static function blocksGuestSignup(?Task $task, ?Person $person): bool
    {
        if (null === $task || $task->isRestricted()) {
            return false;
        }

        return Roles::onBoard($task->roleSlug)
            && ! Roles::guestSignup($task->roleSlug)
            && ! self::isCrewFor($task, $person);
    }

    /**
     * Whether a board may show this person who is signed up for this task:
     * yes unless the role has Anonymous display on and they are a guest.
     * null: the group's board or the web page signed out.
     */
    public static function showsNames(Task $task, ?Person $person): bool
    {
        return ! Roles::anonymous($task->roleSlug) || self::isCrewFor($task, $person);
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
