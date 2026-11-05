<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The language of the website the visitor is reading, for the crew page.
 *
 * On a site translated with Polylang, every page has a language. A link from
 * a page to the crew page carries that language as a marker in the address
 * (?ec_lang=fi, see Web\CrewAppPage::markCrewLinks()). The crew page shows that
 * language and keeps it in a cookie that lasts until the browser is closed, so
 * later visits in the same session stay in it. Only without a marker or that
 * cookie does the browser's (phone's) language decide.
 *
 * Without Polylang every answer here is "not known" (null) and the page
 * links are WordPress's own.
 */
final class PageLanguage
{
    /** The query argument a link to the crew page carries: a language code. */
    public const MARKER = 'ec_lang';

    /** The session cookie that keeps the marked language. */
    public const COOKIE = 'eventcrew_lang';

    /**
     * The language of the page WordPress is showing, from Polylang: a site
     * language or English, or null without Polylang (or for a language the
     * plugin does not have).
     */
    public static function ofSite(): ?string
    {
        if (! function_exists('pll_current_language')) {
            return null;
        }

        return Language::normalize(pll_current_language('slug'));
    }

    /**
     * The language the visitor came with: the marker in this request's
     * address, otherwise the one kept in the session cookie. Null when
     * neither holds a site language or English.
     */
    public static function marked(): ?string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- a display choice, not an action.
        $marker = isset($_GET[self::MARKER]) ? Language::normalize(wp_unslash($_GET[self::MARKER])) : null;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if (null !== $marker) {
            return $marker;
        }

        return isset($_COOKIE[self::COOKIE]) ? Language::normalize(wp_unslash($_COOKIE[self::COOKIE])) : null;
    }

    /**
     * Keeps the marker of this request's address in the session cookie, so
     * the next page in this browser session is in the same language. Run
     * before any output (template_redirect). Returns the language kept, or
     * null when the address has no valid marker.
     */
    public static function remember(): ?string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- a display choice, not an action.
        $marker = isset($_GET[self::MARKER]) ? Language::normalize(wp_unslash($_GET[self::MARKER])) : null;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if (null === $marker) {
            return null;
        }

        if (! headers_sent()) {
            // No expiry: the cookie ends with the browser session.
            setcookie(self::COOKIE, $marker, [
                'path' => '/',
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        $_COOKIE[self::COOKIE] = $marker;

        return $marker;
    }

    /**
     * $href with the marker for $language added, before any #fragment. $href
     * is an attribute value as it stands in HTML, so the separator is &amp;.
     * An address that already has a marker is returned as it is.
     */
    public static function markHref(string $href, string $language): string
    {
        if (str_contains($href, self::MARKER . '=')) {
            return $href;
        }

        $hash = strpos($href, '#');
        $fragment = false === $hash ? '' : substr($href, $hash);
        $base = false === $hash ? $href : substr($href, 0, $hash);
        $separator = str_contains($base, '?') ? '&amp;' : '?';

        return $base . $separator . self::MARKER . '=' . rawurlencode($language) . $fragment;
    }

    /** The website's home page in $language (Polylang), else the home page. */
    public static function homeUrl(?string $language): string
    {
        if (null !== $language && function_exists('pll_home_url')) {
            $url = pll_home_url($language);

            if (is_string($url) && '' !== $url) {
                return $url;
            }
        }

        return home_url('/');
    }

    /**
     * The page that is $pageId's translation into $language (Polylang), when
     * there is one and it is published; else $pageId itself.
     */
    public static function translatedPage(int $pageId, ?string $language): int
    {
        if ($pageId <= 0 || null === $language || ! function_exists('pll_get_post')) {
            return $pageId;
        }

        $translated = (int) pll_get_post($pageId, $language);

        return $translated > 0 && 'publish' === get_post_status($translated) ? $translated : $pageId;
    }
}
