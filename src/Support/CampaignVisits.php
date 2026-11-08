<?php

declare(strict_types=1);

namespace EventCrew\Support;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Counts the page views that come in from a campaign link (Owner, Q386),
 * for the monthly report: a front-end page whose address carries
 * utm_campaign=<name>, or the short ?c=<name>, adds one to that campaign
 * and to the month's total (SiteStats). No cookie and nothing about the
 * visitor is kept, so the same person opening the link twice counts twice.
 *
 * The count is made from the browser, not while the page is built: the page
 * cache (Surge) serves cached pages without running PHP and strips utm_*
 * from the request before WordPress sees it. So every front-end page ends
 * with the same tiny script (script()): when the address in the browser has
 * one of PARAMS it sends the name, and only the name, with
 * navigator.sendBeacon to POST /wp-json/eventcrew/v1/hit (hit()), which
 * cleans the name and counts it. The script holds nothing that differs
 * between visitors, so a cached copy of the page is right for everyone; it
 * uses no cookie and no browser storage.
 *
 * Not counted: signed-in editors and administrators (the script is left
 * out of their pages, which the cache does not serve), a browser fetching
 * ahead (prefetch), and robots by their user agent (SiteStats::isBot()).
 */
final class CampaignVisits
{
    /** The campaign parameters, the standard one first. */
    public const PARAMS = ['utm_campaign', 'c'];

    /** The REST namespace and route the script sends to. */
    public const ROUTE_NAMESPACE = 'eventcrew/v1';
    public const ROUTE = '/hit';

    public static function boot(): void
    {
        add_action('wp_footer', [self::class, 'printScript'], 99);
        add_action('rest_api_init', [self::class, 'registerRoute']);
    }

    public static function registerRoute(): void
    {
        register_rest_route(
            self::ROUTE_NAMESPACE,
            self::ROUTE,
            [
                'methods' => 'POST',
                'callback' => [self::class, 'hit'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /** The wp_footer hook: the script, except on an editor's own pages. */
    public static function printScript(): void
    {
        if (current_user_can('edit_posts')) {
            return;
        }

        // Built from a literal and a JSON-encoded URL in script().
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<script>' . self::script() . "</script>\n";
    }

    /**
     * The script, the same for every visitor: reads the campaign from the
     * browser's address and sends it once with sendBeacon.
     */
    public static function script(): string
    {
        $url = (string) wp_json_encode(rest_url(self::ROUTE_NAMESPACE . self::ROUTE), JSON_UNESCAPED_SLASHES);
        $names = (string) wp_json_encode(self::PARAMS);

        return '(function(){var q=new URLSearchParams(location.search),c="";'
            . $names . '.some(function(p){return c=q.get(p)||""});'
            . 'if(c&&navigator.sendBeacon){navigator.sendBeacon(' . $url
            . ',new URLSearchParams({campaign:c.slice(0,100)}))}})();';
    }

    /**
     * The REST callback: counts the campaign the script sent, when it is a
     * name and the request comes from a browser. Answers 204 either way, so
     * the answer tells a caller nothing.
     */
    public static function hit(WP_REST_Request $request): WP_REST_Response
    {
        $raw = $request->get_param('campaign');
        $campaign = is_string($raw) ? SiteStats::campaignName(sanitize_text_field(wp_unslash($raw))) : '';

        if ('' !== $campaign && self::countable()) {
            SiteStats::addVisit($campaign);
        }

        $response = new WP_REST_Response(null, 204);
        $response->header('Cache-Control', 'no-cache, must-revalidate, max-age=0');

        return $response;
    }

    /** Whether the beacon came from a visitor's browser, not a prefetch or a robot. */
    private static function countable(): bool
    {
        $agent = sanitize_text_field(wp_unslash((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')));

        return ! EmailClicks::isPrefetch() && ! SiteStats::isBot($agent);
    }
}
