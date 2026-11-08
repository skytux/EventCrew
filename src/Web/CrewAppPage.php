<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Support\PageLanguage;

/**
 * The Crew app page (the page set as the signup page) shown as a page of its
 * own: the full browser width, without the theme's header, menu and footer,
 * with the website's banner (SiteBanner) and a "← Website" link at the top.
 * Its background is the app colour (Settings, App), with white or black
 * text, whichever reads better on it.
 *
 * Also sends the old address of a page whose address was changed to its new
 * one. WordPress does this for posts but not for pages, and the Crew app's
 * address is in emails already sent.
 *
 * And on a site translated with Polylang, links from a website page to the
 * Crew app carry that page's language (Support\PageLanguage), so the Crew app
 * opens in the language the visitor was reading.
 */
final class CrewAppPage
{
    /** '1' (the default) shows the Crew app as its own page; '0' inside the theme. */
    public const STANDALONE_OPTION = 'eventcrew_crew_app_standalone';

    /** Post meta on a page: one row per address (slug) the page had before. */
    public const OLD_SLUG_META = '_eventcrew_old_slug';

    /** Set when templateFor() picks the page file, which reads it. */
    private static ?self $current = null;

    /** @var list<string>|null See crewPaths(). */
    private ?array $crewPaths = null;

    public function __construct(
        private readonly SignupController $signup,
        private readonly PwaController $pwa
    ) {
    }

    public function boot(): void
    {
        add_filter('template_include', [$this, 'templateFor'], 99);
        add_action('post_updated', [$this, 'rememberOldSlug'], 10, 3);
        add_action('template_redirect', [$this, 'redirectOldSlug'], 1);
        add_action('template_redirect', [$this, 'rememberLanguage'], 2);
        add_filter('render_block', [$this, 'markCrewLinks'], 10, 1);
    }

    public static function isStandalone(): bool
    {
        return '0' !== (string) get_option(self::STANDALONE_OPTION, '1');
    }

    /** The template_include filter: the plugin's own page file on the Crew app page. */
    public function templateFor(string $template): string
    {
        if (! self::isStandalone() || ! $this->pwa->shouldInject()) {
            return $template;
        }

        self::$current = $this;

        return EVENTCREW_PLUGIN_DIR . 'templates/public/crew-app.php';
    }

    /** The instance that picked the page file, for templates/public/crew-app.php. */
    public static function current(): ?self
    {
        return self::$current;
    }

    /** The Crew app's content, rendered before wp_head() so its stylesheet lands in <head>. */
    public function content(): string
    {
        return $this->signup->renderShortcode();
    }

    /** The language the page is shown in; see SignupController::pageLanguage(). */
    public function language(): ?string
    {
        return $this->signup->pageLanguage();
    }

    /** The website's own banner at the top (SiteBanner), its logo linking home. */
    public function banner(): string
    {
        return SiteBanner::html($this->websiteUrl());
    }

    /** The "← Website" link: the home page in the language the page is shown in. */
    public function websiteUrl(): string
    {
        return PageLanguage::homeUrl($this->language());
    }

    /**
     * The template_redirect listener: a language marker in the address is
     * kept for the browser session. The Crew app page in a marked language
     * is not stored by page caches, since its address alone does not say
     * which language it is in.
     */
    public function rememberLanguage(): void
    {
        PageLanguage::remember();

        if (! $this->pwa->shouldInject() || null === PageLanguage::marked()) {
            return;
        }

        if (! defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        nocache_headers();
    }

    /**
     * The render_block filter: on a website page with a language (Polylang),
     * every link to the Crew app page - its address now or an old one - gets
     * that language as its marker. Not on the Crew app page itself, whose own
     * links keep the language it is shown in.
     */
    public function markCrewLinks(mixed $content): mixed
    {
        if (! is_string($content) || ! str_contains($content, 'href')) {
            return $content;
        }

        $language = PageLanguage::ofSite();

        if (null === $language || $this->pwa->shouldInject()) {
            return $content;
        }

        $paths = $this->crewPaths();

        if ([] === $paths) {
            return $content;
        }

        $host = (string) wp_parse_url((string) home_url('/'), PHP_URL_HOST);

        return (string) preg_replace_callback(
            '~(<a\b[^>]*?\shref=)(["\'])([^"\']*)\2~i',
            static function (array $match) use ($paths, $host, $language): string {
                $url = html_entity_decode($match[3], ENT_QUOTES);
                $linkHost = (string) wp_parse_url($url, PHP_URL_HOST);
                $path = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');

                if (('' !== $linkHost && 0 !== strcasecmp($linkHost, $host)) || ! in_array($path, $paths, true)) {
                    return $match[0];
                }

                return $match[1] . $match[2] . PageLanguage::markHref($match[3], $language) . $match[2];
            },
            $content
        );
    }

    /**
     * The Crew app page's address paths without the slashes ("crew-app"),
     * now and before (OLD_SLUG_META), read once per request.
     *
     * @return list<string>
     */
    private function crewPaths(): array
    {
        if (null !== $this->crewPaths) {
            return $this->crewPaths;
        }

        $pageId = (int) get_option(PwaController::PAGE_OPTION, 0);
        $paths = [];

        if ($pageId > 0) {
            $url = get_permalink($pageId);

            if (is_string($url) && '' !== $url) {
                $paths[] = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');
            }

            foreach ((array) get_post_meta($pageId, self::OLD_SLUG_META) as $slug) {
                $paths[] = is_string($slug) ? $slug : '';
            }
        }

        $this->crewPaths = array_values(array_unique(array_filter($paths, static fn (string $p): bool => '' !== $p)));

        return $this->crewPaths;
    }

    /**
     * The page background and its text colour.
     *
     * @return array{background: string, text: string}
     */
    public function colors(): array
    {
        $background = $this->pwa->themeColor();

        return ['background' => $background, 'text' => self::isLight($background) ? '#111111' : '#ffffff'];
    }

    /** Whether a #rgb or #rrggbb colour is light enough to need black text. */
    public static function isLight(string $hex): bool
    {
        $hex = ltrim($hex, '#');

        if (3 === strlen($hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (1 !== preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return true;
        }

        [$r, $g, $b] = array_map('hexdec', str_split($hex, 2));

        // Perceived brightness (ITU-R BT.601 weights), 0 to 255.
        return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 150;
    }

    /**
     * The post_updated listener: keeps a published page's old address when
     * its address changes.
     */
    public function rememberOldSlug(int $postId, object $after, object $before): void
    {
        if ('page' !== ($after->post_type ?? '') || 'publish' !== ($before->post_status ?? '')) {
            return;
        }

        $old = (string) ($before->post_name ?? '');

        if ('' === $old || $old === ($after->post_name ?? '')) {
            return;
        }

        if (! in_array($old, (array) get_post_meta($postId, self::OLD_SLUG_META), true)) {
            add_post_meta($postId, self::OLD_SLUG_META, $old);
        }
    }

    /**
     * The template_redirect listener: a page address that is not found but
     * was a page's old address goes to that page, with a permanent redirect.
     */
    public function redirectOldSlug(): void
    {
        if (! is_404()) {
            return;
        }

        $target = $this->oldSlugTarget((string) ($_SERVER['REQUEST_URI'] ?? ''));

        if (null !== $target) {
            wp_safe_redirect($target, 301);
            exit;
        }
    }

    /**
     * The new address for a request to a page's old address, with the
     * request's query string kept (it carries the sign-in token of an old
     * email link); null when no page had that address.
     */
    public function oldSlugTarget(string $requestUri): ?string
    {
        $path = trim((string) wp_parse_url($requestUri, PHP_URL_PATH), '/');
        $slug = sanitize_title(basename($path));

        if ('' === $slug) {
            return null;
        }

        $ids = get_posts([
            'post_type' => 'page',
            'post_status' => 'publish',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key -- only on a 404.
            'meta_key' => self::OLD_SLUG_META,
            'meta_value' => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value -- only on a 404.
            'fields' => 'ids',
            'numberposts' => 1,
        ]);

        if ([] === $ids) {
            return null;
        }

        $url = (string) get_permalink((int) $ids[0]);
        $query = (string) wp_parse_url($requestUri, PHP_URL_QUERY);

        return '' === $query ? $url : $url . (str_contains($url, '?') ? '&' : '?') . $query;
    }
}
