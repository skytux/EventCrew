<?php

declare(strict_types=1);

namespace EventCrew\Tests;

use EventCrew\Support\SiteLanguages;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * The plugin's catalogs against languages/eventcrew.pot, which
 * tools/make-pot.php builds from the code:
 * - a complete catalog translates every string in the .pot, so a person who
 *   reads that language never gets a built-in string in English. Every
 *   catalog in languages/ is complete, except the old PARTIAL ones;
 * - the Finnish and Swedish catalogs hold only strings the plugin still
 *   uses. A string removed from the code must be removed from them too.
 */
final class LanguageCatalogTest extends PHPUnitTestCase
{
    /**
     * Old machine-made catalogs that stopped being kept up to date. A site
     * using one of these languages gets the strings added since in English.
     * Finnish and Swedish must never be here: they are the site's languages.
     */
    private const PARTIAL = ['de_DE', 'es_ES', 'fr_FR', 'it_IT', 'ja', 'nl_NL', 'pl_PL', 'pt_BR', 'ru_RU', 'zh_CN'];

    /** The catalogs that must be complete and current. */
    private const REQUIRED = ['fi', 'sv_SE'];

    public function testFinnishAndSwedishCatalogsExistAndAreKeptComplete(): void
    {
        $root = dirname(__DIR__);

        foreach (self::REQUIRED as $locale) {
            self::assertFileExists($root . '/languages/eventcrew-' . $locale . '.po');
            self::assertNotContains($locale, self::PARTIAL);
        }

        self::assertSame('sv_SE', SiteLanguages::locale('sv'));
        self::assertSame('fi', SiteLanguages::locale('fi'));
    }

    /**
     * Every catalog a site language can use (languages/eventcrew-{locale}.po
     * for a locale in SiteLanguages::KNOWN) translates every string in the
     * .pot, the old PARTIAL ones excepted. The failure lists the missing
     * msgids.
     */
    public function testEveryCatalogInUseTranslatesEveryString(): void
    {
        $root = dirname(__DIR__);
        $pot = self::keys((string) file_get_contents($root . '/languages/eventcrew.pot'));
        $locales = array_map(static fn (array $known): string => $known[2], SiteLanguages::KNOWN);
        $missing = [];

        self::assertNotEmpty($pot);

        foreach (glob($root . '/languages/eventcrew-*.po') ?: [] as $file) {
            $locale = substr(basename($file, '.po'), strlen('eventcrew-'));

            if (! in_array($locale, $locales, true) || in_array($locale, self::PARTIAL, true)) {
                continue;
            }

            $translated = self::keys((string) file_get_contents($file), true);

            foreach (array_diff($pot, $translated) as $key) {
                $missing[] = $locale . ': ' . str_replace("\x04", ' | ', $key);
            }
        }

        self::assertSame(
            [],
            $missing,
            "These strings have no translation. Add them to the catalog:\n" . implode("\n", $missing)
        );
    }

    public function testEveryFinnishAndSwedishEntryIsAStringTheCodeStillUses(): void
    {
        $root = dirname(__DIR__);
        $pot = self::keys((string) file_get_contents($root . '/languages/eventcrew.pot'));

        foreach (self::REQUIRED as $locale) {
            $po = self::keys((string) file_get_contents($root . '/languages/eventcrew-' . $locale . '.po'));

            self::assertSame([], array_values(array_diff($po, $pot)), $locale);
        }
    }

    /** The Crew app's button that stops the next announcement is in Finnish (Owner, Q238). */
    public function testTheStopAnnouncementButtonIsTranslated(): void
    {
        $po = (string) file_get_contents(dirname(__DIR__) . '/languages/eventcrew-fi.po');

        self::assertStringContainsString("msgid \"Stop this announcement\"\nmsgstr \"Lopeta tämä ilmoitus\"\n", $po);
    }

    /**
     * Each entry's msgctxt and msgid, joined by "\x04" as in a .mo file. The
     * header (empty msgid) is left out. With $translatedOnly, so are the
     * entries that are fuzzy or have an empty msgstr (WordPress shows those
     * in English).
     *
     * @return list<string>
     */
    private static function keys(string $catalog, bool $translatedOnly = false): array
    {
        $keys = [];

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

            if ('' === ($fields['msgid'] ?? '')) {
                continue;
            }

            if ($translatedOnly && (str_contains($block, '#, fuzzy') || ! self::isTranslated($fields))) {
                continue;
            }

            $keys[] = isset($fields['msgctxt']) ? $fields['msgctxt'] . "\x04" . $fields['msgid'] : $fields['msgid'];
        }

        return $keys;
    }

    /**
     * Whether every msgstr of an entry (each plural form) is filled in.
     *
     * @param array<string, string> $fields
     */
    private static function isTranslated(array $fields): bool
    {
        $forms = array_filter(
            $fields,
            static fn (string $field): bool => str_starts_with($field, 'msgstr'),
            ARRAY_FILTER_USE_KEY
        );

        return [] !== $forms && ! in_array('', $forms, true);
    }
}
