<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
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

        // The completed status was written...
        self::assertSame('completed', $this->wpdb->updates[0]['data']['status']);
        // ...the tap was acknowledged, and the roster message edited in place.
        self::assertSame('Marked completed.', $this->lastCallTo('answerCallbackQuery')['text']);
        self::assertContains('editMessageText', $this->calledMethods());
    }

    public function testANonOrganizerNonLeaderCannotMark(): void
    {
        $this->organizer(false); // a linked, non-organizer person (id 7)
        $this->wpdb->nextRows[] = ['id' => 11, 'task_id' => 5, 'person_id' => 8, 'status' => 'signed_up']; // assignment
        $this->wpdb->nextRows[] = ['id' => 5, 'task_date' => '2026-07-20', 'role_slug' => 'clean', 'capacity' => 3]; // task
        $this->wpdb->nextResults[] = []; // isLeaderOn: no leader task that day

        $this->service()->onMark([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'data' => 'rm:11:n',
            'message' => ['message_id' => 42, 'chat' => ['id' => 555]],
        ]);

        self::assertStringContainsString('leader', $this->lastCallTo('answerCallbackQuery')['text']);
        self::assertNotContains('editMessageText', $this->calledMethods());
    }

    public function testALeaderCanMarkTheirOwnNight(): void
    {
        // A non-organizer who holds the leader slot that day may mark attendance.
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Lead', 'is_organizer' => 0, 'telegram_user_id' => 555];
        $this->wpdb->nextRows[] = ['id' => 11, 'task_id' => 5, 'person_id' => 9, 'status' => 'signed_up']; // marked assignment
        $this->wpdb->nextRows[] = ['id' => 5, 'task_date' => '2026-07-20', 'role_slug' => 'clean', 'capacity' => 3]; // its task
        // isLeaderOn: a leader task that day, and the marker holds it.
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
