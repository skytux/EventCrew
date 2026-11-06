<?php

declare(strict_types=1);

namespace EventCrew\Support;

use SimpleXMLElement;
use ZipArchive;

/**
 * Reads the rows of a ticket file mailed in by an organizer: an .xlsx
 * spreadsheet (as the Holvi shop exports it) or a .csv file.
 *
 * Each row comes back as a list of cell texts indexed from 0 by column, so
 * column A is 0 and column C is 2. An empty cell is '', and so is a cell
 * holding the text "None", which is how the Holvi export writes an empty
 * field. Rows with no text at all are left out.
 */
final class TicketFile
{
    /** The largest worksheet or shared-strings part read, unpacked. */
    private const MAX_PART_BYTES = 20 * 1024 * 1024;

    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** Whether the file name is one this class reads. */
    public static function isTicketFile(string $filename): bool
    {
        $filename = strtolower(trim($filename));

        return str_ends_with($filename, '.xlsx') || str_ends_with($filename, '.csv');
    }

    /**
     * @return array<int, array<int, string>>
     * @throws TicketFileException When the file cannot be read.
     */
    public static function rows(string $filename, string $bytes): array
    {
        $filename = strtolower(trim($filename));

        if (str_ends_with($filename, '.xlsx')) {
            return self::xlsxRows($bytes);
        }

        if (str_ends_with($filename, '.csv')) {
            return self::csvRows($bytes);
        }

        throw new TicketFileException(
            __('The attached file is not an .xlsx or .csv file. Save the list as a .csv file and send it again.', 'eventcrew')
        );
    }

    /**
     * The rows of a CSV file in UTF-8, with or without a byte order mark. The
     * delimiter is a comma or a semicolon (a spreadsheet saved as CSV with a
     * Finnish locale uses semicolons), whichever the first line has more of.
     *
     * @return array<int, array<int, string>>
     */
    public static function csvRows(string $bytes): array
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }

        $firstLine = (string) strtok($bytes, "\r\n");
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $stream = fopen('php://temp', 'r+');

        if (false === $stream) {
            return [];
        }

        fwrite($stream, $bytes);
        rewind($stream);
        $rows = [];

        while (false !== ($row = fgetcsv($stream, null, $delimiter, '"', ''))) {
            $rows[] = array_map(static fn (mixed $cell): string => self::cell((string) $cell), $row);
        }

        fclose($stream);

        return self::withoutEmptyRows($rows);
    }

    /**
     * The rows of the first worksheet of an .xlsx file. Text cells are read
     * from the shared strings table and from inline strings. Each cell is
     * placed by the column letters of its reference, so a missing (empty)
     * cell does not move the cells after it to the left.
     *
     * @return array<int, array<int, string>>
     * @throws TicketFileException When the file cannot be read.
     */
    public static function xlsxRows(string $bytes): array
    {
        if (! class_exists(ZipArchive::class) || ! function_exists('simplexml_load_string')) {
            throw new TicketFileException(
                __(
                    'This website cannot read .xlsx files, because its PHP has no zip or XML support. Save the list as a .csv file and send it again, or ask the site’s administrator to turn on the PHP zip extension.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                    'eventcrew'
                )
            );
        }

        $unreadable = new TicketFileException(
            __(
                'The attached .xlsx file could not be read. Export it again from the shop and send it again.',
                'eventcrew'
            )
        );

        $path = tempnam(sys_get_temp_dir(), 'ecx');

        if (false === $path || false === file_put_contents($path, $bytes)) {
            throw $unreadable;
        }

        $zip = new ZipArchive();

        if (true !== $zip->open($path)) {
            self::removeTemp($path);

            throw $unreadable;
        }

        try {
            $sheet = self::firstSheetPath($zip);
            $sheetXml = null === $sheet ? null : self::xml($zip, $sheet);

            if (null === $sheetXml) {
                throw $unreadable;
            }

            $shared = self::sharedStrings($zip);

            return self::withoutEmptyRows(self::sheetRows($sheetXml, $shared));
        } finally {
            $zip->close();
            self::removeTemp($path);
        }
    }

    /** Deletes a temporary file of our own; nothing to report if it is gone. */
    private static function removeTemp(string $path): void
    {
        // phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
        // phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
        @unlink($path);
        // phpcs:enable
    }

    /**
     * The worksheet the workbook lists first, found through the workbook's
     * relationships. Falls back to the lowest-numbered sheet file.
     */
    private static function firstSheetPath(ZipArchive $zip): ?string
    {
        $workbook = self::xml($zip, 'xl/workbook.xml');
        $rels = self::xml($zip, 'xl/_rels/workbook.xml.rels');

        if (null !== $workbook && null !== $rels) {
            $sheets = $workbook->children(self::MAIN_NS)->sheets;
            $first = null === $sheets ? null : $sheets->children(self::MAIN_NS)->sheet[0];
            $relId = null === $first ? '' : (string) $first->attributes(self::REL_NS)['id'];

            foreach ($rels->children() as $rel) {
                if ('' !== $relId && (string) $rel->attributes()['Id'] === $relId) {
                    $target = ltrim((string) $rel->attributes()['Target'], '/');
                    $target = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;

                    if (false !== $zip->locateName($target)) {
                        return $target;
                    }
                }
            }
        }

        $found = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (1 === preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $name, $match)) {
                $found[(int) $match[1]] = $name;
            }
        }

        ksort($found);

        return [] === $found ? null : reset($found);
    }

    /**
     * @return array<int, string>
     */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = self::xml($zip, 'xl/sharedStrings.xml');

        if (null === $xml) {
            return [];
        }

        $strings = [];

        foreach ($xml->children(self::MAIN_NS)->si as $item) {
            $strings[] = self::text($item);
        }

        return $strings;
    }

    /**
     * @param array<int, string> $shared
     * @return array<int, array<int, string>>
     */
    private static function sheetRows(SimpleXMLElement $sheet, array $shared): array
    {
        $data = $sheet->children(self::MAIN_NS)->sheetData;
        $rows = [];

        if (null === $data) {
            return [];
        }

        foreach ($data->children(self::MAIN_NS)->row as $row) {
            $cells = [];
            $next = 0;

            foreach ($row->children(self::MAIN_NS)->c as $cell) {
                // attributes() with no argument: the cell's own (un-prefixed)
                // attributes. $cell['r'] would look in the main namespace.
                $ref = (string) $cell->attributes()['r'];
                $column = '' === $ref ? $next : self::columnIndex($ref);
                $next = $column + 1;
                $cells[$column] = self::cell(self::cellValue($cell, $shared));
            }

            if ([] === $cells) {
                continue;
            }

            $filled = array_fill(0, max(array_keys($cells)) + 1, '');
            $rows[] = array_replace($filled, $cells);
        }

        return $rows;
    }

    /**
     * @param array<int, string> $shared
     */
    private static function cellValue(SimpleXMLElement $cell, array $shared): string
    {
        $type = (string) $cell->attributes()['t'];
        $children = $cell->children(self::MAIN_NS);

        if ('inlineStr' === $type) {
            return null === $children->is ? '' : self::text($children->is);
        }

        $value = null === $children->v ? '' : (string) $children->v;

        if ('s' === $type) {
            return $shared[(int) $value] ?? '';
        }

        return $value;
    }

    /**
     * The text of a string item: its own <t>, or the <t> of each rich text
     * run joined. Phonetic hints (<rPh>) are not part of the text.
     */
    private static function text(SimpleXMLElement $item): string
    {
        $children = $item->children(self::MAIN_NS);

        if (null !== $children->t) {
            return (string) $children->t;
        }

        $text = '';

        foreach ($children->r as $run) {
            $text .= (string) $run->children(self::MAIN_NS)->t;
        }

        return $text;
    }

    /** "C12" → 2, "AA3" → 26. */
    private static function columnIndex(string $ref): int
    {
        $letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $ref));
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    private static function xml(ZipArchive $zip, string $name): ?SimpleXMLElement
    {
        $stat = $zip->statName($name);

        if (false === $stat || (int) $stat['size'] > self::MAX_PART_BYTES) {
            return null;
        }

        $content = $zip->getFromName($name);

        if (false === $content) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return false === $xml ? null : $xml;
    }

    /** One cell's text, trimmed; the text "None" counts as empty. */
    private static function cell(string $value): string
    {
        $value = trim($value);

        return 'None' === $value ? '' : $value;
    }

    /**
     * @param array<int, array<int, string>> $rows
     * @return array<int, array<int, string>>
     */
    private static function withoutEmptyRows(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => '' !== implode('', $row)
        ));
    }
}
