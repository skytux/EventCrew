<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\EventVenue;
use EventCrew\Support\HybridPolicy;
use EventCrew\Support\NewsletterLayout;
use EventCrew\Support\SiteLanguages;
use EventCrew\Tests\Telegram\FakeEvents;
use EventCrew\Tests\TestCase;

/**
 * The next events announcement's own cards (Owner, 2026-11-08): a picture's
 * alt text "DJ · Event · weekday date time · place" in the email's first
 * language, leaving out what is not known, and only the doors' closing
 * time under the picture, then the buttons. The post-event email's cards
 * (the default) stay as they were.
 */
final class NewsletterAnnouncementCardTest extends TestCase
{
    use FakeEvents;
    use FakeEventNames;

    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeEvents();
        $this->event(41, '2026-07-27', 'Summer Dance');

        $this->options = [SiteLanguages::OPTION => ['fi', 'en']];
        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
    }

    /** Event 41's clean names, and its DJ unless $withDj is false. */
    private function names(bool $withDj = true): void
    {
        $this->fakeEventNames(
            [41 => ['en' => 'Summer Dance', 'fi' => 'Kesätanssit']],
            $withDj ? [41 => ['DJ Aurora']] : []
        );
    }

    /**
     * @param array<string, string>|null $practical
     * @return array<string, mixed>
     */
    private static function card(?array $practical = null, string $time = '21:00'): array
    {
        $card = [
            'id' => 41,
            'date' => '2026-07-27',
            'time' => $time,
            'title' => 'Summer Dance',
            'title_fi' => '',
            'url' => 'https://site.test/event-41/',
            'image' => 'https://cdn.test/41.jpg',
            'ticket_url' => 'https://shop.test/p/41',
            'sold_out' => false,
        ];

        if (null !== $practical) {
            $card['practical'] = $practical;
        }

        return $card;
    }

    /** @return array<string, string> */
    private static function practical(string $venue = 'Old Hall'): array
    {
        return ['venue' => $venue, 'address' => 'Main St 1', 'doors_open' => '20:30', 'doors_close' => '02:00'];
    }

    /** @param list<array<string, mixed>> $cards */
    private static function announcement(array $cards, string $lang): string
    {
        return NewsletterLayout::html($cards, [], null, '#4a6cf7', $lang, false, HybridPolicy::NEWSLETTER, true);
    }

    private static function alt(string $html): string
    {
        self::assertSame(1, preg_match('/<img [^>]*alt="([^"]*)"/', $html, $match));

        return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
    }

    public function testTheAltTextIsInTheEmailsFirstLanguage(): void
    {
        $this->names();
        $both = self::announcement([self::card(self::practical())], '');
        $english = self::announcement([self::card(self::practical())], 'en');

        self::assertSame('DJ Aurora · Kesätanssit · ma 27.7.2026 21:00 · Old Hall', self::alt($both));
        self::assertSame('DJ Aurora · Summer Dance · Mon 27.7.2026 21:00 · Old Hall', self::alt($english));
    }

    public function testThePlaceIsTheEventsVenueWhenTheModelFoundNone(): void
    {
        $this->names();
        $this->options[EventVenue::DEFAULT_OPTION] = 'Dance Studio';

        $html = self::announcement([self::card(self::practical(''))], 'en');

        self::assertSame('DJ Aurora · Summer Dance · Mon 27.7.2026 21:00 · Dance Studio', self::alt($html));
    }

    public function testUnknownPartsAreLeftOut(): void
    {
        $this->names(false);

        $html = self::announcement([self::card(null, '')], 'en');

        self::assertSame('Summer Dance · Mon 27.7.2026', self::alt($html));
    }

    public function testUnderThePictureOnlyTheDoorsCloseThenTheButtons(): void
    {
        $this->names();
        $card = self::card(self::practical());
        $html = self::announcement([$card], 'en');
        $text = NewsletterLayout::text([$card], [], null, 'en', HybridPolicy::NEWSLETTER, true);

        foreach ([$html, $text] as $out) {
            self::assertStringContainsString('Doors close 02:00', $out);
            self::assertStringNotContainsString('Doors open', $out);
            self::assertStringNotContainsString('Main St 1', $out);
        }

        self::assertStringNotContainsString('Old Hall', $text);
        self::assertLessThan(strpos($html, 'Doors close 02:00'), strpos($html, '<img'));
        self::assertLessThan(
            strpos($html, 'Buy a ticket'),
            strpos($html, 'Doors close 02:00'),
            'the buttons come after'
        );
        self::assertStringEndsWith("DJ Aurora\nDoors close 02:00\nBuy a ticket: https://shop.test/p/41", $text);
    }

    /** The post-event email's card (no $announcement) keeps every line and its alt text. */
    public function testTheThankYouCardIsUnchanged(): void
    {
        $this->names();
        $card = self::card(self::practical());
        $html = NewsletterLayout::html([$card], [], null, '#4a6cf7', 'en');
        $text = NewsletterLayout::text([$card], [], null, 'en');

        self::assertSame('27.7.2026 · 21:00 — Summer Dance — Kesätanssit — DJ Aurora', self::alt($html));

        foreach ([$html, $text] as $out) {
            self::assertStringContainsString('Old Hall', $out);
            self::assertStringContainsString('Main St 1', $out);
            self::assertStringContainsString('Doors open 20:30', $out);
            self::assertStringContainsString('Doors close 02:00', $out);
        }
    }
}
