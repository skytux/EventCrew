<?php

declare(strict_types=1);

namespace EventCrew\Tests;

use EventCrew\Support\Language;

/**
 * The test suite's stand-in for WordPress's gettext: while Language::current()
 * is Finnish, a string comes out of the real compiled languages/eventcrew-fi.mo;
 * otherwise it comes back as written. So a test that reads Finnish text proves
 * the shipped .mo has it, and every other test keeps reading English.
 */
final class FinnishCatalog
{
    /** @var array<string, string>|null msgid (plural joined with \0) => msgstr */
    private static ?array $entries = null;

    public static function translate(string $text): string
    {
        if (Language::FINNISH !== Language::current()) {
            return $text;
        }

        $found = self::entries()[$text] ?? '';

        return '' === $found ? $text : $found;
    }

    /**
     * _x(): the entry with this context ("context\x04text" in the .mo) when
     * there is one, else the entry without a context, as before contexts were
     * read here.
     */
    public static function translateInContext(string $text, string $context): string
    {
        if (Language::FINNISH !== Language::current()) {
            return $text;
        }

        $found = self::entries()[$context . "\x04" . $text] ?? '';

        return '' === $found ? self::translate($text) : $found;
    }

    public static function translatePlural(string $single, string $plural, int $number): string
    {
        if (Language::FINNISH !== Language::current()) {
            return 1 === $number ? $single : $plural;
        }

        $found = self::entries()[$single . "\0" . $plural] ?? '';

        if ('' === $found) {
            return 1 === $number ? $single : $plural;
        }

        // Finnish has the same two forms as English: one, and everything else.
        $forms = explode("\0", $found);

        return 1 === $number ? $forms[0] : ($forms[1] ?? $forms[0]);
    }

    /**
     * Reads the .mo once per run: the GNU format's two tables of
     * (length, offset) pairs, in the byte order the magic number gives.
     *
     * @return array<string, string>
     */
    private static function entries(): array
    {
        if (null !== self::$entries) {
            return self::$entries;
        }

        $data = (string) file_get_contents(dirname(__DIR__) . '/languages/eventcrew-fi.mo');
        $magic = unpack('V', substr($data, 0, 4));
        $format = (false !== $magic && 0x950412de === $magic[1]) ? 'V' : 'N';
        $header = unpack($format . '7', substr($data, 0, 28));

        if (false === $header) {
            return self::$entries = [];
        }

        // Magic, revision, count, then the two table offsets.
        [, , $count, $originals, $translations] = array_values($header);
        $entries = [];

        for ($i = 0; $i < $count; $i++) {
            $original = unpack($format . '2', substr($data, $originals + 8 * $i, 8));
            $translation = unpack($format . '2', substr($data, $translations + 8 * $i, 8));

            if (false === $original || false === $translation) {
                continue;
            }

            $key = substr($data, $original[2], $original[1]);
            $entries[$key] = substr($data, $translation[2], $translation[1]);
        }

        return self::$entries = $entries;
    }
}
