<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * Draws a plain app icon - a coloured square with the app's initials - for
 * installs where the site has set no Site Icon of its own.
 *
 * Generated rather than shipped so there is no binary asset to maintain and the
 * colour follows the configured theme. Needs the GD extension; when it is
 * absent png() returns '' and the caller falls back (to the Site Icon, or a
 * blank pixel), so a GD-less host degrades instead of erroring.
 */
final class AppIcon
{
    public static function available(): bool
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagepng');
    }

    /**
     * PNG bytes for a $size x $size icon, or '' when GD is unavailable.
     */
    public static function png(int $size, string $text, string $background): string
    {
        if (! self::available()) {
            return '';
        }

        $size = max(16, $size);
        $image = imagecreatetruecolor($size, $size);

        [$r, $g, $b] = self::rgb($background);
        $bg = imagecolorallocate($image, $r, $g, $b);
        $fg = imagecolorallocate($image, 255, 255, 255);

        imagefilledrectangle($image, 0, 0, $size, $size, $bg);

        $initials = self::initials($text);

        // The built-in font 5 is the largest bundled glyph set; scaling it up
        // keeps this dependency-free (no TrueType font file to ship).
        $scale = max(1, (int) round($size / 24));
        $glyphW = imagefontwidth(5) * $scale;
        $glyphH = imagefontheight(5) * $scale;
        $textW = $glyphW * strlen($initials);

        $x = (int) (($size - $textW) / 2);
        $y = (int) (($size - $glyphH) / 2);

        self::drawScaled($image, $initials, $x, $y, $scale, $fg, $bg);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        imagedestroy($image);

        return $bytes;
    }

    private static function initials(string $text): string
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $initials = '';

        foreach ($words as $word) {
            if ('' !== $word) {
                $initials .= strtoupper($word[0]);
            }

            if (2 === strlen($initials)) {
                break;
            }
        }

        return '' === $initials ? 'EC' : $initials;
    }

    /**
     * Renders the built-in font at an integer scale by drawing each glyph onto
     * a small buffer and copy-resizing it, since GD's imagestring is fixed-size.
     *
     * @param \GdImage $image
     */
    private static function drawScaled(
        \GdImage $image,
        string $text,
        int $x,
        int $y,
        int $scale,
        int $fg,
        int $bg
    ): void {
        $cellW = imagefontwidth(5);
        $cellH = imagefontheight(5);

        for ($i = 0; $i < strlen($text); $i++) {
            $glyph = imagecreatetruecolor($cellW, $cellH);
            imagefilledrectangle($glyph, 0, 0, $cellW, $cellH, $bg);
            imagestring($glyph, 5, 0, 0, $text[$i], $fg);

            imagecopyresized(
                $image,
                $glyph,
                $x + $i * $cellW * $scale,
                $y,
                0,
                0,
                $cellW * $scale,
                $cellH * $scale,
                $cellW,
                $cellH
            );

            imagedestroy($glyph);
        }
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function rgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        if (3 === strlen($hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (6 !== strlen($hex) || 1 !== preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = '4A6CF7';
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
