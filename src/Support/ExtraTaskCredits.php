<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Database\Schema;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CreditGrantRepository;

/**
 * The credit for extra tasks on the same night (Settings, on by default):
 * someone who works two tasks on one night gets one free-entry credit for
 * the second, on top of the usual credit for every N completed tasks (which
 * still counts every completed task).
 *
 * While the night is ahead the credit is pending: the tasks held that night
 * minus one. It becomes a real credit only at the after-event run, and only
 * for a task that was completed (StandingNotice adds it with the thank-you);
 * a cancelled, no-show or late-cancelled task never gives it, and neither
 * does a task marked "does not count toward free-entry credit" (Lou
 * 2026-11-08). A task marked arrived late does give it.
 *
 * When a task is changed away from completed after its credit was added (to
 * no-show, say), the credit is taken back and the person told (revoke(),
 * from StandingNotice).
 *
 * The added credit is a credit_grants row with source 'extra_task' and the
 * task's assignment id, under a unique key, so it is added once however many
 * runs see it. Nights before the setting appeared (Schema 36) never get it.
 */
final class ExtraTaskCredits
{
    /** The setting: '1' or '0', on when unset. */
    public const OPTION = 'eventcrew_extra_task_credit';

    /**
     * How many days back a night still shows a pending credit and is still
     * looked at for credits to add.
     */
    public const WINDOW_DAYS = 7;

    public function __construct(
        private readonly AssignmentRepository $assignments = new AssignmentRepository(),
        private readonly CreditGrantRepository $grants = new CreditGrantRepository()
    ) {
    }

    /** Whether extra tasks on the same night earn a credit (the OPTION setting). */
    public static function isOn(): bool
    {
        return (bool) get_option(self::OPTION, true);
    }

    /** Saves the OPTION setting. */
    public static function save(bool $on): void
    {
        update_option(self::OPTION, $on ? '1' : '0');
    }

    /**
     * Adds the credits for the person's extra completed tasks on $date: one
     * for every completed task of the night but the first. Returns how many
     * were added now (0 when they were already added, or the setting is off,
     * or the night is from before the setting).
     */
    public function release(int $personId, string $date): int
    {
        if (! self::isOn() || $personId <= 0 || '' === $date || $date < self::since()) {
            return 0;
        }

        $ids = $this->assignments->creditedIdsOn($personId, $date);
        // The first completed task is the night's ordinary one.
        array_shift($ids);
        $added = 0;

        foreach ($ids as $assignmentId) {
            $note = sprintf(
                /* translators: %s: the date of the night */
                __('Extra task on %s', 'eventcrew'),
                $date
            );

            if ($this->grants->recordExtraTask($personId, $assignmentId, $note)) {
                ++$added;
            }
        }

        return $added;
    }

    /**
     * Takes back the extra-task credits on $date that no task earns any
     * more: after a status change, every credit whose task is no longer a
     * completed extra task of the night (the night's first counted task, or
     * not completed). Returns how many were taken back. Works with the
     * setting off too: a credit added under it is still taken back.
     */
    public function revoke(int $personId, string $date): int
    {
        if ($personId <= 0 || '' === $date) {
            return 0;
        }

        $granted = $this->grants->extraTaskAssignmentsOn($personId, $date);

        if ([] === $granted) {
            return 0;
        }

        $earning = $this->assignments->creditedIdsOn($personId, $date);
        // The first completed task is the night's ordinary one.
        array_shift($earning);
        $removed = 0;

        foreach (array_diff($granted, $earning) as $assignmentId) {
            if ($this->grants->deleteExtraTask($personId, $assignmentId)) {
                ++$removed;
            }
        }

        return $removed;
    }

    /**
     * The person's pending credits for extra tasks, over the nights ahead
     * and the last WINDOW_DAYS days. 0 when the setting is off.
     */
    public function pendingFor(int $personId): int
    {
        if (! self::isOn() || $personId <= 0) {
            return 0;
        }

        return $this->grants->pendingExtraTasks($personId, $this->from());
    }

    /** The person's pending credits for extra tasks on one night. */
    public function pendingOn(int $personId, string $date): int
    {
        if (! self::isOn() || $personId <= 0 || '' === $date || $date < self::since()) {
            return 0;
        }

        return $this->grants->pendingExtraTasks($personId, $date, $date);
    }

    /**
     * Nights with credits still to add after their thank-you went out: a
     * task marked completed later than the rest of the night. For each, the
     * credits are added and the count returned, so the caller can tell the
     * person.
     *
     * @return array<int, array{person_id: int, task_date: string, added: int}>
     */
    public function releaseLate(string $cutoff, string $thanksKind, int $limit): array
    {
        if (! self::isOn() || $limit <= 0) {
            return [];
        }

        $out = [];

        foreach ($this->grants->extraTasksToAdd($this->from(), $cutoff, $thanksKind, $limit) as $night) {
            $added = $this->release($night['person_id'], $night['task_date']);

            if ($added > 0) {
                $out[] = $night + ['added' => $added];
            }
        }

        return $out;
    }

    /**
     * " (+N pending)" after a credit count, or '' when nothing is pending:
     * "Credits: 2 (+1 pending)".
     */
    public static function pendingSuffix(int $pending): string
    {
        if ($pending <= 0) {
            return '';
        }

        return ' ' . sprintf(
            /* translators: %d: pending free-entry credits for extra tasks on the same night */
            __('(+%d pending)', 'eventcrew'),
            $pending
        );
    }

    /**
     * The sentence for a sign-up that made a pending credit: '' when none
     * is pending that night.
     */
    public static function pendingAddedLine(int $pending): string
    {
        if ($pending <= 0) {
            return '';
        }

        return sprintf(
            /* translators: %d: pending free-entry credits for extra tasks on that night */
            _n(
                'This is an extra task on the same night: %d free-entry credit is pending. It is added after the event if you do the task.',
                'These are extra tasks on the same night: %d free-entry credits are pending. They are added after the event for the tasks you do.',
                $pending,
                'eventcrew'
            ),
            $pending
        );
    }

    /** The sentence for a cancellation that took a pending credit away. */
    public static function pendingRemovedLine(): string
    {
        return __('The pending free-entry credit for an extra task that night is gone.', 'eventcrew');
    }

    /**
     * The sentence for credits added after the event, in the thank-you or
     * on its own.
     */
    public static function addedLine(int $added): string
    {
        return sprintf(
            /* translators: %d: free-entry credits just added for extra tasks */
            _n(
                'Your extra task that night earned %d more free-entry credit.',
                'Your extra tasks that night earned %d more free-entry credits.',
                $added,
                'eventcrew'
            ),
            $added
        );
    }

    /** The sentence for credits taken back after a task's status changed. */
    public static function removedLine(int $removed): string
    {
        return sprintf(
            /* translators: %d: free-entry credits taken back */
            _n(
                'A task of yours was marked as not done, so %d free-entry credit for an extra task that night was taken back.',
                'A task of yours was marked as not done, so %d free-entry credits for extra tasks that night were taken back.',
                $removed,
                'eventcrew'
            ),
            $removed
        );
    }

    /**
     * The first night the credit counts: the day Schema 36 ran. '' (no
     * limit) only before that migration.
     */
    public static function since(): string
    {
        return (string) get_option(Schema::EXTRA_TASK_CREDIT_SINCE_OPTION, '');
    }

    /** The first night looked at: WINDOW_DAYS ago, never before since(). */
    private function from(): string
    {
        $today = strtotime((string) current_time('Y-m-d'));
        $from = gmdate('Y-m-d', ($today === false ? time() : $today) - self::WINDOW_DAYS * DAY_IN_SECONDS);

        return max($from, self::since());
    }
}
