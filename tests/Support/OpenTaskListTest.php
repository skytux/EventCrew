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

    public function testLeavesOutATaskWhoseRoleHasGuestSignUpOffUnlessTheRoleIsHeld(): void
    {
        $this->options[Roles::OPTION_NAME][1]['guest_signup'] = false; // Bar

        $this->queue([$this->taskRow(1, 'door'), $this->taskRow(2, 'bar')]);
        $rows = (new OpenTaskList())->forPerson($this->person());
        self::assertSame([1], array_map(static fn (array $row): int => $row['task']->id, $rows));

        $this->queue([$this->taskRow(1, 'door'), $this->taskRow(2, 'bar')]);
        $rows = (new OpenTaskList())->forPerson($this->person(['bar']));
        self::assertSame([1, 2], array_map(static fn (array $row): int => $row['task']->id, $rows));
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

    public function testTheBlockGroupsTheTasksByDateAndVanishesWhenEmpty(): void
    {
        $door = Task::fromRow($this->taskRow(4, 'door', 3));
        $dj = Task::fromRow($this->taskRow(3, 'dj'));
        $bar = Task::fromRow(['task_date' => '2099-11-15', 'starts_at' => null, 'ends_at' => null] + $this->taskRow(5, 'bar', 2));

        self::assertSame('', OpenTaskList::block([]));
        self::assertSame('', OpenTaskList::block([OpenTaskList::CONTEXT_KEY => []]));
        self::assertSame(
            "\n\nOpen now:\n📅 Sat 14 Nov\n\n🔹 Door 18:00–20:00 at Dance Night\n2 of 3 places free"
                . "\n\n🔹 DJ 18:00–20:00 at Dance Night"
                . "\n\n📅 Sun 15 Nov\n\n🔹 Bar at Dance Night\n1 of 2 places free",
            OpenTaskList::block([OpenTaskList::CONTEXT_KEY => [
                ['task' => $door, 'free' => 2],
                ['task' => $dj, 'free' => null],
                ['task' => $bar, 'free' => 1],
            ]])
        );
    }

    public function testAListShowsAtMostThreeDatesAndEightTasks(): void
    {
        $rows = [];

        foreach (['2099-11-14', '2099-11-15', '2099-11-16', '2099-11-17'] as $index => $date) {
            $rows[] = ['task' => Task::fromRow(['task_date' => $date] + $this->taskRow(10 + $index, 'door')), 'free' => 1];
        }

        $text = OpenTaskList::grouped($rows);

        self::assertSame(3, substr_count($text, '📅'));
        self::assertStringNotContainsString('17 Nov', $text);

        $many = [];

        for ($id = 1; $id <= 10; ++$id) {
            $many[] = ['task' => Task::fromRow($this->taskRow($id, 'door')), 'free' => 1];
        }

        self::assertSame(OpenTaskList::MAX_TASKS, substr_count(OpenTaskList::grouped($many), 'places free'));
    }

    public function testForPersonStopsBeforeAFourthDate(): void
    {
        $this->queue([
            ['task_date' => '2099-11-14'] + $this->taskRow(1, 'door'),
            ['task_date' => '2099-11-15'] + $this->taskRow(2, 'door'),
            ['task_date' => '2099-11-16'] + $this->taskRow(3, 'door'),
            ['task_date' => '2099-11-17'] + $this->taskRow(4, 'door'),
        ]);

        $rows = (new OpenTaskList())->forPerson($this->person());

        self::assertSame([1, 2, 3], array_map(static fn (array $row): int => $row['task']->id, $rows));
    }

    public function testDescriptionsGiveOneShortLinePerRoleListed(): void
    {
        $this->options[Roles::OPTION_NAME] = [
            ['slug' => 'door', 'label' => 'Door', 'description' => 'Check tickets at the door. Smile a lot and keep the queue moving.'],
            ['slug' => 'bar', 'label' => 'Bar', 'description' => str_repeat('Pour drinks and wash glasses ', 6)],
            ['slug' => 'dj', 'label' => 'DJ'],
        ];
        $rows = [
            ['task' => Task::fromRow($this->taskRow(1, 'door')), 'free' => 1],
            ['task' => Task::fromRow($this->taskRow(2, 'door')), 'free' => 1],
            ['task' => Task::fromRow($this->taskRow(3, 'bar')), 'free' => 1],
            ['task' => Task::fromRow($this->taskRow(4, 'dj')), 'free' => null],
        ];

        $text = OpenTaskList::descriptions($rows);
        $lines = explode("\n", $text);

        self::assertSame('📝 What each task involves', $lines[0]);
        self::assertSame('Door: Check tickets at the door.', $lines[1]);
        self::assertStringStartsWith('Bar: Pour drinks', $lines[2]);
        self::assertStringEndsWith('…', $lines[2]);
        self::assertLessThanOrEqual(OpenTaskList::SHORT_DESCRIPTION_MAX + 1, mb_strlen(substr($lines[2], 5)));
        self::assertCount(3, $lines, 'one line per role, none for a role without a description');
        self::assertSame('', OpenTaskList::descriptions([]));
    }

    public function testOneTaskStillSignsUpWithTheJoinOnlyCallbackOrTheRoleListsSignUp(): void
    {
        $mailer = new Mailer(new Logger(), new EmailTemplate(new Logger()));
        $actions = [
            OpenTaskList::action(Task::fromRow($this->taskRow(4, 'door')), $mailer),
            OpenTaskList::action(Task::fromRow($this->taskRow(3, 'dj')), $mailer),
        ];

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
