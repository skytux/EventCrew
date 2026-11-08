<?php

declare(strict_types=1);

namespace EventCrew\Web;

/**
 * The website's own banner at the top of the Crew app page (Owner
 * 2026-11-08), which has no theme header of its own: the site logo on the
 * picture the website's pages open with.
 *
 * Read from WordPress each time, so it follows the site: the logo is the
 * site logo (Appearance, the custom logo; the site name when there is none)
 * and the picture is the first cover block of the theme's page template
 * (looking into its template parts and synced patterns), with that block's
 * focal point. A classic theme's header image stands in when there is no
 * such block; with neither, the logo sits on the app colour.
 */
final class SiteBanner
{
    /** Template parts and synced patterns looked into, at most this deep. */
    private const MAX_DEPTH = 4;

    /**
     * The banner's HTML, the logo linking to $homeUrl.
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

        $picture = self::pictureHtml();

        return '<header class="eventcrew-site-banner' . ('' !== $picture ? ' has-picture' : '') . '">'
            . $picture
            . '<a class="eventcrew-site-banner-home" href="' . esc_url($homeUrl) . '" rel="home">'
            . '<span class="eventcrew-site-banner-plate">' . $logo . '</span>'
            . '</a>'
            . '</header>';
    }

    /**
     * The picture behind the logo as an <img>, or '' when the site has none.
     */
    private static function pictureHtml(): string
    {
        $cover = self::cover();

        if (null !== $cover) {
            $position = sprintf('object-position:%s%% %s%%', self::percent($cover['x']), self::percent($cover['y']));

            if ($cover['id'] > 0) {
                $img = (string) wp_get_attachment_image($cover['id'], 'full', false, [
                    'class' => 'eventcrew-site-banner-picture',
                    'alt' => '',
                    'sizes' => '100vw',
                    'loading' => 'eager',
                    'decoding' => 'async',
                    'style' => $position,
                ]);

                if ('' !== $img) {
                    return $img;
                }
            }

            if ('' !== $cover['url']) {
                return self::img($cover['url'], $position);
            }
        }

        $header = function_exists('get_header_image') ? (string) get_header_image() : '';

        return '' === $header ? '' : self::img($header, 'object-position:50% 50%');
    }

    private static function img(string $url, string $style): string
    {
        return '<img class="eventcrew-site-banner-picture" src="' . esc_url($url) . '" alt=""'
            . ' loading="eager" decoding="async" style="' . esc_attr($style) . '">';
    }

    private static function percent(float $unit): string
    {
        return (string) round($unit * 100, 2);
    }

    /**
     * The first cover block with a picture in the theme's page template: its
     * attachment id (0 for a picture by address only), address and focal
     * point; null for a classic theme or a template without one.
     *
     * @return array{id: int, url: string, x: float, y: float}|null
     */
    public static function cover(): ?array
    {
        if (! function_exists('get_block_template') || ! function_exists('wp_is_block_theme')) {
            return null;
        }

        if (! wp_is_block_theme()) {
            return null;
        }

        $template = get_block_template(get_stylesheet() . '//page', 'wp_template');

        if (! is_object($template) || ! isset($template->content)) {
            return null;
        }

        return self::findCover(parse_blocks((string) $template->content), 0);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array{id: int, url: string, x: float, y: float}|null
     */
    private static function findCover(array $blocks, int $depth): ?array
    {
        foreach ($blocks as $block) {
            $name = (string) ($block['blockName'] ?? '');
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];

            if ('core/cover' === $name) {
                $id = (int) ($attrs['id'] ?? 0);
                $url = (string) ($attrs['url'] ?? '');

                if ($id > 0 || '' !== $url) {
                    $focal = is_array($attrs['focalPoint'] ?? null) ? $attrs['focalPoint'] : [];

                    return [
                        'id' => $id,
                        'url' => $url,
                        'x' => self::unit($focal['x'] ?? 0.5),
                        'y' => self::unit($focal['y'] ?? 0.5),
                    ];
                }
            }

            $inner = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];

            if ($depth < self::MAX_DEPTH) {
                $inner = array_merge(self::referenced($name, $attrs), $inner);
            }

            $found = [] === $inner ? null : self::findCover($inner, $depth + 1);

            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The blocks a template part or a synced pattern stands for.
     *
     * @param array<string, mixed> $attrs
     * @return array<int, array<string, mixed>>
     */
    private static function referenced(string $name, array $attrs): array
    {
        if ('core/template-part' === $name && '' !== (string) ($attrs['slug'] ?? '')) {
            $theme = (string) ($attrs['theme'] ?? get_stylesheet());
            $part = get_block_template($theme . '//' . (string) $attrs['slug'], 'wp_template_part');

            return is_object($part) && isset($part->content) ? parse_blocks((string) $part->content) : [];
        }

        if ('core/block' === $name && (int) ($attrs['ref'] ?? 0) > 0) {
            $post = get_post((int) $attrs['ref']);

            return $post instanceof \WP_Post && 'publish' === $post->post_status
                ? parse_blocks((string) $post->post_content)
                : [];
        }

        return [];
    }

    private static function unit(mixed $value): float
    {
        return is_numeric($value) ? min(1.0, max(0.0, (float) $value)) : 0.5;
    }
}
