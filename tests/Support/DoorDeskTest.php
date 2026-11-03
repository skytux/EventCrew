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
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\DoorDesk;
use EventCrew\Support\DoorSession;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\MarkedBy;
use EventCrew\Support\Roles;
use EventCrew\Support\SignedLink;
use EventCrew\Support\TicketMail;
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

    private function desk(?ClaimNotifier $notifier = null): DoorDesk
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
            new CreditGrantRepository(),
            $notifier
        );
    }

    private function notifier(): ClaimNotifier
    {
        return new ClaimNotifier(
            new TaskRepository(),
            new AssignmentRepository(),
            new Mailer(new Logger(), new EmailTemplate(new Logger())),
            $this->client(),
            $this->standing()
        );
    }

    /**
     * Signs person 8 up for task 9 at the desk and answers the emails sent.
     *
     * @return list<string>
     */
    private function workTonightMails(string $role, bool $organizer): array
    {
        $mails = [];
        Functions\when('get_the_title')->justReturn('');
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body) use (&$mails): bool {
            $mails[] = $to . "\n" . $body;

            return true;
        });

        $person = $this->personRow(8, $organizer);
        $person['email_verified_at'] = '2026-07-01 00:00:00';
        $this->wpdb->nextRows[] = $person;                         // people->find
        $this->wpdb->nextRows[] = $this->taskRow(9, $role);        // tasks->find
        $this->wpdb->nextVars[] = 3;                               // join: capacity
        $this->wpdb->nextRows[] = null;                            // join: no row yet
        $this->wpdb->nextQueryResults[] = 1;                       // join: inserted
        $this->wpdb->nextQueryResults[] = 1;                       // the check-in
        $this->wpdb->nextResults[] = [$this->taskRow(9, $role)];
        $this->wpdb->nextRows[] = $this->assignmentRow(30, 9, 8, 'signed_up');
        $this->wpdb->nextRows[] = $this->taskRow(9, $role);        // confirmation: tasks->find
        $this->wpdb->nextRows[] = $this->assignmentRow(30, 9, 8, 'arrived'); // confirmation: findFor

        self::assertSame(DoorDesk::WORK_OK, $this->desk($this->notifier())->workTonight(self::NIGHT, 1, 8, 9, true));

        return $mails;
    }

    public function testAnOrganizerSignedUpForOrganizeAtTheDeskGetsTheTicket(): void
    {
        $mails = $this->workTonightMails(Roles::ORGANIZE_SLUG, true);

        self::assertCount(1, $mails);
        self::assertStringStartsWith('p8@example.com', $mails[0]);
        self::assertStringContainsString('wp-json/eventcrew/v1/ticket?token=', $mails[0]);
    }

    public function testAnyTaskSignedUpAtTheDeskSendsTheTicket(): void
    {
        $mails = $this->workTonightMails('decorate', false);

        self::assertCount(1, $mails);
        self::assertStringContainsString('wp-json/eventcrew/v1/ticket?token=', $mails[0]);
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

    public function testThePlusOnesOfATicketSoldAtTheDoorCanBeChanged(): void
    {
        $this->wpdb->nextResults[] = [['id' => 9, 'event_date' => self::NIGHT, 'name' => 'Bea', 'source' => 'door']];

        self::assertTrue($this->desk()->setPlusOnes(self::NIGHT, CheckinRepository::KIND_BUYER, 9, 3));
        self::assertSame(['plus_ones' => 3], $this->wpdb->updates[0]['data']);
    }

    public function testAHolviTicketHasNoPlusOnes(): void
    {
        $this->wpdb->nextResults[] = [['id' => 9, 'event_date' => self::NIGHT, 'name' => 'Bea', 'source' => 'paste']];

        self::assertTrue($this->desk()->setPlusOnes(self::NIGHT, CheckinRepository::KIND_BUYER, 9, 3));
        self::assertSame(['plus_ones' => 0], $this->wpdb->updates[0]['data']);
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

    /** A desk buyer with an email gets an EC-B ticket code and the ticket mail run is queued. */
    public function testAHolviBuyerWithAnEmailGetsATicketCodeAndTheMailRunIsQueued(): void
    {
        $this->wpdb->nextQueryResults[] = 1;
        $scheduled = [];
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->alias(function (int $time, string $hook) use (&$scheduled): bool {
            $scheduled[] = $hook;

            return true;
        });

        self::assertSame(DoorDesk::HOLVI_OK, $this->desk()->addHolviBuyer(self::NIGHT, 1, 'Ada', 'ada@example.com'));

        $update = $this->wpdb->updates[0];
        self::assertSame('wp_eventcrew_ticket_buyers', $update['table']);
        $code = $update['data']['ticket_code'];
        self::assertMatchesRegularExpression('/^EC-B\d+-[0-9a-f]{16}$/', $code);
        self::assertSame($update['where']['id'], TicketMail::deskBuyerIdFromCode($code));
        // The send waits TicketMail::DELAY first: its start event is queued.
        self::assertSame([TicketMail::START_HOOK], $scheduled);
    }

    /** No email, no ticket: no code is written and no mail run is queued. */
    public function testAHolviBuyerWithoutAnEmailGetsNoTicketCode(): void
    {
        $this->wpdb->nextQueryResults[] = 1;

        $this->desk()->addHolviBuyer(self::NIGHT, 1, 'Ada', '');

        self::assertSame([], $this->wpdb->updates);
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
        // Holvi tickets get no +1 on the desk; a ticket sold at the door does.
        self::assertSame([0, 0], array_column($sections[3]['rows'], 'max'));
        self::assertSame([CheckinRepository::MAX_PLUS_ONES], array_column($sections[4]['rows'], 'max'));
    }

    public function testTheCountWithoutANameIsChangedInsideTheDatabaseInOneStatement(): void
    {
        global $wpdb;
        $name = DoorDesk::ANONYMOUS_OPTION_PREFIX . self::NIGHT;
        $desk = $this->desk();

        $this->options[$name] = 4;
        $wpdb->queries = [];
        self::assertSame(4, $desk->countAnonymous(self::NIGHT, 1));

        // One statement adds 1 inside the database: two phones pressing at
        // once both count. The answer is what the database then holds.
        self::assertCount(1, $wpdb->queries);
        self::assertSame(
            "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('{$name}', 1, 'no')"
            . ' ON DUPLICATE KEY UPDATE option_value = GREATEST(CAST(option_value AS SIGNED) + 1, 0)',
            $wpdb->queries[0]
        );
        // Nothing was written from PHP, which would overwrite a press made meanwhile.
        self::assertSame(4, $this->options[$name]);

        $wpdb->queries = [];
        $desk->countAnonymous(self::NIGHT, -1);
        // Taking one off never goes below 0, and a first press of -1 stores 0.
        self::assertSame(
            "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('{$name}', 0, 'no')"
            . ' ON DUPLICATE KEY UPDATE option_value = GREATEST(CAST(option_value AS SIGNED) + -1, 0)',
            $wpdb->queries[0]
        );
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
        // The ticket is cancelled, never deleted: its link shows DISABLED.
        self::assertSame([], $this->wpdb->deletes);
        self::assertNotEmpty(array_filter(
            $this->wpdb->queries,
            static fn (string $sql): bool => str_contains($sql, 'SET cancelled_at')
                && str_contains($sql, 'WHERE id = 44 AND cancelled_at IS NULL')
        ));
        $grant = end($this->wpdb->inserts)['data'];
        self::assertSame(8, $grant['person_id']);
        self::assertSame(0, $grant['credits']);
        self::assertSame('Ticket exchanged against taking a task', $grant['note']);
        self::assertSame(1, $grant['granted_by_person_id']);
        self::assertStringContainsString('credit is given back', $desk->workTonightText(DoorDesk::WORK_SWAPPED));
    }

    public function testOpenTasksListsTonightsTasksWithAFreePlace(): void
    {
        $this->withDjRole();
        $full = $this->taskRow(6, 'bar');
        $dj = $this->taskRow(7, 'dj');
        $this->wpdb->nextRows[] = $this->personRow(8);
        $this->wpdb->nextResults[] = [$this->taskRow(5, 'decorate', '10:00', '11:00'), $full, $dj];
        $this->wpdb->nextResults[] = [['task_id' => 5, 'taken' => 1], ['task_id' => 6, 'taken' => 3]];

        $tasks = $this->desk()->openTasks(self::NIGHT, 8);

        self::assertCount(1, $tasks);
        self::assertSame(5, $tasks[0]['id']);
        self::assertSame(2, $tasks[0]['free']);
    }

    public function testOpenTasksMarksTheTasksTheyHoldEvenAFullOne(): void
    {
        $full = $this->taskRow(6, 'bar');
        $this->wpdb->nextRows[] = $this->personRow(8);
        $this->wpdb->nextResults[] = [$this->taskRow(5, 'decorate', '10:00', '11:00'), $full];
        $this->wpdb->nextResults[] = [['task_id' => 5, 'taken' => 1], ['task_id' => 6, 'taken' => 3]];
        $this->wpdb->nextResults[] = [
            $this->assignmentRow(30, 6, 8, 'signed_up'),
            $this->assignmentRow(31, 5, 8, 'cancelled'),
        ];

        $tasks = $this->desk()->openTasks(self::NIGHT, 8);

        self::assertSame([5, 6], array_column($tasks, 'id'));
        self::assertSame([false, true], array_column($tasks, 'mine'));
    }

    public function testOpenTasksLeavesOutTheOrganizeTaskForSomeoneWhoIsNotAnOrganizer(): void
    {
        $organize = $this->taskRow(9, Roles::ORGANIZE_SLUG);
        $organize['capacity'] = 1;
        $this->wpdb->nextRows[] = $this->personRow(8);
        $this->wpdb->nextResults[] = [$this->taskRow(5, 'decorate'), $organize];
        $this->wpdb->nextResults[] = [];

        $tasks = $this->desk()->openTasks(self::NIGHT, 8);

        self::assertSame([5], array_column($tasks, 'id'));
    }

    /**
     * An organizer sees the organize task, with no free-place count, even when
     * more people are on it than its stored capacity.
     */
    public function testOpenTasksShowsTheOrganizeTaskToAnOrganizerWithNoLimit(): void
    {
        $organize = $this->taskRow(9, Roles::ORGANIZE_SLUG);
        $organize['capacity'] = 1;
        $this->wpdb->nextRows[] = $this->personRow(8, true);
        $this->wpdb->nextResults[] = [$organize];
        $this->wpdb->nextResults[] = [['task_id' => 9, 'taken' => 4]];

        $tasks = $this->desk()->openTasks(self::NIGHT, 8);

        self::assertSame([9], array_column($tasks, 'id'));
        self::assertNull($tasks[0]['free']);
    }

    /**
     * Settings > Roles with a "DJ" role set to "Only people with this role".
     */
    private function withDjRole(): void
    {
        $this->options[Roles::OPTION_NAME] = [
            ['slug' => 'decorate', 'label' => 'Decorate'],
            ['slug' => 'dj', 'label' => 'DJ', 'emoji' => '🎧', 'restricted' => true],
        ];
    }

    public function testOpenTasksShowsARestrictedTaskOnlyToSomeoneWithItsRole(): void
    {
        $this->withDjRole();
        $dj = $this->taskRow(9, 'dj');
        $dj['capacity'] = 1;

        $this->wpdb->nextRows[] = $this->personRow(8);
        $this->wpdb->nextResults[] = [$this->taskRow(5, 'decorate'), $dj];
        $this->wpdb->nextResults[] = [];

        self::assertSame([5], array_column($this->desk()->openTasks(self::NIGHT, 8), 'id'));

        $holder = $this->personRow(8);
        $holder['role_slugs'] = 'dj';
        $this->wpdb->nextRows[] = $holder;
        $this->wpdb->nextResults[] = [$this->taskRow(5, 'decorate'), $dj];
        $this->wpdb->nextResults[] = [['task_id' => 9, 'taken' => 4]];

        $tasks = $this->desk()->openTasks(self::NIGHT, 8);

        self::assertSame([5, 9], array_column($tasks, 'id'));
        // No limit: shown with no free-place count although 4 > capacity 1,
        // and the desk asks the credit question for it.
        self::assertNull($tasks[1]['free']);
        self::assertSame([false, true], array_column($tasks, 'ask_credit'));
    }

    public function testWorkingTonightOnARestrictedTaskNeedsTheRoleAndTheCreditAnswer(): void
    {
        $this->withDjRole();

        $this->wpdb->nextRows[] = $this->personRow(8);
        $this->wpdb->nextRows[] = $this->taskRow(9, 'dj');

        self::assertSame(DoorDesk::WORK_UNAVAILABLE, $this->desk()->workTonight(self::NIGHT, 1, 8, 9));

        $holder = $this->personRow(8);
        $holder['role_slugs'] = 'dj';
        $this->wpdb->nextRows[] = $holder;
        $this->wpdb->nextRows[] = $this->taskRow(9, 'dj');

        self::assertSame(DoorDesk::WORK_CREDIT_QUESTION, $this->desk()->workTonight(self::NIGHT, 1, 8, 9));
        self::assertStringNotContainsString('INSERT', implode("\n", $this->wpdb->queries));

        $this->wpdb->nextRows[] = $holder;
        $this->wpdb->nextRows[] = $this->taskRow(9, 'dj');
        $this->wpdb->nextVars[] = 3;            // join: capacity
        $this->wpdb->nextRows[] = null;         // join: no row yet
        $this->wpdb->nextQueryResults[] = 1;    // join: inserted
        $this->wpdb->nextQueryResults[] = 1;    // the check-in

        $this->desk()->workTonight(self::NIGHT, 1, 8, 9, false);

        $insert = (string) current(array_filter(
            $this->wpdb->queries,
            static fn (string $sql): bool => str_contains($sql, 'counts_credit)')
        ));
        self::assertStringContainsString("SELECT 9, 8, 'signed_up', '2026-07-20 12:00:00', 0", $insert);
    }

    /**
     * An organizer at the desk sees every task of the night with a free place
     * and can sign anyone up to any of them, role or not.
     */
    public function testAnOrganizerAtTheDeskSeesAndFillsATaskOfARoleThePersonDoesNotHold(): void
    {
        $this->withDjRole();
        $dj = $this->taskRow(9, 'dj');

        $this->wpdb->nextRows[] = $this->personRow(8);              // the member
        $this->wpdb->nextResults[] = [$this->taskRow(5, 'decorate'), $dj];
        $this->wpdb->nextResults[] = [];                            // occupancy
        $this->wpdb->nextResults[] = [];                            // held tasks
        $this->wpdb->nextRows[] = $this->personRow(1, true);        // the door person

        self::assertSame([5, 9], array_column($this->desk()->openTasks(self::NIGHT, 8, 1), 'id'));

        $this->wpdb->nextRows[] = $this->personRow(8);              // people->find
        $this->wpdb->nextRows[] = $dj;                              // tasks->find
        $this->wpdb->nextRows[] = $this->personRow(1, true);        // the door person
        $this->wpdb->nextVars[] = 3;                                // join: capacity
        $this->wpdb->nextRows[] = null;                             // join: no row yet
        $this->wpdb->nextQueryResults[] = 1;                        // join: inserted
        $this->wpdb->nextQueryResults[] = 1;                        // the check-in

        $this->desk()->workTonight(self::NIGHT, 1, 8, 9, true);

        self::assertStringContainsString(
            "SELECT 9, 8, 'signed_up'",
            implode("\n", $this->wpdb->queries)
        );
    }

    public function testCrewAtTheDeskCannotFillATaskOfARoleThePersonDoesNotHold(): void
    {
        $this->withDjRole();
        $this->wpdb->nextRows[] = $this->personRow(8);   // people->find
        $this->wpdb->nextRows[] = $this->taskRow(9, 'dj'); // tasks->find
        $this->wpdb->nextRows[] = $this->personRow(1);   // the door person: Welcome crew

        self::assertSame(DoorDesk::WORK_UNAVAILABLE, $this->desk()->workTonight(self::NIGHT, 1, 8, 9, true));
        self::assertStringNotContainsString('INSERT', implode("\n", $this->wpdb->queries));
    }

    public function testWorkingTonightRefusesTheOrganizeTaskToSomeoneWhoIsNotAnOrganizer(): void
    {
        $this->wpdb->nextRows[] = $this->personRow(8);
        $this->wpdb->nextRows[] = $this->taskRow(9, Roles::ORGANIZE_SLUG);
        // What a join would read if it got that far, so only the check stops it.
        $this->wpdb->nextVars[] = 3;
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextQueryResults[] = 1;

        self::assertSame(DoorDesk::WORK_UNAVAILABLE, $this->desk()->workTonight(self::NIGHT, 1, 8, 9));
        self::assertSame([], $this->wpdb->inserts);
        self::assertStringNotContainsString('INSERT', implode("\n", $this->wpdb->queries));
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
        self::assertSame(1, $this->wpdb->updates[0]['data']['changed_by']);
        self::assertSame(MarkedBy::PERSON, $this->wpdb->updates[0]['data']['changed_by_kind']);
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

    public function testWorkingTonightRefusesAnotherNightsTask(): void
    {
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
        // Recorded as the door person's EventCrew person id.
        self::assertSame(1, $update['data']['changed_by']);
        self::assertSame(MarkedBy::PERSON, $update['data']['changed_by_kind']);
    }

    public function testMarkingCrewOutPutsTheirArrivedAssignmentBackToSignedUp(): void
    {
        // findRow: the night's tasks, the task's crew, the crew member's name,
        // and no redemptions.
        $this->wpdb->nextResults[] = [$this->taskRow(5, 'decorate', '10:00', '11:00')];
        $this->wpdb->nextResults[] = [$this->assignmentRow(30, 5, 8, 'arrived')];
        $this->wpdb->nextRows[] = $this->personRow(8);
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextQueryResults[] = 1; // the check-in delete
        // markCrewSignedOut: the night's tasks and the member's assignment there.
        $this->wpdb->nextResults[] = [$this->taskRow(5, 'decorate', '10:00', '11:00')];
        $this->wpdb->nextRows[] = $this->assignmentRow(30, 5, 8, 'arrived');

        self::assertTrue($this->desk()->mark(self::NIGHT, 1, CheckinRepository::KIND_CREW, 8, false, 0));

        self::assertCount(1, $this->wpdb->updates);
        $update = $this->wpdb->updates[0];
        self::assertSame('signed_up', $update['data']['status']);
        self::assertSame(['id' => 30], $update['where']);
        self::assertSame(1, $update['data']['changed_by']);
        self::assertSame(MarkedBy::PERSON, $update['data']['changed_by_kind']);
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

    public function testScanningTonightsCrewTicketFindsTheirRowWithoutMarkingThem(): void
    {
        $this->wpdb->nextRows[] = $this->assignmentRow(30, 5, 8, 'signed_up');   // assignments->find
        $this->wpdb->nextRows[] = $this->taskRow(5, 'decorate');                 // tasks->find
        $this->wpdb->nextRows[] = $this->personRow(8);                           // the name
        $this->queueCrewEntrants();

        $url = 'https://site.test/wp-json/eventcrew/v1/ticket?token=' . SignedLink::sign('ticket', 30);
        $scan = $this->desk()->scan(self::NIGHT, $url);

        self::assertSame(DoorDesk::SCAN_OK, $scan['code']);
        self::assertSame('crew:8', $scan['key']);
        self::assertSame('Person 8', $scan['name']);
        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->wpdb->updates);
    }

    public function testABareTokenScansTheSameAsTheLink(): void
    {
        $this->wpdb->nextRows[] = $this->assignmentRow(30, 5, 8, 'signed_up');
        $this->wpdb->nextRows[] = $this->taskRow(5, 'decorate');
        $this->wpdb->nextRows[] = $this->personRow(8);
        $this->queueCrewEntrants();

        self::assertSame('crew:8', $this->desk()->scan(self::NIGHT, ' ' . SignedLink::sign('ticket', 30) . ' ')['key']);
    }

    public function testAScanBeforeTheWindowOpensSaysWhenItOpens(): void
    {
        $this->wpdb->nextRows[] = $this->assignmentRow(30, 5, 8, 'signed_up');
        $this->wpdb->nextRows[] = $this->taskRow(5, 'decorate');
        $this->wpdb->nextRows[] = $this->personRow(8);
        $this->queueCrewEntrants();
        // The scan window's read of the night: Welcome starts at 19:00, so
        // scans open at 18:00, after the clock's 12:00.
        $this->wpdb->nextResults[] = [$this->taskRow(6, 'welcome', '19:00', '23:00')];

        $desk = $this->desk();
        $scan = $desk->scan(self::NIGHT, SignedLink::sign('ticket', 30));

        self::assertSame(DoorDesk::SCAN_TOO_EARLY, $scan['code']);
        self::assertSame('', $scan['key']);
        self::assertSame(
            'Ticket scanning opens at 18:00.',
            $desk->scanText($scan['code'], $scan['name'], $scan['date'], $scan['at'])
        );
    }

    public function testAScanAfterTheEventEndedSaysScanningClosed(): void
    {
        $this->wpdb->nextRows[] = $this->assignmentRow(30, 5, 8, 'signed_up');
        $this->wpdb->nextRows[] = $this->taskRow(5, 'decorate');
        $this->wpdb->nextRows[] = $this->personRow(8);
        $this->queueCrewEntrants();
        // The night ran 08:00 to 11:00; the clock reads 12:00.
        $this->wpdb->nextResults[] = [$this->taskRow(6, 'welcome', '08:00', '11:00')];

        $desk = $this->desk();
        $scan = $desk->scan(self::NIGHT, SignedLink::sign('ticket', 30));

        self::assertSame(DoorDesk::SCAN_CLOSED, $scan['code']);
        self::assertSame(
            'Ticket scanning closed at 11:00.',
            $desk->scanText($scan['code'], $scan['name'], $scan['date'], $scan['at'])
        );
    }

    public function testAScanInsideTheWindowFindsTheRow(): void
    {
        $this->wpdb->nextRows[] = $this->assignmentRow(30, 5, 8, 'signed_up');
        $this->wpdb->nextRows[] = $this->taskRow(5, 'decorate');
        $this->wpdb->nextRows[] = $this->personRow(8);
        $this->queueCrewEntrants();
        // Welcome at 12:30: scans opened at 11:30 and close at 14:00.
        $this->wpdb->nextResults[] = [$this->taskRow(6, 'welcome', '12:30', '14:00')];

        self::assertSame(DoorDesk::SCAN_OK, $this->desk()->scan(self::NIGHT, SignedLink::sign('ticket', 30))['code']);
    }

    /** A guest's emailed ticket link scans to their row on the guest list. */
    public function testScanningAGuestsEmailedTicketFindsTheirGuestRow(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 12,
            'event_date' => self::NIGHT,
            'name' => 'Cy Cole',
            'plus_ones' => 2,
            'email' => 'cy@example.test',
        ];

        $url = 'https://site.test/wp-json/eventcrew/v1/ticket?token=' . SignedLink::sign('guest_ticket', 12);
        $scan = $this->desk()->scan(self::NIGHT, $url);

        self::assertSame(DoorDesk::SCAN_OK, $scan['code']);
        self::assertSame('guest:12', $scan['key']);
        self::assertSame('Cy Cole', $scan['name']);
        self::assertSame([], $this->wpdb->inserts);
    }

    /** The short signed code in a guest's emailed QR scans to their row. */
    public function testScanningAGuestsShortQrCodeFindsTheirGuestRow(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 12,
            'event_date' => self::NIGHT,
            'name' => 'Cy Cole',
            'plus_ones' => 2,
            'email' => 'cy@example.test',
        ];

        $scan = $this->desk()->scan(self::NIGHT, TicketMail::guestCode(12));

        self::assertSame(DoorDesk::SCAN_OK, $scan['code']);
        self::assertSame('guest:12', $scan['key']);
        self::assertSame('Cy Cole', $scan['name']);
        self::assertSame([], $this->wpdb->inserts);
    }

    /** The short EC-C code in a crew sign-up's ticket email scans to their crew row. */
    public function testScanningACrewShortQrCodeFindsTheirCrewRow(): void
    {
        $this->wpdb->nextRows[] = $this->assignmentRow(30, 5, 8, 'signed_up');
        $this->wpdb->nextRows[] = $this->taskRow(5, 'decorate');
        $this->wpdb->nextRows[] = $this->personRow(8);
        $this->queueCrewEntrants();

        $scan = $this->desk()->scan(self::NIGHT, ' ' . TicketMail::crewCode(30) . ' ');

        self::assertSame(DoorDesk::SCAN_OK, $scan['code']);
        self::assertSame('crew:8', $scan['key']);
        self::assertSame([], $this->wpdb->inserts);
    }

    /** The short EC-F code in a credit's ticket email scans to the credit row. */
    public function testScanningACreditShortQrCodeFindsTheCreditRow(): void
    {
        $credit = ['id' => 44, 'person_id' => 9, 'redeemed_for' => self::NIGHT];
        $this->wpdb->nextRows[] = $credit;
        $this->wpdb->nextRows[] = $this->personRow(9);
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [$credit];
        $this->wpdb->nextRows[] = $this->personRow(9);

        $scan = $this->desk()->scan(self::NIGHT, TicketMail::creditCode(44));

        self::assertSame(DoorDesk::SCAN_OK, $scan['code']);
        self::assertSame('credit:9', $scan['key']);
    }

    /** The EC-B code in a desk buyer's ticket email scans to their Holvi row. */
    public function testScanningADeskBuyerShortQrCodeFindsTheirBuyerRow(): void
    {
        $code = TicketMail::deskBuyerCode(17);
        $this->wpdb->nextRows[] = ['id' => 17, 'event_date' => self::NIGHT, 'name' => 'Ada', 'source' => 'desk', 'ticket_code' => $code];

        $scan = $this->desk()->scan(self::NIGHT, $code);

        self::assertSame(DoorDesk::SCAN_OK, $scan['code']);
        self::assertSame('buyer:17', $scan['key']);
        self::assertSame('Ada', $scan['name']);
    }

    /** A desk buyer row that lost its code no longer has a ticket. */
    public function testADeskBuyerCodeNoLongerOnTheRowIsRefused(): void
    {
        $this->wpdb->nextRows[] = ['id' => 17, 'event_date' => self::NIGHT, 'name' => 'Ada', 'source' => 'desk', 'ticket_code' => null];

        self::assertSame(DoorDesk::SCAN_DISABLED, $this->desk()->scan(self::NIGHT, TicketMail::deskBuyerCode(17))['code']);
    }

    /** Codes of the three new kinds with a changed signature are not ours. */
    public function testTamperedCrewCreditAndBuyerCodesAreNotOurs(): void
    {
        $desk = $this->desk();

        foreach ([TicketMail::crewCode(30), TicketMail::creditCode(44), TicketMail::deskBuyerCode(17)] as $code) {
            $forged = substr($code, 0, -1) . ('0' === substr($code, -1) ? '1' : '0');
            self::assertSame(DoorDesk::SCAN_NOT_OURS, $desk->scan(self::NIGHT, $forged)['code'], $forged);
        }

        self::assertSame([], $this->wpdb->queries);
    }

    /** A guest code with a changed signature, or another guest's id, is not ours. */
    public function testATamperedGuestCodeIsNotOneOfOurs(): void
    {
        $code = TicketMail::guestCode(12);
        $last = substr($code, -1);
        $desk = $this->desk();

        foreach ([substr($code, 0, -1) . ('0' === $last ? '1' : '0'), 'EC-G13-' . substr($code, -16)] as $text) {
            $scan = $desk->scan(self::NIGHT, $text);
            self::assertSame(DoorDesk::SCAN_NOT_OURS, $scan['code'], $text);
        }

        self::assertSame([], $this->wpdb->queries, 'a forged code reads nothing');
    }

    public function testAGuestCodeForAnotherNightSaysWhichNight(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 12,
            'event_date' => '2026-12-12',
            'name' => 'Cy Cole',
            'plus_ones' => 0,
            'email' => 'cy@example.test',
        ];

        $scan = $this->desk()->scan(self::NIGHT, TicketMail::guestCode(12));

        self::assertSame(DoorDesk::SCAN_OTHER_NIGHT, $scan['code']);
        self::assertSame('2026-12-12', $scan['date']);
        self::assertSame('Cy Cole', $scan['name']);
    }

    /** A guest whose email address was deleted no longer has a ticket. */
    public function testAGuestTicketWithItsEmailDeletedNoLongerScans(): void
    {
        $this->wpdb->nextRows[] = ['id' => 12, 'event_date' => self::NIGHT, 'name' => 'Cy Cole', 'email' => null];

        $scan = $this->desk()->scan(self::NIGHT, SignedLink::sign('guest_ticket', 12));

        self::assertSame(DoorDesk::SCAN_DISABLED, $scan['code']);
        self::assertSame('', $scan['key']);
    }

    public function testATicketForAnotherNightSaysWhichNight(): void
    {
        $other = ['task_date' => '2026-12-12'] + $this->taskRow(5, 'decorate');
        $this->wpdb->nextRows[] = $this->assignmentRow(30, 5, 8, 'signed_up');
        $this->wpdb->nextRows[] = $other;
        $this->wpdb->nextRows[] = $this->personRow(8);

        $desk = $this->desk();
        $scan = $desk->scan(self::NIGHT, SignedLink::sign('ticket', 30));

        self::assertSame(DoorDesk::SCAN_OTHER_NIGHT, $scan['code']);
        self::assertSame('2026-12-12', $scan['date']);
        self::assertStringStartsWith('Person 8: this ticket is for ', $desk->scanText($scan['code'], $scan['name'], $scan['date']));
    }

    public function testACancelledSignUpIsRefused(): void
    {
        $this->wpdb->nextRows[] = $this->assignmentRow(30, 5, 8, 'cancelled');
        $this->wpdb->nextRows[] = $this->taskRow(5, 'decorate');
        $this->wpdb->nextRows[] = $this->personRow(8);

        $desk = $this->desk();
        $scan = $desk->scan(self::NIGHT, SignedLink::sign('ticket', 30));

        self::assertSame(DoorDesk::SCAN_DISABLED, $scan['code']);
        self::assertSame('Person 8: this ticket is cancelled.', $desk->scanText($scan['code'], $scan['name'], $scan['date']));
    }

    public function testTonightsCreditTicketFindsTheCreditRow(): void
    {
        $credit = ['id' => 44, 'person_id' => 9, 'redeemed_for' => self::NIGHT];
        $this->wpdb->nextRows[] = $credit;                // redemptions->find
        $this->wpdb->nextRows[] = $this->personRow(9);    // the name
        $this->wpdb->nextResults[] = [];                  // tonight's tasks: none
        $this->wpdb->nextResults[] = [$credit];           // tonight's redemptions
        $this->wpdb->nextRows[] = $this->personRow(9);

        $scan = $this->desk()->scan(self::NIGHT, SignedLink::sign('credit_ticket', 44));

        self::assertSame(DoorDesk::SCAN_OK, $scan['code']);
        self::assertSame('credit:9', $scan['key']);
    }

    public function testSomeOtherQrCodeIsNotOneOfOurs(): void
    {
        $desk = $this->desk();

        foreach (['https://example.com/?token=abc.def', 'hello', SignedLink::sign('manage', 30)] as $text) {
            $scan = $desk->scan(self::NIGHT, $text);
            self::assertSame(DoorDesk::SCAN_NOT_OURS, $scan['code'], $text);
        }

        self::assertSame('This QR code is not one of our tickets.', $desk->scanText(DoorDesk::SCAN_NOT_OURS, '', ''));
    }

    /**
     * Queues entrants() for one crew member, person 8 on task 5: the night's
     * tasks, the task's crew, the name, and no redemptions.
     */
    private function queueCrewEntrants(): void
    {
        $this->wpdb->nextResults[] = [$this->taskRow(5, 'decorate')];
        $this->wpdb->nextResults[] = [$this->assignmentRow(30, 5, 8, 'signed_up')];
        $this->wpdb->nextRows[] = $this->personRow(8);
        $this->wpdb->nextResults[] = [];
    }

    public function testACrewRowMayBringTheSumOfItsTasksPlusOnes(): void
    {
        // A DJ (+1) who is also the host (+1) tonight may bring two.
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [
            ['plus_ones' => 1] + $this->taskRow(5, 'dj'),
            ['plus_ones' => 1] + $this->taskRow(6, 'host'),
            $this->taskRow(7, 'decorate'),
        ];
        $this->wpdb->nextResults[] = [$this->assignmentRow(30, 5, 8, 'signed_up')];
        $this->wpdb->nextResults[] = [$this->assignmentRow(31, 6, 8, 'signed_up')];
        $this->wpdb->nextResults[] = [$this->assignmentRow(32, 7, 9, 'signed_up')];
        $this->wpdb->nextRows[] = $this->personRow(8);
        $this->wpdb->nextRows[] = $this->personRow(9);
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];

        $rows = $this->desk()->state(self::NIGHT)['sections'][0]['rows'];
        $byRef = array_column($rows, null, 'ref');

        self::assertSame(2, $byRef[8]['max']);
        self::assertStringEndsWith(' · allowed +2', $byRef[8]['detail']);
        // A task with no +1 lets its crew bring nobody, as before.
        self::assertSame(0, $byRef[9]['max']);
        self::assertStringNotContainsString('allowed', $byRef[9]['detail']);
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

    /**
     * An old night's list after the 60-day purge: Holvi rows with no name,
     * email or ticket code still render, as "No name", with their check-in.
     */
    public function testAHolviRowBlankedByThePurgeShowsNoNameAndKeepsItsCheckin(): void
    {
        $this->wpdb->nextResults[] = [['kind' => 'buyer', 'ref_id' => 9, 'plus_ones' => 0]]; // check-ins
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];
        $blanked = ['event_date' => self::NIGHT, 'name' => '', 'email' => null, 'ticket_code' => null];
        $this->wpdb->nextResults[] = [
            ['id' => 9, 'source' => 'email'] + $blanked,
            ['id' => 10, 'source' => 'desk'] + $blanked,
        ];

        $sections = $this->desk()->state(self::NIGHT)['sections'];

        self::assertSame(['No name', 'No name'], array_column($sections[3]['rows'], 'name'));
        self::assertSame(['Holvi', 'Holvi'], array_column($sections[3]['rows'], 'detail'));
        self::assertSame([true, false], array_column($sections[3]['rows'], 'in'));
    }

    public function testTheListKeepsTheFullNameAndTheWelcomeGetsTheFirstName(): void
    {
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [
            ['id' => 9, 'event_date' => self::NIGHT, 'name' => 'Bea Virtanen', 'source' => 'paste'],
        ];

        $row = $this->desk()->state(self::NIGHT)['sections'][3]['rows'][0];

        self::assertSame('Bea Virtanen', $row['name']);
        self::assertSame('Bea', $row['first']);
    }

    public function testTheWelcomeUsesOnlyTheFirstName(): void
    {
        self::assertSame('Ada', DoorDesk::firstName('Ada Lovelace'));
        self::assertSame('Anna', DoorDesk::firstName("  Anna \t Maria Virtanen "));
        self::assertSame('Ada', DoorDesk::firstName('Ada'));
        self::assertSame('Äijä', DoorDesk::firstName('Äijä Öljynen'));
        self::assertSame('', DoorDesk::firstName(''));
        self::assertSame('', DoorDesk::firstName('   '));
    }

    /**
     * A ticket buyer row on tonight's date with a ticket code.
     *
     * @return array<string, mixed>
     */
    private function codedBuyerRow(int $id, string $name): array
    {
        return ['id' => $id, 'event_date' => self::NIGHT, 'name' => $name, 'source' => 'email', 'ticket_code' => 'AB12'];
    }

    public function testATicketCodeFindsItsTicketWithoutMarkingIt(): void
    {
        $this->wpdb->nextResults[] = [$this->codedBuyerRow(12, 'Ada Lovelace')]; // findByTicketCode
        $this->wpdb->nextResults[] = [];                                         // check-ins

        $tickets = $this->desk()->ticketsByCode(self::NIGHT, ['AB12']);

        self::assertSame(
            [[
                'key' => 'buyer:12', 'kind' => 'buyer', 'ref' => 12,
                'name' => 'Ada Lovelace', 'first' => 'Ada', 'in' => false, 'at' => '',
            ]],
            $tickets
        );
        self::assertStringContainsString("ticket_code = 'AB12'", $this->wpdb->queries[0]);
        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->wpdb->updates);
    }

    public function testEveryTicketOnOneCodeIsListedAndCheckedInOnesSaySo(): void
    {
        $this->wpdb->nextResults[] = [$this->codedBuyerRow(12, 'Ada'), $this->codedBuyerRow(13, 'Ada')];
        $this->wpdb->nextResults[] = [['kind' => 'buyer', 'ref_id' => 12, 'plus_ones' => 0]];

        $tickets = $this->desk()->ticketsByCode(self::NIGHT, ['AB12']);

        self::assertSame(['buyer:12', 'buyer:13'], array_column($tickets, 'key'));
        self::assertSame([true, false], array_column($tickets, 'in'));
    }

    public function testTheSameTicketFoundByTwoDecodedCodesIsListedOnce(): void
    {
        $this->wpdb->nextResults[] = [$this->codedBuyerRow(12, 'Ada')];
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [$this->codedBuyerRow(12, 'Ada')];

        self::assertSame(['buyer:12'], array_column($this->desk()->ticketsByCode(self::NIGHT, ['AB12', 'ab12']), 'key'));
    }

    /** The door says "already used · 21:05": the time of the first check-in. */
    public function testATicketCodeAlreadyUsedTonightGivesTheTimeItWasUsed(): void
    {
        $this->wpdb->nextResults[] = [$this->codedBuyerRow(12, 'Ada')];
        $this->wpdb->nextResults[] = [
            ['kind' => 'buyer', 'ref_id' => 12, 'plus_ones' => 0, 'checked_in_at' => self::NIGHT . ' 21:05:00'],
        ];

        $tickets = $this->desk()->ticketsByCode(self::NIGHT, ['AB12']);

        self::assertTrue($tickets[0]['in']);
        self::assertSame('21:05', $tickets[0]['at']);
        self::assertStringContainsString('checked_in_at', $this->wpdb->queries[1]);
    }

    public function testAListRowCheckedInGivesItsTimeAndOneNotInGivesNone(): void
    {
        $this->queueState([['kind' => 'guest', 'ref_id' => 4, 'plus_ones' => 1, 'checked_in_at' => self::NIGHT . ' 20:15:00']]);
        $in = $this->desk()->state(self::NIGHT);
        $this->queueState([]);
        $out = $this->desk()->state(self::NIGHT);

        $find = static function (array $state): array {
            foreach ($state['sections'] as $section) {
                foreach ($section['rows'] as $row) {
                    if ('guest:4' === $row['key']) {
                        return $row;
                    }
                }
            }

            return [];
        };

        self::assertSame('20:15', $find($in)['at']);
        self::assertSame('', $find($out)['at']);
    }

    public function testATicketCodeThatMatchesNothingFindsNothingAndSaysSo(): void
    {
        $desk = $this->desk();

        self::assertSame([], $desk->ticketsByCode(self::NIGHT, ['NOPE']));
        self::assertSame([], $this->wpdb->inserts);
        self::assertSame('No ticket on tonight’s list has this code.', $desk->noTicketCodeText());
    }

    public function testTheTicketDecodersAreTheHolviPresetsTextItselfByDefault(): void
    {
        self::assertSame([''], DoorDesk::ticketDecoders());
    }

    public function testTheTicketDecodersAreEachFormatsDecoderListedOnce(): void
    {
        $this->options['eventcrew_ticket_formats'] = [
            ['id' => 'a', 'name' => 'A', 'columns' => [], 'decoder_js' => 'return text.split("/").pop();'],
            ['id' => 'b', 'name' => 'B', 'columns' => [], 'decoder_js' => ''],
            ['id' => 'c', 'name' => 'C', 'columns' => [], 'decoder_js' => ' return text.split("/").pop(); '],
        ];

        self::assertSame(['return text.split("/").pop();', ''], DoorDesk::ticketDecoders());
    }
}
