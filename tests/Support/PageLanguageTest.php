<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\PageLanguage;
use EventCrew\Tests\TestCase;

/** Q306: the crew page follows the language of the website page the visitor came from. */
final class PageLanguageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_GET = [];
        $_COOKIE = [];
        Functions\when('get_option')->justReturn(false);
        Functions\when('wp_unslash')->returnArg(1);
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_COOKIE = [];

        parent::tearDown();
    }

    public function testTheMarkerInTheAddressComesFirstThenTheSessionCookie(): void
    {
        self::assertNull(PageLanguage::marked());

        $_COOKIE[PageLanguage::COOKIE] = 'fi';
        self::assertSame('fi', PageLanguage::marked());

        $_GET[PageLanguage::MARKER] = 'en';
        self::assertSame('en', PageLanguage::marked());
    }

    public function testAMarkerThatIsNoSiteLanguageCountsAsNone(): void
    {
        $_GET[PageLanguage::MARKER] = 'xx';
        $_COOKIE[PageLanguage::COOKIE] = ['fi'];

        self::assertNull(PageLanguage::marked());
        self::assertNull(PageLanguage::remember());
    }

    public function testTheMarkerIsKeptForTheSession(): void
    {
        Functions\when('is_ssl')->justReturn(true);
        $_GET[PageLanguage::MARKER] = 'FI';

        self::assertSame('fi', PageLanguage::remember());
        self::assertSame('fi', $_COOKIE[PageLanguage::COOKIE]);

        unset($_GET[PageLanguage::MARKER]);
        self::assertSame('fi', PageLanguage::marked());
    }

    public function testTheMarkerGoesBeforeTheFragmentAndOnlyOnce(): void
    {
        self::assertSame('/crew-app/?ec_lang=fi', PageLanguage::markHref('/crew-app/', 'fi'));
        self::assertSame(
            'https://site.test/crew-app/?a=1&amp;ec_lang=fi#tasks',
            PageLanguage::markHref('https://site.test/crew-app/?a=1#tasks', 'fi')
        );
        self::assertSame('/crew-app/?ec_lang=en', PageLanguage::markHref('/crew-app/?ec_lang=en', 'fi'));
    }

    public function testTheSitesLanguageAndItsPagesComeFromPolylang(): void
    {
        Functions\when('home_url')->justReturn('https://site.test/');
        Functions\when('pll_current_language')->justReturn('fi');
        Functions\when('pll_home_url')->alias(static fn (string $l): string => 'https://site.test/' . $l . '/');
        Functions\when('pll_get_post')->alias(static fn (int $id, string $l): int => 'fi' === $l ? 1200 : 0);
        Functions\when('get_post_status')->justReturn('publish');

        self::assertSame('fi', PageLanguage::ofSite());
        self::assertSame('https://site.test/fi/', PageLanguage::homeUrl('fi'));
        self::assertSame('https://site.test/', PageLanguage::homeUrl(null));
        self::assertSame(1200, PageLanguage::translatedPage(1031, 'fi'));
        self::assertSame(1031, PageLanguage::translatedPage(1031, 'en'));
        self::assertSame(1031, PageLanguage::translatedPage(1031, null));

        Functions\when('get_post_status')->justReturn('draft');
        self::assertSame(1031, PageLanguage::translatedPage(1031, 'fi'));
    }
}
