<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\PrivacyExporter;
use EventCrew\Tests\TestCase;

/**
 * WordPress's Export Personal Data tool, for people who are not WordPress
 * users - which is all of them here, so core's own exporters see nothing.
 */
final class PrivacyExporterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_option')->justReturn(false);
        Functions\when('get_the_title')->justReturn('');
    }

    private function exporter(): PrivacyExporter
    {
        return new PrivacyExporter(
            new PersonRepository(),
            new AssignmentRepository(),
            new TaskRepository(),
            new RedemptionRepository(),
            new CreditGrantRepository()
        );
    }

    public function testAnUnknownAddressExportsNothingAndIsDone(): void
    {
        // findByEmail -> no row. Nothing to say, and nothing to resume.
        $this->wpdb->nextRows[] = null;

        $result = $this->exporter()->export('stranger@example.com');

        self::assertSame([], $result['data']);
        self::assertTrue($result['done']);
    }

    public function testAKnownPersonGetsTheirProfileBack(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam'];
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam'];

        $result = $this->exporter()->export('sam@example.com');

        self::assertTrue($result['done']);
        self::assertNotSame([], $result['data']);

        $values = array_column($result['data'][0]['data'], 'value', 'name');
        self::assertSame('sam@example.com', $values['Email']);
        self::assertSame('Sam', $values['Name']);
    }

    public function testItRegistersItselfWithWordPresssExportTool(): void
    {
        $exporters = $this->exporter()->register([]);

        self::assertArrayHasKey('eventcrew', $exporters);
        self::assertIsCallable($exporters['eventcrew']['callback']);
    }

    /**
     * A ticket buyer who is not a crew member still gets their tickets back,
     * looked up by email in the ticket buyers table.
     */
    public function testATicketBuyerWithNoCrewRecordGetsTheirTickets(): void
    {
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextResults[] = [
            [
                'id' => 4,
                'event_date' => '2026-08-01',
                'name' => 'Bo Buyer',
                'email' => 'bo@example.test',
                'ticket_code' => 'T-123',
            ],
        ];

        $result = $this->exporter()->export('bo@example.test');

        self::assertTrue($result['done']);
        self::assertCount(1, $result['data']);
        self::assertSame('eventcrew-bought-tickets', $result['data'][0]['group_id']);

        $values = array_column($result['data'][0]['data'], 'value', 'name');
        self::assertSame('2026-08-01', $values['Event date']);
        self::assertSame('Bo Buyer', $values['Name']);
        self::assertSame('bo@example.test', $values['Email']);
        self::assertSame('T-123', $values['Ticket code']);
        self::assertContains(
            "SELECT * FROM wp_eventcrew_ticket_buyers WHERE email = 'bo@example.test' ORDER BY event_date ASC, id ASC",
            $this->wpdb->queries
        );
    }

    /** A guest from a mailed guest list gets their guest list place. */
    public function testAGuestWithAnEmailGetsTheirGuestListPlace(): void
    {
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextResults[] = []; // no ticket buyer rows
        $this->wpdb->nextResults[] = [
            [
                'id' => 7,
                'event_date' => '2026-08-01',
                'name' => 'Cy Guest',
                'plus_ones' => 2,
                'note' => '',
                'email' => 'cy@example.test',
            ],
        ];

        $result = $this->exporter()->export('cy@example.test');

        self::assertCount(1, $result['data']);
        self::assertSame('eventcrew-guest-list', $result['data'][0]['group_id']);

        $values = array_column($result['data'][0]['data'], 'value', 'name');
        self::assertSame('2026-08-01', $values['Event date']);
        self::assertSame('Cy Guest', $values['Name']);
        self::assertSame('cy@example.test', $values['Email']);
        self::assertSame('2', $values['Plus-ones']);
        self::assertContains(
            "SELECT * FROM wp_eventcrew_guests WHERE email = 'cy@example.test' ORDER BY event_date ASC, id ASC",
            $this->wpdb->queries
        );
    }

    /**
     * An announcement subscriber is exported by email alone, with the name
     * they are greeted with and their language.
     */
    public function testAnAnnouncementSubscriberGetsTheirSubscription(): void
    {
        $this->wpdb->nextRows[] = null; // no crew record
        $this->wpdb->nextResults[] = []; // no ticket buyer rows
        $this->wpdb->nextResults[] = []; // no guest rows
        $this->wpdb->nextRows[] = [
            'id' => 4,
            'email' => 'fan@example.test',
            'status' => 'confirmed',
            'created_at' => '2026-07-01 10:00:00',
            'confirmed_at' => '2026-07-01 10:05:00',
            'name' => 'Anna',
            'language' => 'fi',
        ];

        $result = $this->exporter()->export('fan@example.test');

        self::assertCount(1, $result['data']);
        self::assertSame('eventcrew-announcement', $result['data'][0]['group_id']);

        $values = array_column($result['data'][0]['data'], 'value', 'name');
        self::assertSame('fan@example.test', $values['Email']);
        self::assertSame('confirmed', $values['Status']);
        self::assertSame('Anna', $values['Name']);
        self::assertSame('fi', $values['Language']);
        self::assertSame('2026-07-01 10:05:00', $values['Confirmed']);
        self::assertSame(
            "SELECT * FROM wp_eventcrew_newsletter_subscribers WHERE email = 'fan@example.test'",
            $this->wpdb->lastQuery()
        );
    }
}
