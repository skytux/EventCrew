<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\MessageActions;
use EventCrew\Support\OpenTaskList;
use EventCrew\Support\Roles;
use EventCrew\Tests\Telegram\TelegramTestCase;

/**
 * OpenTaskList: the open tasks one person may take, one nameless line and
 * one Sign up action each.
 */
final class OpenTaskListTest extends TelegramTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_the_title')->justReturn('');
        Functions\when('get_permalink')->justReturn('https://site.test/crew/');
        $this->options['eventcrew_signup_page_id'] = 897;
        $this->options[Roles::OPTION_NAME] = [
            ['slug' => 'door', 'label' => 'Door'],
            ['slug' => 'bar', 'label' => 'Bar'],
            ['slug' => 'dj', 'label' => 'DJ', 'restricted' => true],
        ];
    }

    /** @return array<string, mixed> */
    private function taskRow(int $id, string $role, int $capacity = 2, ?int $event = null): array
    {
        return [
            'id' => $id,
            'task_date' => '2099-11-14',
            'role_slug' => $role,
            'capacity' => $capacity,
            'event_post_id' => $event,
            'event_label' => 'Dance Night',
            'starts_at' => '2099-11-14 18:00:00',
            'ends_at' => '2099-11-14 20:00:00',
        ];
    }

    private function person(array $roles = []): Person
    {
        return new Person(8, 'sam@example.com', 'Sam Lee', '2026-01-01 00:00:00', roleSlugs: $roles);
    }

    /**
     * Queues the three reads: the upcoming tasks, the person's assignments,
     * the occupancy.
     *
     * @param list<array<string, mixed>> $tasks
     * @param list<array<string, mixed>> $assignments
     * @param list<array<string, mixed>> $occupancy
     */
    private function queue(array $tasks, array $assignments = [], array $occupancy = []): void
    {
        $this->wpdb->nextResults[] = $tasks;
        $this->wpdb->nextResults[] = $assignments;
        $this->wpdb->nextResults[] = $occupancy;
    }

    public function testListsOnlyTasksThePersonMayTake(): void
    {
        $this->queue(
            [
                $this->taskRow(1, 'door'),            // on it already
                $this->taskRow(2, 'bar'),             // full
                $this->taskRow(3, 'dj'),              // restricted, role not held
                $this->taskRow(4, 'door', 3),         // open: 1 of 3 taken
            ],
            [['id' => 50, 'task_id' => 1, 'person_id' => 8, 'status' => 'signed_up']],
            [['task_id' => 2, 'taken' => 2], ['task_id' => 4, 'taken' => 1]]
        );

        $rows = (new OpenTaskList())->forPerson($this->person());

        self::assertSame([4], array_map(static fn (array $row): int => $row['task']->id, $rows));
        self::assertSame(2, $rows[0]['free']);
    }

    public function testARoleHolderGetsTheRolesTasksWithNoLimit(): void
    {
        $this->queue([$this->taskRow(3, 'dj'), $this->taskRow(4, 'door')]);

        $rows = (new OpenTaskList())->forPerson($this->person(['dj']), null, 3, 'dj');

        self::assertCount(1, $rows);
        self::assertSame(3, $rows[0]['task']->id);
        self::assertNull($rows[0]['free'], 'a restricted task has no limit');
    }

    public function testFiltersByEventAndStopsAtTheMaximum(): void
    {
        $this->queue([
            $this->taskRow(1, 'door', 2, 70),
            $this->taskRow(2, 'bar', 2, 71),
            $this->taskRow(3, 'bar', 2, 70),
            $this->taskRow(4, 'door', 2, 70),
        ]);

        $rows = (new OpenTaskList())->forPerson($this->person(), 70, 2);

        self::assertSame([1, 3], array_map(static fn (array $row): int => $row['task']->id, $rows));
    }

    public function testNoTasksMeansNoFurtherQueries(): void
    {
        $this->wpdb->nextResults[] = [];

        self::assertSame([], (new OpenTaskList())->forPerson($this->person()));
        self::assertCount(1, $this->wpdb->queries);
    }

    public function testTheBlockHasOneNamelessLinePerTaskAndVanishesWhenEmpty(): void
    {
        $door = Task::fromRow($this->taskRow(4, 'door', 3));
        $dj = Task::fromRow($this->taskRow(3, 'dj'));

        self::assertSame('', OpenTaskList::block([]));
        self::assertSame('', OpenTaskList::block([OpenTaskList::CONTEXT_KEY => []]));
        self::assertSame(
            "\n\nOpen now:\n- 📅 Sat 14 Nov 18:00–20:00: Door at Dance Night (2 of 3 free)"
                . "\n- 📅 Sat 14 Nov 18:00–20:00: DJ at Dance Night",
            OpenTaskList::block([OpenTaskList::CONTEXT_KEY => [
                ['task' => $door, 'free' => 2],
                ['task' => $dj, 'free' => null],
            ]])
        );
    }

    public function testEachRowSignsUpWithTheJoinOnlyCallbackOrTheRoleListsSignUp(): void
    {
        $mailer = new Mailer(new Logger(), new EmailTemplate(new Logger()));
        $context = [OpenTaskList::CONTEXT_KEY => [
            ['task' => Task::fromRow($this->taskRow(4, 'door')), 'free' => 1],
            ['task' => Task::fromRow($this->taskRow(3, 'dj')), 'free' => null],
        ]];

        $actions = OpenTaskList::actions($context, $mailer);

        self::assertSame(
            [
                [['text' => 'Sign up: Door · Sat 14 Nov', 'callback_data' => 'j:4']],
                [['text' => 'Sign up: DJ · Sat 14 Nov', 'callback_data' => 'rt:s:3']],
            ],
            MessageActions::keyboard($actions)
        );
        self::assertSame(
            [
                ['label' => 'Sign up: Door · Sat 14 Nov', 'url' => 'https://site.test/crew/#eventcrew-task-4'],
                ['label' => 'Sign up: DJ · Sat 14 Nov', 'url' => 'https://site.test/crew/#eventcrew-task-3'],
            ],
            MessageActions::email($actions)
        );
    }
}
