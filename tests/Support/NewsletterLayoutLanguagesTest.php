<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\HybridPolicy;
use EventCrew\Support\NewsletterLayout;
use EventCrew\Support\SiteLanguages;
use EventCrew\Tests\TestCase;

/**
 * The next events announcement in the site's languages (HybridPolicy):
 * with Finnish and English it is exactly what it was before the languages
 * were generalised (the fixture holds that output), with 3 languages the
 * every-language version has 3 parts, and with 4 languages hybrid is off so
 * it is written in the main language only.
 */
final class NewsletterLayoutLanguagesTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/newsletter-layout-fi-en.json';

    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
    }

    /** @param list<string> $languages */
    private function siteLanguages(array $languages): void
    {
        $this->options[SiteLanguages::OPTION] = $languages;
    }

    /** @return array<string, mixed> */
    private static function card(int $id, string $date, string $title): array
    {
        return [
            'id' => $id,
            'date' => $date,
            'time' => '21:00',
            'title' => $title,
            'title_fi' => '',
            'url' => 'https://site.test/event-' . $id . '/',
            'sold_out' => false,
        ];
    }

    /**
     * Every shape of the announcement: pictures or none, the practical
     * information, the ticket and music buttons, a sold-out card, a card's
     * own text, an organizers' text, and the "Later" list.
     *
     * @return array{cards: list<array<string, mixed>>, later: list<array<string, mixed>>, text: array<string, string>}
     */
    private static function content(): array
    {
        $full = [
            'image' => 'https://cdn.test/1.jpg',
            'ticket_url' => 'https://shop.test/p/1',
            'price' => '27.50 EUR',
            'listen' => 'https://music.test/dj',
            'practical' => [
                'venue' => 'Old Hall',
                'address' => 'Main Street 1, Helsinki',
                'doors_open' => '18:30',
                'doors_close' => '19:00',
            ],
            'text_fi' => "Tanssimme koko yön.\n\nOta kengät mukaan.",
            'text_en' => "We dance all night.\n\nBring shoes.",
            'text_sv' => "Vi dansar hela natten.\n\nTa med skor.",
        ] + self::card(1, '2026-07-27', 'Summer Dance');
        $plain = [
            'practical' => ['venue' => 'Studio', 'address' => '', 'doors_open' => '20:00', 'doors_close' => ''],
            'ticket_url' => 'https://shop.test/p/2',
        ] + self::card(2, '2026-07-28', 'Open Floor');
        $soldOut = ['sold_out' => true, 'image' => 'https://cdn.test/3.jpg'] + self::card(3, '2026-08-01', 'Full');
        $later = [
            ['image' => 'https://cdn.test/4.jpg', 'price' => '22.00 EUR', 'ticket_url' => 'https://shop.test/p/4']
                + self::card(4, '2026-08-10', 'Autumn Night'),
            ['sold_out' => true] + self::card(5, '2026-08-20', 'Gone'),
            self::card(6, '2026-09-01', 'Plain Later'),
        ];

        return [
            'cards' => [$full, $plain, $soldOut],
            'later' => $later,
            'text' => [
                'fi' => "Tervetuloa!\nNähdään.",
                'en' => "Welcome!\nSee you.",
                'sv' => "Välkommen!\nVi ses.",
            ],
        ];
    }

    /**
     * Every output of the layout for each language argument, keyed by a name.
     *
     * @param list<string> $langs
     * @return array<string, string>
     */
    private static function outputs(array $langs): array
    {
        $content = self::content();
        $out = [];

        foreach ($langs as $lang) {
            foreach ([true, false] as $withText) {
                $text = $withText ? $content['text'] : null;
                $key = $lang . '|' . ($withText ? 'text' : 'none');
                $out['html|' . $key] = NewsletterLayout::html(
                    $content['cards'],
                    $content['later'],
                    $text,
                    '#7a3cff',
                    $lang
                );
                $out['band|' . $key] = NewsletterLayout::html(
                    $content['cards'],
                    $content['later'],
                    $text,
                    '#7a3cff',
                    $lang,
                    true
                );
                $out['text|' . $key] = NewsletterLayout::text($content['cards'], $content['later'], $text, $lang);
            }

            // A text in one language only: a one-language email in the other
            // language shows it instead of nothing.
            foreach (['fi', 'en'] as $only) {
                $text = [$only => $content['text'][$only]];
                $out['html|' . $lang . '|only-' . $only] = NewsletterLayout::html([], [], $text, '#7a3cff', $lang);
                $out['text|' . $lang . '|only-' . $only] = NewsletterLayout::text([], [], $text, $lang);
            }

            $out['greeting|' . $lang . '|name'] = implode("\n", NewsletterLayout::greeting('Anna', $lang));
            $out['greeting|' . $lang . '|none'] = implode("\n", NewsletterLayout::greeting(null, $lang));
        }

        return $out;
    }

    /**
     * With the site languages Finnish and English, every output is the same,
     * byte for byte, as before the languages were generalised.
     */
    public function testFinnishAndEnglishGiveTheOutputOfBefore(): void
    {
        $this->siteLanguages(['fi', 'en']);
        $outputs = self::outputs(['', 'fi', 'en']);
        $before = json_decode((string) file_get_contents(self::FIXTURE), true);

        self::assertSame(array_keys($before), array_keys($outputs));

        foreach ($before as $key => $output) {
            self::assertSame($output, $outputs[$key], $key);
        }
    }

    /**
     * With 3 site languages hybrid is allowed: the every-language version
     * has each text 3 times with FI, SV and EN labels, in the site order,
     * the fixed words included (from languages/eventcrew-sv_SE).
     */
    public function testThreeLanguagesGiveThreePartsInTheEveryLanguageVersion(): void
    {
        $this->siteLanguages(['fi', 'sv', 'en']);
        $content = self::content();

        $html = NewsletterLayout::html($content['cards'], $content['later'], $content['text'], '#7a3cff');
        $text = NewsletterLayout::text($content['cards'], $content['later'], $content['text']);

        foreach (['FI', 'SV', 'EN'] as $tag) {
            // The organizers' text, the card's own text, and two cards' door times.
            self::assertSame(4, substr_count($html, '>' . $tag . '</span>'), $tag);
        }

        self::assertStringContainsString(
            "FI: Tervetuloa!\nNähdään.\n\nSV: Välkommen!\nVi ses.\n\nEN: Welcome!\nSee you.",
            $text
        );
        self::assertStringContainsString(
            "FI: Ovet aukeavat 18:30\nOvet sulkeutuvat 19:00\n\nSV: Dörrarna öppnar 18:30\nDörrarna stänger 19:00\n\n"
                . "EN: Doors open 18:30\nDoors close 19:00",
            $text
        );
        self::assertStringContainsString('Vi dansar hela natten.', $html);
        self::assertLessThan(strpos($html, 'Vi dansar'), strpos($html, 'Tanssimme'));
        self::assertLessThan(strpos($html, 'We dance'), strpos($html, 'Vi dansar'));
        self::assertStringContainsString('Osta lippu<br>Köp en biljett<br>Buy a ticket', $html);
        self::assertStringStartsWith("TULEVAT TAPAHTUMAT
KOMMANDE EVENEMANG
COMING EVENTS

", $text);
        self::assertSame("Hei Anna!
Hej Anna!
Hi Anna!", NewsletterLayout::greeting('Anna')['text']);

        // A subscriber whose language is Swedish gets Swedish alone, with no label.
        $swedish = NewsletterLayout::text($content['cards'], [], $content['text'], 'sv');

        self::assertStringContainsString("Välkommen!\nVi ses.", $swedish);
        self::assertStringNotContainsString('SV: ', $swedish);
        self::assertStringNotContainsString('Tervetuloa', $swedish);
    }

    /**
     * The every-language version follows the Hybrid tick of the message kind
     * it is given: with the newsletter's tick off and the post-event email's
     * on, the newsletter is the main language alone, and the post-event
     * email's coming events are in both languages.
     */
    public function testTheEveryLanguageVersionFollowsTheTickOfItsKind(): void
    {
        $this->options[HybridPolicy::OPTION] = [HybridPolicy::NEWSLETTER => false, HybridPolicy::FOLLOWUP => true];
        $content = self::content();

        $newsletter = NewsletterLayout::text($content['cards'], [], $content['text']);
        $followup = NewsletterLayout::text($content['cards'], [], $content['text'], '', HybridPolicy::FOLLOWUP);
        $followupHtml = NewsletterLayout::html(
            $content['cards'],
            [],
            $content['text'],
            '#7a3cff',
            '',
            true,
            HybridPolicy::FOLLOWUP
        );

        self::assertStringStartsWith("TULEVAT TAPAHTUMAT\n\nTervetuloa!", $newsletter);
        self::assertStringNotContainsString('Welcome', $newsletter);
        self::assertStringStartsWith("TULEVAT TAPAHTUMAT\nCOMING EVENTS\n\nFI: Tervetuloa!", $followup);
        self::assertStringContainsString('EN: Welcome!', $followup);
        self::assertStringContainsString('>EN</span>', $followupHtml);
    }

    /** 3 different texts go one per line, however short (Owner, 2026-11-06: one language per row). */
    public function testThreeTextsAreJoinedOnOneLineWhenTheyFit(): void
    {
        self::assertSame("Ovet\nDörrar\nDoors", NewsletterLayout::joinAll(['Ovet', 'Dörrar', 'Doors']));
        self::assertSame(
            "Osta lippu\nKöp en biljett\nBuy a ticket",
            NewsletterLayout::joinAll(['Osta lippu', 'Köp en biljett', 'Buy a ticket'])
        );
        self::assertSame('DJ', NewsletterLayout::joinAll(['DJ', 'DJ', 'DJ']));
    }

    /**
     * With 4 site languages hybrid is switched off, so the every-language
     * version (a subscriber whose language is not known) is the main
     * language alone, with no labels; a subscriber with a site language
     * still gets their own.
     */
    public function testFourLanguagesGiveTheMainLanguageAloneInsteadOfEveryLanguage(): void
    {
        $this->siteLanguages(['fi', 'sv', 'de', 'en']);
        $content = self::content();

        self::assertFalse(HybridPolicy::isAllowed());

        $html = NewsletterLayout::html($content['cards'], $content['later'], $content['text'], '#7a3cff', '', true);
        $text = NewsletterLayout::text($content['cards'], $content['later'], $content['text']);

        foreach (['FI', 'SV', 'DE', 'EN'] as $tag) {
            self::assertStringNotContainsString('>' . $tag . '</span>', $html);
            self::assertStringNotContainsString($tag . ': ', $text);
        }

        self::assertStringStartsWith("TULEVAT TAPAHTUMAT\n\nTervetuloa!\nNähdään.", $text);
        self::assertStringNotContainsString('Welcome', $text);
        self::assertStringNotContainsString('Coming events', $html);
        self::assertStringContainsString('Ovet aukeavat 18:30', $html);
        self::assertStringNotContainsString('Doors open', $html);
        self::assertSame('Hei Anna!', NewsletterLayout::greeting('Anna')['text']);

        // A German subscriber gets German; the text has no German, so the
        // main language's text stands in.
        $german = NewsletterLayout::text([], [], $content['text'], 'de');

        self::assertStringContainsString('Tervetuloa!', $german);
        self::assertStringNotContainsString('Welcome', $german);
    }
}
