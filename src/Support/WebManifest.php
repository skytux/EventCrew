<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * Builds the web app manifest that makes the signup page installable to a phone
 * home screen.
 *
 * A pure function of its config - no options, no clock, no HTTP - so the shape
 * the browser has to accept for the "Install app" prompt to appear is unit
 * tested; the controller only reads the options and JSON-encodes the result.
 */
final class WebManifest
{
    /**
     * @param array{
     *     name: string, short_name: string, start_url: string, icon_url: string,
     *     theme_color: string, background_color: string
     * } $config
     * @return array<string, mixed>
     */
    public static function build(array $config): array
    {
        return [
            'name' => $config['name'],
            'short_name' => $config['short_name'],
            'start_url' => $config['start_url'],
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => $config['background_color'],
            'theme_color' => $config['theme_color'],
            'icons' => [
                [
                    'src' => self::iconSrc($config['icon_url'], 192),
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => self::iconSrc($config['icon_url'], 512),
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => self::iconSrc($config['icon_url'], 512),
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ],
            ],
        ];
    }

    private static function iconSrc(string $base, int $size): string
    {
        $separator = str_contains($base, '?') ? '&' : '?';

        return $base . $separator . 'size=' . $size;
    }
}
