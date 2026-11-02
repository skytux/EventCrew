<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\TicketFile;
use EventCrew\Support\TicketFileException;
use EventCrew\Support\TicketFormats;
use EventCrew\Tests\TestCase;

/**
 * The ticket file formats: the stored list, the Holvi preset, and matching a
 * file's header row to a format.
 */
final class TicketFormatsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
    }

    public function testWithNothingStoredTheListIsTheHolviPreset(): void
    {
        $all = TicketFormats::all();

        self::assertCount(1, $all);
        self::assertSame('holvi', $all[0]['id']);
        self::assertSame('Tilauskoodi', $all[0]['columns']['code']);
        self::assertSame('', $all[0]['decoder_js']);
        self::assertSame($all[0], TicketFormats::find('holvi'));
        self::assertNull(TicketFormats::find('nope'));
    }

    public function testTheHolviPresetReadsOneTicketPerRow(): void
    {
        $result = TicketFormats::tickets(TicketFile::rows('holvi.xlsx', TinyXlsx::holvi()));

        self::assertSame('holvi', $result['format']);
        self::assertSame(
            [
                ['name' => 'Anna Tester', 'email' => 'anna@example.com', 'code' => 'A1'],
                ['name' => 'Anna Tester', 'email' => 'anna@example.com', 'code' => 'A1'],
                ['name' => 'Bo Example', 'email' => 'bo@example.com', 'code' => 'B2'],
            ],
            $result['tickets']
        );
    }

    public function testHeadersMatchWithoutRegardToCaseAndAnyAcceptedTextCounts(): void
    {
        $this->options[TicketFormats::OPTION] = [[
            'id' => 'shop',
            'name' => 'Shop',
            'columns' => ['name' => ['Full name', 'Buyer'], 'email' => 'E-mail', 'code' => ['Ticket', 'Order']],
            'decoder_js' => 'return code;',
        ]];

        $result = TicketFormats::tickets([
            ['ORDER', 'buyer', 'e-MAIL'],
            ['Z9', 'Anna', 'anna@example.com'],
        ]);

        self::assertSame('shop', $result['format']);
        self::assertSame([['name' => 'Anna', 'email' => 'anna@example.com', 'code' => 'Z9']], $result['tickets']);
        self::assertSame('return code;', TicketFormats::find('shop')['decoder_js']);
    }

    public function testAFormatWithAnEmptyEmailColumnStillMatches(): void
    {
        $this->options[TicketFormats::OPTION] = [[
            'id' => 'plain',
            'name' => 'Plain',
            'columns' => ['name' => 'Name', 'email' => '', 'code' => 'Code'],
            'decoder_js' => '',
        ]];

        $result = TicketFormats::tickets([['Name', 'Code'], ['Anna', 'A1']]);

        self::assertSame([['name' => 'Anna', 'email' => '', 'code' => 'A1']], $result['tickets']);
    }

    public function testAFileMatchingNoFormatFailsNamingItsHeaders(): void
    {
        try {
            TicketFormats::tickets([['Tilaus', 'Hinta, EUR', 'Maa'], ['1', '10', 'FI']]);
            self::fail('Expected the file to be refused.');
        } catch (TicketFileException $exception) {
            self::assertStringContainsString('do not match any ticket file format', $exception->getMessage());
            self::assertStringContainsString('Tilaus, Hinta, EUR, Maa', $exception->getMessage());
        }
    }

    public function testAMissingJoinedColumnMeansNoMatch(): void
    {
        // Holvi's name is "Etunimi + Sukunimi"; Sukunimi is missing here.
        $this->expectException(TicketFileException::class);

        TicketFormats::tickets([['Etunimi', 'Sähköposti', 'Tilauskoodi'], ['Anna', 'a@example.com', 'A1']]);
    }

    public function testSaveGivesNewFormatsAnIdAndDropsEmptyOnes(): void
    {
        $stored = TicketFormats::save([
            ['id' => 'holvi', 'name' => 'Holvi', 'columns' => TicketFormats::holvi()['columns'], 'decoder_js' => ''],
            ['id' => '', 'name' => 'My Shop', 'columns' => ['name' => ['Name', ' ', 'Buyer'], 'email' => '', 'code' => 'Code']],
            ['id' => '', 'name' => '', 'columns' => ['name' => '', 'email' => '', 'code' => ''], 'decoder_js' => ''],
        ]);

        self::assertCount(2, $stored);
        self::assertSame('my-shop', $stored[1]['id']);
        self::assertSame(['Name', 'Buyer'], $stored[1]['columns']['name']);
        self::assertSame($stored, $this->options[TicketFormats::OPTION]);
        self::assertSame('My Shop', TicketFormats::find('my-shop')['name']);
    }
}
