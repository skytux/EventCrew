<?php

declare(strict_types=1);

namespace EventCrew\Web;

/**
 * The website's banner at the top of the Crew app page (Owner 2026-11-08):
 * the site logo alone on the page's own colour, linking to the website, with
 * no picture or box behind it (Owner 2026-11-08).
 *
 * Read from WordPress each time, so it follows the site: the custom logo
 * (Appearance), or the site name when there is none.
 */
final class SiteBanner
{
    /**
     * The banner's HTML, the logo linking to $homeUrl; '' when the site has
     * neither a logo nor a name.
     */
    public static function html(string $homeUrl): string
    {
        $name = (string) get_bloginfo('name');
        $logoId = (int) get_theme_mod('custom_logo');
        $logo = $logoId > 0
            ? (string) wp_get_attachment_image($logoId, 'full', false, [
                'class' => 'eventcrew-site-banner-logo',
                'alt' => $name,
                'loading' => 'eager',
                'decoding' => 'async',
            ])
            : '';

        if ('' === $logo) {
            if ('' === $name) {
                return '';
            }

            $logo = '<span class="eventcrew-site-banner-name">' . esc_html($name) . '</span>';
        }

        return '<header class="eventcrew-site-banner">'
            . '<a class="eventcrew-site-banner-home" href="' . esc_url($homeUrl) . '" rel="home">'
            . $logo
            . '</a>'
            . '</header>';
    }
}
