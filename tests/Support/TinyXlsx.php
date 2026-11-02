<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use ZipArchive;

/**
 * Builds a small .xlsx file in memory for the ticket file tests, so no binary
 * fixture with real buyers' data is ever checked in.
 *
 * Each cell is given by its reference ("A1", "C2"), so a test can leave
 * cells out. A value starting with "s:" is written as a shared string, any
 * other value as an inline string, and a number as a number.
 */
final class TinyXlsx
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /**
     * @param array<string, string|int> $cells reference => value
     * @param bool $secondSheetFirst Lists a decoy sheet2.xml as well, and makes the
     *                               workbook name sheet2 first, to prove the first
     *                               sheet is found through the workbook.
     */
    public static function build(array $cells, bool $secondSheetFirst = false): string
    {
        $shared = [];
        $rows = [];

        foreach ($cells as $ref => $value) {
            preg_match('/^([A-Z]+)(\d+)$/', $ref, $match);
            $row = (int) $match[2];

            if (is_int($value)) {
                $xml = '<c r="' . $ref . '"><v>' . $value . '</v></c>';
            } elseif (str_starts_with($value, 's:')) {
                $shared[] = substr($value, 2);
                $xml = '<c r="' . $ref . '" t="s"><v>' . (count($shared) - 1) . '</v></c>';
            } else {
                $xml = '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars($value, ENT_XML1) . '</t></is></c>';
            }

            $rows[$row][] = $xml;
        }

        ksort($rows);
        $sheetData = '';

        foreach ($rows as $number => $cellsXml) {
            $sheetData .= '<row r="' . $number . '">' . implode('', $cellsXml) . '</row>';
        }

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="' . self::NS . '"><sheetData>' . $sheetData . '</sheetData></worksheet>';

        $sharedXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="' . self::NS . '">';

        foreach ($shared as $text) {
            $sharedXml .= '<si><t>' . htmlspecialchars($text, ENT_XML1) . '</t></si>';
        }

        $sharedXml .= '</sst>';

        $decoy = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="' . self::NS . '"><sheetData><row r="1">'
            . '<c r="A1" t="inlineStr"><is><t>Decoy</t></is></c></row></sheetData></worksheet>';

        $relNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="' . self::NS . '" xmlns:r="' . $relNs . '"><sheets>'
            . '<sheet name="Tickets" sheetId="1" r:id="rId1"/>'
            . '</sheets></workbook>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $relNs . '/worksheet" Target="worksheets/sheet'
            . ($secondSheetFirst ? '2' : '1') . '.xml"/>'
            . '</Relationships>';

        $path = (string) tempnam(sys_get_temp_dir(), 'ecxt');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
        $zip->addFromString('xl/sharedStrings.xml', $sharedXml);

        if ($secondSheetFirst) {
            $zip->addFromString('xl/worksheets/sheet1.xml', $decoy);
            $zip->addFromString('xl/worksheets/sheet2.xml', $sheet);
        } else {
            $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        }

        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /**
     * A Holvi-like ticket export: the real Holvi header texts, made-up buyers.
     * Two tickets share order code A1; the second buyer has no phone (a gap)
     * and the discount column reads "None".
     */
    public static function holvi(): string
    {
        return self::build([
            'A1' => 'Tilaus', 'B1' => 's:Tilauskoodi', 'C1' => 'Alennuskoodi',
            'D1' => 's:Sukunimi', 'E1' => 's:Etunimi', 'F1' => 'Sähköposti', 'G1' => 'Puhelinnumero',
            'A2' => 1, 'B2' => 's:A1', 'C2' => 'None', 'D2' => 's:Tester', 'E2' => 's:Anna',
            'F2' => 'anna@example.com', 'G2' => '0401',
            'A3' => 1, 'B3' => 's:A1', 'C3' => 'None', 'D3' => 's:Tester', 'E3' => 's:Anna',
            'F3' => 'anna@example.com',
            'A4' => 2, 'B4' => 'B2', 'C4' => 'None', 'D4' => 'Example', 'E4' => 'Bo',
            'F4' => 'bo@example.com',
        ]);
    }
}
