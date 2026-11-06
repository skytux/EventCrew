<?php

declare(strict_types=1);

namespace EventCrew\Support;

use Closure;

/**
 * One-time changes to the stored texts of the organizer notices, for the
 * placeholders they gained in the "less friction" batch B4. The same runner
 * as FrictionB1Migration, with its own option: each step runs once per
 * site, and only adds a placeholder a text lacks.
 */
final class FrictionB4Migration
{
    /** The finished steps: step name => 1. */
    public const OPTION = 'eventcrew_migr_fric_b4';

    /**
     * The steps: step name => kind id => what it does to one text.
     *
     * @return array<string, array<string, Closure(string): string>>
     */
    private static function steps(): array
    {
        $record = static fn (string $text): string => FrictionB3Migration::appended($text, ["\n{record}"]);

        return [
            'role_met_record' => ['role_met' => $record, 'role_met_dm' => $record],
        ];
    }

    /** Runs every step not yet done. Does nothing once all have run. */
    public static function run(): void
    {
        FrictionB1Migration::runSteps(self::OPTION, self::steps());
    }
}
