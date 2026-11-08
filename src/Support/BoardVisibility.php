<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Models\Task;

/**
 * Which tasks a board shows, in one place for the Telegram group board, the
 * private copies of it, the bot's lists and the web board.
 *
 * The guest board (the Telegram group, and the web page when signed out)
 * shows a task only when its role is not restricted and is ticked "Guest
 * display". A known person (signed in on the web, or reading their own copy
 * of the board or a list in a private chat) also sees the tasks of the roles
 * they hold, and of every role ticked "Members display"; an organizer sees
 * every task. A task of an archived role is shown to nobody (Owner
 * 2026-11-07).
 *
 * Four ticks per role decide what a guest gets (Owner 2026-11-07, Members
 * display 2026-11-08). A guest, for a task, is anyone who is not an
 * organizer and does not hold its role: the Telegram group, the web page
 * signed out, and a signed-in person or a private copy of the board for
 * someone without the role.
 * - Guest display (on_board): guests see the role's tasks.
 * - Members display (members_display): known people see the role's tasks
 *   too, also without the role; the group board and the signed-out page
 *   still follow Guest display only.
 * - Guest sign up (guest_signup): guests may sign up for them. Means
 *   something only while Guest display or Members display is on. Off, guests
 *   see the task and its places but get no sign-up button, and SignupService
 *   refuses them (CREW_ONLY). A restricted role stays holders-only whatever
 *   this says (SignupService::blocksRestrictedSlot()).
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

    /**
     * Whether every known person sees this task, whatever roles they hold:
     * the guest board's tasks, and those of a role ticked Members display.
     * What a list sent to all members may show.
     */
    public static function onMembersBoard(Task $task): bool
    {
        return self::onGuestBoard($task)
            || (Roles::membersDisplay($task->roleSlug) && ! Roles::isArchived($task->roleSlug));
    }

    /**
     * Whether a board or a list shows this task to this person: the web board
     * signed in, a private copy of the Telegram board, a list in a DM or an
     * email to them. null: the group board or the web page signed out, which
     * get only the guest board's tasks.
     */
    public static function visibleTo(Task $task, ?Person $person): bool
    {
        if (null === $person) {
            return self::onGuestBoard($task);
        }

        if (self::onMembersBoard($task)) {
            return true;
        }

        if (Roles::isArchived($task->roleSlug)) {
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
     * guest for it, its role is shown to guests (Guest display) or to members
     * (Members display), and Guest sign up is off. A restricted task is left
     * to SignupService::blocksRestrictedSlot(); a role with both displays off
     * keeps the rules it had before the ticks. null: signed out, a guest.
     */
    public static function blocksGuestSignup(?Task $task, ?Person $person): bool
    {
        if (null === $task || $task->isRestricted()) {
            return false;
        }

        return (Roles::onBoard($task->roleSlug) || Roles::membersDisplay($task->roleSlug))
            && ! Roles::guestSignup($task->roleSlug)
            && ! self::isCrewFor($task, $person);
    }

    /**
     * True for a task this person sees but may not take as a guest: Guest
     * sign up is off (blocksGuestSignup()), or it is a restricted task shown
     * to them by Members display without the role. The boards show its
     * places with "Crew only" and no sign-up button. An organizer is never a
     * guest: a restricted task of a role they do not hold is roleOnly().
     */
    public static function crewOnly(Task $task, ?Person $person): bool
    {
        if (self::blocksGuestSignup($task, $person)) {
            return true;
        }

        return $task->isRestricted() && null !== $person && ! self::isCrewFor($task, $person);
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
