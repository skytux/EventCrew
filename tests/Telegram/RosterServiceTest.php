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
            new RosterAssembler(new TaskRepository(), new AssignmentRepository(), new PersonRepository()),
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

    public function testNonOrganizerCannotMark(): void
    {
        $this->organizer(false);

        $this->service()->onMark([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'data' => 'rm:11:n',
            'message' => ['message_id' => 42, 'chat' => ['id' => 555]],
        ]);

        self::assertSame('Only organizers can mark attendance.', $this->lastCallTo('answerCallbackQuery')['text']);
        self::assertNotContains('editMessageText', $this->calledMethods());
    }
}
