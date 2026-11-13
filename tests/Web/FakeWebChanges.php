<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use EventCrew\Models\Person;
use EventCrew\Web\WebChanges;

/** An organizer's change, answering what the test set. */
final class FakeWebChanges implements WebChanges
{
    /** @var array{ok: bool, text: string, state: array<string, mixed>} */
    public array $prepared = ['ok' => true, 'text' => 'Take the ticket away?', 'state' => ['person' => 9]];

    /** @var array{changed: bool, text: string} */
    public array $outcome = ['changed' => true, 'text' => '✅ Ticket taken away.'];

    /** @var list<array{0: int, 1: string, 2: array<string, mixed>}> */
    public array $ran = [];

    public function prepare(Person $organizer, string $action): array
    {
        return $this->prepared;
    }

    public function run(Person $organizer, string $action, array $state): array
    {
        $this->ran[] = [$organizer->id, $action, $state];

        return $this->outcome;
    }
}
