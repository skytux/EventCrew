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
}
