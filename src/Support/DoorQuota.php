<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The most people the sales desk may let in on one night (wp-admin Settings).
 *
 * The number compared with the max is the desk's "expected" count: everyone
 * on tonight's list with the people they may bring, plus the people counted
 * in without a name. An action on the desk that would raise it past the max
 * is refused, unless "Allow going over the max" is ticked: then it goes
 * through and the desk shows a warning every time.
 */
final class DoorQuota
{
    /** The max; 0 means there is none. */
    public const MAX_OPTION = 'eventcrew_door_max_people';

    /** '1' when the desk may go over the max, with a warning. */
    public const OVER_OPTION = 'eventcrew_door_allow_over_max';

    public const OK = 'ok';
    public const WARN = 'warn';
    public const REFUSED = 'refused';

    public static function max(): int
    {
        return max(0, (int) get_option(self::MAX_OPTION, 0));
    }

    public static function allowsOver(): bool
    {
        return '1' === (string) get_option(self::OVER_OPTION, '0');
    }

    /**
     * What happens to an action that adds $adding people to $expected:
     * OK, WARN (goes through, over the max) or REFUSED.
     */
    public static function verdict(int $expected, int $adding): string
    {
        $max = self::max();

        if (0 === $max || $adding <= 0 || $expected + $adding <= $max) {
            return self::OK;
        }

        return self::allowsOver() ? self::WARN : self::REFUSED;
    }

    /**
     * How many people a desk action adds to the expected count, read from the
     * desk's state before the action. 0 for an action that adds nobody.
     *
     * @param array{sections: array<int, array{rows: array<int, array<string, mixed>>}>} $state
     * @param array{kind?: string, ref?: int, plus?: int, in?: bool, step?: int, person?: int} $args
     */
    public static function adding(array $state, string $action, array $args): int
    {
        return match ($action) {
            'ticket', 'holvi' => 1,
            'guest' => 1 + max(0, $args['plus'] ?? 0),
            'count' => ($args['step'] ?? 1) > 0 ? 1 : 0,
            'give', 'work' => self::isListed($state, $args['person'] ?? 0) ? 0 : 1,
            'plus' => self::plusAdding($state, $args),
            'mark' => ($args['in'] ?? false) ? self::plusAdding($state, $args) : 0,
            default => 0,
        };
    }

    /** The sentence shown when the desk refuses an action at the max. */
    public static function refusedText(): string
    {
        return sprintf(
            /* translators: %d: the most people allowed in on one night */
            __('The max of %d people is reached. Nobody more can be added.', 'eventcrew'),
            self::max()
        );
    }

    /** The warning shown after an action that left the desk over the max. */
    public static function warningText(int $expected): string
    {
        return sprintf(
            /* translators: 1: the number of people expected tonight, 2: the most people allowed */
            __('Over the max: %1$d people, max %2$d.', 'eventcrew'),
            $expected,
            self::max()
        );
    }

    /**
     * Whether a person is already on tonight's list as crew or with a
     * free-entry credit, so a ticket or a task for them adds nobody.
     *
     * @param array{sections: array<int, array{rows: array<int, array<string, mixed>>}>} $state
     */
    private static function isListed(array $state, int $personId): bool
    {
        foreach ($state['sections'] as $section) {
            foreach ($section['rows'] as $row) {
                if (in_array($row['kind'] ?? '', ['crew', 'credit'], true) && (int) ($row['ref'] ?? 0) === $personId) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * How many more people a row counts for once its plus-ones are set to
     * $args['plus']. A row counts 1 + the larger of its plus-ones and, for a
     * guest, the people they may bring (as DoorDesk::state() counts it).
     *
     * @param array{sections: array<int, array{rows: array<int, array<string, mixed>>}>} $state
     * @param array{kind?: string, ref?: int, plus?: int} $args
     */
    private static function plusAdding(array $state, array $args): int
    {
        foreach ($state['sections'] as $section) {
            foreach ($section['rows'] as $row) {
                $sameRow = ($row['kind'] ?? '') === ($args['kind'] ?? '')
                    && (int) ($row['ref'] ?? 0) === ($args['ref'] ?? 0);

                if (! $sameRow) {
                    continue;
                }

                $max = (int) ($row['max'] ?? 0);
                $allowed = 'guest' === $row['kind'] ? $max : 0;
                $now = max((int) ($row['plus'] ?? 0), $allowed);
                $after = max(min(max(0, $args['plus'] ?? 0), $max), $allowed);

                return max(0, $after - $now);
            }
        }

        return 0;
    }
}
