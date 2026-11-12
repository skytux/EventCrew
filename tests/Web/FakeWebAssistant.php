<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use EventCrew\Models\Person;
use EventCrew\Web\WebAssistant;

/** The helper, answering what the test set. */
final class FakeWebAssistant implements WebAssistant
{
    /** @var array{status: string, text: string, lang: ?string, action: array<string, mixed>|null} */
    public array $next = ['status' => 'ok', 'text' => '', 'lang' => 'en', 'action' => null];

    /** The action recheckWeb() lets through, or null for none. */
    public ?string $recheck = null;

    /** @var list<array{0: int, 1: string}> */
    public array $asked = [];

    /** @var list<array{0: int, 1: string, 2: bool}> */
    public array $noted = [];

    /** @return array{status: string, text: string, lang: ?string, action: array<string, mixed>|null} */
    public static function answer(string $text, ?string $action = null, bool $confirm = false): array
    {
        return [
            'status' => 'ok',
            'text' => $text,
            'lang' => 'en',
            'action' => null === $action ? null : [
                'ok' => true,
                'error' => '',
                'action' => $action,
                'confirm' => $confirm,
                'run' => 'tap',
                'payload' => '',
            ],
        ];
    }

    public function answerWeb(Person $person, string $text): array
    {
        $this->asked[] = [$person->id, $text];

        return $this->next;
    }

    public function recheckWeb(Person $person, string $action): array
    {
        $ok = $action === $this->recheck;

        return [
            'ok' => $ok,
            'error' => $ok ? '' : 'invalid',
            'action' => $ok ? $action : '',
            'confirm' => $ok,
            'run' => $ok ? 'tap' : '',
            'payload' => '',
        ];
    }

    public function noteWeb(Person $person, string $action, bool $changed): void
    {
        $this->noted[] = [$person->id, $action, $changed];
    }
}
