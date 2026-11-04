<?php

declare(strict_types=1);

namespace EventCrew\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * The Finnish catalog holds only strings the plugin still uses: every entry
 * in languages/eventcrew-fi.po is in languages/eventcrew.pot, which
 * tools/make-pot.php builds from the code. A string removed from the code
 * must be removed from the .po too.
 */
final class LanguageCatalogTest extends PHPUnitTestCase
{
    public function testEveryFinnishEntryIsAStringTheCodeStillUses(): void
    {
        $root = dirname(__DIR__);
        $pot = self::keys((string) file_get_contents($root . '/languages/eventcrew.pot'));
        $po = self::keys((string) file_get_contents($root . '/languages/eventcrew-fi.po'));

        self::assertNotEmpty($pot);
        self::assertSame([], array_values(array_diff($po, $pot)));
    }

    /**
     * Each entry's msgctxt and msgid, joined by "\x04" as in a .mo file. The
     * header (empty msgid) is left out.
     *
     * @return list<string>
     */
    private static function keys(string $catalog): array
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

            $keys[] = isset($fields['msgctxt']) ? $fields['msgctxt'] . "\x04" . $fields['msgid'] : $fields['msgid'];
        }

        return $keys;
    }
}
