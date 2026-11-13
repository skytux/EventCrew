<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CheckinRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Repositories\TicketTransferRepository;
use EventCrew\Support\BuyerImport;
use EventCrew\Support\DoorAccess;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\TicketTransferService;
use EventCrew\Telegram\ParityCommands;
use EventCrew\Telegram\TransferBot;
use EventCrew\Web\DoorOrganizer;

/**
 * The Crew app actions the bot gained (parityB): /markall, /buyers,
 * /testmail, /checkmail, /notify, /giveaway and a ticket file sent to the
 * bot. The clock reads 2026-07-20 12:00.
 */
final class ParityCommandsTest extends TelegramTestCase
{
    use CommandTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommandTest();
        Functions\when('sanitize_file_name')->returnArg(1);
    }

    private function command(): ParityCommands
    {
        $transfers = new TicketTransferService(
            new TicketTransferRepository(),
            new RedemptionRepository(),
            new PersonRepository(),
            new TaskRepository(),
            new CheckinRepository()
        );

        return new ParityCommands(
            new PersonRepository(),
            new TaskRepository(),
            $this->roster(),
            new DoorOrganizer(
                new FreeEntryGate(),
                new BuyerImport(new TicketBuyerRepository(), new DoorAccess(new TaskRepository(), new AssignmentRepository())),
                new TicketBuyerRepository()
            ),
            new RedemptionRepository(),
            $transfers,
            new TransferBot($transfers, new PersonRepository(), $this->client()),
            $this->client()
        );
    }

    public function testOnlyItsOwnCommandsAreTaken(): void
    {
        self::assertSame('markall', ParityCommands::commandOf('/markall Bar ; done'));
        self::assertSame('checkmail', ParityCommands::commandOf('/checkmail@EventCrewBot'));
        self::assertSame('', ParityCommands::commandOf('/markallx'));
        self::assertSame('', ParityCommands::commandOf('/join 12'));
        self::assertFalse($this->command()->onCommand(555, 555, '/join 12', true));
    }

    public function testMarkAllAsksBeforeMarkingTheTask(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextResults[] = [$this->task(7, '2026-07-25')];
        $this->wpdb->nextRows[] = $this->task(7, '2026-07-25');

        self::assertTrue($this->command()->onCommand(555, 555, '/markall 25.7. bar ; done', true));

        self::assertStringStartsWith('Mark everyone still on ', $this->lastText());
        $id = $this->requestId('pb:');
        self::assertSame(['pb:y:' . $id, 'pb:x:' . $id], $this->buttonData());
        self::assertSame([], $this->wpdb->updates);
    }

    public function testMarkAllWithoutAMarkShowsTheUsage(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();

        $this->command()->onCommand(555, 555, '/markall bar', true);

        self::assertSame(ParityCommands::usage(ParityCommands::MARK_ALL), $this->lastText());
        self::assertSame([], $this->transients);
    }

    public function testBuyersAddAsksWithTheNames(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();

        $this->command()->onCommand(555, 555, '/buyers 25.7. add Anna ; Ben', true);

        self::assertSame("Add 2 ticket buyers to the list of Sat 25 Jul?\nAnna, Ben", $this->lastText());
    }

    public function testBuyersResetAsksAndThenEmptiesTheList(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextCols[] = ['2026-07-25'];

        $this->command()->onCommand(555, 555, '/buyers reset', true);

        self::assertStringStartsWith('Delete every imported ticket buyer of Sat 25 Jul?', $this->lastText());
        $id = $this->requestId('pb:');

        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextQueryResults[] = 3;
        $this->command()->onCallback($this->tap('pb:y:' . $id));

        self::assertStringContainsString('DELETE FROM', implode("\n", $this->wpdb->queries));
        self::assertStringStartsWith('✅ Sat 25 Jul', $this->lastText());
        self::assertSame([], $this->transients);
    }

    public function testTestMailGoesToTheOrganizersOwnAddressAfterTheQuestion(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();

        $this->command()->onCommand(555, 555, '/testmail tickets 25.7.', true);

        self::assertSame('Send a test ticket email for Sat 25 Jul to your own address?', $this->lastText());
    }

    public function testCheckMailTakesNoArguments(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();
        $this->command()->onCommand(555, 555, '/checkmail', true);
        self::assertSame('Check the mailbox for ticket and guest lists now?', $this->lastText());

        $this->wpdb->nextRows[] = $this->organizer();
        $this->command()->onCommand(555, 555, '/checkmail now please', true);
        self::assertSame('Check the mailbox now: /checkmail', $this->lastText());
    }

    public function testAMemberCannotUseTheOrganizerCommands(): void
    {
        $this->wpdb->nextRows[] = $this->person(2, 'Bo');

        $this->command()->onCommand(602, 602, '/checkmail', true);

        self::assertStringNotContainsString('Check the mailbox for', $this->lastText());
        self::assertSame([], $this->transients);
    }

    public function testNotifySetsOneSwitch(): void
    {
        $this->wpdb->nextRows[] = $this->person(2, 'Bo');

        $this->command()->onCommand(602, 602, '/notify credit email off', true);

        self::assertStringStartsWith("✅ Saved.\n", $this->lastText());
        $prefs = json_decode((string) end($this->wpdb->updates)['data']['notify_prefs'], true);
        self::assertFalse($prefs['credit']['email']);
    }

    public function testNotifyWithAnUnknownTypeShowsTheUsage(): void
    {
        $this->wpdb->nextRows[] = $this->person(2, 'Bo');

        $this->command()->onCommand(602, 602, '/notify weather email off', true);

        self::assertSame(ParityCommands::usage(ParityCommands::NOTIFY), $this->lastText());
        self::assertSame([], $this->wpdb->updates);
    }

    public function testTheNotifyQuestionNamesTheSwitch(): void
    {
        $bo = Person::fromRow($this->person(2, 'Bo'));

        self::assertStringStartsWith('Turn this notification off?', ParityCommands::notifyQuestion($bo, 'credit telegram off'));
        self::assertSame('', ParityCommands::notifyQuestion($bo, 'credit fax off'));
        // Organizer rows are not offered to members.
        self::assertSame('', ParityCommands::notifyQuestion($bo, 'role_news email off'));
    }

    public function testGiveAwayArgumentsAreRead(): void
    {
        self::assertSame(
            ['date' => '2026-07-25', 'cancel' => false, 'who' => 'ann@example.com', 'their' => 'Ann Example'],
            ParityCommands::giveAwayArgs('25.7. ann@example.com ; Ann Example')
        );
        self::assertSame(['date' => '', 'cancel' => true, 'who' => '', 'their' => ''], ParityCommands::giveAwayArgs('cancel'));
    }

    public function testAFileThatIsNotATicketFileGoesOn(): void
    {
        $message = ['document' => ['file_id' => 'F1', 'file_name' => 'photo.jpg', 'file_size' => 100]];

        self::assertFalse($this->command()->onDocument(555, 555, $message));
        self::assertNull($this->lastCallTo('sendMessage'));
    }

    public function testAnOrganizersTicketFileIsAskedAbout(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();
        $message = ['document' => ['file_id' => 'F1', 'file_name' => 'holvi.xlsx', 'file_size' => 100], 'caption' => '25.7.'];

        self::assertTrue($this->command()->onDocument(555, 555, $message));

        self::assertStringStartsWith('Add the ticket buyers in holvi.xlsx to the list of Sat 25 Jul?', $this->lastText());
        self::assertSame('F1', array_values($this->transients)[0]['file'] ?? '');
    }

    public function testATooBigTicketFileIsRefused(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();
        $message = ['document' => ['file_id' => 'F1', 'file_name' => 'holvi.csv', 'file_size' => DoorOrganizer::MAX_FILE_BYTES + 1]];

        self::assertTrue($this->command()->onDocument(555, 555, $message));
        self::assertSame('That file is too big for a ticket file.', $this->lastText());
        self::assertSame([], $this->transients);
    }
}
