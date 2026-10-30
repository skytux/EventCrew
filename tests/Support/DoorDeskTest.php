<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CheckinRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\DoorDesk;
use EventCrew\Support\DoorSession;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Telegram\TicketRedemptionService;

/**
 * The door page's logic: logging a device in, the session it buys, the
 * check-ins, the crew's arrival, the "Give ticket" spend and the state
 * version the page polls.
 */
final class DoorDeskTest extends SalesDeskTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
    }

    private function desk(): DoorDesk
    {
        return new DoorDesk(
            new TaskRepository(),
            new AssignmentRepository(),
            new PersonRepository(),
            new RedemptionRepository(),
            new GuestRepository(),
            new TicketBuyerRepository(),
            new CheckinRepository(),
            new AuthTokenRepository(),
            $this->standing(),
            new TicketRedemptionService(
                new PersonRepository(),
                new TaskRepository(),
                new RedemptionRepository(),
                $this->standing(),
                new FreeEntryGate(),
                $this->client(),
                new Mailer(new Logger(), new EmailTemplate(new Logger()))
            ),
            $this->salesDeskGate()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenRow(int $personId): array
    {
        return ['id' => 3, 'person_id' => $personId, 'expires_at' => '2026-07-20 23:00:00', 'used_at' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private function guestRow(int $id, string $name): array
    {
        return ['id' => $id, 'event_date' => self::NIGHT, 'name' => $name, 'plus_ones' => 1, 'note' => ''];
    }

    public function testSpendingTheLinkGivesACookieForThatPersonAndNight(): void
    {
        $this->wpdb->nextRows[] = $this->tokenRow(1);         // consume
        $this->wpdb->nextRows[] = $this->personRow(1, true);  // people->find

        $login = $this->desk()->login('raw', self::NIGHT, DoorSession::linkSignature('raw', self::NIGHT));

        self::assertNotNull($login['cookie']);
        self::assertSame(['person_id' => 1, 'date' => self::NIGHT], DoorSession::read($login['cookie']));
        self::assertGreaterThan(0, $login['seconds']);
        // The token is marked used.
        self::assertCount(1, $this->wpdb->updates);
    }

    public function testALinkWithTheWrongSignatureDoesNotTouchTheToken(): void
    {
        $login = $this->desk()->login('raw', '2026-07-27', DoorSession::linkSignature('raw', self::NIGHT));

        self::assertNull($login['cookie']);
        self::assertSame([], $this->wpdb->queries);
        self::assertSame([], $this->wpdb->updates);
    }

    public function testAWelcomeLinkSpentAfterTheEventEndSaysTheNightHasClosed(): void
    {
        $this->wpdb->nextRows[] = $this->tokenRow(8);
        $this->wpdb->nextRows[] = $this->personRow(8);
        $this->wpdb->nextResults[] = [$this->taskRow(6, 'welcome', '10:00', '11:00'), $this->taskRow(7, 'clean', '11:30')];

        $login = $this->desk()->login('raw', self::NIGHT, DoorSession::linkSignature('raw', self::NIGHT));

        self::assertNull($login['cookie']);
        self::assertSame('The sales desk for this night has closed.', $login['message']);
    }

    public function testATamperedCookieHasNoSession(): void
    {
        $cookie = DoorSession::mint(1, self::NIGHT);

        self::assertNull($this->desk()->session(substr($cookie, 0, -1) . ('0' === substr($cookie, -1) ? '1' : '0')));
        self::assertSame([], $this->wpdb->queries);
    }

    public function testAfterTheEventEndAnOrganizerSessionStaysOpenAndAWelcomeOneCloses(): void
    {
        $this->wpdb->nextRows[] = $this->personRow(1, true);
        $organizer = $this->desk()->session(DoorSession::mint(1, self::NIGHT));

        $this->wpdb->nextRows[] = $this->personRow(8);
        $this->wpdb->nextResults[] = [$this->taskRow(6, 'welcome', '10:00', '11:00'), $this->taskRow(7, 'clean', '11:30')];
        $welcome = $this->desk()->session(DoorSession::mint(8, self::NIGHT));

        self::assertNotNull($organizer);
        self::assertTrue($organizer['open']);
        self::assertNotNull($welcome);
        self::assertFalse($welcome['open']);
    }

    public function testMarkingAGuestInWritesACheckin(): void
    {
        $this->wpdb->nextResults[] = [$this->guestRow(4, 'Ann')]; // guests->forDate
        $this->wpdb->nextQueryResults[] = 1;

        self::assertTrue($this->desk()->mark(self::NIGHT, 1, CheckinRepository::KIND_GUEST, 4, true, 2));
        self::assertStringContainsString('INSERT INTO wp_eventcrew_checkins', $this->wpdb->lastQuery());
        self::assertStringContainsString("'guest', 4, 2,", $this->wpdb->lastQuery());
    }

    public function testMarkingAGuestOutDeletesTheCheckin(): void
    {
        $this->wpdb->nextResults[] = [$this->guestRow(4, 'Ann')];

        self::assertTrue($this->desk()->mark(self::NIGHT, 1, CheckinRepository::KIND_GUEST, 4, false, 0));
        self::assertSame(
            ['event_date' => self::NIGHT, 'kind' => 'guest', 'ref_id' => 4],
            $this->wpdb->deletes[0]['where']
        );
    }

    public function testSomeoneNotOnTheListCannotBeMarked(): void
    {
        $this->wpdb->nextResults[] = [$this->guestRow(4, 'Ann')];

        self::assertFalse($this->desk()->mark(self::NIGHT, 1, CheckinRepository::KIND_GUEST, 99, true, 0));
        self::assertSame([], $this->wpdb->deletes);
        self::assertStringNotContainsString('INSERT', $this->wpdb->lastQuery());
    }

    public function testThePlusOnesOfAnEntryCanBeChanged(): void
    {
        $this->wpdb->nextResults[] = [$this->guestRow(4, 'Ann')];

        self::assertTrue($this->desk()->setPlusOnes(self::NIGHT, CheckinRepository::KIND_GUEST, 4, 3));
        self::assertSame(['plus_ones' => 3], $this->wpdb->updates[0]['data']);
    }

    public function testMarkingCrewInSetsTheirAssignmentToArrived(): void
    {
        // isOnList: the night's tasks, the task's crew, the crew member's name,
        // and no redemptions.
        $this->wpdb->nextResults[] = [$this->taskRow(5, 'decorate', '10:00', '11:00')];
        $this->wpdb->nextResults[] = [$this->assignmentRow(30, 5, 8, 'signed_up')];
        $this->wpdb->nextRows[] = $this->personRow(8);
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextQueryResults[] = 1; // the check-in
        // markCrewArrived: the night's tasks and the member's assignment there.
        $this->wpdb->nextResults[] = [$this->taskRow(5, 'decorate', '10:00', '11:00')];
        $this->wpdb->nextRows[] = $this->assignmentRow(30, 5, 8, 'signed_up');

        self::assertTrue($this->desk()->mark(self::NIGHT, 1, CheckinRepository::KIND_CREW, 8, true, 0));

        $update = $this->wpdb->updates[0];
        self::assertSame('arrived', $update['data']['status']);
        self::assertSame(['id' => 30], $update['where']);
    }

    public function testGiveTicketSpendsACreditEvenWhenFreeEntryIsClosed(): void
    {
        $this->options[FreeEntryGate::OPTION] = [self::NIGHT];

        $this->wpdb->nextRows[] = $this->personRow(7);  // giveTicket: people->find
        $this->wpdb->nextResults[] = [];                // forPerson -> not redeemed
        $this->wpdb->nextResults[] = [                  // historyFor: two completions, one credit
            ['id' => 1, 'task_id' => 1, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-07-01'],
            ['id' => 2, 'task_id' => 2, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-07-02'],
        ];
        $this->wpdb->nextVars[] = 0;
        $this->wpdb->nextVars[] = 0;
        $this->wpdb->nextResults[] = [$this->taskRow(5, 'decorate', '10:00', '11:00')]; // eventContext
        $this->wpdb->nextQueryResults[] = 1;            // the redemption

        self::assertSame(TicketRedemptionService::TICKET_READY, $this->desk()->giveTicket(self::NIGHT, 7));
        self::assertStringContainsString('INSERT INTO wp_eventcrew_redemptions', implode("\n", $this->wpdb->queries));
    }

    public function testGiveTicketRefusesAMemberWithNoCredit(): void
    {
        $this->wpdb->nextRows[] = $this->personRow(7);
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextVars[] = 0;
        $this->wpdb->nextVars[] = 0;

        $desk = $this->desk();
        $code = $desk->giveTicket(self::NIGHT, 7);

        self::assertSame(TicketRedemptionService::NO_CREDIT, $code);
        self::assertSame('They have no credit left.', $desk->giveTicketText($code));
        self::assertStringNotContainsString('INSERT', implode("\n", $this->wpdb->queries));
    }

    /**
     * Queues the five list reads state() makes: check-ins, tasks, redemptions,
     * guests and Holvi buyers.
     *
     * @param array<int, array<string, mixed>> $checkins
     */
    private function queueState(array $checkins): void
    {
        $this->wpdb->nextResults[] = $checkins;
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [$this->guestRow(4, 'Ann')];
        $this->wpdb->nextResults[] = [];
    }

    public function testTheStateVersionChangesWhenSomeoneComesIn(): void
    {
        $desk = $this->desk();

        $this->queueState([]);
        $before = $desk->state(self::NIGHT);
        $this->queueState([]);
        $same = $desk->state(self::NIGHT);
        $this->queueState([['kind' => 'guest', 'ref_id' => 4, 'plus_ones' => 1]]);
        $after = $desk->state(self::NIGHT);

        self::assertSame($before['version'], $same['version']);
        self::assertNotSame($before['version'], $after['version']);
        self::assertSame(0, $before['in']);
        self::assertSame(2, $after['in']);
        self::assertSame(2, $after['expected']);
    }
}
