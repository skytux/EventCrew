<?php

declare(strict_types=1);

namespace EventCrew\Tests;

use EventCrew\Support\Language;
use EventCrew\Support\SiteLanguages;

/**
 * The test suite's stand-in for WordPress's gettext: while Language::current()
 * is Finnish or Swedish, a string comes out of the real compiled
 * languages/eventcrew-{locale}.mo; otherwise it comes back as written. So a
 * test that reads Finnish or Swedish text proves the shipped catalog has it,
 * and every other test keeps reading English.
 *
 * The .mo is built from the .po at release (tools/make-mo.php). Until a
 * catalog's .mo is there, its .po is read instead.
 */
final class FinnishCatalog
{
    /** The languages answered from their catalog; every other one reads as written. */
    private const SERVED = [Language::FINNISH, 'sv'];

    /** @var array<string, array<string, string>> per locale: msgid (plural joined with \0) => msgstr */
    private static array $entries = [];

    public static function translate(string $text): string
    {
        if (! self::served()) {
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
        if (! self::served()) {
            return $text;
        }

        $found = self::entries()[$context . "\x04" . $text] ?? '';

        return '' === $found ? self::translate($text) : $found;
    }

    public static function translatePlural(string $single, string $plural, int $number): string
    {
        if (! self::served()) {
            return 1 === $number ? $single : $plural;
        }

        $found = self::entries()[$single . "\0" . $plural] ?? '';

        if ('' === $found) {
            return 1 === $number ? $single : $plural;
        }

        // Finnish and Swedish have the same two forms as English: one, and everything else.
        $forms = explode("\0", $found);

        return 1 === $number ? $forms[0] : ($forms[1] ?? $forms[0]);
    }

    private static function served(): bool
    {
        return in_array(Language::current(), self::SERVED, true);
    }

    /**
     * The current language's catalog, read once per run: its .mo, else its
     * .po.
     *
     * @return array<string, string>
     */
    private static function entries(): array
    {
        $locale = SiteLanguages::locale((string) Language::current());

        if (isset(self::$entries[$locale])) {
            return self::$entries[$locale];
        }

        $base = dirname(__DIR__) . '/languages/eventcrew-' . $locale;

        return self::$entries[$locale] = is_readable($base . '.mo')
            ? self::readMo((string) file_get_contents($base . '.mo'))
            : self::readPo((string) file_get_contents($base . '.po'));
    }

    /**
     * A .mo file's entries: the GNU format's two tables of (length, offset)
     * pairs, in the byte order the magic number gives.
     *
     * @return array<string, string>
     */
    private static function readMo(string $data): array
    {
        $magic = unpack('V', substr($data, 0, 4));
        $format = (false !== $magic && 0x950412de === $magic[1]) ? 'V' : 'N';
        $header = unpack($format . '7', substr($data, 0, 28));

        if (false === $header) {
            return [];
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

        return $entries;
    }

    /**
     * A .po file's translated entries, keyed as in a .mo: context joined
     * with \x04, plural forms with \0. Fuzzy and empty entries are left out.
     *
     * @return array<string, string>
     */
    private static function readPo(string $catalog): array
    {
        $entries = [];
        $unescape = static fn (string $s): string => stripcslashes($s);

        foreach (preg_split('/\n\s*\n/', trim($catalog)) ?: [] as $block) {
            $fields = [];
            $current = null;

            foreach (explode("\n", $block) as $line) {
                if (1 === preg_match('/^(msgctxt|msgid|msgid_plural|msgstr(?:\[\d\])?) "(.*)"$/', $line, $match)) {
                    $current = $match[1];
                    $fields[$current] = $match[2];
                } elseif (null !== $current && 1 === preg_match('/^"(.*)"$/', $line, $match)) {
                    $fields[$current] .= $match[1];
                }
            }

            if ('' === ($fields['msgid'] ?? '') || str_contains($block, '#, fuzzy')) {
                continue;
            }

            $key = $unescape($fields['msgid']);
            $key = isset($fields['msgid_plural']) ? $key . "\0" . $unescape($fields['msgid_plural']) : $key;
            $key = isset($fields['msgctxt']) ? $unescape($fields['msgctxt']) . "\x04" . $key : $key;
            $plural = static fn (string $field): bool => str_starts_with($field, 'msgstr[');
            $forms = isset($fields['msgstr'])
                ? [$fields['msgstr']]
                : array_values(array_filter($fields, $plural, ARRAY_FILTER_USE_KEY));
            $value = implode("\0", array_map($unescape, $forms));

            if ('' !== str_replace("\0", '', $value)) {
                $entries[$key] = $value;
            }
        }

        return $entries;
    }
}
