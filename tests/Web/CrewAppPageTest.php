<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use Brain\Monkey\Functions;
use EventCrew\Tests\TestCase;
use EventCrew\Web\CrewAppPage;
use EventCrew\Web\PwaController;
use EventCrew\Web\SignupController;

final class CrewAppPageTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var list<array{int, string, string}> */
    private array $addedMeta = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [PwaController::PAGE_OPTION => 897];
        $this->addedMeta = [];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('is_page')->alias(static fn (int $id): bool => 897 === $id);
        Functions\when('add_post_meta')->alias(
            function (int $id, string $key, string $value): bool {
                $this->addedMeta[] = [$id, $key, $value];

                return true;
            }
        );
        Functions\when('wp_parse_url')->alias(
            static fn (string $url, int $part = -1): mixed => parse_url($url, $part)
        );
        Functions\when('sanitize_title')->alias(static fn (string $s): string => strtolower($s));
    }

    private function page(): CrewAppPage
    {
        $signup = (new \ReflectionClass(SignupController::class))->newInstanceWithoutConstructor();

        return new CrewAppPage($signup, new PwaController());
    }

    public function testTheCrewAppPageGetsThePluginsOwnPageFile(): void
    {
        $page = $this->page();

        self::assertSame(
            EVENTCREW_PLUGIN_DIR . 'templates/public/crew-app.php',
            $page->templateFor('/theme/page.html')
        );
        self::assertSame($page, CrewAppPage::current());
    }

    public function testOtherPagesAndTheSwitchedOffSettingKeepTheThemeTemplate(): void
    {
        Functions\when('is_page')->justReturn(false);
        self::assertSame('/theme/page.html', $this->page()->templateFor('/theme/page.html'));

        Functions\when('is_page')->alias(static fn (int $id): bool => 897 === $id);
        $this->options[CrewAppPage::STANDALONE_OPTION] = '0';
        self::assertSame('/theme/page.html', $this->page()->templateFor('/theme/page.html'));
    }

    public function testTextIsWhiteOnADarkAppColourAndBlackOnALightOne(): void
    {
        $this->options[PwaController::COLOR_OPTION] = '#3a297d';
        self::assertSame(['background' => '#3a297d', 'text' => '#ffffff'], $this->page()->colors());

        $this->options[PwaController::COLOR_OPTION] = 'ffd';
        self::assertSame(['background' => '#ffd', 'text' => '#111111'], $this->page()->colors());

        self::assertFalse(CrewAppPage::isLight('#4a6cf7'));
        self::assertTrue(CrewAppPage::isLight('#ffffff'));
        self::assertTrue(CrewAppPage::isLight('#c0c0c0'));
        self::assertFalse(CrewAppPage::isLight('#808080'));
    }

    public function testAPublishedPageKeepsItsOldAddressWhenTheAddressChanges(): void
    {
        Functions\when('get_post_meta')->justReturn([]);

        $before = (object) ['post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'my-volunteer-profile'];
        $after = (object) ['post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'crew-app'];

        $this->page()->rememberOldSlug(897, $after, $before);

        self::assertSame([[897, CrewAppPage::OLD_SLUG_META, 'my-volunteer-profile']], $this->addedMeta);
    }

    public function testNothingIsKeptForAnUnchangedAddressADraftOrAPost(): void
    {
        Functions\when('get_post_meta')->justReturn([]);
        $page = $this->page();

        $same = (object) ['post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'crew-app'];
        $page->rememberOldSlug(897, $same, $same);

        $draft = (object) ['post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'old'];
        $page->rememberOldSlug(897, (object) ['post_type' => 'page', 'post_name' => 'new'], $draft);

        $post = (object) ['post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'old'];
        $page->rememberOldSlug(5, (object) ['post_type' => 'post', 'post_name' => 'new'], $post);

        self::assertSame([], $this->addedMeta);
    }

    public function testAnAddressAlreadyKeptIsNotAddedTwice(): void
    {
        Functions\when('get_post_meta')->justReturn(['my-volunteer-profile']);

        $before = (object) ['post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'my-volunteer-profile'];
        $after = (object) ['post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'crew-app'];

        $this->page()->rememberOldSlug(897, $after, $before);

        self::assertSame([], $this->addedMeta);
    }

    public function testAnOldAddressLeadsToTheNewOneWithTheSignInTokenKept(): void
    {
        $asked = [];
        Functions\when('get_posts')->alias(
            static function (array $args) use (&$asked): array {
                $asked[] = $args['meta_value'];

                return 'my-volunteer-profile' === $args['meta_value'] ? [897] : [];
            }
        );
        Functions\when('get_permalink')->justReturn('https://site.test/crew-app/');

        $page = $this->page();

        self::assertSame(
            'https://site.test/crew-app/?eventcrew_token=abc',
            $page->oldSlugTarget('/my-volunteer-profile/?eventcrew_token=abc')
        );
        self::assertSame('https://site.test/crew-app/', $page->oldSlugTarget('/my-volunteer-profile/'));
        self::assertNull($page->oldSlugTarget('/no-such-page/'));
        self::assertNull($page->oldSlugTarget('/'));
        self::assertSame(['my-volunteer-profile', 'my-volunteer-profile', 'no-such-page'], $asked);
    }
}
