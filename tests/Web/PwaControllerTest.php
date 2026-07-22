<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use Brain\Monkey\Functions;
use EventCrew\Tests\TestCase;
use EventCrew\Web\PwaController;

final class PwaControllerTest extends TestCase
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
        Functions\when('get_bloginfo')->justReturn('Dance Crew');
        Functions\when('home_url')->alias(static fn (string $p = '/'): string => 'https://site.test' . $p);
        Functions\when('get_permalink')->alias(static fn (int $id): string => 'https://site.test/signup/');
        Functions\when('add_query_arg')->alias(
            static function ($a, $b = null, $c = null): string {
                if (is_array($a)) {
                    return $b . '?' . http_build_query($a);
                }

                return $c . '?' . $a . '=' . $b;
            }
        );
        Functions\when('esc_url')->returnArg(1);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('wp_json_encode')->alias(static fn (mixed $v): string => (string) json_encode($v));
    }

    private function controller(): PwaController
    {
        return new PwaController();
    }

    public function testManifestConfigUsesTheSignupPagePermalinkAndAppName(): void
    {
        $this->options[PwaController::PAGE_OPTION] = 12;
        $this->options[PwaController::NAME_OPTION] = 'Crew App';

        $config = $this->controller()->manifestConfig();

        self::assertSame('Crew App', $config['name']);
        self::assertStringContainsString('https://site.test/signup/', $config['start_url']);
        self::assertStringContainsString('eventcrew_app=1', $config['start_url']);
    }

    public function testManifestConfigFallsBackToTheSiteNameAndDefaultColour(): void
    {
        $config = $this->controller()->manifestConfig();

        self::assertSame('Dance Crew', $config['name']);
        self::assertSame(PwaController::DEFAULT_COLOR, $config['theme_color']);
    }

    public function testCustomThemeColourIsNormalisedWithAHash(): void
    {
        $this->options[PwaController::COLOR_OPTION] = 'ff8800';

        self::assertSame('#ff8800', $this->controller()->manifestConfig()['theme_color']);
    }

    public function testShouldInjectOnlyOnTheConfiguredPage(): void
    {
        $this->options[PwaController::PAGE_OPTION] = 12;

        Functions\when('is_page')->alias(static fn (int $id): bool => 12 === $id);
        self::assertTrue($this->controller()->shouldInject());

        Functions\when('is_page')->justReturn(false);
        self::assertFalse($this->controller()->shouldInject());
    }

    public function testShouldNotInjectWhenNoPageIsConfigured(): void
    {
        Functions\when('is_page')->justReturn(true);

        self::assertFalse($this->controller()->shouldInject());
    }

    public function testSiteIconUrlIsPreferredWhenTheSiteHasOne(): void
    {
        Functions\when('has_site_icon')->justReturn(true);
        Functions\when('get_site_icon_url')->justReturn('https://site.test/icon-512.png');

        self::assertSame('https://site.test/icon-512.png', $this->controller()->siteIconUrl(512));
    }

    public function testSiteIconUrlIsNullWithoutASiteIcon(): void
    {
        Functions\when('has_site_icon')->justReturn(false);

        self::assertNull($this->controller()->siteIconUrl(512));
    }

    public function testHeadTagsCarryTheManifestAndAppleTags(): void
    {
        $this->options[PwaController::PAGE_OPTION] = 12;

        $tags = $this->controller()->headTags();

        self::assertStringContainsString('rel="manifest"', $tags);
        self::assertStringContainsString('apple-mobile-web-app-capable', $tags);
        self::assertStringContainsString('apple-touch-icon', $tags);
        self::assertStringContainsString('serviceWorker', $tags);
    }
}
