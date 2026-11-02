<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Vendor\QrCodeGenerator\QRCode;

use const EventCrew\Vendor\QrCodeGenerator\QR_ERROR_CORRECT_LEVEL_M;

/**
 * A QR code drawn on the server, for the ticket email: an email cannot run
 * the script the web ticket page draws its code with.
 *
 * The code itself comes from the bundled PHP copy of qrcode-generator
 * (assets/vendor/qrcode-generator/qrcode.php, by the author of the qrcode.js
 * beside it). png() needs PHP's GD extension; without it, table() draws the
 * same code as an HTML table of black and white cells, which every email
 * client shows.
 */
class TicketQr
{
    /** Quiet zone around the code, in modules. The QR standard asks for 4. */
    private const MARGIN = 4;

    /**
     * The code's modules, row by row: true is a dark module.
     *
     * @return array<int, array<int, bool>>
     */
    public function matrix(string $data): array
    {
        self::load();

        $qr = QRCode::getMinimumQRCode($data, QR_ERROR_CORRECT_LEVEL_M);
        $count = $qr->getModuleCount();
        $rows = [];

        for ($row = 0; $row < $count; $row++) {
            for ($col = 0; $col < $count; $col++) {
                $rows[$row][$col] = (bool) $qr->isDark($row, $col);
            }
        }

        return $rows;
    }

    /**
     * The code as PNG bytes, $scale pixels per module, or null when GD is not
     * installed.
     */
    public function png(string $data, int $scale = 6): ?string
    {
        if (! function_exists('imagecreate') || ! function_exists('imagepng')) {
            return null;
        }

        $matrix = $this->matrix($data);
        $size = (count($matrix) + 2 * self::MARGIN) * $scale;
        $image = imagecreate($size, $size);

        if (false === $image) {
            return null;
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);

        if (false === $white || false === $black) {
            return null;
        }

        imagefill($image, 0, 0, $white);

        foreach ($matrix as $row => $cells) {
            foreach ($cells as $col => $dark) {
                if ($dark) {
                    $x = ($col + self::MARGIN) * $scale;
                    $y = ($row + self::MARGIN) * $scale;
                    imagefilledrectangle($image, $x, $y, $x + $scale - 1, $y + $scale - 1, $black);
                }
            }
        }

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        return '' === $png ? null : $png;
    }

    /**
     * The code as an HTML table, $cell pixels per module, for an email when
     * there is no PNG. Each row is one table row; a run of same-coloured
     * modules is one cell with a colspan, which keeps the markup small.
     */
    public function table(string $data, int $cell = 4): string
    {
        $matrix = $this->matrix($data);
        $width = count($matrix) + 2 * self::MARGIN;
        $blank = array_fill(0, $width, false);
        $rows = array_merge(
            array_fill(0, self::MARGIN, $blank),
            array_map(
                static fn (array $cells): array => array_merge(
                    array_fill(0, self::MARGIN, false),
                    $cells,
                    array_fill(0, self::MARGIN, false)
                ),
                $matrix
            ),
            array_fill(0, self::MARGIN, $blank)
        );

        $html = sprintf(
            '<table role="presentation" cellpadding="0" cellspacing="0" border="0"'
                . ' style="border-collapse:collapse;margin:0 auto;background:#ffffff" width="%1$d">',
            $width * $cell
        );

        foreach ($rows as $cells) {
            $html .= '<tr>';
            $run = 0;

            foreach ($cells as $i => $dark) {
                $run++;

                if (isset($cells[$i + 1]) && $cells[$i + 1] === $dark) {
                    continue;
                }

                $html .= sprintf(
                    '<td%1$s style="width:%2$dpx;height:%3$dpx;padding:0;background:%4$s"></td>',
                    $run > 1 ? ' colspan="' . $run . '"' : '',
                    $run * $cell,
                    $cell,
                    $dark ? '#000000' : '#ffffff'
                );
                $run = 0;
            }

            $html .= '</tr>';
        }

        return $html . '</table>';
    }

    private static function load(): void
    {
        if (! class_exists(QRCode::class, false)) {
            require_once dirname(__DIR__, 2) . '/assets/vendor/qrcode-generator/qrcode.php';
        }
    }
}
