<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use EventCrew\Models\Person;
use EventCrew\Web\WebTaskSignup;

/** Signing up and cancelling, recorded. */
final class FakeTaskSignup implements WebTaskSignup
{
    /** @var list<array{0: string, 1: int, 2: int}> */
    public array $ran = [];

    public function claimAndNotify(Person $person, int $taskId, ?bool $countsCredit = null): string
    {
        $this->ran[] = ['join', $person->id, $taskId];

        return 'claimed';
    }

    public function dropAndNotify(Person $person, int $taskId): string
    {
        $this->ran[] = ['leave', $person->id, $taskId];

        return 'dropped';
    }
}
