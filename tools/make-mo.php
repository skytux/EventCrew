<?php

/**
 * Compiles each languages/*.po into the .mo file WordPress reads.
 *
 * A small pure-PHP compiler rather than msgfmt or WP-CLI's `i18n make-mo`,
 * because this project's dev box has neither (see ROADMAP's dev-environment
 * note). It reads the subset of the PO format tools/make-pot.php writes and a
 * translator edits: comments, msgctxt, msgid, msgid_plural, msgstr and
 * msgstr[n], each with continuation lines. Untranslated and fuzzy entries are
 * left out, the same as msgfmt does, so WordPress falls back to the English.
 *
 *     php tools/make-mo.php
 *
 * @package EventCrew
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$files = glob($root . '/languages/*.po');

if (false === $files || [] === $files) {
    fwrite(STDERR, "No .po files in languages/.\n");
    exit(1);
}

/**
 * Undoes the C-style escapes a PO string literal may hold.
 */
$unescape = static function (string $literal): string {
    return (string) preg_replace_callback(
        '/\\\\(.)/s',
        static fn (array $m): string => match ($m[1]) {
            'n' => "\n",
            't' => "\t",
            'r' => "\r",
            default => $m[1],
        },
        $literal
    );
};

/**
 * Parses a .po file into msgid key => msgstr value, both in .mo form: a
 * context is joined to the msgid with \x04, and plural forms with \x00.
 *
 * @return array<string, string>
 */
$parse = static function (string $path) use ($unescape): array {
    $lines = file($path, FILE_IGNORE_NEW_LINES);

    if (false === $lines) {
        throw new RuntimeException('Could not read ' . $path);
    }

    $entries = [];
    $entry = [];
    $fuzzy = false;
    $field = null;

    $flush = static function () use (&$entries, &$entry, &$fuzzy, &$field): void {
        if (isset($entry['msgid'])) {
            $strings = [];

            foreach ($entry as $key => $value) {
                if (str_starts_with($key, 'msgstr')) {
                    $strings[$key] = $value;
                }
            }

            ksort($strings, SORT_NATURAL);
            $translated = '' !== implode('', $strings);
            $isHeader = '' === $entry['msgid'] && ! isset($entry['msgctxt']);

            if ($translated && (! $fuzzy || $isHeader)) {
                $key = $entry['msgid'];

                if (isset($entry['msgid_plural'])) {
                    $key .= "\0" . $entry['msgid_plural'];
                }

                if (isset($entry['msgctxt'])) {
                    $key = $entry['msgctxt'] . "\x04" . $key;
                }

                $entries[$key] = implode("\0", $strings);
            }
        }

        $entry = [];
        $fuzzy = false;
        $field = null;
    };

    foreach ($lines as $number => $line) {
        $line = trim($line);

        if ('' === $line) {
            $flush();
            continue;
        }

        if (str_starts_with($line, '#,')) {
            $fuzzy = $fuzzy || str_contains($line, 'fuzzy');
            continue;
        }

        if (str_starts_with($line, '#')) {
            continue;
        }

        if (1 === preg_match('/^(msgctxt|msgid_plural|msgid|msgstr(?:\[\d+\])?)\s+"(.*)"$/s', $line, $m)) {
            // A new msgid or msgctxt after a msgstr starts the next entry
            // even without a blank line between them.
            if (in_array($m[1], ['msgid', 'msgctxt'], true) && null !== $field && str_starts_with($field, 'msgstr')) {
                $flush();
            }

            $field = $m[1];
            $entry[$field] = $unescape($m[2]);
            continue;
        }

        if (1 === preg_match('/^"(.*)"$/s', $line, $m) && null !== $field) {
            $entry[$field] .= $unescape($m[1]);
            continue;
        }

        throw new RuntimeException(sprintf('%s:%d: cannot read "%s"', $path, $number + 1, $line));
    }

    $flush();

    return $entries;
};

/**
 * Writes the entries as a little-endian GNU .mo file, sorted by key as the
 * format requires for its binary search.
 *
 * @param array<string, string> $entries
 */
$write = static function (string $path, array $entries): void {
    ksort($entries, SORT_STRING);

    $count = count($entries);
    $originalsTable = 28;
    $translationsTable = $originalsTable + 8 * $count;
    $offset = $translationsTable + 8 * $count;

    $originals = '';
    $translations = '';
    $originalIndex = '';
    $translationIndex = '';

    foreach (array_keys($entries) as $key) {
        $originalIndex .= pack('VV', strlen($key), $offset + strlen($originals));
        $originals .= $key . "\0";
    }

    $offset += strlen($originals);

    foreach ($entries as $value) {
        $translationIndex .= pack('VV', strlen($value), $offset + strlen($translations));
        $translations .= $value . "\0";
    }

    // Magic, revision, count, the two table offsets, and an empty hash table.
    $header = pack('VVVVVVV', 0x950412de, 0, $count, $originalsTable, $translationsTable, 0, 0);
    $body = $header . $originalIndex . $translationIndex . $originals . $translations;

    if (false === file_put_contents($path, $body)) {
        throw new RuntimeException('Could not write ' . $path);
    }
};

foreach ($files as $po) {
    $entries = $parse($po);
    $mo = substr($po, 0, -3) . '.mo';
    $write($mo, $entries);
    printf("%s\n%d strings\n", $mo, count($entries) - (isset($entries['']) ? 1 : 0));
}
