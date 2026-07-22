<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Support\AppIcon;
use EventCrew\Support\WebManifest;

/**
 * Makes the signup page an installable app: it serves the web manifest, the
 * service worker and the app icons, and injects the head tags Android and iOS
 * need to offer "Add to Home Screen".
 *
 * The assets are ordinary front-end requests (?eventcrew_pwa=…) rather than REST
 * routes, so they work on the host that blocks /wp-json, exactly like the signup
 * actions. The service worker is served from the site root path, so its scope
 * covers the whole origin and the installed app navigates normally.
 */
final class PwaController
{
    /** The page holding the [eventcrew_signup] shortcode - the app's home. */
    public const PAGE_OPTION = 'eventcrew_signup_page_id';
    public const NAME_OPTION = 'eventcrew_app_name';
    public const COLOR_OPTION = 'eventcrew_app_theme_color';

    public const DEFAULT_COLOR = '#4a6cf7';

    private const PARAM = 'eventcrew_pwa';

    public function boot(): void
    {
        add_action('template_redirect', [$this, 'maybeServeAsset']);
        add_action('wp_head', [$this, 'injectHeadTags']);
    }

    /**
     * Serves one of the PWA assets when the request asks for it, then exits.
     * The untested-by-design boundary; the shape of each asset is built by the
     * tested methods below.
     */
    public function maybeServeAsset(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only asset selector.
        $asset = isset($_GET[self::PARAM]) ? sanitize_key(wp_unslash($_GET[self::PARAM])) : '';

        if ('' === $asset) {
            return;
        }

        if ('manifest' === $asset) {
            header('Content-Type: application/manifest+json; charset=utf-8');
            echo wp_json_encode(WebManifest::build($this->manifestConfig()));
            exit;
        }

        if ('sw' === $asset) {
            header('Content-Type: text/javascript; charset=utf-8');
            header('Service-Worker-Allowed: /');
            // Static script text, no user data.
            echo $this->serviceWorkerJs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            exit;
        }

        if ('icon' === $asset) {
            $this->serveIcon();
        }
    }

    /**
     * The head tags that make the page installable, output only on the
     * configured signup page.
     */
    public function injectHeadTags(): void
    {
        if (! $this->shouldInject()) {
            return;
        }

        // Built from esc_*'d values in headTags().
        echo $this->headTags(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Whether this request is the signup page, and so should carry the app tags.
     */
    public function shouldInject(): bool
    {
        $pageId = (int) get_option(self::PAGE_OPTION, 0);

        return $pageId > 0 && is_page($pageId);
    }

    /**
     * The manifest inputs, resolved from the options and the page permalink.
     *
     * @return array<string, string>
     */
    public function manifestConfig(): array
    {
        $name = $this->appName();
        $color = $this->themeColor();

        return [
            'name' => $name,
            'short_name' => $this->shortName($name),
            'start_url' => $this->startUrl(),
            'icon_url' => add_query_arg(self::PARAM, 'icon', home_url('/')),
            'theme_color' => $color,
            'background_color' => '#ffffff',
        ];
    }

    /**
     * The Site Icon URL at a given size when the site has one, else null so the
     * caller falls back to the generated icon.
     */
    public function siteIconUrl(int $size): ?string
    {
        if (function_exists('has_site_icon') && has_site_icon()) {
            $url = get_site_icon_url($size);

            return '' === $url ? null : $url;
        }

        return null;
    }

    public function headTags(): string
    {
        $manifest = esc_url(add_query_arg(self::PARAM, 'manifest', home_url('/')));
        $sw = esc_url(add_query_arg(self::PARAM, 'sw', home_url('/')));
        $appleIcon = esc_url(add_query_arg([self::PARAM => 'icon', 'size' => 180], home_url('/')));
        $color = esc_attr($this->themeColor());
        $name = esc_attr($this->appName());

        return "\n"
            . '<link rel="manifest" href="' . $manifest . '">' . "\n"
            . '<meta name="theme-color" content="' . $color . '">' . "\n"
            . '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
            . '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n"
            . '<meta name="apple-mobile-web-app-title" content="' . $name . '">' . "\n"
            . '<link rel="apple-touch-icon" href="' . $appleIcon . '">' . "\n"
            . '<script>if("serviceWorker" in navigator){navigator.serviceWorker.register('
            . wp_json_encode($sw) . ',{scope:"/"});}</script>' . "\n";
    }

    private function serveIcon(): never
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only.
        $size = isset($_GET['size']) ? max(16, min(1024, (int) $_GET['size'])) : 192;

        $siteIcon = $this->siteIconUrl($size);

        if (null !== $siteIcon) {
            wp_redirect($siteIcon);

            exit;
        }

        $png = AppIcon::png($size, $this->appName(), $this->themeColor());

        if ('' === $png) {
            status_header(404);

            exit;
        }

        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        echo $png; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary image bytes.

        exit;
    }

    private function startUrl(): string
    {
        $pageId = (int) get_option(self::PAGE_OPTION, 0);
        $base = $pageId > 0 ? get_permalink($pageId) : false;

        if (false === $base) {
            $base = home_url('/');
        }

        return add_query_arg('eventcrew_app', '1', $base);
    }

    private function appName(): string
    {
        $name = trim((string) get_option(self::NAME_OPTION, ''));

        if ('' === $name) {
            $name = trim((string) get_bloginfo('name'));
        }

        return '' === $name ? 'EventCrew' : $name;
    }

    private function shortName(string $name): string
    {
        $first = strtok($name, ' ');

        return false === $first ? $name : $first;
    }

    private function themeColor(): string
    {
        $color = trim((string) get_option(self::COLOR_OPTION, ''));

        return 1 === preg_match('/^#?[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?$/', $color)
            ? (str_starts_with($color, '#') ? $color : '#' . $color)
            : self::DEFAULT_COLOR;
    }

    private function serviceWorkerJs(): string
    {
        return <<<'JS'
        const EVENTCREW_CACHE = 'eventcrew-shell-v1';
        self.addEventListener('install', function () { self.skipWaiting(); });
        self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });
        self.addEventListener('fetch', function (e) {
            if (e.request.method !== 'GET') { return; }
            e.respondWith(
                fetch(e.request).then(function (res) {
                    var copy = res.clone();
                    caches.open(EVENTCREW_CACHE).then(function (c) { c.put(e.request, copy); });
                    return res;
                }).catch(function () { return caches.match(e.request); })
            );
        });
        JS;
    }
}
