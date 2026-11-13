<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Person;

/**
 * A change asked in the Crew app's helper box (an action AssistantWebActions
 * plans as op "change": an organizer's, or a person's own ticket): first
 * the question shown with Confirm and Cancel, then, on Confirm, the change
 * itself with its real outcome. $organizer is the person asking (an
 * organizer for every change but their own ticket). AssistantWebChanges is the one implementation; tests fake it.
 */
interface WebChanges
{
    /** Choice buttons the page shows at most; more is "Several match". */
    public const MAX_CHOICES = 4;

    /**
     * The question for $action ("/unticket 14.11. Anna"), or (ok false) the
     * answer why it cannot be done. "state" is what run() needs; nothing is
     * changed yet. "choices" (ok false), when several people, tasks or marks
     * match: the page shows one button each (at most MAX_CHOICES) and passes
     * the chosen one's state to choose().
     *
     * @return array{ok: bool, text: string, state: array<string, mixed>, choices?: list<array{label: string, state: array<string, mixed>}>}
     */
    public function prepare(Person $organizer, string $action): array;

    /**
     * The step after a choice button: the next choice or the question, as
     * prepare() answers.
     *
     * @param array<string, mixed> $state
     * @return array{ok: bool, text: string, state: array<string, mixed>, choices?: list<array{label: string, state: array<string, mixed>}>}
     */
    public function choose(Person $organizer, string $action, array $state): array;

    /**
     * Runs the change prepare() gave $state for. "changed" only when it went
     * through; "text" is the outcome in the bot's words. "ticket", after a
     * person's own ticket, is its address for the page's Open my ticket
     * button: never put in the text or any log.
     *
     * @param array<string, mixed> $state
     * @return array{changed: bool, text: string, ticket?: string}
     */
    public function run(Person $organizer, string $action, array $state): array;
}
