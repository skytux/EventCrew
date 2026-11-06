<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The ticket file formats the import knows, set in Settings → Email.
 *
 * A format names the header texts of three columns in a ticket file: the
 * buyer's name, their email address and the ticket code. Each column is one
 * header text or a list of accepted header texts, compared without regard to
 * case. A header text may join several columns with " + ", as in
 * "Etunimi + Sukunimi": the cells are then joined with a space.
 *
 * decoder_js is a JavaScript function body the door's ticket scanner uses.
 * It is stored here as it was typed and is not used on the server.
 *
 * With nothing stored, the list is the Holvi preset alone.
 *
 * @phpstan-type Format array{
 *     id: string,
 *     name: string,
 *     columns: array<string, string|array<int, string>>,
 *     decoder_js: string
 * }
 */
final class TicketFormats
{
    public const OPTION = 'eventcrew_ticket_formats';

    /** The three columns of a format. */
    public const COLUMNS = ['name', 'email', 'code'];

    /**
     * Holvi's ticket export (.xlsx, Finnish headers). One row per ticket.
     * Despite its name, Tilauskoodi is different on every row, also for two
     * tickets of one order (those share Tilaus and Kuitin numero instead), so
     * it is the ticket's own code.
     *
     * @return Format
     */
    public static function holvi(): array
    {
        return [
            'id' => 'holvi',
            'name' => 'Holvi',
            'columns' => [
                'name' => 'Etunimi + Sukunimi',
                'email' => 'Sähköposti',
                'code' => 'Tilauskoodi',
            ],
            'decoder_js' => '',
        ];
    }

    /**
     * The stored formats, or the Holvi preset when nothing is stored.
     *
     * @return array<int, Format>
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);
        $formats = [];

        foreach (is_array($stored) ? $stored : [] as $format) {
            if (is_array($format)) {
                $format = self::normalize($format);

                if ('' !== $format['id']) {
                    $formats[] = $format;
                }
            }
        }

        return [] === $formats ? [self::holvi()] : $formats;
    }

    /**
     * @return Format|null
     */
    public static function find(string $id): ?array
    {
        foreach (self::all() as $format) {
            if ($format['id'] === $id) {
                return $format;
            }
        }

        return null;
    }

    /**
     * Stores the list. Formats with neither a name nor a name column are left
     * out; a format without an id gets one made from its name. Returns what
     * was stored.
     *
     * @param array<int, array<string, mixed>> $formats
     * @return array<int, Format>
     */
    public static function save(array $formats): array
    {
        $stored = [];
        $ids = [];

        foreach ($formats as $format) {
            $format = self::normalize($format);

            if ('' === $format['name'] && [] === self::headerTexts($format['columns']['name'])) {
                continue;
            }

            $id = '' !== $format['id'] ? $format['id'] : sanitize_key(str_replace(' ', '-', $format['name']));
            $id = '' === $id ? 'format' : $id;
            $base = $id;

            for ($n = 2; isset($ids[$id]); $n++) {
                $id = $base . '-' . $n;
            }

            $ids[$id] = true;
            $format['id'] = $id;
            $format['name'] = '' === $format['name'] ? $id : $format['name'];
            $stored[] = $format;
        }

        update_option(self::OPTION, $stored, false);

        return $stored;
    }

    /**
     * The accepted header texts of one column, empty ones left out.
     *
     * @param string|array<int, string>|mixed $column
     * @return array<int, string>
     */
    public static function headerTexts(mixed $column): array
    {
        $texts = is_array($column) ? $column : [$column];

        return array_values(array_filter(
            array_map(static fn (mixed $text): string => trim(is_scalar($text) ? (string) $text : ''), $texts),
            static fn (string $text): bool => '' !== $text
        ));
    }

    /**
     * Reads the tickets out of a ticket file's rows. The first row is the
     * header row; the first format whose columns are all found in it is used.
     * A row with neither a name nor a ticket code is left out. Every other
     * row is one ticket, even when it repeats another row.
     *
     * @param array<int, array<int, string>> $rows
     * @return array{format: string, tickets: array<int, array{name: string, email: string, code: string}>}
     * @throws TicketFileException When no format matches the header row.
     */
    public static function tickets(array $rows): array
    {
        $header = $rows[0] ?? [];

        foreach (self::all() as $format) {
            $columns = self::match($format, $header);

            if (null === $columns) {
                continue;
            }

            $tickets = [];

            foreach (array_slice($rows, 1) as $row) {
                $ticket = [
                    'name' => self::value($row, $columns['name']),
                    'email' => self::value($row, $columns['email']),
                    'code' => self::value($row, $columns['code']),
                ];

                if ('' === $ticket['name'] && '' === $ticket['code']) {
                    continue;
                }

                $tickets[] = $ticket;
            }

            return ['format' => $format['id'], 'tickets' => $tickets];
        }

        $found = array_values(array_filter(
            array_map('trim', $header),
            static fn (string $text): bool => '' !== $text
        ));

        throw new TicketFileException(
            sprintf(
                /* translators: %s: the column headers found in the file, separated by commas */
                __(
                    'The columns of the attached file do not match any ticket file format in Settings → Email. Send the shop’s export as it is, or add a format with these columns there. The columns in the file are: %s', // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                    'eventcrew'
                ),
                [] === $found ? '-' : implode(', ', $found)
            )
        );
    }

    /**
     * For each column of the format, the header row positions its value is
     * read from; null when a column the format names is not in the header
     * row. A column the format leaves empty reads nothing.
     *
     * @param array{columns: array<string, mixed>} $format
     * @param array<int, string> $header
     * @return array<string, array<int, int>>|null
     */
    public static function match(array $format, array $header): ?array
    {
        $positions = [];

        foreach ($header as $index => $text) {
            $key = self::key($text);

            if ('' !== $key && ! isset($positions[$key])) {
                $positions[$key] = (int) $index;
            }
        }

        $found = [];

        foreach (self::COLUMNS as $column) {
            $texts = self::headerTexts($format['columns'][$column] ?? '');

            if ([] === $texts) {
                if ('name' === $column) {
                    return null;
                }

                $found[$column] = [];
                continue;
            }

            $found[$column] = null;

            foreach ($texts as $text) {
                $parts = [];

                foreach (explode('+', $text) as $part) {
                    $parts[] = $positions[self::key($part)] ?? null;
                }

                if (! in_array(null, $parts, true)) {
                    $found[$column] = array_map('intval', $parts);
                    break;
                }
            }

            if (null === $found[$column]) {
                return null;
            }
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $format
     * @return Format
     */
    private static function normalize(array $format): array
    {
        $columns = is_array($format['columns'] ?? null) ? $format['columns'] : [];
        $clean = [];

        foreach (self::COLUMNS as $column) {
            $texts = self::headerTexts($columns[$column] ?? '');
            $clean[$column] = 1 === count($texts) ? $texts[0] : ([] === $texts ? '' : $texts);
        }

        return [
            'id' => sanitize_key((string) ($format['id'] ?? '')),
            'name' => trim((string) ($format['name'] ?? '')),
            'columns' => $clean,
            'decoder_js' => (string) ($format['decoder_js'] ?? ''),
        ];
    }

    /**
     * @param array<int, string> $row
     * @param array<int, int> $positions
     */
    private static function value(array $row, array $positions): string
    {
        $parts = [];

        foreach ($positions as $position) {
            $cell = trim((string) ($row[$position] ?? ''));

            if ('' !== $cell) {
                $parts[] = $cell;
            }
        }

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $parts)));
    }

    /** The form two header texts are compared in. */
    private static function key(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', str_replace("\xEF\xBB\xBF", '', $text)));

        return function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
    }
}
