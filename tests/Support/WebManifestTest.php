<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\WebManifest;
use EventCrew\Tests\TestCase;

final class WebManifestTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function config(): array
    {
        return [
            'name' => 'Dance Crew',
            'short_name' => 'Crew',
            'start_url' => 'https://site.test/signup/?eventcrew_app=1',
            'icon_url' => 'https://site.test/?eventcrew_pwa=icon',
            'theme_color' => '#4a6cf7',
            'background_color' => '#ffffff',
        ];
    }

    public function testBuildsAStandaloneInstallableManifest(): void
    {
        $manifest = WebManifest::build($this->config());

        self::assertSame('standalone', $manifest['display']);
        self::assertSame('/', $manifest['scope']);
        self::assertSame('Dance Crew', $manifest['name']);
        self::assertSame('https://site.test/signup/?eventcrew_app=1', $manifest['start_url']);
        self::assertSame('#4a6cf7', $manifest['theme_color']);
    }

    public function testTheScopeIsTheOneGivenElseTheWholeSite(): void
    {
        self::assertSame('/crew-app/', WebManifest::build(['scope' => '/crew-app/'] + $this->config())['scope']);
        self::assertSame('Crew', WebManifest::build(['scope' => '/crew-app/'] + $this->config())['short_name']);
    }

    public function testNamesItselfAsARelatedWebAppWhenGivenItsAddress(): void
    {
        $url = 'https://site.test/?eventcrew_pwa=manifest';

        self::assertSame(
            [['platform' => 'webapp', 'url' => $url]],
            WebManifest::build(['manifest_url' => $url] + $this->config())['related_applications']
        );
        self::assertArrayNotHasKey('related_applications', WebManifest::build($this->config()));
    }

    public function testAdvertisesTheRequiredIconSizesIncludingMaskable(): void
    {
        $manifest = WebManifest::build($this->config());

        $sizes = array_map(static fn (array $i): string => $i['sizes'], $manifest['icons']);
        $purposes = array_map(static fn (array $i): string => $i['purpose'], $manifest['icons']);

        self::assertContains('192x192', $sizes);
        self::assertContains('512x512', $sizes);
        self::assertContains('maskable', $purposes);
        // Icon URLs carry the size the endpoint should render.
        self::assertStringContainsString('size=512', $manifest['icons'][1]['src']);
    }

    /** Owner 2026-11-13: links into the scope open the installed app where the browser can. */
    public function testLinksOpenTheInstalledApp(): void
    {
        $manifest = WebManifest::build([
            'name' => 'Crew', 'short_name' => 'Crew', 'start_url' => '/crew-app/',
            'icon_url' => '/?icon', 'theme_color' => '#000000', 'background_color' => '#ffffff',
        ]);

        self::assertSame('preferred', $manifest['handle_links']);
        self::assertSame(['client_mode' => ['navigate-existing', 'auto']], $manifest['launch_handler']);
    }
}
