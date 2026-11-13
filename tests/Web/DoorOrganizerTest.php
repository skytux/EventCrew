<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\BuyerImport;
use EventCrew\Support\DoorAccess;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Tests\Support\SalesDeskTestCase;
use EventCrew\Tests\Support\TinyXlsx;
use EventCrew\Web\DoorOrganizer;

/**
 * The organizers' section of the sales desk: the Holvi ticket file picked on
 * the page, and the free entry switch. Nobody but an organizer gets either.
 */
final class DoorOrganizerTest extends SalesDeskTestCase
{
    /** @var array<int, string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('sanitize_file_name')->returnArg(1);
        Functions\when('wp_schedule_single_event')->justReturn(true);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function organizer(bool $uploaded = true): DoorOrganizer
    {
        return new DoorOrganizer(
            new FreeEntryGate(),
            new BuyerImport(
                new TicketBuyerRepository(),
                new DoorAccess(new TaskRepository(), new AssignmentRepository())
            ),
            new TicketBuyerRepository(),
            null,
            static fn (string $path): bool => $uploaded
        );
    }

    private function person(bool $organizer): Person
    {
        return Person::fromRow($this->personRow($organizer ? 1 : 2, $organizer));
    }

    /**
     * A $_FILES entry for $bytes saved under the name $name.
     *
     * @return array<string, mixed>
     */
    private function upload(string $name, string $bytes, ?int $size = null): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ecdoor');
        file_put_contents($path, $bytes);
        $this->files[] = $path;

        return ['name' => $name, 'tmp_name' => $path, 'size' => $size ?? strlen($bytes), 'error' => UPLOAD_ERR_OK];
    }

    public function testTheHolviFileAddsItsTicketsToTheNight(): void
    {
        $result = $this->organizer()->importUpload(
            $this->person(true),
            self::NIGHT,
            $this->upload('holvi.xlsx', TinyXlsx::holvi())
        );

        self::assertTrue($result['ok']);
        self::assertSame(3, $result['added']);
        self::assertCount(3, $this->wpdb->inserts);
        self::assertSame('upload', $this->wpdb->inserts[0]['data']['source']);
        self::assertSame(self::NIGHT, $this->wpdb->inserts[0]['data']['event_date']);
        self::assertSame('anna@example.com', $this->wpdb->inserts[0]['data']['email']);
        self::assertStringContainsString('3 ticket buyers added', $result['message']);
        self::assertSame('admin_buyers_added_3_0', $result['code']);
    }

    public function testTheSameFileTwiceAddsNothing(): void
    {
        // hasBatch(): that file's batch is already on the night.
        $this->wpdb->nextVars[] = '3';

        $result = $this->organizer()->importUpload(
            $this->person(true),
            self::NIGHT,
            $this->upload('holvi.xlsx', TinyXlsx::holvi())
        );

        self::assertTrue($result['ok']);
        self::assertSame(0, $result['added']);
        self::assertSame([], $this->wpdb->inserts);
        self::assertStringContainsString('already added', $result['message']);
        self::assertSame('admin_buyers_file_same', $result['code']);
    }

    public function testTheSalesDeskCrewCannotAddATicketFile(): void
    {
        $result = $this->organizer()->importUpload(
            $this->person(false),
            self::NIGHT,
            $this->upload('holvi.xlsx', TinyXlsx::holvi())
        );

        self::assertFalse($result['ok']);
        self::assertTrue($result['refused']);
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testAFileThatIsNotATicketFileIsRefused(): void
    {
        $result = $this->organizer()->importUpload(
            $this->person(true),
            self::NIGHT,
            $this->upload('photo.jpg', 'not a sheet')
        );

        self::assertFalse($result['ok']);
        self::assertFalse($result['refused']);
        self::assertStringContainsString('not an .xlsx or .csv file', $result['message']);
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testATooBigFileIsRefused(): void
    {
        $result = $this->organizer()->importUpload(
            $this->person(true),
            self::NIGHT,
            $this->upload('holvi.xlsx', TinyXlsx::holvi(), DoorOrganizer::MAX_FILE_BYTES + 1)
        );

        self::assertFalse($result['ok']);
        self::assertStringContainsString('too big', $result['message']);
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testNoFilePickedSaysSo(): void
    {
        $result = $this->organizer()->importUpload($this->person(true), self::NIGHT, []);

        self::assertFalse($result['ok']);
        self::assertSame('Pick the ticket file first.', $result['message']);
        self::assertSame('admin_buyers_file_failed', $result['code']);
    }

    public function testAPathPhpDidNotReceiveIsNotRead(): void
    {
        $result = $this->organizer(false)->importUpload(
            $this->person(true),
            self::NIGHT,
            $this->upload('holvi.xlsx', TinyXlsx::holvi())
        );

        self::assertFalse($result['ok']);
        self::assertSame('The file did not arrive. Try again.', $result['message']);
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testACsvFileThatMatchesNoFormatSaysWhy(): void
    {
        $result = $this->organizer()->importUpload(
            $this->person(true),
            self::NIGHT,
            $this->upload('orders.csv', "Tilaus,Maa\n1,FI\n")
        );

        self::assertFalse($result['ok']);
        self::assertStringContainsString('do not match any ticket file format', $result['message']);
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testFreeEntryClosesAndReopens(): void
    {
        $closed = $this->organizer()->run($this->person(true), self::NIGHT, DoorOrganizer::OP_FREE_ENTRY);

        self::assertTrue($closed['ok']);
        self::assertSame([self::NIGHT], $this->options[FreeEntryGate::OPTION]);
        self::assertSame('Reopen free entry', $this->organizer()->info(self::NIGHT)['toggle']);
        self::assertNotSame('', $this->organizer()->info(self::NIGHT)['closed_text']);

        $reopened = $this->organizer()->run($this->person(true), self::NIGHT, DoorOrganizer::OP_FREE_ENTRY);

        self::assertTrue($reopened['ok']);
        self::assertNotContains(self::NIGHT, $this->options[FreeEntryGate::OPTION]);
        self::assertSame('Close free entry (sold out)', $this->organizer()->info(self::NIGHT)['toggle']);
    }

    public function testTheSalesDeskCrewCannotCloseFreeEntryOrSendTickets(): void
    {
        foreach ([DoorOrganizer::OP_FREE_ENTRY, DoorOrganizer::OP_MAIL_BUYERS, DoorOrganizer::OP_MAIL_GUESTS] as $op) {
            $result = $this->organizer()->run($this->person(false), self::NIGHT, $op);

            self::assertFalse($result['ok'], $op);
            self::assertTrue($result['refused'], $op);
        }

        self::assertArrayNotHasKey(FreeEntryGate::OPTION, $this->options);
    }
}
