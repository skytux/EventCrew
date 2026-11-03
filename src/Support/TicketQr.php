<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Vendor\QrCodeGenerator\QRCode;

use const EventCrew\Vendor\QrCodeGenerator\QR_ERROR_CORRECT_LEVEL_H;

/**
 * A QR code drawn on the server, for the ticket email: an email cannot run
 * the script the web ticket page draws its code with.
 *
 * The code itself comes from the bundled PHP copy of qrcode-generator
 * (assets/vendor/qrcode-generator/qrcode.php, by the author of the qrcode.js
 * beside it), at error correction level H like the web ticket's code: up to
 * 30% of it can be lost and it still scans, so a square in the middle can
 * hold the site's logo. table() draws it as HTML tables of black and white
 * cells.
 */
class TicketQr
{
    /** Quiet zone around the code, in modules. The QR standard asks for 4. */
    private const MARGIN = 4;

    /** The logo square's side, as a share of the code's side. */
    private const HOLE_SHARE = 0.2;

    /** The most of the code's modules the logo square may cover. */
    private const HOLE_MAX_AREA = 0.07;

    /**
     * The first row and column free of the top left finder pattern (rows and
     * columns 0-6), its white separator (7) and the format information beside
     * it (8). The logo square starts at or after it on both axes, and by
     * symmetry ends as far from the other two finders.
     */
    private const HOLE_MIN_START = 9;

    /**
     * The smallest QR version drawn with a logo. Version 1 (21 modules) has
     * no room for a square of any useful size between the finders and their
     * format information; version 2 (25 modules) has room for 5 modules.
     */
    private const LOGO_MIN_VERSION = 2;

    /** Pixels of white between the logo and the code around it. */
    private const LOGO_PAD = 3;

    /**
     * The code's modules, row by row: true is a dark module. $minVersion
     * raises the QR version (and so the module count) for a short $data; what
     * the code holds stays the same.
     *
     * @return array<int, array<int, bool>>
     */
    public function matrix(string $data, int $minVersion = 1): array
    {
        self::load();

        $qr = QRCode::getMinimumQRCode($data, QR_ERROR_CORRECT_LEVEL_H);

        if ($qr->getTypeNumber() < $minVersion) {
            $qr->setTypeNumber($minVersion);
            $qr->make();
        }

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
     * The square left white for the logo in a code of $count modules a side:
     * [first row and column, side], in modules. The side is about HOLE_SHARE
     * of the code's side, odd like the module count so the square sits
     * exactly in the middle, and no bigger than HOLE_MAX_AREA of the modules
     * or than leaves HOLE_MIN_START free on every edge. [0, 0] when no square
     * fits.
     *
     * @return array{0: int, 1: int}
     */
    public static function hole(int $count): array
    {
        $side = (int) round($count * self::HOLE_SHARE);

        if ($side % 2 !== $count % 2) {
            $side++;
        }

        while (
            $side > 0
            && ($side * $side > self::HOLE_MAX_AREA * $count * $count
                || intdiv($count - $side, 2) < self::HOLE_MIN_START)
        ) {
            $side -= 2;
        }

        return $side > 0 ? [intdiv($count - $side, 2), $side] : [0, 0];
    }

    /**
     * The largest whole number of pixels per module, at least $least, at
     * which table() draws the code for $data (with or without a logo, as
     * $logo says) no wider than $width pixels, quiet zone included. A code
     * too large for $width at $least pixels is drawn at $least anyway.
     */
    public function fitCell(string $data, int $width, string $logo = '', int $least = 6): int
    {
        $count = count($this->matrix($data, '' === $logo ? 1 : self::LOGO_MIN_VERSION));

        return max($least, intdiv($width, $count + 2 * self::MARGIN));
    }

    /**
     * The code as HTML tables, $cell pixels per module, which every email
     * client shows: nothing attached and nothing written in as data. $logo,
     * when not '', is the URL of a square image drawn in the middle of the
     * code (the only thing fetched); with '' the code is drawn whole.
     *
     * One outer table holds one row per module row. Each module row is its
     * own table, so its cells never have to line up with another row's
     * columns: a run of same-coloured modules is one cell $run * $cell pixels
     * wide, which keeps the markup small. Every cell has its size in pixels,
     * font-size and line-height 0 and no text, so nothing stretches it.
     *
     * White is drawn once per row table, black once per black cell, each
     * three times over: bgcolor for Outlook, background-color for the rest,
     * and a one-colour background-image, which the mail apps that darken
     * colours in dark mode leave alone. A white cell has no colour of its
     * own and shows its row's white: giving every white cell the three as
     * well makes a long code's email about a third bigger, too close to the
     * size at which Gmail cuts a message short. The white quiet zone of
     * MARGIN modules is a white row above and below and part of the first and
     * last cell of every module row.
     *
     * With a logo, the module rows the logo square covers are one outer row
     * of three cells: a table of the rows' left parts, a white cell holding
     * the logo, and a table of the rows' right parts. The modules under the
     * square are left out; level H restores them.
     */
    public function table(string $data, int $cell = 6, string $logo = ''): string
    {
        $matrix = $this->matrix($data, '' === $logo ? 1 : self::LOGO_MIN_VERSION);
        $count = count($matrix);
        $width = ($count + 2 * self::MARGIN) * $cell;
        $quiet = self::MARGIN * $cell;
        [$start, $side] = '' === $logo ? [0, 0] : self::hole($count);
        $end = $start + $side;

        $html = sprintf(
            '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="%1$d" align="center"'
                . ' bgcolor="#ffffff"'
                . ' style="border-collapse:collapse;margin:0 auto;width:%1$dpx;%2$s">',
            $width,
            self::colour(false)
        ) . self::row([[$width, false]], $width, $quiet);

        foreach ($matrix as $y => $modules) {
            if ($side > 0 && $y >= $start && $y < $end) {
                if ($y === $start) {
                    $html .= $this->band($matrix, $start, $side, $cell, $logo);
                }

                continue;
            }

            $html .= self::row(self::runs($modules, self::MARGIN, self::MARGIN, $cell), $width, $cell);
        }

        return $html . self::row([[$width, false]], $width, $quiet) . '</table>';
    }

    /**
     * The outer row for the module rows the logo square covers: the left and
     * right parts of those rows, each part a table of one row per module row,
     * and between them a white cell of $side modules a side with the logo in
     * its middle, LOGO_PAD pixels in from each edge.
     *
     * @param array<int, array<int, bool>> $matrix
     */
    private function band(array $matrix, int $start, int $side, int $cell, string $logo): string
    {
        $count = count($matrix);
        $leftWidth = (self::MARGIN + $start) * $cell;
        $rightWidth = (self::MARGIN + $count - $start - $side) * $cell;
        $square = $side * $cell;
        $image = max(1, $square - 2 * self::LOGO_PAD);
        $left = '';
        $right = '';

        for ($y = $start; $y < $start + $side; $y++) {
            $left .= self::row(
                self::runs(array_slice($matrix[$y], 0, $start), self::MARGIN, 0, $cell),
                $leftWidth,
                $cell
            );
            $right .= self::row(
                self::runs(array_slice($matrix[$y], $start + $side), 0, self::MARGIN, $cell),
                $rightWidth,
                $cell
            );
        }

        $zero = 'padding:0;font-size:0;line-height:0;mso-line-height-rule:exactly';
        $part = static fn (int $px, string $rows): string => sprintf(
            '<td width="%1$d" valign="top" style="%2$s"><table cellpadding="0" cellspacing="0" border="0"'
                . ' width="%1$d" style="border-collapse:collapse">%3$s</table></td>',
            $px,
            $zero,
            $rows
        );

        return sprintf(
            '<tr><td style="%1$s"><table cellpadding="0" cellspacing="0" border="0" width="%2$d"'
                . ' style="border-collapse:collapse"><tr>',
            $zero,
            $leftWidth + $square + $rightWidth
        )
            . $part($leftWidth, $left)
            . sprintf(
                '<td class="ec-qr-logo" width="%1$d" height="%1$d" align="center" valign="middle" bgcolor="#ffffff"'
                    . ' style="%2$s;%3$s"><img src="%4$s" width="%5$d" height="%5$d" alt=""'
                    . ' style="display:block;margin:0 auto;width:%5$dpx;height:%5$dpx;border:0;outline:none"></td>',
                $square,
                $zero,
                self::colour(false),
                htmlspecialchars($logo, ENT_QUOTES, 'UTF-8'),
                $image
            )
            . $part($rightWidth, $right)
            . '</tr></table></td></tr>';
    }

    /**
     * $modules with $before and $after white modules around them, as runs of
     * one colour: [width in pixels, dark] each.
     *
     * @param array<int, bool> $modules
     * @return list<array{0: int, 1: bool}>
     */
    private static function runs(array $modules, int $before, int $after, int $cell): array
    {
        $all = array_merge(array_fill(0, $before, false), array_values($modules), array_fill(0, $after, false));
        $runs = [];
        $run = 0;
        $last = $all[0] ?? false;

        foreach ($all as $dark) {
            if ($dark === $last) {
                $run++;
                continue;
            }

            $runs[] = [$run * $cell, $last];
            $run = 1;
            $last = $dark;
        }

        if ($run > 0) {
            $runs[] = [$run * $cell, $last];
        }

        return $runs;
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
                . ' width="%1$d" bgcolor="#ffffff" style="border-collapse:collapse;%3$s"><tr>',
            $width,
            $zero,
            self::colour(false)
        );

        foreach ($runs as [$px, $dark]) {
            $html .= $dark
                ? sprintf(
                    '<td width="%1$d" height="%2$d" bgcolor="#000000" style="%3$s;font-size:0;line-height:0"></td>',
                    $px,
                    $height,
                    self::colour(true)
                )
                : sprintf('<td width="%1$d" height="%2$d" style="font-size:0;line-height:0"></td>', $px, $height);
        }

        return $html . '</tr></table></td></tr>';
    }

    /**
     * A cell's colour as CSS: the colour, and the same colour as a gradient
     * image, which Gmail's and Outlook's apps do not recolour in dark mode.
     */
    private static function colour(bool $dark): string
    {
        return $dark
            ? 'background-color:#000;background-image:linear-gradient(#000000,#000000)'
            : 'background-color:#fff;background-image:linear-gradient(#ffffff,#ffffff)';
    }

    private static function load(): void
    {
        if (! class_exists(QRCode::class, false)) {
            require_once dirname(__DIR__, 2) . '/assets/vendor/qrcode-generator/qrcode.php';
        }
    }
}
