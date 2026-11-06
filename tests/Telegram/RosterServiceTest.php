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
use EventCrew\Telegram\DayPicker;
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

        // Row 0 is the arrived button; the done/late/no-show row follows it.
        $row = $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'][1];
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

        self::assertStringContainsString('welcome crew can mark attendance', $this->lastCallTo('answerCallbackQuery')['text']);
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
            'data' => 'rm:11:a',
            'message' => ['message_id' => 42, 'chat' => ['id' => 555]],
        ]);

        self::assertSame('arrived', $this->wpdb->updates[0]['data']['status']);
        self::assertSame('Marked arrived.', $this->lastCallTo('answerCallbackQuery')['text']);
    }

    /**
     * The rows that make the Telegram user a non-organizer (id 8) on that
     * night's sales-desk task, then the marked assignment in $status.
     */
    private function salesDeskMarker(string $status): void
    {
        $this->options[Roles::OPTION_NAME] = [
            ['slug' => 'clean', 'label' => 'Clean'],
            ['slug' => 'leader', 'label' => 'Crew leader', 'restricted' => true, 'sales_desk' => true],
        ];
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Lead', 'is_organizer' => 0, 'telegram_user_id' => 555];
        $this->wpdb->nextRows[] = ['id' => 11, 'task_id' => 5, 'person_id' => 9, 'status' => $status];
        $this->wpdb->nextRows[] = ['id' => 5, 'task_date' => '2026-07-20', 'role_slug' => 'clean', 'capacity' => 3];
        $this->wpdb->nextResults[] = [
            ['id' => 7, 'task_date' => '2026-07-20', 'role_slug' => 'leader', 'capacity' => 1],
        ];
        $this->wpdb->nextRows[] = ['id' => 20, 'task_id' => 7, 'person_id' => 8, 'status' => 'signed_up'];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function creditOrNoShowMarks(): array
    {
        return ['done' => ['c'], 'late' => ['l'], 'no-show' => ['n']];
    }

    /**
     * @dataProvider creditOrNoShowMarks
     */
    public function testTheSalesDeskCannotMarkAnythingButArrived(string $mark): void
    {
        $this->salesDeskMarker('signed_up');

        $this->service()->onMark([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'data' => 'rm:11:' . $mark,
            'message' => ['message_id' => 42, 'chat' => ['id' => 555]],
        ]);

        self::assertSame([], $this->wpdb->updates);
        self::assertSame(
            'The welcome crew can only mark people as arrived.',
            $this->lastCallTo('answerCallbackQuery')['text']
        );
        self::assertNotContains('editMessageText', $this->calledMethods());
    }

    public function testTheSalesDeskCannotChangeAMarkAlreadyMade(): void
    {
        // Marking a completed person arrived would take their credit away.
        $this->salesDeskMarker('completed');

        $this->service()->onMark([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'data' => 'rm:11:a',
            'message' => ['message_id' => 42, 'chat' => ['id' => 555]],
        ]);

        self::assertSame([], $this->wpdb->updates);
        self::assertSame(
            'The welcome crew can only mark people as arrived.',
            $this->lastCallTo('answerCallbackQuery')['text']
        );
    }

    public function testTheSalesDeskGetsOnlyAnArrivedButton(): void
    {
        $this->options[Roles::OPTION_NAME] = [
            ['slug' => 'clean', 'label' => 'Clean'],
            ['slug' => 'leader', 'label' => 'Crew leader', 'restricted' => true, 'sales_desk' => true],
        ];
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Lead', 'is_organizer' => 0, 'telegram_user_id' => 555];
        $this->wpdb->nextCols[] = ['2026-07-20']; // defaultDate()
        $this->wpdb->nextCols[] = [8];            // personIdsAssignedOn(): the asker is rostered
        // isOnSalesDesk: the leader task that day, and the asker is on it.
        $this->wpdb->nextResults[] = [
            ['id' => 7, 'task_date' => '2026-07-20', 'role_slug' => 'leader', 'capacity' => 1],
        ];
        $this->wpdb->nextRows[] = ['id' => 20, 'task_id' => 7, 'person_id' => 8, 'status' => 'signed_up'];
        // RosterAssembler: one task with one person signed up and one already arrived.
        $this->wpdb->nextResults[] = [
            ['id' => 5, 'task_date' => '2026-07-20', 'role_slug' => 'clean', 'capacity' => 3],
        ];
        $this->wpdb->nextResults[] = [
            ['id' => 11, 'task_id' => 5, 'person_id' => 9, 'status' => 'signed_up'],
            ['id' => 12, 'task_id' => 5, 'person_id' => 10, 'status' => 'arrived'],
        ];
        $this->wpdb->nextRows[] = ['id' => 9, 'email' => 'sam@example.com', 'display_name' => 'Sam'];
        $this->wpdb->nextRows[] = ['id' => 10, 'email' => 'kim@example.com', 'display_name' => 'Kim'];

        $this->service()->onRosterCommand(555, 555);

        $sent = $this->lastCallTo('sendMessage');
        self::assertSame(
            [[['text' => '👋 Sam', 'callback_data' => 'rm:11:a']]],
            $sent['reply_markup']['inline_keyboard']
        );
        self::assertStringContainsString('👋 arrived', $sent['text']);
        self::assertStringNotContainsString('✓ done', $sent['text']);
    }

    public function testAnOrganizerGetsArrivedAboveDoneLateAndNoShow(): void
    {
        $this->organizer(true);
        $this->wpdb->nextCols[] = ['2026-07-20'];
        $this->wpdb->nextResults[] = [
            ['id' => 5, 'task_date' => '2026-07-20', 'role_slug' => 'clean', 'capacity' => 3],
        ];
        // Sam is still signed up, Kim has already arrived.
        $this->wpdb->nextResults[] = [
            ['id' => 11, 'task_id' => 5, 'person_id' => 8, 'status' => 'signed_up'],
            ['id' => 12, 'task_id' => 5, 'person_id' => 9, 'status' => 'arrived'],
        ];
        $this->wpdb->nextRows[] = ['id' => 8, 'email' => 'sam@example.com', 'display_name' => 'Sam'];
        $this->wpdb->nextRows[] = ['id' => 9, 'email' => 'kim@example.com', 'display_name' => 'Kim'];

        $this->service()->onRosterCommand(555, 555);

        $sent = $this->lastCallTo('sendMessage');
        // Sam gets 👋 on a row of its own, then ✓ ⏰ ✗; Kim, already arrived,
        // gets no 👋 (arrived only from signed up), only ✓ ⏰ ✗.
        self::assertSame(
            [
                [['text' => '👋 Sam', 'callback_data' => 'rm:11:a']],
                [
                    ['text' => '✓ Sam', 'callback_data' => 'rm:11:c'],
                    ['text' => '⏰ Sam', 'callback_data' => 'rm:11:l'],
                    ['text' => '✗ Sam', 'callback_data' => 'rm:11:n'],
                ],
                [
                    ['text' => '✓ Kim', 'callback_data' => 'rm:12:c'],
                    ['text' => '⏰ Kim', 'callback_data' => 'rm:12:l'],
                    ['text' => '✗ Kim', 'callback_data' => 'rm:12:n'],
                ],
            ],
            $sent['reply_markup']['inline_keyboard']
        );
        self::assertStringContainsString('👋 arrived · ✓ done · ⏰ late · ✗ no-show', $sent['text']);
    }

    public function testOrganizerCanMarkSomeoneArrived(): void
    {
        $this->organizer(true);
        $this->wpdb->nextRows[] = ['id' => 11, 'task_id' => 5, 'person_id' => 8, 'status' => 'signed_up'];
        $this->wpdb->nextRows[] = ['id' => 5, 'task_date' => '2026-07-20', 'role_slug' => 'clean', 'capacity' => 3];
        $this->wpdb->nextResults[] = [
            ['id' => 5, 'task_date' => '2026-07-20', 'role_slug' => 'clean', 'capacity' => 3],
        ];
        $this->wpdb->nextResults[] = [
            ['id' => 11, 'task_id' => 5, 'person_id' => 8, 'status' => 'arrived'],
        ];
        $this->wpdb->nextRows[] = ['id' => 8, 'email' => 'sam@example.com', 'display_name' => 'Sam'];

        $this->service()->onMark([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'data' => 'rm:11:a',
            'message' => ['message_id' => 42, 'chat' => ['id' => 555]],
        ]);

        self::assertSame('arrived', $this->wpdb->updates[0]['data']['status']);
        self::assertSame(7, $this->wpdb->updates[0]['data']['changed_by']);
        self::assertSame('Marked arrived.', $this->lastCallTo('answerCallbackQuery')['text']);

        // The edited roster drops Sam's 👋 (now arrived) and keeps ✓ ⏰ ✗.
        $edit = $this->lastCallTo('editMessageText');
        self::assertSame(
            ['rm:11:c', 'rm:11:l', 'rm:11:n'],
            array_column(array_merge(...$edit['reply_markup']['inline_keyboard']), 'callback_data')
        );
    }

    // --- day buttons and sign-up time ------------------------------------------

    public function testTheRosterOffersTheUpcomingDaysWithTheShownOneMarked(): void
    {
        $this->organizer(true);
        $this->wpdb->nextCols[] = ['2026-07-20'];               // defaultDate()
        $this->wpdb->nextResults[] = [];                         // forDate(): no tasks
        $this->wpdb->nextCols[] = ['2026-07-20', '2026-07-27']; // datesWithTasks()

        $this->service()->onRosterCommand(555, 555);

        $buttons = array_merge(...$this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard']);
        self::assertSame(
            [DayPicker::BLANK => 'x', '📅 Mon 20 Jul ✓' => 'rd:2026-07-20', 'Mon 27 Jul ▶' => 'rd:2026-07-27'],
            array_column($buttons, 'callback_data', 'text')
        );
    }

    public function testATypedDateStillWorks(): void
    {
        $this->organizer(true);
        $this->wpdb->nextResults[] = [
            ['id' => 5, 'task_date' => '2026-07-27', 'role_slug' => 'clean', 'capacity' => 3],
        ];
        $this->wpdb->nextResults[] = [];

        $this->service()->onRosterCommand(555, 555, true, '27.7.');

        self::assertStringStartsWith('📋 Mon 27 Jul', $this->lastCallTo('sendMessage')['text']);
    }

    public function testPickingADayEditsTheMessageToThatDaysRoster(): void
    {
        $this->organizer(true);
        $this->wpdb->nextResults[] = [
            ['id' => 6, 'task_date' => '2026-07-27', 'role_slug' => 'clean', 'capacity' => 3],
        ];
        $this->wpdb->nextResults[] = [
            ['id' => 14, 'task_id' => 6, 'person_id' => 8, 'status' => 'signed_up'],
        ];
        $this->wpdb->nextRows[] = ['id' => 8, 'email' => 'sam@example.com', 'display_name' => 'Sam'];
        $this->wpdb->nextCols[] = ['2026-07-20', '2026-07-27']; // datesWithTasks()

        $this->service()->onSelect([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'data' => 'rd:2026-07-27',
            'message' => ['message_id' => 42, 'chat' => ['id' => 555]],
        ]);

        $edit = $this->lastCallTo('editMessageText');
        self::assertSame(42, $edit['message_id']);
        self::assertStringStartsWith('📋 Mon 27 Jul', $edit['text']);
        self::assertStringContainsString('Sam', $edit['text']);
        $buttons = array_column(array_merge(...$edit['reply_markup']['inline_keyboard']), 'callback_data', 'text');
        self::assertSame('rd:2026-07-27', $buttons['📅 Mon 27 Jul ✓'] ?? null);
        self::assertSame('rd:2026-07-20', $buttons['◀ Mon 20 Jul'] ?? null);
    }

    public function testPickingADayIsRefusedToSomeoneNotRosteredThatDay(): void
    {
        $this->organizer(false);
        $this->wpdb->nextCols[] = []; // personIdsAssignedOn() -> nobody

        $this->service()->onSelect([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'data' => 'rd:2026-07-27',
            'message' => ['message_id' => 42, 'chat' => ['id' => 555]],
        ]);

        self::assertStringContainsString('Only organizers', $this->lastCallTo('answerCallbackQuery')['text']);
        self::assertNotContains('editMessageText', $this->calledMethods());
    }

    public function testEachPersonShowsWhenTheySignedUp(): void
    {
        $this->organizer(true);
        $this->wpdb->nextCols[] = ['2026-07-20'];
        $this->wpdb->nextResults[] = [
            ['id' => 5, 'task_date' => '2026-07-20', 'role_slug' => 'clean', 'capacity' => 3],
        ];
        $this->wpdb->nextResults[] = [
            ['id' => 11, 'task_id' => 5, 'person_id' => 8, 'status' => 'signed_up', 'signed_up_at' => '2026-07-02 14:05:00'],
        ];
        $this->wpdb->nextRows[] = ['id' => 8, 'email' => 'sam@example.com', 'display_name' => 'Sam'];

        $this->service()->onRosterCommand(555, 555);

        self::assertStringContainsString(
            'Sam (Signed up) — Beginner · signed up 2 Jul 14:05',
            $this->lastCallTo('sendMessage')['text']
        );
    }

    // --- Who sees what on the roster (Q304) -----------------------------------

    /**
     * One task on 2026-07-20 with Sam signed up, Jo a no-show and Kim a late
     * cancel, in the order the assembler reads them.
     */
    private function nightWithANoShow(): void
    {
        $this->wpdb->nextResults[] = [
            ['id' => 5, 'task_date' => '2026-07-20', 'role_slug' => 'clean', 'capacity' => 3],
        ];
        $this->wpdb->nextResults[] = [
            ['id' => 11, 'task_id' => 5, 'person_id' => 8, 'status' => 'signed_up', 'signed_up_at' => '2026-07-02 14:05:00'],
            ['id' => 12, 'task_id' => 5, 'person_id' => 9, 'status' => 'no_show'],
            ['id' => 13, 'task_id' => 5, 'person_id' => 10, 'status' => 'late_cancel'],
        ];
        $this->wpdb->nextRows[] = ['id' => 8, 'email' => 'sam@example.com', 'display_name' => 'Sam'];
        $this->wpdb->nextRows[] = ['id' => 9, 'email' => 'jo@example.com', 'display_name' => 'Jo'];
        $this->wpdb->nextRows[] = ['id' => 10, 'email' => 'kim@example.com', 'display_name' => 'Kim'];
    }

    public function testOtherCrewSeeOnlyTheNamesOfThePeopleOnATask(): void
    {
        $this->organizer(false);
        $this->wpdb->nextCols[] = ['2026-07-20']; // defaultDate()
        $this->wpdb->nextCols[] = [7];            // personIdsAssignedOn(): rostered that day
        $this->wpdb->nextResults[] = [];          // isOnSalesDesk(): no sales-desk task
        $this->nightWithANoShow();

        $this->service()->onRosterCommand(555, 555);

        $text = $this->lastCallTo('sendMessage')['text'];
        self::assertStringContainsString("(1/3)\n  • Sam", $text);
        self::assertStringNotContainsString('Beginner', $text);
        self::assertStringNotContainsString('Signed up', $text);
        self::assertStringNotContainsString('signed up 2 Jul', $text);
        self::assertStringNotContainsString('Jo', $text);
        self::assertStringNotContainsString('No-show', $text);
        self::assertStringNotContainsString('Kim', $text);
    }

    public function testThatNightsSalesDeskSeesEverything(): void
    {
        $this->options[Roles::OPTION_NAME] = [
            ['slug' => 'clean', 'label' => 'Clean'],
            ['slug' => 'leader', 'label' => 'Crew leader', 'restricted' => true, 'sales_desk' => true],
        ];
        $this->organizer(false);
        $this->wpdb->nextCols[] = ['2026-07-20']; // defaultDate()
        $this->wpdb->nextCols[] = [7];            // personIdsAssignedOn(): rostered that day
        // isOnSalesDesk(): a sales-desk task that day, and the asker is on it.
        $this->wpdb->nextResults[] = [['id' => 4, 'task_date' => '2026-07-20', 'role_slug' => 'leader', 'capacity' => 1]];
        $this->wpdb->nextRows[] = ['id' => 20, 'task_id' => 4, 'person_id' => 7, 'status' => 'signed_up'];
        $this->nightWithANoShow();

        $this->service()->onRosterCommand(555, 555);

        $text = $this->lastCallTo('sendMessage')['text'];
        self::assertStringContainsString('Sam (Signed up) — Beginner', $text);
        self::assertStringContainsString('Jo (No-show) — Beginner', $text);
        self::assertStringContainsString('Kim (Late cancel', $text);
    }

    // --- Telegram's message limits on a big night (Q281) ---------------------

    /** The upcoming days the picker shows in the big-night tests: the most it offers. */
    private const BIG_NIGHT_DAYS = [
        '2026-07-20', '2026-07-27', '2026-08-03', '2026-08-10', '2026-08-17', '2026-08-24',
    ];

    /**
     * Queues one task on 2026-07-20 with $count people in $status, in the
     * order the assembler reads them, then the picker's upcoming days.
     */
    private function bigNight(int $count, string $status, string $namePrefix = 'Person'): void
    {
        $this->wpdb->nextResults[] = [
            ['id' => 5, 'task_date' => '2026-07-20', 'role_slug' => 'clean', 'capacity' => $count],
        ];
        $assignments = [];

        for ($i = 1; $i <= $count; $i++) {
            $assignments[] = [
                'id' => 1000 + $i, 'task_id' => 5, 'person_id' => 100 + $i, 'status' => $status,
                'signed_up_at' => '2026-07-02 14:05:00',
            ];
        }

        $this->wpdb->nextResults[] = $assignments;

        for ($i = 1; $i <= $count; $i++) {
            $this->wpdb->nextRows[] = [
                'id' => 100 + $i, 'email' => "p{$i}@example.com", 'display_name' => $namePrefix . ' ' . $i,
            ];
        }

        $this->wpdb->nextCols[] = self::BIG_NIGHT_DAYS; // datesWithTasks()
    }

    /**
     * Fails unless one sent or edited message keeps inside Telegram's limits:
     * at most 100 inline buttons, at most 8 on a row, callback data of 1-64
     * bytes, and text of 1-4096 characters (counted in UTF-16, as Telegram does).
     *
     * @param array<string, mixed> $body
     */
    private function assertWithinTelegramLimits(array $body): void
    {
        $text = (string) $body['text'];
        $length = intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
        self::assertGreaterThan(0, $length);
        self::assertLessThanOrEqual(4096, $length, 'message text too long');

        $rows = $body['reply_markup']['inline_keyboard'] ?? [];
        self::assertLessThanOrEqual(100, count(array_merge([], ...array_values($rows))), 'more than 100 buttons');

        foreach ($rows as $row) {
            self::assertLessThanOrEqual(8, count($row), 'more than 8 buttons on a row');

            foreach ($row as $button) {
                self::assertGreaterThan(0, strlen($button['callback_data']));
                self::assertLessThanOrEqual(64, strlen($button['callback_data']), 'callback_data over 64 bytes');
            }
        }
    }

    /** The asker as a linked person, organizer or not (id 7). */
    private function asker(bool $isOrganizer): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 7, 'email' => 'boss@example.com', 'display_name' => 'Boss',
            'is_organizer' => $isOrganizer ? 1 : 0, 'telegram_user_id' => 555,
        ];
    }

    /**
     * Every page of the roster for 2026-07-20: /roster, then a tap on each
     * next-page button in turn, with $queue queuing that page's rows each time.
     *
     * @param callable(): void $queue
     * @return array<int, array<string, mixed>> The message body of each page.
     */
    private function allPages(callable $queue, bool $isOrganizer): array
    {
        $this->asker($isOrganizer);
        $this->wpdb->nextCols[] = ['2026-07-20']; // defaultDate()

        if (! $isOrganizer) {
            $this->wpdb->nextCols[] = [7];   // personIdsAssignedOn(): rostered that day
            $this->wpdb->nextResults[] = []; // isOnSalesDesk(): no task that day
        }

        $queue();
        $this->service()->onRosterCommand(555, 555);
        $pages = [$this->lastCallTo('sendMessage')];

        for ($guard = 0; $guard < 20; $guard++) {
            $next = $this->pageButton($pages[count($pages) - 1], 'Next »');

            if (null === $next) {
                break;
            }

            $this->asker($isOrganizer);

            if (! $isOrganizer) {
                $this->wpdb->nextCols[] = [7];   // personIdsAssignedOn()
                $this->wpdb->nextResults[] = []; // isOnSalesDesk()
            }

            $queue();
            $this->service()->onSelect([
                'id' => 'cbq',
                'from' => ['id' => 555],
                'data' => $next,
                'message' => ['message_id' => 42, 'chat' => ['id' => 555]],
            ]);
            $pages[] = $this->lastCallTo('editMessageText');
        }

        return $pages;
    }

    /**
     * The callback data of the first button whose text starts with $label, or null.
     *
     * @param array<string, mixed> $body
     */
    private function pageButton(array $body, string $label): ?string
    {
        foreach (array_merge([], ...array_values($body['reply_markup']['inline_keyboard'] ?? [])) as $button) {
            if (str_starts_with($button['text'], $label)) {
                return $button['callback_data'];
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     * @return array<int, string> Every mark callback across the pages.
     */
    private function markCallbacks(array $pages): array
    {
        $data = [];

        foreach ($pages as $page) {
            foreach (array_merge([], ...array_values($page['reply_markup']['inline_keyboard'] ?? [])) as $button) {
                if (str_starts_with($button['callback_data'], 'rm:')) {
                    $data[] = $button['callback_data'];
                }
            }
        }

        return $data;
    }

    public function testABigNightForAnOrganizerStaysWithinTelegramLimits(): void
    {
        // 40 people still signed up: 4 buttons each (👋, then ✓ ⏰ ✗) is 160,
        // plus the day buttons - far over Telegram's 100.
        $pages = $this->allPages(fn () => $this->bigNight(40, 'signed_up'), true);

        $this->assertWithinTelegramLimits($pages[0]);
        self::assertGreaterThan(1, count($pages));

        foreach ($pages as $page) {
            $this->assertWithinTelegramLimits($page);
            // Every page keeps the day buttons and the legend.
            self::assertNotNull($this->pageButton($page, '📅 Mon 20 Jul ✓'));
            self::assertStringContainsString('👋 arrived · ✓ done · ⏰ late · ✗ no-show', $page['text']);
        }

        // Across the pages each of the 40 gets all four buttons exactly once.
        $marks = array_map(
            static fn (string $data): string => (string) preg_replace('#^(rm:\d+:[acln]).*$#', '$1', $data),
            $this->markCallbacks($pages)
        );
        self::assertCount(160, $marks);
        self::assertCount(160, array_unique($marks));

        // Everyone is listed once, on one page or another.
        $text = implode("\n", array_column($pages, 'text'));
        for ($i = 1; $i <= 40; $i++) {
            self::assertSame(1, substr_count($text, "Person {$i} ("), "Person {$i}");
        }

        // The pages say which page they are, and the last has no next button.
        self::assertStringContainsString('page 1 of ' . count($pages), $pages[0]['text']);
        self::assertNull($this->pageButton($pages[count($pages) - 1], 'Next »'));
        self::assertNotNull($this->pageButton($pages[count($pages) - 1], '« Previous'));
    }

    public function testABigNightForTheSalesDeskStaysWithinTelegramLimits(): void
    {
        $this->options[Roles::OPTION_NAME] = [
            ['slug' => 'clean', 'label' => 'Clean'],
            ['slug' => 'leader', 'label' => 'Crew leader', 'restricted' => true, 'sales_desk' => true],
        ];
        // A non-organizer on that night's sales desk: 👋 only, one per person.
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Lead', 'is_organizer' => 0, 'telegram_user_id' => 555];
        $this->wpdb->nextCols[] = ['2026-07-20']; // defaultDate()
        $this->wpdb->nextCols[] = [8];            // personIdsAssignedOn()
        $this->wpdb->nextResults[] = [
            ['id' => 7, 'task_date' => '2026-07-20', 'role_slug' => 'leader', 'capacity' => 1],
        ];
        $this->wpdb->nextRows[] = ['id' => 20, 'task_id' => 7, 'person_id' => 8, 'status' => 'signed_up'];
        $this->bigNight(120, 'signed_up');
        // The picker asks per day whether the asker is rostered on it: only the first.
        $this->wpdb->nextCols[] = [8];

        $this->service()->onRosterCommand(555, 555);

        $sent = $this->lastCallTo('sendMessage');
        $this->assertWithinTelegramLimits($sent);
        self::assertNotNull($this->pageButton($sent, 'Next »'));
    }

    public function testALongRosterTextIsSplitIntoPagesUnder4096Characters(): void
    {
        // 110 people with long names, seen by a rostered crew member with no
        // buttons and names only: the text alone runs past 4096 characters.
        $pages = $this->allPages(
            fn () => $this->bigNight(110, 'completed', 'Someone With A Rather Long Name Äöå'),
            false
        );

        $this->assertWithinTelegramLimits($pages[0]);
        self::assertGreaterThan(1, count($pages));

        foreach ($pages as $page) {
            $this->assertWithinTelegramLimits($page);
        }

        $text = implode("\n", array_column($pages, 'text')) . "\n";
        for ($i = 1; $i <= 110; $i++) {
            self::assertSame(1, substr_count($text, "Äöå {$i}\n"), "Person {$i}");
        }
    }

    public function testAMarkOnALaterPageEditsThatPageInPlace(): void
    {
        $pages = $this->allPages(fn () => $this->bigNight(40, 'signed_up'), true);
        $second = $this->markCallbacks([$pages[1]]);
        // The first ✓ on page two carries the page: rm:<id>:c:1.
        $tap = array_values(array_filter($second, static fn (string $d): bool => str_contains($d, ':c')))[0];
        self::assertMatchesRegularExpression('#^rm:\d+:c:1$#', $tap);
        $assignmentId = (int) explode(':', $tap)[1];
        $this->telegramCalls = [];

        $this->asker(true);
        $this->wpdb->nextRows[] = ['id' => $assignmentId, 'task_id' => 5, 'person_id' => 8, 'status' => 'signed_up'];
        $this->wpdb->nextRows[] = ['id' => 5, 'task_date' => '2026-07-20', 'role_slug' => 'clean', 'capacity' => 40];
        $this->bigNight(40, 'signed_up');

        $this->service()->onMark([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'data' => $tap,
            'message' => ['message_id' => 42, 'chat' => ['id' => 555]],
        ]);

        self::assertSame('completed', $this->wpdb->updates[0]['data']['status']);
        $edit = $this->lastCallTo('editMessageText');
        $this->assertWithinTelegramLimits($edit);
        // The edited message is still page two, not page one.
        self::assertSame($pages[1]['text'], $edit['text']);
        self::assertStringContainsString('page 2 of', $edit['text']);
    }

    public function testAMarkFromAButtonWithoutAPageEditsPageOne(): void
    {
        // Buttons sent before pages existed carry no page.
        $this->asker(true);
        $this->wpdb->nextRows[] = ['id' => 1001, 'task_id' => 5, 'person_id' => 8, 'status' => 'signed_up'];
        $this->wpdb->nextRows[] = ['id' => 5, 'task_date' => '2026-07-20', 'role_slug' => 'clean', 'capacity' => 40];
        $this->bigNight(40, 'signed_up');

        $this->service()->onMark([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'data' => 'rm:1001:c',
            'message' => ['message_id' => 42, 'chat' => ['id' => 555]],
        ]);

        self::assertSame('completed', $this->wpdb->updates[0]['data']['status']);
        $edit = $this->lastCallTo('editMessageText');
        $this->assertWithinTelegramLimits($edit);
        self::assertStringContainsString('page 1 of', $edit['text']);
    }
}
