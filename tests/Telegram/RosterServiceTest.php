<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\MarkedBy;
use EventCrew\Support\Roles;
use EventCrew\Support\RosterAssembler;
use EventCrew\Telegram\RosterService;

final class RosterServiceTest extends TelegramTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_the_title')->justReturn('');
    }

    private function service(): RosterService
    {
        return new RosterService(
            new RosterAssembler(new TaskRepository(), new AssignmentRepository(), new PersonRepository(), $this->standing()),
            new TaskRepository(),
            new PersonRepository(),
            new AssignmentRepository(),
            $this->client()
        );
    }

    private function organizer(bool $isOrganizer): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'boss@example.com',
            'display_name' => 'Boss',
            'is_organizer' => $isOrganizer ? 1 : 0,
            'telegram_user_id' => 555,
        ];
    }

    public function testRefusesAnUnlinkedSender(): void
    {
        // No person row -> findByTelegramUserId returns null.
        $this->service()->onRosterCommand(555, 555);

        self::assertSame(
            'Only organizers or people rostered on the day can see the roster.',
            $this->lastCallTo('sendMessage')['text']
        );
    }

    public function testRefusesANonOrganizerNotRosteredThatDay(): void
    {
        $this->organizer(false);
        $this->wpdb->nextCols[] = ['2026-07-20']; // defaultDate()
        $this->wpdb->nextCols[] = [];              // personIdsAssignedOn() -> nobody

        $this->service()->onRosterCommand(555, 555);

        self::assertSame(
            'Only organizers or people rostered on the day can see the roster.',
            $this->lastCallTo('sendMessage')['text']
        );
    }

    public function testSendsTheRosterToAnOrganizer(): void
    {
        $this->organizer(true);
        // datesWithTasks() -> today, which the default rule selects.
        $this->wpdb->nextCols[] = ['2026-07-20'];
        // RosterAssembler: forDate() task, forTask() assignment, find() person.
        $this->wpdb->nextResults[] = [
            ['id' => 5, 'task_date' => '2026-07-20', 'role_slug' => 'clean', 'capacity' => 3],
        ];
        $this->wpdb->nextResults[] = [
            ['id' => 11, 'task_id' => 5, 'person_id' => 8, 'status' => 'arrived'],
        ];
        $this->wpdb->nextRows[] = ['id' => 8, 'email' => 'sam@example.com', 'display_name' => 'Sam'];

        $this->service()->onRosterCommand(555, 555);

        $text = $this->lastCallTo('sendMessage')['text'];
        self::assertStringContainsString('Sam', $text);
        self::assertStringContainsString('Arrived', $text);
    }

    /**
     * An organizer sees the organize task on the roster with a plain count,
     * since it has no limit to count against.
     */
    public function testAnOrganizerSeesTheOrganizeTaskWithNoLimit(): void
    {
        $this->organizer(true);
        $this->wpdb->nextCols[] = ['2026-07-20'];
        $this->wpdb->nextResults[] = [
            ['id' => 6, 'task_date' => '2026-07-20', 'role_slug' => 'organize', 'capacity' => 1],
        ];
        $this->wpdb->nextResults[] = [
            ['id' => 12, 'task_id' => 6, 'person_id' => 8, 'status' => 'signed_up'],
            ['id' => 13, 'task_id' => 6, 'person_id' => 9, 'status' => 'signed_up'],
        ];
        $this->wpdb->nextRows[] = ['id' => 8, 'email' => 'sam@example.com', 'display_name' => 'Sam'];
        $this->wpdb->nextRows[] = ['id' => 9, 'email' => 'kim@example.com', 'display_name' => 'Kim'];

        $this->service()->onRosterCommand(555, 555);

        $text = $this->lastCallTo('sendMessage')['text'];
        self::assertStringContainsString('Organize', $text);
        self::assertStringContainsString('(2)', $text);
        self::assertStringNotContainsString('2/1', $text);
    }

    /**
     * Someone rostered on the day who is not an organizer gets the roster, but
     * without the organize task.
     */
    public function testSomeoneWhoIsNotAnOrganizerDoesNotSeeTheOrganizeTask(): void
    {
        $this->organizer(false);
        $this->wpdb->nextCols[] = ['2026-07-20']; // defaultDate()
        $this->wpdb->nextCols[] = [7];            // personIdsAssignedOn() -> they are rostered
        $this->wpdb->nextResults[] = [];          // sales desk check: no task that day
        $this->wpdb->nextResults[] = [
            ['id' => 5, 'task_date' => '2026-07-20', 'role_slug' => 'clean', 'capacity' => 3],
            ['id' => 6, 'task_date' => '2026-07-20', 'role_slug' => 'organize', 'capacity' => 1],
        ];
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];

        $this->service()->onRosterCommand(555, 555);

        $text = $this->lastCallTo('sendMessage')['text'];
        self::assertStringContainsString('Clean', $text);
        self::assertStringNotContainsString('Organize', $text);
    }

    public function testTheRosterSaysWhoMarkedEachPersonAndWhen(): void
    {
        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('UTC'));

        $this->organizer(true);
        $this->wpdb->nextCols[] = ['2026-07-20']; // datesWithTasks()
        $this->wpdb->nextResults[] = [
            ['id' => 5, 'task_date' => '2026-07-20', 'role_slug' => 'clean', 'capacity' => 3],
        ];
        $this->wpdb->nextResults[] = [
            [
                'id' => 11, 'task_id' => 5, 'person_id' => 8, 'status' => 'arrived',
                'status_changed_at' => '2026-07-20 21:42:00', 'changed_by' => 7, 'changed_by_kind' => 'person',
            ],
            [
                'id' => 12, 'task_id' => 5, 'person_id' => 9, 'status' => 'no_show',
                'status_changed_at' => '2026-07-20 22:15:00', 'changed_by' => 3,
            ],
        ];
        $this->wpdb->nextRows[] = ['id' => 8, 'email' => 'sam@example.com', 'display_name' => 'Sam'];
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'boss@example.com', 'display_name' => 'Boss'];
        $this->wpdb->nextRows[] = ['id' => 9, 'email' => 'jo@example.com', 'display_name' => 'Jo'];

        $this->service()->onRosterCommand(555, 555);

        $text = $this->lastCallTo('sendMessage')['text'];
        self::assertStringContainsString("Sam (Arrived) — Beginner\n    marked by Boss at 21:42", $text);
        // An old row whose id kind is not known gives only the time.
        self::assertStringContainsString("Jo (No-show) — Beginner\n    marked at 22:15", $text);
    }

    public function testFromAGroupTheRosterGoesToTheDmWithABreadcrumb(): void
    {
        $this->organizer(true);
        $this->wpdb->nextCols[] = ['2026-07-20']; // datesWithTasks()
        $this->wpdb->nextResults[] = [
            ['id' => 5, 'task_date' => '2026-07-20', 'role_slug' => 'clean', 'capacity' => 3],
        ];
        $this->wpdb->nextResults[] = [
            ['id' => 11, 'task_id' => 5, 'person_id' => 8, 'status' => 'arrived'],
        ];
        $this->wpdb->nextRows[] = ['id' => 8, 'email' => 'sam@example.com', 'display_name' => 'Sam'];

        // Asked from a group (chat -100 differs from the asker 555), not private.
        $this->service()->onRosterCommand(555, -100, false);

        // The roster itself lands in the asker's DM...
        self::assertSame(555, $this->telegramCalls[0]['body']['chat_id']);
        self::assertStringContainsString('Sam', $this->telegramCalls[0]['body']['text']);
        // ...and only a breadcrumb goes to the group.
        self::assertSame(-100, $this->lastCallTo('sendMessage')['chat_id']);
        self::assertSame('📬 Sent you a DM.', $this->lastCallTo('sendMessage')['text']);
    }

    public function testTellsTheOrganizerWhenNothingIsScheduled(): void
    {
        $this->organizer(true);
        // datesWithTasks() -> empty.
        $this->wpdb->nextCols[] = [];

        $this->service()->onRosterCommand(555, 555);

        self::assertSame('No tasks are scheduled yet.', $this->lastCallTo('sendMessage')['text']);
    }

    public function testOrganizerCanMarkSomeoneCompleted(): void
    {
        $this->organizer(true);            // findByTelegramUserId
        $this->wpdb->nextRows[] = [        // assignments->find(11)
            'id' => 11,
            'task_id' => 5,
            'person_id' => 8,
            'status' => 'signed_up',
        ];
        // refreshRosterMessage: tasks->find(5), then forDate() task, forTask(), find() person.
        $this->wpdb->nextRows[] = ['id' => 5, 'task_date' => '2026-07-20', 'role_slug' => 'clean', 'capacity' => 3];
        $this->wpdb->nextResults[] = [
            ['id' => 5, 'task_date' => '2026-07-20', 'role_slug' => 'clean', 'capacity' => 3],
        ];
        $this->wpdb->nextResults[] = [
            ['id' => 11, 'task_id' => 5, 'person_id' => 8, 'status' => 'completed'],
        ];
        $this->wpdb->nextRows[] = ['id' => 8, 'email' => 'sam@example.com', 'display_name' => 'Sam'];

        $this->service()->onMark([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'data' => 'rm:11:c',
            'message' => ['message_id' => 42, 'chat' => ['id' => 555]],
        ]);

        // The completed status was written, recorded as the organizer's person id...
        self::assertSame('completed', $this->wpdb->updates[0]['data']['status']);
        self::assertSame(7, $this->wpdb->updates[0]['data']['changed_by']);
        self::assertSame(MarkedBy::PERSON, $this->wpdb->updates[0]['data']['changed_by_kind']);
        // ...the tap was acknowledged, and the roster message edited in place.
        self::assertSame('Marked completed.', $this->lastCallTo('answerCallbackQuery')['text']);
        self::assertContains('editMessageText', $this->calledMethods());
    }

    public function testOrganizerCanMarkSomeoneArrivedLate(): void
    {
        $this->organizer(true);
        $this->wpdb->nextRows[] = ['id' => 11, 'task_id' => 5, 'person_id' => 8, 'status' => 'signed_up'];
        $this->wpdb->nextRows[] = ['id' => 5, 'task_date' => '2026-07-20', 'role_slug' => 'clean', 'capacity' => 3];
        $this->wpdb->nextResults[] = [
            ['id' => 5, 'task_date' => '2026-07-20', 'role_slug' => 'clean', 'capacity' => 3],
        ];
        $this->wpdb->nextResults[] = [
            ['id' => 11, 'task_id' => 5, 'person_id' => 8, 'status' => 'arrived_late'],
        ];
        $this->wpdb->nextRows[] = ['id' => 8, 'email' => 'sam@example.com', 'display_name' => 'Sam'];

        $this->service()->onMark([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'data' => 'rm:11:l',
            'message' => ['message_id' => 42, 'chat' => ['id' => 555]],
        ]);

        self::assertSame('arrived_late', $this->wpdb->updates[0]['data']['status']);
        self::assertSame('Marked arrived late.', $this->lastCallTo('answerCallbackQuery')['text']);
    }

    public function testTheRosterOffersALateButtonPerExpectedPerson(): void
    {
        $this->organizer(true);
        $this->wpdb->nextCols[] = ['2026-07-20'];
        $this->wpdb->nextResults[] = [
            ['id' => 5, 'task_date' => '2026-07-20', 'role_slug' => 'clean', 'capacity' => 3],
        ];
        $this->wpdb->nextResults[] = [
            ['id' => 11, 'task_id' => 5, 'person_id' => 8, 'status' => 'signed_up'],
        ];
        $this->wpdb->nextRows[] = ['id' => 8, 'email' => 'sam@example.com', 'display_name' => 'Sam'];

        $this->service()->onRosterCommand(555, 555);

        $row = $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'][0];
        self::assertSame(['rm:11:c', 'rm:11:l', 'rm:11:n'], array_column($row, 'callback_data'));
    }

    public function testSomeoneNotOnASalesDeskTaskCannotMark(): void
    {
        $this->organizer(false); // a linked, non-organizer person (id 7)
        $this->wpdb->nextRows[] = ['id' => 11, 'task_id' => 5, 'person_id' => 8, 'status' => 'signed_up']; // assignment
        $this->wpdb->nextRows[] = ['id' => 5, 'task_date' => '2026-07-20', 'role_slug' => 'clean', 'capacity' => 3]; // task
        $this->wpdb->nextResults[] = []; // isOnSalesDesk: no task that day

        $this->service()->onMark([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'data' => 'rm:11:n',
            'message' => ['message_id' => 42, 'chat' => ['id' => 555]],
        ]);

        self::assertStringContainsString('open the sales desk', $this->lastCallTo('answerCallbackQuery')['text']);
        self::assertNotContains('editMessageText', $this->calledMethods());
    }

    public function testCrewOnASalesDeskRoleCanMarkTheirOwnNight(): void
    {
        // Crew leader marked "Opens the sales desk" in Settings.
        $this->options[Roles::OPTION_NAME] = [
            ['slug' => 'clean', 'label' => 'Clean'],
            ['slug' => 'leader', 'label' => 'Crew leader', 'restricted' => true, 'sales_desk' => true],
        ];
        // A non-organizer on a sales-desk task that day may mark attendance.
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Lead', 'is_organizer' => 0, 'telegram_user_id' => 555];
        $this->wpdb->nextRows[] = ['id' => 11, 'task_id' => 5, 'person_id' => 9, 'status' => 'signed_up']; // marked assignment
        $this->wpdb->nextRows[] = ['id' => 5, 'task_date' => '2026-07-20', 'role_slug' => 'clean', 'capacity' => 3]; // its task
        // isOnSalesDesk: a sales-desk task that day, and the marker is on it.
        $this->wpdb->nextResults[] = [['id' => 7, 'task_date' => '2026-07-20', 'role_slug' => 'leader', 'capacity' => 1]];
        $this->wpdb->nextRows[] = ['id' => 20, 'task_id' => 7, 'person_id' => 8, 'status' => 'signed_up'];

        $this->service()->onMark([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'data' => 'rm:11:c',
            'message' => ['message_id' => 42, 'chat' => ['id' => 555]],
        ]);

        self::assertSame('completed', $this->wpdb->updates[0]['data']['status']);
        self::assertSame('Marked completed.', $this->lastCallTo('answerCallbackQuery')['text']);
    }
}
