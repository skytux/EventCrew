<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Support\Language;
use EventCrew\Support\SiteLanguages;
use EventCrew\Support\TranslatedText;
use EventCrew\Tests\TestCase;

/**
 * The site's languages (a setting, Finnish then English by default), and
 * Language and TranslatedText reading them instead of fixed lists.
 */
final class SiteLanguagesTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
        Functions\when('delete_option')->alias(function (string $name): bool {
            unset($this->options[$name]);

            return true;
        });
    }

    public function testNothingStoredMeansFinnishThenEnglish(): void
    {
        self::assertSame(['fi', 'en'], SiteLanguages::codes());
        self::assertSame('fi', SiteLanguages::main());
        self::assertSame(['fi', 'en'], TranslatedText::languages());
        self::assertTrue(TranslatedText::autoEnabled());
        self::assertTrue(SiteLanguages::detectEnabled());
    }

    /** A stored list is read in order; unknown codes, repeats and junk are dropped. */
    public function testTheStoredListIsCleaned(): void
    {
        $this->options[SiteLanguages::OPTION] = ['SV', 'fi', 'xx', 'fi', 7, ' en '];

        self::assertSame(['sv', 'fi', 'en'], SiteLanguages::codes());
        self::assertSame('sv', SiteLanguages::main());

        $this->options[SiteLanguages::OPTION] = ['xx', 'yy'];
        self::assertSame(['fi', 'en'], SiteLanguages::codes(), 'nothing usable reads as the default');

        $this->options[SiteLanguages::OPTION] = 'fi,en';
        self::assertSame(['fi', 'en'], SiteLanguages::codes(), 'not a list reads as the default');
    }

    /** The typed field: codes in order, separated by commas or spaces; unknown ones are reported. */
    public function testTheTypedLanguagesAreParsed(): void
    {
        self::assertSame(
            ['codes' => ['sv', 'fi', 'en'], 'unknown' => ['xx']],
            SiteLanguages::parse(' SV, fi en;xx, fi ')
        );
        self::assertSame(['codes' => [], 'unknown' => []], SiteLanguages::parse(''));
    }

    public function testSaveStoresTheThreeSettings(): void
    {
        SiteLanguages::save(['en', 'fi', 'sv', 'xx'], false, false);

        self::assertSame(['en', 'fi', 'sv'], $this->options[SiteLanguages::OPTION]);
        self::assertSame('0', $this->options[TranslatedText::AUTO_OPTION]);
        self::assertSame('0', $this->options[SiteLanguages::DETECT_OPTION]);
        self::assertFalse(TranslatedText::autoEnabled());
        self::assertFalse(SiteLanguages::detectEnabled());

        SiteLanguages::save([], true, true);

        self::assertArrayNotHasKey(SiteLanguages::OPTION, $this->options, 'an empty list goes back to the default');
        self::assertSame(['fi', 'en'], SiteLanguages::codes());
        self::assertSame('1', $this->options[TranslatedText::AUTO_OPTION]);
    }

    /** The names table covers WordPress's most used locales and Swedish, Estonian and Russian. */
    public function testTheNamesTableCoversManyLanguages(): void
    {
        $expected = ['de', 'es', 'fr', 'it', 'pt', 'nl', 'ru', 'ja', 'zh', 'pl', 'sv', 'tr', 'id', 'ko', 'ar',
            'cs', 'da', 'nb', 'el', 'he', 'hu', 'ro', 'uk', 'vi', 'et', 'fi', 'en'];

        foreach ($expected as $code) {
            self::assertTrue(SiteLanguages::isKnown($code), $code);
        }

        self::assertSame('sv_SE', SiteLanguages::locale('sv'));
        self::assertSame('et', SiteLanguages::locale('et'));
        self::assertSame('ru_RU', SiteLanguages::locale('ru'));
        self::assertSame('fi', SiteLanguages::locale('fi'));
        self::assertSame('Swedish', SiteLanguages::name('sv'));
        self::assertSame('Svenska', SiteLanguages::label('sv'));
        self::assertSame('Finnish', SiteLanguages::label('fi'));
        self::assertSame('suomi', Language::run('fi', static fn (): string => SiteLanguages::label('fi')));
    }

    /** Finnish has the plugin's own .mo; Swedish has none, so its readers get English. */
    public function testOnlyLanguagesWithAFileHaveTheirOwnStrings(): void
    {
        self::assertTrue(SiteLanguages::hasCatalog('en'));
        self::assertTrue(SiteLanguages::hasCatalog('fi'));
        self::assertFalse(SiteLanguages::hasCatalog('sv'));
        self::assertFalse(SiteLanguages::hasCatalog('xx'));
    }

    /** A person may choose any site language; English is always accepted. */
    public function testLanguageAcceptsTheSiteLanguages(): void
    {
        self::assertNull(Language::normalize('sv'));
        self::assertSame('fi', Language::normalize('FI'));

        $this->options[SiteLanguages::OPTION] = ['sv', 'fi'];

        self::assertSame('sv', Language::normalize('sv'));
        self::assertSame('en', Language::normalize('en'), 'English is the plugin\'s own language');
        self::assertNull(Language::normalize('de'));
    }

    /** Switching to a site language asks WordPress for its locale; an unknown language is English. */
    public function testSwitchingUsesTheLanguagesLocale(): void
    {
        $this->options[SiteLanguages::OPTION] = ['fi', 'en', 'sv'];

        $locale = Language::run('sv', static fn (): mixed => Language::filterLocale('en_US'));
        self::assertSame('sv_SE', $locale);

        $locale = Language::run('de', static fn (): mixed => Language::filterLocale('fi'));
        self::assertSame('en_US', $locale, 'not a site language: English');
    }

    /**
     * The WordPress locale each language switches gettext to, so its
     * languages/eventcrew-<locale>.mo is the one loaded.
     */
    public function testEachLanguageHasItsWordPressLocale(): void
    {
        $expected = [
            'fi' => 'fi', 'en' => 'en_US', 'sv' => 'sv_SE', 'es' => 'es_ES', 'de' => 'de_DE', 'fr' => 'fr_FR',
            'ja' => 'ja', 'pt' => 'pt_BR', 'it' => 'it_IT', 'ru' => 'ru_RU', 'nl' => 'nl_NL', 'zh' => 'zh_CN',
            'pl' => 'pl_PL',
        ];
        $this->options[SiteLanguages::OPTION] = array_keys($expected);

        foreach ($expected as $code => $locale) {
            self::assertSame($locale, SiteLanguages::locale($code), $code);
            self::assertSame($code, Language::run($code, static fn (): ?string => Language::current()), $code);
            self::assertSame(
                $locale,
                Language::run($code, static fn (): mixed => Language::filterLocale('en_US')),
                $code
            );
        }

        self::assertNull(Language::current());
        self::assertSame('en_US', Language::filterLocale('en_US'), 'outside run(): the site locale');
    }

    /** A person whose language is not a site language reads English; fi and en are as before. */
    public function testForPersonFallsBackToEnglish(): void
    {
        $person = static fn (?string $language): Person => new Person(
            8,
            'mia@example.test',
            'Mia Crew',
            '2026-01-01 00:00:00',
            language: $language
        );
        $current = static fn (): ?string => Language::current();

        self::assertSame('fi', Language::forPerson($person('fi'), $current));
        self::assertSame('en', Language::forPerson($person('en'), $current));
        self::assertSame('en', Language::forPerson($person('es'), $current), 'es is not a site language yet');
        self::assertSame('en', Language::forPerson(null, $current));

        $this->options[SiteLanguages::OPTION] = ['fi', 'en', 'es'];
        self::assertSame('es', Language::forPerson($person('es'), $current));
    }

    /** The browser's first site language wins; English first, or none, is null (English). */
    public function testTheBrowserCanAskForAnySiteLanguage(): void
    {
        $this->options[SiteLanguages::OPTION] = ['fi', 'en', 'sv'];

        self::assertSame('sv', Language::fromBrowser('sv-SE,en;q=0.8'));
        self::assertNull(Language::fromBrowser('en,sv;q=0.9'));
        self::assertSame('fi', Language::fromBrowser('de,fi;q=0.9,sv;q=0.8'));
        self::assertNull(Language::fromBrowser('de'));
        self::assertSame('sv', Language::preferredInBrowser('sv,fi'));
    }

    /**
     * A language added to the site is empty, and not edited by hand, in
     * every stored text; a language taken off keeps its texts in the value.
     */
    public function testStoredTextsFollowTheLanguages(): void
    {
        $stored = [
            'source' => 'Hello',
            'lang' => 'en',
            'texts' => ['fi' => 'Hei', 'en' => 'Hello'],
            'manual' => ['fi' => true, 'en' => false],
        ];

        $this->options[SiteLanguages::OPTION] = ['fi', 'en', 'sv'];
        $value = TranslatedText::normalize($stored);

        self::assertSame(['fi' => 'Hei', 'en' => 'Hello', 'sv' => ''], $value['texts']);
        self::assertFalse($value['manual']['sv']);

        $this->options[SiteLanguages::OPTION] = ['en'];
        $value = TranslatedText::normalize($stored);

        self::assertSame(['en' => 'Hello', 'fi' => 'Hei'], $value['texts'], 'Finnish is kept, after the others');
        self::assertTrue($value['manual']['fi']);
        self::assertSame('Hello', TranslatedText::text($value, 'en'));
    }
}
