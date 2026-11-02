<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\Language;
use EventCrew\Tests\TestCase;

/**
 * Language::fromBrowser(): Finnish only when the browser ranks Finnish above
 * English; English (null) in every other case.
 */
final class BrowserLanguageTest extends TestCase
{
    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function headers(): array
    {
        return [
            'no header' => [null, null],
            'empty header' => ['', null],
            'Finnish only' => ['fi', 'fi'],
            'Finnish with a region' => ['fi-FI', 'fi'],
            'Finnish first, English after' => ['fi-FI,fi;q=0.9,en-US;q=0.8,en;q=0.7', 'fi'],
            'English first, Finnish after' => ['en-US,en;q=0.9,fi;q=0.8', null],
            'English only' => ['en-GB', null],
            'neither language' => ['sv-SE,de;q=0.8', null],
            'Swedish first, then Finnish' => ['sv-SE,fi;q=0.8', 'fi'],
            'Swedish first, then English, then Finnish' => ['sv,en;q=0.8,fi;q=0.5', null],
            'listed later but with a higher q' => ['en;q=0.5,fi;q=0.9', 'fi'],
            'equal q, Finnish listed first' => ['fi;q=0.8,en;q=0.8', 'fi'],
            'equal q, English listed first' => ['en;q=0.8,fi;q=0.8', null],
            'Finnish refused with q=0' => ['fi;q=0,de', null],
            'upper case and spaces' => [' FI-fi ; Q=1 , EN ; q=0.5', 'fi'],
        ];
    }

    /**
     * @dataProvider headers
     */
    public function testTheBrowsersPreferenceDecides(?string $header, ?string $expected): void
    {
        self::assertSame($expected, Language::fromBrowser($header));
    }
}
