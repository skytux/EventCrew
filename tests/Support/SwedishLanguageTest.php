<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Support\HybridPolicy;
use EventCrew\Support\Language;
use EventCrew\Support\SiteLanguages;
use EventCrew\Tests\TestCase;

/**
 * A Swedish reader gets the plugin's own strings in Swedish: Language::run()
 * puts WordPress in the sv_SE locale, whose catalog is
 * languages/eventcrew-sv_SE.mo (Owner saw the Swedish row of the post-event
 * email in English while that catalog was missing).
 */
final class SwedishLanguageTest extends TestCase
{
    private const INVITE = 'Would you like to hear about new events first? Join the next events announcement: we email you when new events come out. One press of the button is enough, and you can leave at any time.'; // phpcs:ignore Generic.Files.LineLength.TooLong -- the msgid as written.

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => SiteLanguages::OPTION === $name
                ? ['fi', 'en', 'sv']
                : $default
        );
    }

    public function testASwedishPersonsTextIsInTheSwedishLocaleAndInSwedish(): void
    {
        $person = new Person(8, 'mia@example.test', 'Mia Crew', '2026-01-01 00:00:00', language: 'sv');

        [$locale, $hello, $invite] = Language::forPerson(
            $person,
            static fn (): array => [
                Language::filterLocale('en_US'),
                __('Hi,', 'eventcrew'),
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- the msgid, read back.
                __(self::INVITE, 'eventcrew'),
            ]
        );

        self::assertSame('sv_SE', $locale);
        self::assertSame('Hej,', $hello);
        self::assertStringStartsWith('Vill du höra om nya evenemang först?', $invite);
    }

    public function testTheSwedishRowOfAMessageInEveryLanguageIsSwedish(): void
    {
        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- the msgid, read back.
        $rows = HybridPolicy::inEach(['fi', 'en', 'sv'], static fn (): string => __(self::INVITE, 'eventcrew'));

        self::assertSame(self::INVITE, $rows['en']);
        self::assertNotSame(self::INVITE, $rows['sv']);
        self::assertNotSame($rows['fi'], $rows['sv']);
        self::assertStringContainsString('utskicket om kommande evenemang', $rows['sv']);
    }
}
