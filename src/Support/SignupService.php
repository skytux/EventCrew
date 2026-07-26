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
 * reputation gate, the one-time at-risk pass, the leader-only slot, the overlap
 * check and the atomic capacity race can never say one thing to the bot and
 * another to the web. Each surface keeps only its own wording; the decision
 * lives here.
 */
final class SignupService
{
    /** Whether at-risk members are stopped from signing up. Default on. */
    public const GATE_OPTION = 'eventcrew_reputation_gate';

    /** Hours before a task's start inside which a cancel counts as late. */
    public const NOTICE_HOURS_OPTION = 'eventcrew_notice_hours';

    /** Refused: the person is at risk and the gate is on. */
    public const GATED = 'gated';

    /** Refused: the person already holds a clashing slot. */
    public const OVERLAP = 'overlap';

    /** Refused: the leader slot, and this person has no leader permission. */
    public const LEADER_ONLY = 'leader_only';

    public function __construct(
        private readonly AssignmentRepository $assignments,
        private readonly StandingCalculator $standing,
        private readonly PersonRepository $people,
        private readonly TaskRepository $tasks
    ) {
    }

    /**
     * Claims a slot, or says why not. Returns one of self::GATED,
     * self::LEADER_ONLY, self::OVERLAP, or an AssignmentRepository::JOIN_*
     * outcome.
     */
    public function claim(int $personId, int $taskId): string
    {
        ['refusal' => $refusal, 'pass' => $usePass] = $this->evaluate($personId, $taskId);

        if ('' !== $refusal) {
            return $refusal;
        }

        $outcome = $this->assignments->join($taskId, $personId);

        if ($usePass && in_array($outcome, [AssignmentRepository::JOIN_OK, AssignmentRepository::JOIN_REJOINED], true)) {
            $this->people->clearAtRiskPass($personId);
        }

        return $outcome;
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
     * Returns self::GATED, self::LEADER_ONLY, self::OVERLAP or ''.
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
     * @return array{refusal: string, pass: bool}
     */
    private function evaluate(int $personId, int $taskId): array
    {
        $task = $this->tasks->find($taskId);

        // The person is only fetched when the slot is actually leader-only,
        // which most are not: asking first would put a row read on every claim
        // to answer a question that usually does not arise.
        if (null !== $task && Roles::LEADER_SLUG === $task->roleSlug) {
            if (self::blocksLeaderSlot($task, $this->people->find($personId))) {
                return ['refusal' => self::LEADER_ONLY, 'pass' => false];
            }
        }

        $gate = $this->gateRefusal($personId);

        if ('' !== $gate['refusal']) {
            return $gate;
        }

        // Holding a slot across events is fine; a genuine time clash is not.
        if ($this->assignments->hasOverlapping($personId, $taskId)) {
            return ['refusal' => self::OVERLAP, 'pass' => false];
        }

        return ['refusal' => '', 'pass' => $gate['pass']];
    }

    /**
     * Whether this task's slot is reserved for crew leaders and this person is
     * not one. Static and pure, so a caller holding a task and a person can ask
     * without a service or a query - which is what lets the board decide a whole
     * screen of rows without reimplementing the rule.
     */
    public static function blocksLeaderSlot(?Task $task, ?Person $person): bool
    {
        if (null === $task || Roles::LEADER_SLUG !== $task->roleSlug) {
            return false;
        }

        return null === $person || ! $person->canLead();
    }

    /**
     * The refusal that does not depend on which task is being claimed: the
     * standing gate. One query's worth of answer that applies to every row on a
     * board alike, so a caller asks once rather than per task.
     */
    public function personRefusal(int $personId): string
    {
        return $this->gateRefusal($personId)['refusal'];
    }

    /**
     * The gate, and whether an at-risk pass is what is getting them through.
     *
     * @return array{refusal: string, pass: bool}
     */
    private function gateRefusal(int $personId): array
    {
        if (! $this->gateBlocks($personId)) {
            return ['refusal' => '', 'pass' => false];
        }

        // A blocked at-risk member may be waved through once by a pass, which
        // is then spent - but only if the join actually succeeds.
        $person = $this->people->find($personId);

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
        return $this->assignments->cancel($taskId, $personId, $this->noticeHours());
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
