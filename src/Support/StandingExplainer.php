<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The plain-language "how your score works" explanation, in two shapes: aligned
 * text lines for the bot's /me reply (Telegram messages are plain text, so no
 * HTML table), and label/percent rows for the web profile to build a real table
 * from. Both draw their numbers from Reputation, so the explanation can never
 * drift from the actual scoring.
 *
 * The threshold shown is the shipped default; an install that has tuned
 * StandingCalculator::THRESHOLD_OPTION would read a little differently, which is
 * a fair trade for keeping this a pure, storage-free helper.
 */
final class StandingExplainer
{
    /**
     * Outcome label => weight percent, best to worst - the rows of the score
     * table on the web profile.
     *
     * @return array<string, int>
     */
    public static function rows(): array
    {
        return Reputation::outcomeWeights();
    }

    /**
     * The same explanation as a list of text lines for a Telegram message: a
     * heading, one aligned line per outcome, then the recency, rating and
     * credit rules.
     *
     * @return array<int, string>
     */
    public static function lines(): array
    {
        $lines = [__('How your score works:', 'eventcrew')];

        foreach (self::rows() as $label => $percent) {
            $lines[] = sprintf(
                /* translators: 1: outcome name, 2: its score percentage */
                __('• %1$s — %2$d%%', 'eventcrew'),
                $label,
                $percent
            );
        }

        $threshold = (int) round(Reputation::DEFAULT_THRESHOLD * 100);

        $lines[] = '';
        $lines[] = __('Recent tasks count for more than old ones.', 'eventcrew');
        $lines[] = sprintf(
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            /* translators: 1: number of finished tasks needed to be rated, 2: good-standing threshold percentage */
            __('You’re rated after %1$d finished tasks — completions, replacements, late cancels and no-shows all count; %2$d%% or higher is good standing.', 'eventcrew'),
            Reputation::MIN_RATED_TASKS,
            $threshold
        );
        $lines[] = sprintf(
            /* translators: %d: completed tasks needed to earn one free-entry credit */
            __('You earn 1 free-entry credit for every %d completed tasks.', 'eventcrew'),
            Credits::TASKS_PER_CREDIT
        );

        return $lines;
    }
}
