<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CheckinRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\DoorDesk;
use EventCrew\Support\DoorSession;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\Roles;
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
        Functions\when('is_email')->alias(
            static fn (string $email): string|false => false === filter_var($email, FILTER_VALIDATE_EMAIL) ? false : $email
        );
        Functions\when('sanitize_email')->returnArg(1);
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
            $this->salesDeskGate(),
            new CreditGrantRepository()
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

        self::assertTrue($this->desk()->mark(self::NIGHT, 1, CheckinRepository::KIND_GUEST, 4, true, 1));
        self::assertStringContainsString('INSERT INTO wp_eventcrew_checkins', $this->wpdb->lastQuery());
        self::assertStringContainsString("'guest', 4, 1,", $this->wpdb->lastQuery());
    }

    public function testAGuestIsMarkedInWithNoMoreThanTheyMayBring(): void
    {
        $this->wpdb->nextResults[] = [$this->guestRow(4, 'Ann')]; // allowed +1
        $this->wpdb->nextQueryResults[] = 1;

        self::assertTrue($this->desk()->mark(self::NIGHT, 1, CheckinRepository::KIND_GUEST, 4, true, 2));
        self::assertStringContainsString("'guest', 4, 1,", $this->wpdb->lastQuery());
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

    public function testThePlusOnesOfAGuestStopAtWhatTheyMayBring(): void
    {
        $this->wpdb->nextResults[] = [$this->guestRow(4, 'Ann')]; // allowed +1

        self::assertTrue($this->desk()->setPlusOnes(self::NIGHT, CheckinRepository::KIND_GUEST, 4, 3));
        self::assertSame(['plus_ones' => 1], $this->wpdb->updates[0]['data']);
    }

    public function testThePlusOnesOfATicketBuyerCanBeChanged(): void
    {
        $this->wpdb->nextResults[] = [['id' => 9, 'event_date' => self::NIGHT, 'name' => 'Bea', 'source' => 'paste']];

        self::assertTrue($this->desk()->setPlusOnes(self::NIGHT, CheckinRepository::KIND_BUYER, 9, 3));
        self::assertSame(['plus_ones' => 3], $this->wpdb->updates[0]['data']);
    }

    public function testADoorTicketIsRecordedAndCheckedIn(): void
    {
        $this->wpdb->nextQueryResults[] = 1;

        self::assertTrue($this->desk()->addDoorSale(self::NIGHT, 1, ''));
        self::assertSame('door', $this->wpdb->inserts[0]['data']['source']);
        self::assertStringContainsString("'buyer', 1, 0,", $this->wpdb->lastQuery());
    }

    public function testAHolviBuyerMissingFromTheListIsStoredWithNameAndEmailAndCheckedIn(): void
    {
        $this->wpdb->nextQueryResults[] = 1;

        $desk = $this->desk();

        self::assertSame(DoorDesk::HOLVI_OK, $desk->addHolviBuyer(self::NIGHT, 1, ' Ada ', ' ada@example.com '));
        self::assertCount(1, $this->wpdb->inserts);
        $row = $this->wpdb->inserts[0]['data'];
        self::assertSame('wp_eventcrew_ticket_buyers', $this->wpdb->inserts[0]['table']);
        self::assertSame('desk', $row['source']);
        self::assertSame('Ada', $row['name']);
        self::assertSame('ada@example.com', $row['email']);
        self::assertStringContainsString("'buyer', 1, 0,", $this->wpdb->lastQuery());
        self::assertSame('Holvi buyer added and checked in.', $desk->holviBuyerText(DoorDesk::HOLVI_OK));
    }

    public function testAHolviBuyerWithNoNameOrEmailGetsAPlaceholderName(): void
    {
        $this->wpdb->nextQueryResults[] = 1;

        self::assertSame(DoorDesk::HOLVI_OK, $this->desk()->addHolviBuyer(self::NIGHT, 1, '  ', ''));
        self::assertSame('Holvi buyer (no name)', $this->wpdb->inserts[0]['data']['name']);
        self::assertNull($this->wpdb->inserts[0]['data']['email']);
    }

    public function testAHolviBuyerWithAnInvalidEmailIsRefused(): void
    {
        $desk = $this->desk();

        self::assertSame(DoorDesk::HOLVI_BAD_EMAIL, $desk->addHolviBuyer(self::NIGHT, 1, 'Ada', 'ada@'));
        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->wpdb->queries);
        self::assertStringContainsString('not valid', $desk->holviBuyerText(DoorDesk::HOLVI_BAD_EMAIL));
    }

    public function testAHolviBuyerAddedAtTheDeskIsListedWithHolviAndNotAsADoorSale(): void
    {
        $this->wpdb->nextResults[] = [['kind' => 'buyer', 'ref_id' => 11, 'plus_ones' => 0]]; // check-ins
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];
        // In the order the query sorts them: by name.
        $this->wpdb->nextResults[] = [
            [
                'id' => 11,
                'event_date' => self::NIGHT,
                'name' => 'Ada',
                'email' => 'ada@example.com',
                'source' => 'desk',
            ],
            ['id' => 9, 'event_date' => self::NIGHT, 'name' => 'Bea', 'source' => 'paste'],
            ['id' => 10, 'event_date' => self::NIGHT, 'name' => 'Cy', 'source' => 'door'],
        ];

        $sections = $this->desk()->state(self::NIGHT)['sections'];

        self::assertSame(['Ada', 'Bea'], array_column($sections[3]['rows'], 'name'));
        self::assertSame(['Holvi · ada@example.com', 'Holvi'], array_column($sections[3]['rows'], 'detail'));
        self::assertTrue($sections[3]['rows'][0]['in']);
        self::assertSame(['Cy'], array_column($sections[4]['rows'], 'name'));
    }

    public function testTheCountWithoutANameGoesUpAndDownAndNeverBelowZero(): void
    {
        $desk = $this->desk();

        self::assertSame(0, $desk->anonymousCount(self::NIGHT));
        self::assertSame(1, $desk->countAnonymous(self::NIGHT, 1));
        self::assertSame(2, $desk->countAnonymous(self::NIGHT, 1));
        self::assertSame(1, $desk->countAnonymous(self::NIGHT, -1));
        self::assertSame(0, $desk->countAnonymous(self::NIGHT, -1));
        self::assertSame(0, $desk->countAnonymous(self::NIGHT, -1));
        self::assertSame(0, $this->options[DoorDesk::ANONYMOUS_OPTION_PREFIX . self::NIGHT]);
        // Another night has its own count.
        self::assertSame(1, $desk->countAnonymous('2026-07-27', 1));
        self::assertSame(0, $desk->anonymousCount(self::NIGHT));
    }

    public function testTheCountWithoutANameIsPartOfTheCheckedInTotal(): void
    {
        $this->options[DoorDesk::ANONYMOUS_OPTION_PREFIX . self::NIGHT] = 3;
        $this->queueState([['kind' => 'guest', 'ref_id' => 4, 'plus_ones' => 1]]);

        $state = $this->desk()->state(self::NIGHT);

        self::assertSame(3, $state['anonymous']);
        // Ann and her one extra person, plus the three counted without a name.
        self::assertSame(5, $state['in']);
        self::assertSame(5, $state['expected']);
    }

    public function testAGuestAddedAtTheDoorIsCheckedInWithNoExtraPeopleYet(): void
    {
        $this->wpdb->nextQueryResults[] = 1;

        self::assertTrue($this->desk()->addGuest(self::NIGHT, 1, ' Ann ', 2));
        self::assertSame('Ann', $this->wpdb->inserts[0]['data']['name']);
        self::assertSame(2, $this->wpdb->inserts[0]['data']['plus_ones']);
        self::assertStringContainsString("'guest', 1, 0,", $this->wpdb->lastQuery());
    }

    public function testAGuestNeedsAName(): void
    {
        self::assertFalse($this->desk()->addGuest(self::NIGHT, 1, '  ', 0));
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testTheMemberSearchListsSomeoneAlreadyOnTonightsListAndSaysSo(): void
    {
        $this->wpdb->nextResults[] = [$this->taskRow(5, 'decorate', '10:00', '11:00')]; // tasks->forDate
        $this->wpdb->nextResults[] = [$this->assignmentRow(30, 5, 8, 'signed_up')];     // assignments->forTask
        $this->wpdb->nextRows[] = $this->personRow(8);                                  // nameOf(8)
        $this->wpdb->nextResults[] = [];                                                // redemptions->forDate
        $this->wpdb->nextResults[] = [$this->personRow(8), $this->personRow(9)];        // people->all

        $members = $this->desk()->searchMembers(self::NIGHT, 'Person');

        self::assertSame([8, 9], array_column($members, 'id'));
        self::assertSame(['Crew working', ''], array_column($members, 'list'));
        self::assertSame(['crew', ''], array_column($members, 'kind'));
    }

    public function testWorkingTonightWithAFreeEntryTicketCancelsItAndLogsWhy(): void
    {
        $this->wpdb->nextRows[] = $this->personRow(8);                              // people->find
        $this->wpdb->nextRows[] = $this->taskRow(5, 'decorate', '10:00', '11:00');  // tasks->find
        $this->wpdb->nextVars[] = 3;                                                // join: capacity
        $this->wpdb->nextRows[] = null;                                             // join: no row yet
        $this->wpdb->nextQueryResults[] = 1;                                        // join: inserted
        $this->wpdb->nextQueryResults[] = 1;                                        // the check-in
        $this->wpdb->nextResults[] = [$this->taskRow(5, 'decorate', '10:00', '11:00')];
        $this->wpdb->nextRows[] = $this->assignmentRow(30, 5, 8, 'signed_up');
        $this->wpdb->nextResults[] = [                                              // redemptions->forDate
            ['id' => 44, 'person_id' => 8, 'redeemed_for' => self::NIGHT],
            ['id' => 45, 'person_id' => 9, 'redeemed_for' => self::NIGHT],
        ];

        $desk = $this->desk();

        self::assertSame(DoorDesk::WORK_SWAPPED, $desk->workTonight(self::NIGHT, 1, 8, 5));
        self::assertSame([['id' => 44]], array_column($this->wpdb->deletes, 'where'));
        $grant = end($this->wpdb->inserts)['data'];
        self::assertSame(8, $grant['person_id']);
        self::assertSame(0, $grant['credits']);
        self::assertSame('Ticket exchanged against taking a task', $grant['note']);
        self::assertSame(1, $grant['granted_by_person_id']);
        self::assertStringContainsString('credit is given back', $desk->workTonightText(DoorDesk::WORK_SWAPPED));
    }

    public function testOpenTasksListsTonightsTasksWithAFreePlace(): void
    {
        $full = $this->taskRow(6, 'bar');
        $leader = $this->taskRow(7, Roles::LEADER_SLUG);
        $this->wpdb->nextRows[] = $this->personRow(8);
        $this->wpdb->nextResults[] = [$this->taskRow(5, 'decorate', '10:00', '11:00'), $full, $leader];
        $this->wpdb->nextResults[] = [['task_id' => 5, 'taken' => 1], ['task_id' => 6, 'taken' => 3]];

        $tasks = $this->desk()->openTasks(self::NIGHT, 8);

        self::assertCount(1, $tasks);
        self::assertSame(5, $tasks[0]['id']);
        self::assertSame(2, $tasks[0]['free']);
    }

    public function testWorkingTonightSignsUpChecksInAndMarksArrived(): void
    {
        $this->wpdb->nextRows[] = $this->personRow(8);                              // people->find
        $this->wpdb->nextRows[] = $this->taskRow(5, 'decorate', '10:00', '11:00');  // tasks->find
        $this->wpdb->nextVars[] = 3;                                                // join: capacity
        $this->wpdb->nextRows[] = null;                                             // join: no row yet
        $this->wpdb->nextQueryResults[] = 1;                                        // join: inserted
        $this->wpdb->nextQueryResults[] = 1;                                        // the check-in
        $this->wpdb->nextResults[] = [$this->taskRow(5, 'decorate', '10:00', '11:00')];
        $this->wpdb->nextRows[] = $this->assignmentRow(30, 5, 8, 'signed_up');

        self::assertSame(DoorDesk::WORK_OK, $this->desk()->workTonight(self::NIGHT, 1, 8, 5));
        self::assertStringContainsString("'crew', 8, 0,", implode("\n", $this->wpdb->queries));
        self::assertSame('arrived', $this->wpdb->updates[0]['data']['status']);
    }

    public function testWorkingTonightOnAFullTaskChecksNobodyIn(): void
    {
        $this->wpdb->nextRows[] = $this->personRow(8);
        $this->wpdb->nextRows[] = $this->taskRow(5, 'decorate');
        $this->wpdb->nextVars[] = 3;
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextQueryResults[] = 0;

        $desk = $this->desk();

        self::assertSame(DoorDesk::WORK_FULL, $desk->workTonight(self::NIGHT, 1, 8, 5));
        self::assertStringNotContainsString('wp_eventcrew_checkins', implode("\n", $this->wpdb->queries));
        self::assertSame(
            'That task has just filled up. Pick another one.',
            $desk->workTonightText(DoorDesk::WORK_FULL)
        );
    }

    public function testWorkingTonightRefusesALeaderTaskAndAnotherNightsTask(): void
    {
        $this->wpdb->nextRows[] = $this->personRow(8);
        $this->wpdb->nextRows[] = $this->taskRow(7, Roles::LEADER_SLUG);

        self::assertSame(DoorDesk::WORK_LEADER_ONLY, $this->desk()->workTonight(self::NIGHT, 1, 8, 7));

        $elsewhere = $this->taskRow(5, 'decorate');
        $elsewhere['task_date'] = '2026-07-27';
        $this->wpdb->nextRows[] = $this->personRow(8);
        $this->wpdb->nextRows[] = $elsewhere;

        self::assertSame(DoorDesk::WORK_UNAVAILABLE, $this->desk()->workTonight(self::NIGHT, 1, 8, 5));
        self::assertSame([], $this->wpdb->inserts);
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

    public function testAGuestRowStartsAtNoExtraPeopleAndSaysHowManyAreAllowed(): void
    {
        $this->queueState([]);

        $state = $this->desk()->state(self::NIGHT);
        $row = $state['sections'][2]['rows'][0];

        self::assertSame(0, $row['plus']);
        self::assertSame(1, $row['max']);
        self::assertSame('allowed +1', $row['detail']);
        // Ann and the one person she may bring.
        self::assertSame(2, $state['expected']);
    }

    public function testDoorTicketsHaveTheirOwnList(): void
    {
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [
            ['id' => 9, 'event_date' => self::NIGHT, 'name' => 'Bea', 'source' => 'paste'],
            ['id' => 10, 'event_date' => self::NIGHT, 'name' => '', 'source' => 'door'],
        ];

        $sections = $this->desk()->state(self::NIGHT)['sections'];

        self::assertSame(['crew', 'credit', 'guest', 'buyer', 'door'], array_column($sections, 'key'));
        self::assertSame(['Bea'], array_column($sections[3]['rows'], 'name'));
        self::assertSame(['No name'], array_column($sections[4]['rows'], 'name'));
    }
}
