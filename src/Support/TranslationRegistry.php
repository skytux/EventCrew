<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * Every translatable text the wp-admin Translations page shows, one
 * TranslationRow each.
 *
 * A part of the plugin adds its texts with add(): a callable that returns its
 * rows (a list of TranslationRow), called each time the rows are read, so
 * every row holds the stored value. Code outside the plugin can add rows
 * with the FILTER filter. A row whose id is already taken is left out.
 *
 * The registry also does the two things the page asks for: saving one row
 * (saveRow()) and filling every empty language text ("Translate missing",
 * translateMissing()). Both go through TranslatedText::update(), so a text
 * edited by hand is never overwritten.
 */
final class TranslationRegistry
{
    /** Filter: receives the list of TranslationRow and returns it, with rows added. */
    public const FILTER = 'eventcrew/translation_rows';

    /** The page's filters. */
    public const SHOW_MISSING = 'missing';
    public const SHOW_MANUAL = 'manual';
    public const SHOW_EMAILS = 'emails';

    /** @var list<callable(): iterable<TranslationRow>> */
    private array $providers = [];

    public function __construct(
        private readonly TranslatedText $translator
    ) {
    }

    /**
     * Adds a provider: a callable that returns rows.
     *
     * @param callable(): iterable<TranslationRow> $provider
     */
    public function add(callable $provider): void
    {
        $this->providers[] = $provider;
    }

    /**
     * Every row, in the order the providers were added, then the rows from
     * FILTER.
     *
     * @return list<TranslationRow>
     */
    public function rows(): array
    {
        $rows = [];

        foreach ($this->providers as $provider) {
            foreach ($provider() as $row) {
                $rows[] = $row;
            }
        }

        if (function_exists('apply_filters')) {
            $filtered = apply_filters(self::FILTER, $rows);
            $rows = is_array($filtered) ? $filtered : $rows;
        }

        $out = [];
        $seen = [];

        foreach ($rows as $row) {
            if (! $row instanceof TranslationRow || isset($seen[$row->id])) {
                continue;
            }

            $seen[$row->id] = true;
            $out[] = $row;
        }

        return $out;
    }

    /** The row with $id, or null. */
    public function find(string $id): ?TranslationRow
    {
        foreach ($this->rows() as $row) {
            if ($row->id === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The rows that match $search (in the label, the source or any language
     * text, ignoring case) and $show (SHOW_MISSING: a language has no text
     * yet; SHOW_MANUAL: a language was edited by hand; SHOW_EMAILS: the
     * Emails group; '' shows every row).
     *
     * @param list<TranslationRow> $rows
     * @return list<TranslationRow>
     */
    public static function filter(array $rows, string $search, string $show): array
    {
        $search = self::lower(trim($search));

        return array_values(array_filter(
            $rows,
            static function (TranslationRow $row) use ($search, $show): bool {
                $shown = match ($show) {
                    self::SHOW_MISSING => $row->isMissing(),
                    self::SHOW_MANUAL => $row->hasManual(),
                    self::SHOW_EMAILS => TranslationRow::GROUP_EMAILS === $row->group,
                    default => true,
                };

                if (! $shown || '' === $search) {
                    return $shown;
                }

                $haystack = $row->label . "\n" . $row->value['source'] . "\n" . implode("\n", $row->value['texts']);

                return str_contains(self::lower($haystack), $search);
            }
        ));
    }

    /**
     * Saves one row as edited on the page: $source and the posted language
     * $texts. A language text that changed becomes "edited by hand"; an
     * emptied one stops being so and is filled again. A row with a fixed
     * source keeps its source whatever is posted. When the source changed,
     * every language text not edited by hand is translated again.
     *
     * A value that lacks one of the row's required placeholders, as posted
     * or as translated, is not stored; 'missing' lists those placeholders.
     *
     * @param array<string, string> $texts
     * @return array{found: bool, error: string, missing: list<string>}
     *     error is a TranslatedText error kind, '' when none.
     */
    public function saveRow(string $id, string $source, array $texts): array
    {
        $row = $this->find($id);

        if (null === $row) {
            return ['found' => false, 'error' => '', 'missing' => []];
        }

        if ($row->fixedSource) {
            $source = $row->value['source'];
        }

        // Checked before translating, so a refused text costs no translation.
        $missing = $row->missingRequired(['source' => $source, 'texts' => $texts]);

        if ([] !== $missing) {
            return ['found' => true, 'error' => '', 'missing' => $missing];
        }

        $result = $this->translator->update($row->value, $source, $texts, false, $row->maxLength, $row->multiline);

        // A translation can drop a placeholder too.
        $missing = $row->missingRequired($result['value']);

        if ([] !== $missing) {
            return ['found' => true, 'error' => '', 'missing' => $missing];
        }

        if ($result['value'] !== $row->value) {
            $row->store($result['value']);
        }

        return ['found' => true, 'error' => $result['error'], 'missing' => []];
    }

    /**
     * "Translate missing": every row with a source and an empty language
     * text gets that text from the language model. Texts edited by hand are
     * left as they are. Stops asking the model after the first error, so a
     * model that is down is not asked once per row.
     *
     * @return array{filled: int, failed: int, error: string}
     *     filled: rows that now have every language; failed: rows still
     *     missing a language; error: the first error kind, '' when none.
     */
    public function translateMissing(): array
    {
        $filled = 0;
        $failed = 0;
        $error = '';

        foreach ($this->rows() as $row) {
            if (! $row->isMissing()) {
                continue;
            }

            if ('' !== $error) {
                $failed++;

                continue;
            }

            // The same path as a save with nothing changed: update() fills
            // each empty language text that was not edited by hand.
            $result = $this->translator->update(
                $row->value,
                $row->value['source'],
                [],
                false,
                $row->maxLength,
                $row->multiline
            );

            if ('' !== $result['error']) {
                // update() put the source into the empty texts; keep them
                // empty instead, so the row stays "not translated yet".
                $error = $result['error'];
                $failed++;

                continue;
            }

            // A translation that dropped a required placeholder is not
            // stored; the row stays missing that language.
            if ([] !== $row->missingRequired($result['value'])) {
                $failed++;

                continue;
            }

            $row->store($result['value']);
            $filled++;
        }

        return ['filled' => $filled, 'failed' => $failed, 'error' => $error];
    }

    private static function lower(string $text): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
    }
}
