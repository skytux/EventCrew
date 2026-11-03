<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Filters;
use EventCrew\Support\EventName;

/**
 * EventMesh 1.7's two filters, answered from arrays: the clean names per
 * event and language, and the DJs' names per event.
 */
trait FakeEventNames
{
    /**
     * @param array<int, array<string, string>> $names post id => [lang => clean name]
     * @param array<int, list<string>> $djs post id => DJ names
     */
    private function fakeEventNames(array $names, array $djs = []): void
    {
        Filters\expectApplied(EventName::NAME_FILTER)->zeroOrMoreTimes()->andReturnUsing(
            static fn (mixed $value, int $postId, string $lang): string => $names[$postId][$lang] ?? ''
        );
        Filters\expectApplied(EventName::DJ_FILTER)->zeroOrMoreTimes()->andReturnUsing(
            static fn (mixed $value, int $postId): array => $djs[$postId] ?? []
        );
    }
}
