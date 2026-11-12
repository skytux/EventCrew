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
     *     theme_color: string, background_color: string, scope?: string,
     *     manifest_url?: string
     * } $config
     * @return array<string, mixed>
     */
    public static function build(array $config): array
    {
        $manifest = [
            'name' => $config['name'],
            'short_name' => $config['short_name'],
            'start_url' => $config['start_url'],
            // The app's own path, so links elsewhere on the site open in
            // the browser; '/' when none is given.
            'scope' => $config['scope'] ?? '/',
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

        // The app names itself, so a page in its scope can ask
        // navigator.getInstalledRelatedApps() whether it is installed here
        // (Chrome, Edge) and offer "Open in the app" instead of installing.
        if ('' !== ($config['manifest_url'] ?? '')) {
            $manifest['related_applications'] = [
                ['platform' => 'webapp', 'url' => $config['manifest_url']],
            ];
        }

        return $manifest;
    }

    private static function iconSrc(string $base, int $size): string
    {
        $separator = str_contains($base, '?') ? '&' : '?';

        return $base . $separator . 'size=' . $size;
    }
}
