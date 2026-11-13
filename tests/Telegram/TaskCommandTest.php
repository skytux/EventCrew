<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\OpenTaskCall;
use EventCrew\Support\Roles;
use EventCrew\Support\TaskTemplateApplier;
use EventCrew\Telegram\TaskCommand;
use EventCrew\Web\CrewAdminTasks;

/**
 * /addtask, /edittask, /deletetask, /maketasks and /mailtasks: an organizer
 * manages tasks in a private chat, each write behind a ✅ tap.
 *
 * Settings > Roles has Decorate (anyone, 3 places by default) and DJ (only
 * people with the role). Task 5 is Decorate on 2026-07-25, 21:00–23:00, with
 * 2 places. The clock reads 2026-07-20 12:00. The organizer is person 1,
 * Telegram user 555. No EventMesh events.
 */
final class TaskCommandTest extends TelegramTestCase
{
    /** @var array<string, mixed> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->transients = [];
        $this->options[Roles::OPTION_NAME] = [
            ['slug' => 'decorate', 'label' => 'Decorate', 'capacity' => 3],
            ['slug' => 'dj', 'label' => 'DJ', 'emoji' => '🎧', 'restricted' => true],
        ];

        Functions\when('get_transient')->alias(fn (string $key): mixed => $this->transients[$key] ?? false);
        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->transients[$key] = $value;

            return true;
        });
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);

            return true;
        });
        Functions\when('sanitize_textarea_field')->alias(static fn (string $v): string => trim(strip_tags($v)));
        Functions\when('post_type_exists')->justReturn(false);
        Functions\when('get_the_title')->justReturn('');
    }

    private function command(): TaskCommand
    {
        $tasks = new TaskRepository();
        $mailer = new Mailer(new Logger(), new EmailTemplate(new Logger()));
        $admin = new CrewAdminTasks(
            $tasks,
            new AssignmentRepository(),
            new PersonRepository(),
            new TaskTemplateApplier($tasks),
            new OpenTaskCall($tasks, new AssignmentRepository(), new PersonRepository(), new NotificationsRepository(), $mailer, $this->client())
        );

        return new TaskCommand(new PersonRepository(), $tasks, $admin, $this->client());
    }

    /**
     * @return array<string, mixed>
     */
    private function organizer(bool $organizer = true): array
    {
        return [
            'id' => 1,
            'email' => 'owner@example.com',
            'display_name' => 'Owner Organizer',
            'email_verified_at' => '2026-07-01 00:00:00',
            'is_organizer' => $organizer ? 1 : 0,
            'role_slugs' => '',
            'telegram_user_id' => 555,
            'telegram_chat_id' => 555,
        ];
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function task(int $id = 5, string $role = 'decorate', array $changes = []): array
    {
        return $changes + [
            'id' => $id,
            'task_date' => '2026-07-25',
            'role_slug' => $role,
            'capacity' => 2,
            'event_post_id' => null,
            'event_label' => 'Party',
            'starts_at' => '2026-07-25 21:00:00',
            'ends_at' => '2026-07-25 23:00:00',
            'notes' => '',
            'plus_ones' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tap(string $data, int $from = 555): array
    {
        return ['id' => 'cbq', 'from' => ['id' => $from], 'data' => $data, 'message' => ['chat' => ['id' => $from], 'message_id' => 3]];
    }

    /**
     * @return array<int, string>
     */
    private function buttonData(): array
    {
        $keyboard = $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'] ?? [];

        return array_column(array_merge(...$keyboard), 'callback_data');
    }

    private function lastText(): string
    {
        return (string) ($this->lastCallTo('sendMessage')['text'] ?? '');
    }

    private function requestId(): string
    {
        preg_match('/^tk:\w:([0-9a-f]{16})/', $this->buttonData()[0] ?? '', $m);

        return $m[1] ?? '';
    }

    private function nothingWritten(): void
    {
        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->wpdb->updates);
        self::assertStringNotContainsString('deleted_at = ', implode("\n", $this->wpdb->queries));
    }

    public function testLoweringThePlacesBelowThePeopleOnItWarnsAndSavesOnlyAfterTheTap(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextResults[] = [$this->task(), $this->task(9, 'dj')];
        $this->wpdb->nextRows[] = $this->task();                     // confirm: task
        $this->wpdb->nextResults[] = [['task_id' => 5, 'taken' => 2]];

        $this->command()->onCommand(555, 555, '/edittask decorate ; places 1 ; time 22-02', true, TaskCommand::EDIT);

        self::assertSame(
            "Change Decorate · Sat 25 Jul 21:00–23:00?\nTime: 22:00–02:00\nPeople needed: 1\n⚠️ 2 people are on it. Nobody is taken off.",
            $this->lastText()
        );
        $id = $this->requestId();
        self::assertSame(['tk:y:' . $id, 'tk:x:' . $id], $this->buttonData());
        $this->nothingWritten();

        $this->wpdb->nextRows[] = $this->organizer();                // the tapper
        $this->wpdb->nextRows[] = $this->task();                     // write: task
        $this->wpdb->nextRows[] = $this->task();                     // CrewAdminTasks::save: exists
        $this->wpdb->nextRows[] = $this->task(5, 'decorate', [       // the label afterwards
            'capacity' => 1,
            'starts_at' => '2026-07-25 22:00:00',
            'ends_at' => '2026-07-26 02:00:00',
        ]);

        $this->command()->onCallback($this->tap('tk:y:' . $id));

        $data = $this->wpdb->updates[0]['data'];
        self::assertSame(1, $data['capacity']);
        self::assertSame('2026-07-25 22:00:00', $data['starts_at']);
        self::assertSame('2026-07-26 02:00:00', $data['ends_at']);
        self::assertSame('Party', $data['event_label']);
        self::assertSame(0, $data['plus_ones']);
        self::assertSame("✅ Decorate · Sat 25 Jul 22:00–02:00 (+1)\nTask updated.", $this->lastText());
        self::assertSame([], $this->transients);
    }

    public function testDeletingATaskTellsWhoIsTakenOffAndRunsTheAdminDelete(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextResults[] = [$this->task()];
        $this->wpdb->nextRows[] = $this->task();
        $this->wpdb->nextResults[] = [['task_id' => 5, 'taken' => 1]];

        $this->command()->onCommand(555, 555, '/deletetask Decorate', true, TaskCommand::DELETE);

        self::assertSame("Delete Decorate · Sat 25 Jul 21:00–23:00?\n1 person on it is taken off and gets an email.", $this->lastText());
        $id = $this->requestId();
        $this->nothingWritten();

        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextRows[] = $this->task();
        $this->wpdb->nextRows[] = $this->task();
        $this->wpdb->nextCols[] = [7];
        $fired = [];
        Functions\when('do_action')->alias(static function (string $hook, mixed ...$args) use (&$fired): void {
            $fired[] = [$hook, $args];
        });

        $this->command()->onCallback($this->tap('tk:y:' . $id));

        self::assertContains(['eventcrew/task_deleted', [5, [7]]], $fired);
        self::assertStringContainsString('SET deleted_at = ', implode("\n", $this->wpdb->queries));
        self::assertSame("✅ Decorate · Sat 25 Jul 21:00–23:00\nTask deleted.", $this->lastText());
    }

    public function testAddingATaskOnADateTakesTheRolesPlacesAndWritesAfterTheTap(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();

        $this->command()->onCommand(555, 555, '/addtask 25.7. deco ; time 20:30-23 ; notes Bring tape', true, TaskCommand::ADD);

        self::assertSame("Add the task Decorate · Sat 25 Jul 20:30–23:00?\nPeople needed: 3\nNotes: Bring tape", $this->lastText());
        $id = $this->requestId();
        $this->nothingWritten();

        $this->wpdb->nextRows[] = $this->organizer();

        $this->command()->onCallback($this->tap('tk:y:' . $id));

        $data = $this->wpdb->inserts[0]['data'];
        self::assertSame('decorate', $data['role_slug']);
        self::assertSame('2026-07-25', $data['task_date']);
        self::assertSame('2026-07-25 20:30:00', $data['starts_at']);
        self::assertSame('2026-07-25 23:00:00', $data['ends_at']);
        self::assertSame(3, $data['capacity']);
        self::assertSame('Bring tape', $data['notes']);
        self::assertSame("✅ Decorate · Sat 25 Jul 20:30–23:00\nTask added.", $this->lastText());
    }

    public function testAddingWithoutAnEventOrATimeAsksForTheStart(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();

        $this->command()->onCommand(555, 555, '/addtask 25.7. Decorate', true, TaskCommand::ADD);

        self::assertSame('Set a start time. A task cannot be saved without one.', $this->lastText());
        self::assertSame([], $this->transients);
    }

    public function testTwoMatchingTasksAreAskedWithButtonsAndNeverGuessed(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextResults[] = [$this->task(5), $this->task(6, 'decorate', ['starts_at' => '2026-07-25 18:00:00'])];
        $this->wpdb->nextRows[] = $this->task(5);
        $this->wpdb->nextRows[] = $this->task(6, 'decorate', ['starts_at' => '2026-07-25 18:00:00']);

        $this->command()->onCommand(555, 555, '/edittask Decorate ; notes Keys at the bar', true, TaskCommand::EDIT);

        self::assertSame('Which task?', $this->lastText());
        $id = $this->requestId();
        self::assertSame(['tk:c:' . $id . ':5', 'tk:c:' . $id . ':6', 'tk:x:' . $id], $this->buttonData());

        // A task that was not offered cannot be slipped in.
        $this->wpdb->nextRows[] = $this->organizer();
        $this->command()->onCallback($this->tap('tk:c:' . $id . ':9'));
        self::assertSame(0, $this->transients[TaskCommand::STATE_PREFIX . $id]['choice']);

        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextRows[] = $this->task(6, 'decorate', ['starts_at' => '2026-07-25 18:00:00']);
        $this->wpdb->nextResults[] = [];

        $this->command()->onCallback($this->tap('tk:c:' . $id . ':6'));

        self::assertSame("Change Decorate · Sat 25 Jul 18:00–23:00?\nNotes: Keys at the bar", $this->lastText());
        $this->nothingWritten();
    }

    public function testCancelAndSomeoneElsesTapWriteNothing(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextResults[] = [$this->task()];
        $this->wpdb->nextRows[] = $this->task();
        $this->wpdb->nextResults[] = [];

        $this->command()->onCommand(555, 555, '/deletetask Decorate', true, TaskCommand::DELETE);
        $id = $this->requestId();

        $this->command()->onCallback($this->tap('tk:y:' . $id, 777));
        self::assertSame('This request has expired. Please send it again.', $this->lastCallTo('answerCallbackQuery')['text']);

        $this->command()->onCallback($this->tap('tk:x:' . $id));
        self::assertSame('✖️ Cancelled. Nothing was changed.', $this->lastText());

        $this->command()->onCallback($this->tap('tk:y:' . $id));
        $this->nothingWritten();
    }

    public function testOnlyOrganizersInAPrivateChat(): void
    {
        $this->command()->onCommand(-100, 555, '/mailtasks', false, TaskCommand::MAIL);
        self::assertSame('Use /mailtasks in a private chat with me.', $this->lastText());

        $this->wpdb->nextRows[] = $this->organizer(false);
        $this->command()->onCommand(555, 555, '/mailtasks', true, TaskCommand::MAIL);
        self::assertSame('Only organizers can change tasks.', $this->lastText());

        // Organizer when asked, no longer when tapping.
        $this->transients[TaskCommand::STATE_PREFIX . 'aaaaaaaaaaaaaaaa'] = [
            'user' => 555, 'mode' => 'deletetask', 'choices' => [5], 'choice' => 5, 'fields' => [], 'date' => '',
        ];
        $this->wpdb->nextRows[] = $this->organizer(false);
        $this->command()->onCallback($this->tap('tk:y:aaaaaaaaaaaaaaaa'));
        self::assertSame('Only organizers can change tasks.', $this->lastCallTo('answerCallbackQuery')['text']);
        $this->nothingWritten();
    }

    public function testTheOpenTaskEmailIsSentOnlyAfterTheTap(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();

        $this->command()->onCommand(555, 555, '/mailtasks', true, TaskCommand::MAIL);

        self::assertStringStartsWith('Send the open-task email now', $this->lastText());
        $id = $this->requestId();

        $this->wpdb->nextRows[] = $this->organizer();
        $this->command()->onCallback($this->tap('tk:y:' . $id));

        self::assertSame('⚠️ Nothing to send: no open tasks, or everyone eligible already has it.', $this->lastText());
    }

    /** The Crew app's helper box: no choice buttons, so two matching tasks are named instead. */
    public function testTheWebHelperNamesSeveralMatchingTasks(): void
    {
        $this->wpdb->nextResults[] = [$this->task(5), $this->task(6, 'decorate', ['starts_at' => '2026-07-25 18:00:00'])];
        $this->wpdb->nextRows[] = $this->task(5);
        $this->wpdb->nextRows[] = $this->task(6, 'decorate', ['starts_at' => '2026-07-25 18:00:00']);

        $asked = $this->command()->prepareWeb(TaskCommand::EDIT, 'Decorate ; notes Keys at the bar');

        self::assertFalse($asked['ok']);
        self::assertStringStartsWith('Several match: Decorate · ', $asked['text']);
        self::assertSame('', $this->lastText());
    }

    public function testTheWebHelperSendsTheOpenTaskEmailOnlyOnConfirm(): void
    {
        $asked = $this->command()->prepareWeb(TaskCommand::MAIL, '');

        self::assertTrue($asked['ok']);
        self::assertStringStartsWith('Send the open-task email now', $asked['text']);

        $done = $this->command()->runWeb($asked['state']);

        self::assertFalse($done['changed']);
        self::assertSame('⚠️ Nothing to send: no open tasks, or everyone eligible already has it.', $done['text']);
    }

    public function testMakeTasksSaysWhenThereIsNoEvent(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();

        $this->command()->onCommand(555, 555, '/maketasks', true, TaskCommand::MAKE);

        self::assertSame('No coming event was found.', $this->lastText());
    }

    public function testFieldsAreReadOrTheUnreadablePartIsNamed(): void
    {
        self::assertSame(
            [['start' => '21:00', 'end' => '02:30', 'capacity' => 4, 'plus_ones' => 1, 'notes' => ''], ''],
            TaskCommand::parseFields('time 21-2.30 ; places 4 ; plus 1 ; notes -')
        );
        self::assertSame([['end' => ''], ''], TaskCommand::parseFields('end -'));
        self::assertSame([[], 'time 25:00'], TaskCommand::parseFields('places 2 ; time 25:00'));
        self::assertSame([[], 'colour red'], TaskCommand::parseFields('colour red'));
    }
}
