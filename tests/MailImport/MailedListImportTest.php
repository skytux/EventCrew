<?php

declare(strict_types=1);

namespace EventCrew\Tests\MailImport;

use Brain\Monkey\Functions;
use EventCrew\MailImport\MailedListImport;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\BuyerImport;
use EventCrew\Support\DoorAccess;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\MessageTemplates;
use EventCrew\Tests\Support\TinyXlsx;
use EventCrew\Tests\TestCase;

/**
 * A list mailed to the import mailbox. "Today" is 2026-07-20 (the stubbed current_time).
 */
final class MailedListImportTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<int, array{to: string, subject: string, body: string}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [
            EmailTemplate::HTML_OPTION => false,
        ];
        $this->mails = [];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(
            function (string $name, mixed $value): bool {
                $this->options[$name] = $value;

                return true;
            }
        );
        Functions\when('admin_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-admin/' . $p);
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('get_permalink')->justReturn(false);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

            return true;
        });
    }

    private function controller(): MailedListImport
    {
        return new MailedListImport(
            new PersonRepository(),
            new BuyerImport(
                new TicketBuyerRepository(),
                new DoorAccess(new TaskRepository(), new AssignmentRepository())
            ),
            new Mailer(new Logger(), new EmailTemplate(new Logger())),
            new GuestRepository()
        );
    }

    /**
     * The plain list "Anna" and "Bo" mailed from $from.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function importPlainList(string $from = 'owner@example.com'): array
    {
        return $this->controller()->importList($from, "Anna\nBo", '<m1@example.com>');
    }

    private function queueOrganizer(bool $isOrganizer = true): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 9,
            'email' => 'owner@example.com',
            'display_name' => 'Owner',
            'is_organizer' => $isOrganizer ? 1 : 0,
        ];
    }

    /**
     * @return array<int, array{table: string, data: array<string, mixed>}>
     */
    private function buyerInserts(): array
    {
        return array_values(array_filter(
            $this->wpdb->inserts,
            static fn (array $insert): bool => str_ends_with($insert['table'], 'eventcrew_ticket_buyers')
        ));
    }

    /**
     * @return array<int, array{table: string, data: array<string, mixed>}>
     */
    private function guestInserts(): array
    {
        return array_values(array_filter(
            $this->wpdb->inserts,
            static fn (array $insert): bool => str_ends_with($insert['table'], 'eventcrew_guests')
        ));
    }

    // --- handling ----------------------------------------------------------

    public function testAnOrganizersPlainListGoesToTheNextNightsGuestListAndIsConfirmed(): void
    {
        $this->queueOrganizer();
        $this->wpdb->nextCols[] = ['2026-08-08', '2026-08-01', '2026-07-10']; // datesWithTasks
        $this->wpdb->nextResults[] = []; // nobody on the guest list yet

        $answer = $this->importPlainList();

        self::assertSame(200, $answer['status']);
        self::assertSame(
            ['ok' => true, 'added' => 2, 'skipped' => 0, 'date' => '2026-08-01', 'duplicate' => false],
            $answer['body']
        );

        self::assertSame([], $this->buyerInserts());
        $inserts = $this->guestInserts();
        self::assertCount(2, $inserts);
        self::assertSame('2026-08-01', $inserts[0]['data']['event_date']);
        self::assertSame(['Anna', 'Bo'], array_map(static fn (array $i): mixed => $i['data']['name'], $inserts));
        self::assertSame(0, $inserts[0]['data']['plus_ones']);
        self::assertSame('', $inserts[0]['data']['note']);
        self::assertSame(9, $inserts[0]['data']['added_by']);

        self::assertCount(1, $this->mails);
        self::assertSame('owner@example.com', $this->mails[0]['to']);
        self::assertStringStartsWith('Guests added for ', $this->mails[0]['subject']);
        self::assertStringContainsString('2 guests were added to the guest list for', $this->mails[0]['body']);
        self::assertStringNotContainsString('plus-ones', $this->mails[0]['body']);
        self::assertStringContainsString('The list was sent from owner@example.com.', $this->mails[0]['body']);
        self::assertStringContainsString('page=eventcrew-roster', $this->mails[0]['body']);
        self::assertStringContainsString('roster_date=2026-08-01', $this->mails[0]['body']);
    }

    public function testAPlusNAfterANameIsTakenAsPlusOnes(): void
    {
        $this->queueOrganizer();
        $this->wpdb->nextCols[] = ['2026-08-01'];
        $this->wpdb->nextResults[] = [];

        $answer = $this->controller()->importList(
            'owner@example.com',
            "Anna +2\nBo Virtanen  +1\n- Cy +x\nDee 3\n",
            '<g1@example.com>'
        );

        self::assertSame(200, $answer['status']);
        self::assertSame(4, $answer['body']['added']);
        self::assertSame([], $this->buyerInserts());
        $inserts = $this->guestInserts();
        self::assertSame(
            [['Anna', 2], ['Bo Virtanen', 1], ['- Cy +x', 0], ['Dee 3', 0]],
            array_map(static fn (array $i): array => [$i['data']['name'], $i['data']['plus_ones']], $inserts)
        );

        self::assertStringContainsString('4 guests were added to the guest list for', $this->mails[0]['body']);
        self::assertStringContainsString('They bring 3 plus-ones in total.', $this->mails[0]['body']);
    }

    public function testNothingFromTheSignatureLineDownIsAGuest(): void
    {
        $this->queueOrganizer();
        $this->wpdb->nextCols[] = ['2026-08-01'];
        $this->wpdb->nextResults[] = [];

        $answer = $this->controller()->importList(
            'owner@example.com',
            "Anna +2\nBen\n-- \nLou\nphone 040 123 4567\n",
            '<g4@example.com>'
        );

        self::assertSame(200, $answer['status']);
        self::assertSame(
            [['Anna', 2], ['Ben', 0]],
            array_map(static fn (array $i): array => [$i['data']['name'], $i['data']['plus_ones']], $this->guestInserts())
        );
    }

    public function testASignatureLineWithMoreTrailingWhitespaceEndsTheGuestListToo(): void
    {
        $this->queueOrganizer();
        $this->wpdb->nextCols[] = ['2026-08-01'];
        $this->wpdb->nextResults[] = [];

        $this->controller()->importList('owner@example.com', "Owner\n--  \t\nKim\n", '<g5@example.com>');

        self::assertSame(['Owner'], array_map(static fn (array $i): mixed => $i['data']['name'], $this->guestInserts()));
    }

    public function testASecondDeliverySkipsTheNamesAlreadyOnTheGuestList(): void
    {
        $this->queueOrganizer();
        $this->wpdb->nextCols[] = ['2026-08-01'];
        // The first delivery's guests are on the list already.
        $this->wpdb->nextResults[] = [
            ['id' => 1, 'event_date' => '2026-08-01', 'name' => 'Anna', 'plus_ones' => 2, 'note' => '', 'added_by' => 9],
        ];

        $answer = $this->controller()->importList('owner@example.com', "anna +2\n Bo \nBO +1\n", '<g2@example.com>');

        self::assertSame(200, $answer['status']);
        self::assertSame(1, $answer['body']['added']);
        self::assertSame(2, $answer['body']['skipped']);
        self::assertSame(['Bo'], array_map(static fn (array $i): mixed => $i['data']['name'], $this->guestInserts()));
        self::assertStringContainsString('1 guests were added', $this->mails[0]['body']);
        self::assertStringContainsString(
            '2 names were skipped because they were already on the guest list.',
            $this->mails[0]['body']
        );
    }

    public function testAnExtraSendersPlainListHasNoAddedBy(): void
    {
        $this->options[MailedListImport::SENDERS_OPTION] = ['shop@example.com'];
        $this->wpdb->nextCols[] = ['2026-08-01'];
        $this->wpdb->nextResults[] = [];

        $answer = $this->controller()->importList('shop@example.com', "Anna +1\n", '<g3@example.com>');

        self::assertSame(200, $answer['status']);
        $inserts = $this->guestInserts();
        self::assertCount(1, $inserts);
        self::assertArrayNotHasKey('added_by', $inserts[0]['data']);
    }

    public function testTheConfirmationLinksToTheAdminTabRosterWhenThereIsACrewPage(): void
    {
        $this->options['eventcrew_signup_page_id'] = 12;
        Functions\when('get_permalink')->justReturn('https://site.test/crew/');
        $this->queueOrganizer();
        $this->wpdb->nextCols[] = ['2026-08-01']; // datesWithTasks
        $this->wpdb->nextResults[] = []; // nobody on the guest list yet

        $this->importPlainList();

        self::assertCount(1, $this->mails);
        self::assertStringContainsString('https://site.test/crew/#eventcrew-admin-roster', $this->mails[0]['body']);
        self::assertStringNotContainsString('wp-admin', $this->mails[0]['body']);
    }

    public function testANonOrganizerIsRefusedAndNothingIsImported(): void
    {
        $this->queueOrganizer(false);

        $answer = $this->importPlainList();

        self::assertSame(403, $answer['status']);
        self::assertSame('not_organizer', $answer['body']['error']);
        self::assertSame([], $this->buyerInserts());
        self::assertSame([], $this->mails);
    }

    public function testAnUnknownSenderIsRefused(): void
    {
        // No person row queued: the address is not in the crew.
        $answer = $this->importPlainList('stranger@example.com');

        self::assertSame(403, $answer['status']);
        self::assertSame([], $this->buyerInserts());
    }

    public function testTheSameTicketFileMailTwiceAddsNothingAndSendsNoSecondConfirmation(): void
    {
        $this->queueOrganizer();
        $this->wpdb->nextCols[] = ['2026-08-01'];
        $this->wpdb->nextVars[] = '2'; // hasBatch: this Message-ID was imported already

        $answer = $this->controller()->importList(
            'owner@example.com',
            '',
            '<m1@example.com>',
            ['filename' => 'Tickets.xlsx', 'bytes' => TinyXlsx::holvi()]
        );

        self::assertSame(200, $answer['status']);
        self::assertSame(0, $answer['body']['added']);
        self::assertTrue($answer['body']['duplicate']);
        self::assertSame([], $this->buyerInserts());
        self::assertSame([], $this->mails);
    }

    public function testNoUpcomingNightIsRefusedWithAClearMessage(): void
    {
        $this->queueOrganizer();
        $this->wpdb->nextCols[] = ['2026-07-19'];

        $answer = $this->importPlainList();

        self::assertSame(409, $answer['status']);
        self::assertSame('no_upcoming_event', $answer['body']['error']);
        self::assertStringContainsString('no upcoming event', $answer['body']['message']);
        self::assertSame([], $this->buyerInserts());
    }

    // --- ticket files -----------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $organizers person rows
     */
    private function queueOrganizers(array $organizers): void
    {
        $this->wpdb->nextResults[] = $organizers;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function twoOrganizers(): array
    {
        return [
            ['id' => 9, 'email' => 'owner@example.com', 'display_name' => 'Owner', 'is_organizer' => 1],
            ['id' => 10, 'email' => 'kim@example.com', 'display_name' => 'Kim', 'is_organizer' => 1],
        ];
    }

    public function testAnXlsxFileIsImportedOneRowPerTicketAndEveryOrganizerIsTold(): void
    {
        $this->queueOrganizer();
        $this->wpdb->nextCols[] = ['2026-08-01']; // datesWithTasks
        $this->wpdb->nextVars[] = '0'; // hasBatch: new mail
        $this->wpdb->nextResults[] = []; // nobody on the list yet
        $this->queueOrganizers(self::twoOrganizers());

        $answer = $this->controller()->importList(
            'owner@example.com',
            '',
            '<m2@example.com>',
            ['filename' => 'Tickets.xlsx', 'bytes' => TinyXlsx::holvi()]
        );

        self::assertSame(200, $answer['status']);
        self::assertSame(3, $answer['body']['added']);

        $inserts = $this->buyerInserts();
        self::assertCount(3, $inserts);
        self::assertSame('Anna Tester', $inserts[0]['data']['name']);
        self::assertSame('Anna Tester', $inserts[1]['data']['name']);
        self::assertSame('anna@example.com', $inserts[0]['data']['email']);
        self::assertSame('A1', $inserts[1]['data']['ticket_code']);
        self::assertSame('B2', $inserts[2]['data']['ticket_code']);
        self::assertSame([], $this->guestInserts());

        self::assertSame(['owner@example.com', 'kim@example.com'], array_column($this->mails, 'to'));
        self::assertStringStartsWith('Ticket buyers imported for ', $this->mails[1]['subject']);
        self::assertStringContainsString('3 tickets were imported for', $this->mails[1]['body']);
        self::assertStringContainsString('The list was sent from owner@example.com.', $this->mails[1]['body']);
    }

    public function testAnExtraSenderAddressMayMailAListAndIsToldToo(): void
    {
        $this->options[MailedListImport::SENDERS_OPTION] = ['Shop@Example.com'];
        // No person row: the shop's address is not in the crew.
        $this->wpdb->nextCols[] = ['2026-08-01'];
        $this->wpdb->nextVars[] = '0';
        $this->wpdb->nextResults[] = [];
        $this->queueOrganizers(self::twoOrganizers());

        $answer = $this->controller()->importList(
            'shop@example.com',
            '',
            '<m3@example.com>',
            ['filename' => 'Tickets.xlsx', 'bytes' => TinyXlsx::holvi()]
        );

        self::assertSame(200, $answer['status']);
        self::assertCount(3, $this->buyerInserts());
        self::assertSame(
            ['owner@example.com', 'kim@example.com', 'shop@example.com'],
            array_column($this->mails, 'to')
        );
    }

    public function testAnAddressThatIsNeitherAnOrganizerNorAnExtraSenderIsRefused(): void
    {
        $this->options[MailedListImport::SENDERS_OPTION] = ['shop@example.com'];

        $answer = $this->controller()->importList(
            'other@example.com',
            '',
            '<m4@example.com>',
            ['filename' => 'Tickets.xlsx', 'bytes' => TinyXlsx::holvi()]
        );

        self::assertSame(403, $answer['status']);
        self::assertSame([], $this->buyerInserts());
        self::assertSame([], $this->mails);
    }

    public function testAFileMatchingNoFormatIsRefusedAndTheOrganizersAreTold(): void
    {
        $this->queueOrganizer();
        $this->wpdb->nextCols[] = ['2026-08-01'];
        $this->queueOrganizers(self::twoOrganizers());

        $answer = $this->controller()->importList(
            'owner@example.com',
            '',
            '<m5@example.com>',
            ['filename' => 'list.csv', 'bytes' => "Tilaus;Maa\n1;FI\n"]
        );

        self::assertSame(400, $answer['status']);
        self::assertSame('bad_file', $answer['body']['error']);
        self::assertStringContainsString('Tilaus, Maa', $answer['body']['message']);
        self::assertSame([], $this->buyerInserts());
        self::assertSame(['owner@example.com', 'kim@example.com'], array_column($this->mails, 'to'));
        self::assertSame('The ticket list could not be imported', $this->mails[0]['subject']);
        self::assertStringContainsString('Tilaus, Maa', $this->mails[0]['body']);
        self::assertStringContainsString('Nothing was added to the door list.', $this->mails[0]['body']);
    }

    // --- the texts are the Messages page templates ------------------------

    public function testTheGuestListEmailsAreTheBuiltInTextsWhenNothingIsStored(): void
    {
        $roster = "\n\nOpen the Roster page: https://site.test/wp-admin/admin.php?page=eventcrew-roster&roster_date=2026-08-01";

        $this->queueOrganizer();
        $this->wpdb->nextCols[] = ['2026-08-01'];
        $this->wpdb->nextResults[] = [];
        $this->controller()->importList('owner@example.com', "Anna +2\nBo Virtanen  +1\n- Cy +x\nDee 3\n", '<t1@example.com>');

        self::assertSame('Guests added for Sat 1 Aug', $this->mails[0]['subject']);
        self::assertStringStartsWith(
            "4 guests were added to the guest list for Sat 1 Aug. They bring 3 plus-ones in total.\n\nThe list was sent from owner@example.com." . $roster,
            $this->mails[0]['body']
        );

        $this->mails = [];
        $this->queueOrganizer();
        $this->wpdb->nextCols[] = ['2026-08-01'];
        $this->wpdb->nextResults[] = [
            ['id' => 1, 'event_date' => '2026-08-01', 'name' => 'Anna', 'plus_ones' => 2, 'note' => '', 'added_by' => 9],
        ];
        $this->controller()->importList('owner@example.com', "anna +2\n Bo \nBO +1\n", '<t2@example.com>');

        self::assertStringStartsWith(
            "1 guests were added to the guest list for Sat 1 Aug.\n\n2 names were skipped because they were already on the guest list.\n\nThe list was sent from owner@example.com." . $roster,
            $this->mails[0]['body']
        );
    }

    public function testTheTicketEmailsAreTheBuiltInTextsWhenNothingIsStored(): void
    {
        $this->queueOrganizer();
        $this->wpdb->nextCols[] = ['2026-08-01'];
        $this->wpdb->nextVars[] = '0';
        $this->wpdb->nextResults[] = [];
        $this->queueOrganizers([self::twoOrganizers()[0]]);
        $this->controller()->importList('owner@example.com', '', '<t3@example.com>', ['filename' => 'Tickets.xlsx', 'bytes' => TinyXlsx::holvi()]);

        self::assertSame('Ticket buyers imported for Sat 1 Aug', $this->mails[0]['subject']);
        self::assertMatchesRegularExpression(
            '/^3 tickets were imported for Sat 1 Aug\.\n\nThe ticket emails go out at \d\d:\d\d\. To stop them, press "Cancel sending" on the Roster page before then\.\n\nThe list was sent from owner@example\.com\.\n\nOpen the Roster page: /',
            $this->mails[0]['body']
        );

        $this->mails = [];
        $this->queueOrganizer();
        $this->wpdb->nextCols[] = ['2026-08-01'];
        $this->queueOrganizers([self::twoOrganizers()[0]]);
        $this->controller()->importList('owner@example.com', '', '<t4@example.com>', ['filename' => 'list.csv', 'bytes' => "Tilaus;Maa\n1;FI\n"]);

        self::assertSame('The ticket list could not be imported', $this->mails[0]['subject']);
        self::assertStringStartsWith(
            "The columns of the attached file do not match any ticket file format in Settings → Email. The columns in the file are: Tilaus, Maa\n\nNothing was added to the door list.\n\nThe list was sent from owner@example.com.\n\n",
            $this->mails[0]['body']
        );
    }

    public function testTheImportEmailsUseAStoredText(): void
    {
        $prefix = MessageTemplates::OPTION_PREFIX;
        $this->options[$prefix . 'import_guests'] = [
            MessageTemplates::SUBJECT => ['source' => '{added} guests on {day}'],
            MessageTemplates::BODY => ['source' => "Added {added}.\n\n{skipped}\n\n{plus_ones}\n\nFrom {from}."],
        ];
        $this->options[$prefix . 'import_failed'] = [
            MessageTemplates::SUBJECT => ['source' => 'Import failed'],
            MessageTemplates::BODY => ['source' => 'No luck: {reason}'],
        ];

        $this->queueOrganizer();
        $this->wpdb->nextCols[] = ['2026-08-01'];
        $this->wpdb->nextResults[] = [];
        $this->controller()->importList('owner@example.com', "Anna +2\n", '<t5@example.com>');

        self::assertSame('1 guests on Sat 1 Aug', $this->mails[0]['subject']);
        // The empty {skipped} leaves no empty lines behind.
        self::assertStringStartsWith(
            "Added 1.\n\nThey bring 2 plus-ones in total.\n\nFrom owner@example.com.\n\nOpen the Roster page: ",
            $this->mails[0]['body']
        );

        $this->mails = [];
        $this->queueOrganizer();
        $this->wpdb->nextCols[] = ['2026-08-01'];
        $this->queueOrganizers([self::twoOrganizers()[0]]);
        $this->controller()->importList('owner@example.com', '', '<t6@example.com>', ['filename' => 'list.csv', 'bytes' => "Tilaus;Maa\n1;FI\n"]);

        self::assertSame('Import failed', $this->mails[0]['subject']);
        self::assertStringStartsWith('No luck: The columns of the attached file', $this->mails[0]['body']);
    }
}
