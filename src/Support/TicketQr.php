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
 * beside it). table() draws it as HTML tables of black and white cells.
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
     * The code as HTML tables, $cell pixels per module, which every email
     * client shows: no image, so nothing to attach and nothing to fetch.
     *
     * One outer table holds one row per module row. Each module row is its
     * own table, so its cells never have to line up with another row's
     * columns: a run of same-coloured modules is one cell $run * $cell pixels
     * wide, which keeps the markup small. Every cell has its size in pixels
     * and its colour twice (bgcolor for Outlook, background-color for the
     * rest), font-size and line-height 0 and no text, so nothing stretches it.
     * The white quiet zone of MARGIN modules is a white row above and below
     * and part of the first and last cell of every module row.
     */
    public function table(string $data, int $cell = 6): string
    {
        $matrix = $this->matrix($data);
        $width = (count($matrix) + 2 * self::MARGIN) * $cell;
        $quiet = self::MARGIN * $cell;

        $html = sprintf(
            '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="%1$d" align="center"'
                . ' bgcolor="#ffffff"'
                . ' style="border-collapse:collapse;margin:0 auto;width:%1$dpx;background-color:#ffffff">',
            $width
        ) . self::row([[$width, false]], $width, $quiet);

        foreach ($matrix as $modules) {
            $runs = [];
            $run = self::MARGIN;
            $last = false;

            foreach ($modules as $dark) {
                if ($dark === $last) {
                    $run++;
                    continue;
                }

                $runs[] = [$run * $cell, $last];
                $run = 1;
                $last = $dark;
            }

            $runs[] = [($last ? $run * $cell : ($run + self::MARGIN) * $cell), $last];

            if ($last) {
                $runs[] = [$quiet, false];
            }

            $html .= self::row($runs, $width, $cell);
        }

        return $html . self::row([[$width, false]], $width, $quiet) . '</table>';
    }

    /**
     * One row of the outer table: a table of $width pixels whose cells are
     * $runs, each [width in pixels, dark], all $height pixels high.
     *
     * @param list<array{0: int, 1: bool}> $runs
     */
    private static function row(array $runs, int $width, int $height): string
    {
        $zero = 'padding:0;font-size:0;line-height:0;mso-line-height-rule:exactly';
        $html = sprintf(
            '<tr><td style="%2$s"><table cellpadding="0" cellspacing="0" border="0"'
                . ' width="%1$d" style="border-collapse:collapse"><tr>',
            $width,
            $zero
        );

        foreach ($runs as [$px, $dark]) {
            $html .= sprintf(
                '<td width="%1$d" height="%2$d" bgcolor="%3$s"'
                    . ' style="background-color:%4$s;font-size:0;line-height:0"></td>',
                $px,
                $height,
                $dark ? '#000000' : '#ffffff',
                $dark ? '#000' : '#fff'
            );
        }

        return $html . '</tr></table></td></tr>';
    }

    private static function load(): void
    {
        if (! class_exists(QRCode::class, false)) {
            require_once dirname(__DIR__, 2) . '/assets/vendor/qrcode-generator/qrcode.php';
        }
    }
}
