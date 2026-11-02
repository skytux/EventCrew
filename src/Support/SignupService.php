<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;

/**
 * The one rulebook for claiming and dropping a task slot, whatever the channel.
 *
 * The Telegram board and the public web page both go through here, so the
 * question "may this person take this slot?" has a single answer - the
 * reputation gate, the one-time at-risk pass, the restricted roles and the
 * atomic capacity race can never say one thing to the bot and another to the
 * web. Each surface keeps only its own wording; the decision lives here.
 */
final class SignupService
{
    /** Whether at-risk members are stopped from signing up. Default on. */
    public const GATE_OPTION = 'eventcrew_reputation_gate';

    /** Hours before a task's start inside which a cancel counts as late. */
    public const NOTICE_HOURS_OPTION = 'eventcrew_notice_hours';

    /**
     * Minutes before a task's start from which a person can no longer sign
     * themselves out (0 = never locked). Organizers can still take them off.
     */
    public const SIGNOUT_LOCK_OPTION = 'eventcrew_signout_lock_minutes';

    public const SIGNOUT_LOCK_DEFAULT = 5;

    /** Refused: the person is at risk and the gate is on. */
    public const GATED = 'gated';

    /**
     * Refused: a restricted task (Organize, or a role set to "Only people with
     * this role") and this person does not hold the role. The channels word it
     * as "no longer available", because such a person is never shown the task
     * in the first place. The value keeps its old name so stored notice codes
     * and callers written for Organize alone still match.
     */
    public const ORGANIZERS_ONLY = 'organizers_only';

    /**
     * Refused: a restricted task, and nobody said whether it counts toward
     * free-entry credit. Every channel asks "Count toward free-entry credit?"
     * before it claims such a task, so this only shows up from a stale or
     * hand-made request.
     */
    public const CREDIT_QUESTION = 'credit_question';

    public function __construct(
        private readonly AssignmentRepository $assignments,
        private readonly StandingCalculator $standing,
        private readonly PersonRepository $people,
        private readonly TaskRepository $tasks
    ) {
    }

    /**
     * Claims a slot, or says why not. Returns one of self::GATED,
     * self::ORGANIZERS_ONLY, self::CREDIT_QUESTION, or an
     * AssignmentRepository::JOIN_* outcome.
     *
     * $countsCredit is the answer to "Count toward free-entry credit?". It is
     * only asked for restricted tasks, which need it (null refuses with
     * CREDIT_QUESTION); every other task always counts and ignores it.
     */
    public function claim(int $personId, int $taskId, ?bool $countsCredit = null): string
    {
        ['refusal' => $refusal, 'pass' => $usePass, 'task' => $task] = $this->evaluate($personId, $taskId);

        if ('' !== $refusal) {
            return $refusal;
        }

        $restricted = null !== $task && $task->isRestricted();

        if ($restricted && null === $countsCredit) {
            return self::CREDIT_QUESTION;
        }

        $outcome = $this->assignments->join($taskId, $personId, $restricted ? (bool) $countsCredit : true);

        if ($usePass && in_array($outcome, [AssignmentRepository::JOIN_OK, AssignmentRepository::JOIN_REJOINED], true)) {
            $this->people->clearAtRiskPass($personId);
        }

        return $outcome;
    }

    /**
     * An organizer signs someone up: any task, whatever roles the person
     * holds and whatever their standing. A banned person is still refused
     * (self::GATED), the place limit still holds, and a restricted task still
     * needs the answer to "Count toward free-entry credit?" (null refuses with
     * self::CREDIT_QUESTION). An at-risk pass is not spent. Returns one of
     * those codes or an AssignmentRepository::JOIN_* outcome.
     */
    public function claimByOrganizer(int $personId, int $taskId, ?bool $countsCredit = null): string
    {
        $task = $this->tasks->find($taskId);
        $person = $this->people->find($personId);

        if (null === $task || null === $person) {
            return AssignmentRepository::JOIN_UNKNOWN_TASK;
        }

        if ($person->isBanned()) {
            return self::GATED;
        }

        if ($task->isRestricted() && null === $countsCredit) {
            return self::CREDIT_QUESTION;
        }

        return $this->assignments->join($taskId, $personId, $task->isRestricted() ? (bool) $countsCredit : true);
    }

    /**
     * Why a claim would be refused right now, or '' if it would be allowed.
     *
     * Read-only, and the same checks claim() itself runs - it calls this - so
     * the board can say "you cannot take this" before anyone presses a button
     * without a second, drifting copy of the rules. It reports no refusal for
     * someone holding an at-risk pass, because the pass would wave them
     * through; a refusal shown to them would simply be wrong.
     *
     * Returns self::GATED, self::ORGANIZERS_ONLY or ''.
     */
    public function refusalFor(int $personId, int $taskId): string
    {
        return $this->evaluate($personId, $taskId)['refusal'];
    }

    /**
     * The single pass over the rules: why this claim would be refused, and
     * whether an at-risk pass is what is carrying it.
     *
     * One method rather than two so the gate is consulted once - claim() needs
     * both answers, and asking twice would both cost a second standing
     * calculation and open a gap between the two reads.
     *
     * @return array{refusal: string, pass: bool, task: Task|null}
     */
    private function evaluate(int $personId, int $taskId): array
    {
        $task = $this->tasks->find($taskId);

        // Read once for every rule below: a blocked person is refused on any
        // slot, so the row is needed on every claim now.
        $person = $this->people->find($personId);

        if (null !== $person && $person->isBanned()) {
            return ['refusal' => self::GATED, 'pass' => false, 'task' => $task];
        }

        if (self::blocksRestrictedSlot($task, $person)) {
            return ['refusal' => self::ORGANIZERS_ONLY, 'pass' => false, 'task' => $task];
        }

        $gate = $this->gateRefusal($personId, $person);

        if ('' !== $gate['refusal']) {
            return $gate + ['task' => $task];
        }

        /*
         * Overlapping slots used to be refused here. They are not any more:
         * whether two jobs at once is possible is a question about the jobs and
         * the person, and the plugin was answering it for them from the times
         * alone. Someone welcoming at the door until 18:30 can perfectly well
         * be down for a decorating slot that nominally runs to 19:00, and the
         * refusal cost the organizer a filled slot to prevent something that
         * was not a problem.
         */
        return ['refusal' => '', 'pass' => $gate['pass'], 'task' => $task];
    }

    /**
     * Whether this task is restricted (Organize, or a role set to "Only people
     * with this role") and this person does not hold its role. Static and pure,
     * so a caller holding a task and a person can ask without a service or a
     * query: every list that shows tasks asks it to leave the task out for
     * everyone else.
     */
    public static function blocksRestrictedSlot(?Task $task, ?Person $person): bool
    {
        if (null === $task || ! $task->isRestricted()) {
            return false;
        }

        return null === $person || ! $person->hasRole($task->roleSlug);
    }

    /**
     * The refusal that does not depend on which task is being claimed: the
     * standing gate. One query's worth of answer that applies to every row on a
     * board alike, so a caller asks once rather than per task.
     */
    public function personRefusal(int $personId): string
    {
        $person = $this->people->find($personId);

        if (null !== $person && $person->isBanned()) {
            return self::GATED;
        }

        return $this->gateRefusal($personId, $person)['refusal'];
    }

    /**
     * The gate, and whether an at-risk pass is what is getting them through.
     *
     * @return array{refusal: string, pass: bool}
     */
    private function gateRefusal(int $personId, ?Person $person): array
    {
        if (! $this->gateBlocks($personId)) {
            return ['refusal' => '', 'pass' => false];
        }

        // A blocked at-risk member may be waved through once by a pass, which
        // is then spent - but only if the join actually succeeds.
        return null !== $person && $person->hasAtRiskPass()
            ? ['refusal' => '', 'pass' => true]
            : ['refusal' => self::GATED, 'pass' => false];
    }

    /**
     * Drops a slot, recording a cancellation classified by notice. Returns the
     * status recorded, or '' when there was no live slot.
     */
    public function drop(int $personId, int $taskId): string
    {
        return $this->assignments->cancel($taskId, $personId, $this->noticeHours(), self::signoutLockMinutes());
    }

    /** The sign-out lock in minutes, from the setting; 0 = off. */
    public static function signoutLockMinutes(): int
    {
        return max(0, (int) get_option(self::SIGNOUT_LOCK_OPTION, self::SIGNOUT_LOCK_DEFAULT));
    }

    /** The answer every channel gives when the sign-out lock refuses a sign-out. */
    public static function signoutLockedText(): string
    {
        return sprintf(
            /* translators: %d: number of minutes */
            __('Sign-out closes %d minutes before the task starts. Tell an organizer if you cannot come.', 'eventcrew'),
            self::signoutLockMinutes()
        );
    }

    /**
     * Whether a claim should be refused on standing. Only ever true for a
     * rated, at-risk member while the gate is on; the standing history is not
     * read at all when the gate is off.
     */
    private function gateBlocks(int $personId): bool
    {
        if (! (bool) get_option(self::GATE_OPTION, true)) {
            return false;
        }

        return $this->standing->for($personId)->isAtRisk();
    }

    private function noticeHours(): int
    {
        return max(0, (int) get_option(self::NOTICE_HOURS_OPTION, 48));
    }
}
