<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\TicketFile;
use EventCrew\Support\TicketFileException;
use EventCrew\Tests\TestCase;

/**
 * Reading the rows of a mailed ticket file: .xlsx and .csv.
 */
final class TicketFileTest extends TestCase
{
    public function testXlsxReadsSharedAndInlineStringsAndNumbers(): void
    {
        $bytes = TinyXlsx::build([
            'A1' => 's:Name', 'B1' => 'Code', 'C1' => 'Count',
            'A2' => 's:Anna', 'B2' => 'X-1', 'C2' => 2,
        ]);

        self::assertSame(
            [['Name', 'Code', 'Count'], ['Anna', 'X-1', '2']],
            TicketFile::rows('Tickets.XLSX', $bytes)
        );
    }

    public function testXlsxPlacesCellsByColumnLetterSoGapsDoNotShiftColumns(): void
    {
        // B2 is missing, D1 is far right, "None" counts as empty.
        $bytes = TinyXlsx::build([
            'A1' => 'Name', 'B1' => 'Phone', 'C1' => 'Code', 'D1' => 'Note',
            'A2' => 'Anna', 'C2' => 'X-1', 'D2' => 'None',
            'C3' => 'only-code',
        ]);

        self::assertSame(
            [
                ['Name', 'Phone', 'Code', 'Note'],
                ['Anna', '', 'X-1', ''],
                ['', '', 'only-code'],
            ],
            TicketFile::rows('t.xlsx', $bytes)
        );
    }

    public function testXlsxReadsColumnsBeyondZ(): void
    {
        $bytes = TinyXlsx::build(['AA1' => 'Far']);

        $rows = TicketFile::rows('t.xlsx', $bytes);

        self::assertSame('Far', $rows[0][26]);
        self::assertCount(27, $rows[0]);
    }

    public function testXlsxUsesTheSheetTheWorkbookListsFirst(): void
    {
        $bytes = TinyXlsx::build(['A1' => 'Name', 'A2' => 'Anna'], true);

        self::assertSame([['Name'], ['Anna']], TicketFile::rows('t.xlsx', $bytes));
    }

    public function testABrokenXlsxFileFailsWithAMessage(): void
    {
        $this->expectException(TicketFileException::class);
        $this->expectExceptionMessage('could not be read');

        TicketFile::rows('t.xlsx', 'this is not a zip file');
    }

    public function testCsvWithCommas(): void
    {
        self::assertSame(
            [['Name', 'Email', 'Code'], ['Anna Tester', 'anna@example.com', 'A1'], ['Bo, Jr', '', 'B2']],
            TicketFile::rows('t.csv', "Name,Email,Code\nAnna Tester,anna@example.com,A1\n\"Bo, Jr\",,B2\n")
        );
    }

    public function testCsvWithSemicolonsAndAByteOrderMark(): void
    {
        $bytes = "\xEF\xBB\xBFNimi;Sähköposti;Koodi\r\nÄnna;anna@example.com;A1\r\n;;\r\nBo;None;B2\r\n";

        self::assertSame(
            [['Nimi', 'Sähköposti', 'Koodi'], ['Änna', 'anna@example.com', 'A1'], ['Bo', '', 'B2']],
            TicketFile::rows('t.csv', $bytes)
        );
    }

    public function testCsvWithoutAByteOrderMarkKeepsTheFirstHeaderClean(): void
    {
        self::assertSame('Nimi', TicketFile::rows('t.csv', "Nimi;Koodi\nAnna;A1\n")[0][0]);
    }

    public function testAnotherFileTypeIsRefused(): void
    {
        $this->expectException(TicketFileException::class);

        TicketFile::rows('t.pdf', 'whatever');
    }

    public function testOnlyXlsxAndCsvAreTicketFiles(): void
    {
        self::assertTrue(TicketFile::isTicketFile('List.xlsx'));
        self::assertTrue(TicketFile::isTicketFile('list.CSV'));
        self::assertFalse(TicketFile::isTicketFile('list.txt'));
    }
}
