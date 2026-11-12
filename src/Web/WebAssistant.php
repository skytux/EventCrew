<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Person;

/**
 * What the Crew app page's helper box (AssistantWebController) needs from
 * the bot's language-model helper. AssistantService is the one in use.
 */
interface WebAssistant
{
    /**
     * The answer to $text from the signed-in $person.
     *
     * @return array{status: string, text: string, lang: ?string, action: array{ok: bool, error: string, action: string, confirm: bool, run: string, payload: string}|null}
     */
    public function answerWeb(Person $person, string $text): array;

    /**
     * A kept action line checked again for $person, now.
     *
     * @return array{ok: bool, error: string, action: string, confirm: bool, run: string, payload: string}
     */
    public function recheckWeb(Person $person, string $action): array;

    /** Notes in the person's conversation that the page ran $action. */
    public function noteWeb(Person $person, string $action, bool $changed): void;
}
