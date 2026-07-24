<?php

declare(strict_types=1);

namespace EventCrew\Support;

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
        $task = $this->tasks->find($taskId);

        // The leader slot is reserved for crew granted leader permission.
        if (null !== $task && Roles::LEADER_SLUG === $task->roleSlug) {
            $person = $this->people->find($personId);

            if (null === $person || ! $person->canLead()) {
                return self::LEADER_ONLY;
            }
        }

        // A blocked at-risk member may be waved through once by a pass, which is
        // then spent - but only if the join actually succeeds.
        $usePass = false;

        if ($this->gateBlocks($personId)) {
            $person = $this->people->find($personId);

            if (null !== $person && $person->hasAtRiskPass()) {
                $usePass = true;
            } else {
                return self::GATED;
            }
        }

        // Holding a slot across events is fine; a genuine time clash is not.
        if ($this->assignments->hasOverlapping($personId, $taskId)) {
            return self::OVERLAP;
        }

        $outcome = $this->assignments->join($taskId, $personId);

        if ($usePass && in_array($outcome, [AssignmentRepository::JOIN_OK, AssignmentRepository::JOIN_REJOINED], true)) {
            $this->people->clearAtRiskPass($personId);
        }

        return $outcome;
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
