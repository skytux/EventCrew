<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use Brain\Monkey\Functions;
use EventCrew\Tests\TestCase;
use EventCrew\Web\SiteBanner;

final class SiteBannerTest extends TestCase
{
    /** @var array<string, string> template id => content */
    private array $templates = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->templates = [];

        Functions\when('get_bloginfo')->justReturn('Dance Night');
        Functions\when('get_theme_mod')->justReturn(31);
        Functions\when('get_stylesheet')->justReturn('twentytwentyfive');
        Functions\when('wp_is_block_theme')->justReturn(true);
        Functions\when('get_header_image')->justReturn(false);
        Functions\when('get_block_template')->alias(function (string $id): ?object {
            if (! isset($this->templates[$id])) {
                return null;
            }

            return (object) ['content' => $this->templates[$id]];
        });
        Functions\when('parse_blocks')->alias(static fn (string $content): array => json_decode($content, true));
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

    /**
     * @param array<string, mixed> $attrs
     * @param list<array<string, mixed>> $inner
     * @return array<string, mixed>
     */
    private static function block(string $name, array $attrs = [], array $inner = []): array
    {
        return ['blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => $inner];
    }

    public function testTheLogoSitsOnThePageTemplatesCoverPictureAtItsFocalPoint(): void
    {
        $this->templates['twentytwentyfive//page'] = (string) json_encode([
            self::block('core/group', [], [
                self::block('core/cover', ['id' => 21, 'focalPoint' => ['x' => 0.5, 'y' => 0.04]], [
                    self::block('core/site-logo'),
                ]),
            ]),
        ]);

        $html = SiteBanner::html('https://site.test/');

        self::assertStringStartsWith('<header class="eventcrew-site-banner has-picture">', $html);
        self::assertStringContainsString(
            '<img data-id="21" class="eventcrew-site-banner-picture" alt="" sizes="100vw"',
            $html
        );
        self::assertStringContainsString('style="object-position:50% 4%"', $html);
        self::assertStringContainsString(
            '<a class="eventcrew-site-banner-home" href="https://site.test/" rel="home">',
            $html
        );
        self::assertStringContainsString(
            '<img data-id="31" class="eventcrew-site-banner-logo" alt="Dance Night"',
            $html
        );
        // The picture first, under the logo.
        self::assertLessThan(strpos($html, 'data-id="31"'), strpos($html, 'data-id="21"'));
    }

    public function testTheCoverIsFoundInsideATemplatePart(): void
    {
        $this->templates['twentytwentyfive//page'] = (string) json_encode([
            self::block('core/template-part', ['slug' => 'header']),
            self::block('core/post-content'),
        ]);
        $this->templates['twentytwentyfive//header'] = (string) json_encode([
            self::block('core/cover', ['url' => 'https://site.test/hero.jpg']),
        ]);

        $html = SiteBanner::html('https://site.test/');

        self::assertStringContainsString(
            '<img class="eventcrew-site-banner-picture" src="https://site.test/hero.jpg" alt=""',
            $html
        );
        self::assertStringContainsString('object-position:50% 50%', $html);
    }

    public function testAClassicThemeUsesItsHeaderImage(): void
    {
        Functions\when('wp_is_block_theme')->justReturn(false);
        Functions\when('get_header_image')->justReturn('https://site.test/header.jpg');

        $html = SiteBanner::html('https://site.test/');

        self::assertStringContainsString('src="https://site.test/header.jpg"', $html);
        self::assertStringContainsString('has-picture', $html);
    }

    public function testWithoutLogoOrPictureTheSiteNameStandsOnThePlainBanner(): void
    {
        Functions\when('get_theme_mod')->justReturn(0);
        $this->templates['twentytwentyfive//page'] = (string) json_encode([self::block('core/post-content')]);

        $html = SiteBanner::html('https://site.test/');

        self::assertSame(
            '<header class="eventcrew-site-banner">'
            . '<a class="eventcrew-site-banner-home" href="https://site.test/" rel="home">'
            . '<span class="eventcrew-site-banner-plate">'
            . '<span class="eventcrew-site-banner-name">Dance Night</span>'
            . '</span></a></header>',
            $html
        );
    }
}
