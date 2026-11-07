<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

/**
 * The banner above the Telegram board (Owner 2026-11-07, Q361): a wide strip
 * cut from the picture of a chosen page (the Crew membership page), shown as
 * the board message's large link preview, so the board stays a text message
 * that can be edited in place.
 *
 * The picture is the page's first cover block (its focal point decides which
 * strip is kept), or the page's featured image. The strip is made once and
 * kept in uploads/eventcrew/; a new one is made when the page's picture, its
 * focal point or the file changes, and the old one is removed.
 */
final class BoardBanner
{
    public const PAGE_OPTION = 'eventcrew_board_banner_page';

    /** Width and height of the strip, 3:1. */
    public const WIDTH = 1280;
    public const HEIGHT = 427;

    private const DIR = 'eventcrew';
    private const PREFIX = 'board-banner-';

    public static function pageId(): int
    {
        return max(0, (int) get_option(self::PAGE_OPTION, 0));
    }

    /**
     * The banner's URL, or '' when no page is chosen, the page has no
     * picture, or the strip cannot be made.
     */
    public static function url(): string
    {
        $pageId = self::pageId();
        $source = 0 === $pageId ? null : self::source($pageId);

        return null === $source ? '' : self::strip($source['id'], $source['x'], $source['y']);
    }

    /**
     * The page's picture: its first cover block with an image (and that
     * block's focal point), else its featured image, centred.
     *
     * @return array{id: int, x: float, y: float}|null
     */
    public static function source(int $pageId): ?array
    {
        $post = get_post($pageId);

        if (! $post instanceof \WP_Post || 'publish' !== $post->post_status) {
            return null;
        }

        $cover = self::findCover(parse_blocks((string) $post->post_content));

        if (null !== $cover) {
            return $cover;
        }

        $thumb = (int) get_post_thumbnail_id($post);

        return $thumb > 0 ? ['id' => $thumb, 'x' => 0.5, 'y' => 0.5] : null;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array{id: int, x: float, y: float}|null
     */
    private static function findCover(array $blocks): ?array
    {
        foreach ($blocks as $block) {
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];

            if ('core/cover' === ($block['blockName'] ?? '') && (int) ($attrs['id'] ?? 0) > 0) {
                $focal = is_array($attrs['focalPoint'] ?? null) ? $attrs['focalPoint'] : [];

                return [
                    'id' => (int) $attrs['id'],
                    'x' => self::unit($focal['x'] ?? 0.5),
                    'y' => self::unit($focal['y'] ?? 0.5),
                ];
            }

            $inner = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
            $found = [] === $inner ? null : self::findCover($inner);

            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    private static function unit(mixed $value): float
    {
        return is_numeric($value) ? min(1.0, max(0.0, (float) $value)) : 0.5;
    }

    /**
     * The strip of attachment $id around the focal point ($x, $y), made when
     * missing.
     */
    private static function strip(int $id, float $x, float $y): string
    {
        $file = (string) get_attached_file($id);

        if ('' === $file || ! is_readable($file)) {
            return '';
        }

        $uploads = wp_upload_dir();

        if (! empty($uploads['error'])) {
            return '';
        }

        $dir = trailingslashit((string) $uploads['basedir']) . self::DIR;
        $key = substr(md5(implode('|', [$file, (string) filemtime($file), $x, $y, self::WIDTH, self::HEIGHT])), 0, 10);
        $name = self::PREFIX . $id . '-' . $key . '.jpg';
        $path = $dir . '/' . $name;
        $url = trailingslashit((string) $uploads['baseurl']) . self::DIR . '/' . $name;

        if (is_file($path)) {
            return $url;
        }

        if (! wp_mkdir_p($dir) || ! self::make($file, $path, $x, $y)) {
            return '';
        }

        foreach ((array) glob($dir . '/' . self::PREFIX . '*.jpg') as $old) {
            if (is_string($old) && $old !== $path) {
                wp_delete_file($old);
            }
        }

        return $url;
    }

    private static function make(string $file, string $path, float $x, float $y): bool
    {
        $editor = wp_get_image_editor($file);

        if (is_wp_error($editor)) {
            return false;
        }

        $size = $editor->get_size();
        $width = (int) ($size['width'] ?? 0);
        $height = (int) ($size['height'] ?? 0);

        if ($width <= 0 || $height <= 0) {
            return false;
        }

        $crop = self::cropBox($width, $height, $x, $y);
        $outWidth = min(self::WIDTH, $crop['w']);
        $outHeight = (int) round($outWidth * self::HEIGHT / self::WIDTH);

        if (is_wp_error($editor->crop($crop['x'], $crop['y'], $crop['w'], $crop['h'], $outWidth, $outHeight))) {
            return false;
        }

        $editor->set_quality(85);

        return ! is_wp_error($editor->save($path, 'image/jpeg'));
    }

    /**
     * The largest 3:1 box in a $width × $height picture, centred on the
     * focal point ($x, $y) as far as the edges allow.
     *
     * @return array{x: int, y: int, w: int, h: int}
     */
    public static function cropBox(int $width, int $height, float $x, float $y): array
    {
        $w = $width;
        $h = (int) round($width * self::HEIGHT / self::WIDTH);

        if ($h > $height) {
            $h = $height;
            $w = (int) round($height * self::WIDTH / self::HEIGHT);
        }

        return [
            'x' => (int) round(min(max($x * $width - $w / 2, 0), $width - $w)),
            'y' => (int) round(min(max($y * $height - $h / 2, 0), $height - $h)),
            'w' => $w,
            'h' => $h,
        ];
    }
}
