<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Support\AppIcon;
use EventCrew\Support\SiteTexts;
use EventCrew\Support\WebManifest;

/**
 * Makes the signup page an installable app: it serves the web manifest, the
 * service worker and the app icons, and injects the head tags Android and iOS
 * need to offer "Add to Home Screen".
 *
 * The assets are ordinary front-end requests (?eventcrew_pwa=…) rather than REST
 * routes, so they work on the host that blocks /wp-json, exactly like the signup
 * actions. The service worker script is served from the site root path, but
 * the app's scope (manifest and service worker alike) is only the Crew app
 * page's path (scope()): a link to the rest of the site opens in the browser,
 * not inside the app window. The app's forms post to admin-ajax.php and are
 * sent back to the page, so they end inside the scope.
 */
final class PwaController
{
    /** The page holding the [eventcrew_signup] shortcode - the app's home. */
    public const PAGE_OPTION = 'eventcrew_signup_page_id';
    public const NAME_OPTION = 'eventcrew_app_name';

    /** The name under the home-screen icon; blank derives one (shortName()). */
    public const SHORT_NAME_OPTION = 'eventcrew_app_short_name';

    /** Longest short name: what fits under a home-screen icon. */
    public const SHORT_NAME_MAX = 12;
    public const COLOR_OPTION = 'eventcrew_app_theme_color';

    /**
     * The Crew app's accent: the selected tab and sub tab, the panel underline
     * and the focus ring. Separate from COLOR_OPTION, which is also the page
     * background, so the selected tab never disappears into the page. Unset
     * means the theme colour, as before this option existed.
     */
    public const ACCENT_OPTION = 'eventcrew_app_accent_color';

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
            'scope' => $this->scope(),
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
        $name = esc_attr($this->shortName($this->appName()));
        $register = 'if("serviceWorker" in navigator){'
            . 'var s=' . wp_json_encode($this->scope()) . ';'
            . 'navigator.serviceWorker.register(' . wp_json_encode($sw) . ',{scope:s});'
            // Before the scope was the page's path it was the whole site:
            // drop that older registration so it stops handling other pages.
            . 'if(s!=="/"&&navigator.serviceWorker.getRegistrations){'
            . 'navigator.serviceWorker.getRegistrations().then(function(r){r.forEach(function(g){'
            . 'if(new URL(g.scope).pathname==="/"){g.unregister();}});});}}';

        return "\n"
            . '<link rel="manifest" href="' . $manifest . '">' . "\n"
            . '<meta name="theme-color" content="' . $color . '">' . "\n"
            . '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
            . '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n"
            . '<meta name="apple-mobile-web-app-title" content="' . $name . '">' . "\n"
            . '<link rel="apple-touch-icon" href="' . $appleIcon . '">' . "\n"
            . '<script>' . $register . '</script>' . "\n";
    }

    /** The app icon's address at a size (the Site Icon, else the generated one). */
    public static function iconUrl(int $size = 192): string
    {
        return add_query_arg([self::PARAM => 'icon', 'size' => $size], home_url('/'));
    }

    /** The app's name under its home-screen icon, such as "EDH Crew". */
    public function appShortName(): string
    {
        return $this->shortName($this->appName());
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
        $name = SiteTexts::text('app_name');

        if ('' === $name) {
            $name = trim((string) get_bloginfo('name'));
        }

        return '' === $name ? 'EventCrew' : $name;
    }

    /**
     * The app's path on the site: the folder of the Crew app page's address
     * ("/crew-app/"), so the installed app stays on that page and a link
     * elsewhere opens in the browser. When the page has translations at
     * other addresses (Polylang), the part of the path they share, so each
     * language stays in the app; "/" when they share nothing or the page has
     * no pretty address.
     */
    public function scope(): string
    {
        $pageId = (int) get_option(self::PAGE_OPTION, 0);

        if ($pageId <= 0) {
            return '/';
        }

        $ids = [$pageId];

        if (function_exists('pll_get_post_translations')) {
            $translations = pll_get_post_translations($pageId);

            if (is_array($translations)) {
                $ids = array_merge($ids, array_map('intval', array_values($translations)));
            }
        }

        $scope = null;

        foreach (array_unique($ids) as $id) {
            $link = $id > 0 ? get_permalink($id) : false;

            if (! is_string($link) || '' === $link) {
                continue;
            }

            $folder = self::folder((string) wp_parse_url($link, PHP_URL_PATH));
            $scope = null === $scope ? $folder : self::sharedFolder($scope, $folder);
        }

        return $scope ?? '/';
    }

    /** "/a/b/" for "/a/b/" and "/a/b/page.php"; "/" for no path. */
    private static function folder(string $path): string
    {
        if ('' === $path || ! str_starts_with($path, '/')) {
            return '/';
        }

        return str_ends_with($path, '/') ? $path : substr($path, 0, (int) strrpos($path, '/') + 1);
    }

    /** The longest folder both paths are in ("/fi/x/" and "/x/" share "/"). */
    private static function sharedFolder(string $a, string $b): string
    {
        $shared = '/';
        $partsB = explode('/', trim($b, '/'));

        foreach (explode('/', trim($a, '/')) as $i => $part) {
            if ('' === $part || ($partsB[$i] ?? null) !== $part) {
                break;
            }

            $shared .= $part . '/';
        }

        return $shared;
    }

    /**
     * The name under the home-screen icon: the App short name setting, else
     * the app name when it fits, else its initials and "Crew"
     * ("Ecstatic Dance Helsinki" is "EDH Crew").
     */
    public function shortName(string $name): string
    {
        $set = trim(SiteTexts::text('app_short_name'));

        if ('' !== $set) {
            return $set;
        }

        if (mb_strlen($name) <= self::SHORT_NAME_MAX) {
            return $name;
        }

        $words = preg_split('/[\s\-]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        $words = is_array($words) ? $words : [];

        // "Helsinki Dance Crew" is "HD Crew", not "HDC Crew".
        if (count($words) > 1 && 0 === strcasecmp((string) end($words), 'crew')) {
            array_pop($words);
        }

        $initials = '';

        foreach ($words as $word) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        }

        /* translators: %s: the initials of the app name, like "EDH". */
        $short = sprintf(__('%s Crew', 'eventcrew'), $initials);

        return '' === $initials || mb_strlen($short) > self::SHORT_NAME_MAX
            ? mb_substr($name, 0, self::SHORT_NAME_MAX)
            : $short;
    }

    public function themeColor(): string
    {
        $color = trim((string) get_option(self::COLOR_OPTION, ''));

        return 1 === preg_match('/^#?[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?$/', $color)
            ? (str_starts_with($color, '#') ? $color : '#' . $color)
            : self::DEFAULT_COLOR;
    }

    /**
     * The cache name carries the plugin version, so a release invalidates the
     * shell instead of leaving an installed app on the previous one. It matters
     * because this caches the page HTML: before the public CSS moved into its
     * own versioned file, a restyle reached an installed app only if a network
     * fetch happened to succeed first.
     *
     * Nowdoc, so nothing in the JavaScript is interpolated by accident; the one
     * value that does vary is substituted deliberately.
     */
    private function serviceWorkerJs(): string
    {
        $js = <<<'JS'
        const EVENTCREW_CACHE = '%s';
        self.addEventListener('install', function () { self.skipWaiting(); });
        self.addEventListener('activate', function (e) {
            // Drop the shells of previous versions rather than accumulating one
            // per release in the visitor's storage quota.
            e.waitUntil(
                caches.keys().then(function (keys) {
                    return Promise.all(keys.map(function (k) {
                        return (k.indexOf('eventcrew-shell-') === 0 && k !== EVENTCREW_CACHE)
                            ? caches.delete(k)
                            : null;
                    }));
                }).then(function () { return self.clients.claim(); })
            );
        });
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

        return sprintf($js, 'eventcrew-shell-' . EVENTCREW_VERSION) . PushServiceWorker::js();
    }
}
