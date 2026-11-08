<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use Brain\Monkey\Functions;
use EventCrew\Tests\TestCase;
use EventCrew\Web\SiteBanner;

final class SiteBannerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_bloginfo')->justReturn('Dance Night');
        Functions\when('get_theme_mod')->justReturn(31);
        Functions\when('wp_get_attachment_image')->alias(
            static function (int $id, string $size, bool $icon, array $attr): string {
                $out = '<img data-id="' . $id . '"';

                foreach ($attr as $name => $value) {
                    $out .= ' ' . $name . '="' . $value . '"';
                }

                return $out . '>';
            }
        );
    }

    public function testTheBannerIsTheSiteLogoAloneLinkingHome(): void
    {
        $html = SiteBanner::html('https://site.test/');

        self::assertSame(
            '<header class="eventcrew-site-banner">'
            . '<a class="eventcrew-site-banner-home" href="https://site.test/" rel="home">'
            . '<img data-id="31" class="eventcrew-site-banner-logo" alt="Dance Night" loading="eager" decoding="async">'
            . '</a></header>',
            $html
        );
    }

    public function testWithoutALogoTheSiteNameStandsAlone(): void
    {
        Functions\when('get_theme_mod')->justReturn(0);

        self::assertSame(
            '<header class="eventcrew-site-banner">'
            . '<a class="eventcrew-site-banner-home" href="https://site.test/" rel="home">'
            . '<span class="eventcrew-site-banner-name">Dance Night</span>'
            . '</a></header>',
            SiteBanner::html('https://site.test/')
        );
    }

    public function testWithNeitherThereIsNoBanner(): void
    {
        Functions\when('get_theme_mod')->justReturn(0);
        Functions\when('get_bloginfo')->justReturn('');

        self::assertSame('', SiteBanner::html('https://site.test/'));
    }
}
