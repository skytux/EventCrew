<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Person;

/**
 * An organizer change asked in the Crew app's helper box (an action
 * AssistantWebActions plans as op "change"): first the question shown with
 * Confirm and Cancel, then, on Confirm, the change itself with its real
 * outcome. AssistantWebChanges is the one implementation; tests fake it.
 */
interface WebChanges
{
    /**
     * The question for $action ("/unticket 14.11. Anna"), or (ok false) the
     * answer why it cannot be done. "state" is what run() needs; nothing is
     * changed yet.
     *
     * @return array{ok: bool, text: string, state: array<string, mixed>}
     */
    public function prepare(Person $organizer, string $action): array;

    /**
     * Runs the change prepare() gave $state for. "changed" only when it went
     * through; "text" is the outcome in the bot's words.
     *
     * @param array<string, mixed> $state
     * @return array{changed: bool, text: string}
     */
    public function run(Person $organizer, string $action, array $state): array;
}
