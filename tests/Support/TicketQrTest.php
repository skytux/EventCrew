<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\TicketQr;
use EventCrew\Tests\TestCase;

/**
 * The ticket email's QR code drawn as tables: level H, the logo square in
 * the middle, and colours that dark mode leaves alone. The tables are read
 * back cell by cell through the DOM.
 */
final class TicketQrTest extends TestCase
{
    private const LOGO = 'https://site.test/wp-content/uploads/icon-180x180.png';

    private const CELL = 6;

    private const BLACK = 'background-color:#000;background-image:linear-gradient(#000000,#000000)';

    private const WHITE = 'background-color:#fff;background-image:linear-gradient(#ffffff,#ffffff)';

    /** A guest's code: lower-case hex, so byte mode and the biggest code a ticket makes. */
    private const DATA = 'EC-G123456-0123456789abcdef';

    /**
     * Reads the drawn tables back into a grid of modules, quiet zone
     * included: true black, false white, null the logo square. Checks on the
     * way that every row table is exactly as wide as its runs add up to.
     *
     * @return array{grid: list<list<bool|null>>, logo: list<\DOMElement>, square: int}
     */
    private function readBack(string $html): array
    {
        $doc = new \DOMDocument();
        self::assertTrue($doc->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR));
        $xpath = new \DOMXPath($doc);
        $outer = $xpath->query('//body/table')->item(0);
        self::assertInstanceOf(\DOMElement::class, $outer);
        $width = (int) $outer->getAttribute('width');
        $grid = [];
        $logo = [];
        $square = 0;

        foreach ($xpath->query('./tr/td/table', $outer) as $inner) {
            self::assertInstanceOf(\DOMElement::class, $inner);
            self::assertSame($width, (int) $inner->getAttribute('width'));
            $tds = $xpath->query('./tr/td', $inner);

            if (3 === $tds->length && $xpath->query('.//img', $tds->item(1))->length > 0) {
                [$left, $middle, $right] = [$tds->item(0), $tds->item(1), $tds->item(2)];
                self::assertInstanceOf(\DOMElement::class, $left);
                self::assertInstanceOf(\DOMElement::class, $middle);
                self::assertInstanceOf(\DOMElement::class, $right);
                $square = (int) $middle->getAttribute('width');
                self::assertSame($square, (int) $middle->getAttribute('height'));
                self::assertSame('#ffffff', $middle->getAttribute('bgcolor'));
                self::assertStringContainsString(self::WHITE, $middle->getAttribute('style'));
                self::assertSame(
                    $width,
                    (int) $left->getAttribute('width') + $square + (int) $right->getAttribute('width'),
                    'the three parts of the middle band add up to the full width'
                );

                $lefts = $this->partRows($xpath, $left);
                $rights = $this->partRows($xpath, $right);
                self::assertCount(intdiv($square, self::CELL), $lefts);
                self::assertCount(count($lefts), $rights);

                foreach ($lefts as $i => $row) {
                    $grid[] = array_merge($row, array_fill(0, intdiv($square, self::CELL), null), $rights[$i]);
                }

                foreach ($xpath->query('.//img', $middle) as $img) {
                    $logo[] = $img;
                }

                continue;
            }

            [$height, $modules] = $this->runRow($xpath, $inner);

            for ($i = 0; $i < intdiv($height, self::CELL); $i++) {
                $grid[] = $modules;
            }
        }

        return ['grid' => $grid, 'logo' => $logo, 'square' => $square];
    }

    /**
     * The module rows of one side of the middle band: a table of rows, each
     * holding a row table one module high.
     *
     * @return list<list<bool>>
     */
    private function partRows(\DOMXPath $xpath, \DOMElement $part): array
    {
        $rows = [];
        $outer = $xpath->query('./table', $part)->item(0);
        self::assertInstanceOf(\DOMElement::class, $outer);
        self::assertSame($part->getAttribute('width'), $outer->getAttribute('width'));

        foreach ($xpath->query('./tr/td/table', $outer) as $inner) {
            self::assertInstanceOf(\DOMElement::class, $inner);
            self::assertSame($part->getAttribute('width'), $inner->getAttribute('width'));
            [$height, $modules] = $this->runRow($xpath, $inner);
            self::assertSame(self::CELL, $height);
            $rows[] = $modules;
        }

        return $rows;
    }

    /**
     * One row table: its height and its modules. Its runs add up to its
     * width, it is white three times over, and every black run is too.
     *
     * @return array{0: int, 1: list<bool>}
     */
    private function runRow(\DOMXPath $xpath, \DOMElement $table): array
    {
        self::assertSame('#ffffff', $table->getAttribute('bgcolor'));
        self::assertStringContainsString(self::WHITE, $table->getAttribute('style'));
        $modules = [];
        $sum = 0;
        $height = null;

        foreach ($xpath->query('./tr/td', $table) as $td) {
            self::assertInstanceOf(\DOMElement::class, $td);
            $px = (int) $td->getAttribute('width');
            $height ??= (int) $td->getAttribute('height');
            self::assertSame($height, (int) $td->getAttribute('height'));
            self::assertSame(0, $px % self::CELL);
            self::assertStringContainsString('font-size:0;line-height:0', $td->getAttribute('style'));
            $dark = '#000000' === $td->getAttribute('bgcolor');

            if ($dark) {
                self::assertStringContainsString(self::BLACK, $td->getAttribute('style'));
            } else {
                self::assertSame('', $td->getAttribute('bgcolor'), 'a white cell shows its row table');
            }

            $sum += $px;
            $modules = array_merge($modules, array_fill(0, intdiv($px, self::CELL), $dark));
        }

        self::assertSame((int) $table->getAttribute('width'), $sum, 'the runs add up to the row width');

        return [(int) $height, $modules];
    }

    /**
     * The code with the quiet zone round it, as the drawn grid should be.
     *
     * @param array<int, array<int, bool>> $matrix
     * @return list<list<bool|null>>
     */
    private static function expected(array $matrix, int $start = 0, int $side = 0): array
    {
        $count = count($matrix);
        $white = array_fill(0, $count + 8, false);
        $grid = array_fill(0, 4, $white);

        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $dark) {
                $inHole = $side > 0 && $y >= $start && $y < $start + $side && $x >= $start && $x < $start + $side;
                $row[$x] = $inHole ? null : $dark;
            }

            $grid[] = array_merge(array_fill(0, 4, false), array_values($row), array_fill(0, 4, false));
        }

        return array_merge($grid, array_fill(0, 4, $white));
    }

    /** The QR's error correction level from its format information. */
    private static function level(array $matrix): int
    {
        $n = count($matrix);

        // The two level bits at column 8 of the last two rows, mask 0b10
        // applied: L 01, M 00, Q 11, H 10.
        return (((int) $matrix[$n - 1][8] ^ 1) << 1) | (int) $matrix[$n - 2][8];
    }

    public function testTheCodeIsAtLevelH(): void
    {
        $qr = new TicketQr();

        self::assertSame(0b10, self::level($qr->matrix(self::DATA)));
        self::assertSame(0b10, self::level($qr->matrix('AB12CD1')));
        self::assertSame(0b10, self::level($qr->matrix('AB12CD1', 2)));
    }

    /**
     * With a logo: one image with that address, as big as the square less a
     * white edge, in a white cell as big as the square; every other module
     * drawn as the code has it, inside the white quiet zone.
     */
    public function testWithALogoTheMiddleSquareHoldsIt(): void
    {
        $qr = new TicketQr();
        $html = $qr->table(self::DATA, self::CELL, self::LOGO);
        $matrix = $qr->matrix(self::DATA, 2);
        [$start, $side] = TicketQr::hole(count($matrix));
        $read = $this->readBack($html);

        self::assertGreaterThan(0, $side);
        self::assertSame(1, substr_count($html, '<img'));
        self::assertCount(1, $read['logo']);
        self::assertSame(self::LOGO, $read['logo'][0]->getAttribute('src'));
        self::assertSame('', $read['logo'][0]->getAttribute('alt'));
        self::assertSame($side * self::CELL, $read['square']);
        self::assertSame((string) ($side * self::CELL - 6), $read['logo'][0]->getAttribute('width'));
        self::assertSame((string) ($side * self::CELL - 6), $read['logo'][0]->getAttribute('height'));
        self::assertStringContainsString('display:block', $read['logo'][0]->getAttribute('style'));
        self::assertSame(self::expected($matrix, $start, $side), $read['grid']);
    }

    /** A short code is drawn at least at version 2, so a square fits. */
    public function testAShortCodeWithALogoStillGetsASquare(): void
    {
        $qr = new TicketQr();
        $html = $qr->table('AB12CD1', self::CELL, self::LOGO);
        $matrix = $qr->matrix('AB12CD1', 2);
        [$start, $side] = TicketQr::hole(count($matrix));

        self::assertSame(21, count($qr->matrix('AB12CD1')));
        self::assertSame(25, count($matrix));
        self::assertSame([10, 5], [$start, $side]);
        self::assertSame(self::expected($matrix, $start, $side), $this->readBack($html)['grid']);
    }

    /** Without a logo: no image, no square, the whole code. */
    public function testWithoutALogoTheCodeIsWhole(): void
    {
        $qr = new TicketQr();
        $html = $qr->table(self::DATA, self::CELL, '');
        $read = $this->readBack($html);

        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('ec-qr-logo', $html);
        self::assertSame(0, $read['square']);
        self::assertSame(self::expected($qr->matrix(self::DATA)), $read['grid']);
    }

    /**
     * For every size a ticket code can make, the square is in the exact
     * middle, clear of the three finder patterns with their separators and
     * format information, and covers at most 8% of the modules (level H
     * restores up to 30%).
     */
    public function testTheSquareStaysClearOfTheFindersAndSmall(): void
    {
        for ($version = 2; $version <= 15; $version++) {
            $n = 4 * $version + 17;
            [$start, $side] = TicketQr::hole($n);
            $end = $start + $side - 1;

            self::assertGreaterThan(0, $side, "version {$version}");
            self::assertSame($n - 1 - $end, $start, "version {$version}: in the middle");
            self::assertLessThanOrEqual(0.08, $side * $side / ($n * $n), "version {$version}: small");

            // The finders with their separators: rows and columns 0-7 from
            // three corners; the format information runs along row and
            // column 8 beside them.
            $finders = [[0, 8, 0, 8], [0, 8, $n - 9, $n - 1], [$n - 9, $n - 1, 0, 8]];

            foreach ($finders as [$top, $bottom, $left, $right]) {
                $apart = $end < $top || $start > $bottom || $end < $left || $start > $right;
                self::assertTrue($apart, "version {$version}: clear of a finder");
            }
        }
    }

    /** The outer table is white three times over too. */
    public function testTheWholeCodeIsWhiteBehindForDarkMode(): void
    {
        $html = (new TicketQr())->table(self::DATA, self::CELL, self::LOGO);

        self::assertMatchesRegularExpression('/^<table role="presentation"[^>]*bgcolor="#ffffff"[^>]*'
            . preg_quote(self::WHITE, '/') . '/', $html);
        self::assertStringContainsString(self::BLACK, $html);
    }
}
