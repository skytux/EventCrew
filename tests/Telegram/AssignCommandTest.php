<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\Roles;
use EventCrew\Telegram\AssignCommand;

/**
 * /assign and /unassign: an organizer puts a named member on a task, or takes
 * them off, in a private chat with the bot.
 *
 * Settings > Roles has Decorate (anyone) and DJ (only people with the role).
 * Task 5 is Decorate with 2 places, task 9 is DJ, both on 2026-07-25. The
 * clock reads 2026-07-20 12:00. The organizer is person 1, Telegram user 555.
 */
final class AssignCommandTest extends TelegramTestCase
{
    /** @var array<string, mixed> */
    private array $transients = [];

    /** @var array<int, string> */
    private array $mailedTo = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->transients = [];
        $this->mailedTo = [];
        $this->options[Roles::OPTION_NAME] = [
            ['slug' => 'decorate', 'label' => 'Decorate'],
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
        Functions\when('wp_mail')->alias(function (string $to): bool {
            $this->mailedTo[] = $to;

            return true;
        });
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('get_the_title')->justReturn('');
    }

    private function command(): AssignCommand
    {
        return new AssignCommand(new PersonRepository(), new TaskRepository(), $this->roleTaskService(), $this->client());
    }

    /**
     * @return array<string, mixed>
     */
    private function person(int $id, string $name, string $roles = '', bool $organizer = false): array
    {
        return [
            'id' => $id,
            'email' => 'p' . $id . '@example.com',
            'display_name' => $name,
            'email_verified_at' => '2026-07-01 00:00:00',
            'is_organizer' => $organizer ? 1 : 0,
            'role_slugs' => $roles,
            'telegram_user_id' => $organizer ? 555 : 600 + $id,
            'telegram_chat_id' => $organizer ? 555 : 600 + $id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function organizer(): array
    {
        return $this->person(1, 'Owner Organizer', '', true);
    }

    /**
     * @return array<string, mixed>
     */
    private function task(int $id, string $role, int $capacity = 2): array
    {
        return [
            'id' => $id,
            'task_date' => '2026-07-25',
            'role_slug' => $role,
            'capacity' => $capacity,
            'event_post_id' => null,
            'event_label' => 'Party',
            'starts_at' => null,
            'ends_at' => null,
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

    /** The request id in the buttons of the last message. */
    private function requestId(): string
    {
        preg_match('/^as:\w:([0-9a-f]{16})/', $this->buttonData()[0] ?? '', $m);

        return $m[1] ?? '';
    }

    private function nothingWritten(): void
    {
        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->wpdb->updates);
        self::assertStringNotContainsString('INSERT', implode("\n", $this->wpdb->queries));
    }

    public function testAnOrganizerAddsAMemberToARoleTaskTheyLackTheRoleFor(): void
    {
        $milla = $this->person(7, 'Milla Virtanen');

        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextResults[] = [$this->task(5, 'decorate'), $this->task(9, 'dj', 1)];
        $this->wpdb->nextResults[] = [$this->person(2, 'Kim Lee'), $milla];
        $this->wpdb->nextRows[] = $this->task(9, 'dj', 1);   // confirm: task
        $this->wpdb->nextRows[] = $milla;                   // confirm: person
        $this->wpdb->nextRows[] = null;                     // not on the task yet

        $this->command()->onCommand(555, 555, '/assign dj ; mila', true, AssignCommand::ADD);

        $text = $this->lastText();
        self::assertStringContainsString('Add Milla Virtanen to DJ', $text);
        self::assertStringContainsString('Milla Virtanen does not have the DJ role. As an organizer you can still add them; they get the role.', $text);
        self::assertStringContainsString('Count toward free-entry credit?', $text);
        $id = $this->requestId();
        self::assertSame(['as:y:' . $id, 'as:n:' . $id, 'as:x:' . $id], $this->buttonData());
        $this->nothingWritten();

        $this->wpdb->nextRows[] = $this->organizer();                   // the tapper
        $this->wpdb->nextRows[] = $this->task(9, 'dj', 1);              // result label
        $this->wpdb->nextRows[] = $this->task(9, 'dj', 1);              // addPerson: task
        $this->wpdb->nextRows[] = $milla;                               // addPerson: person
        $this->wpdb->nextRows[] = $this->task(9, 'dj', 1);              // claim: task
        $this->wpdb->nextRows[] = ['role_slugs' => 'dj'] + $milla;      // claim: person
        $this->wpdb->nextVars[] = 1;
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextQueryResults[] = 1;
        $this->wpdb->nextRows[] = $this->task(9, 'dj', 1);              // confirmation: task
        $this->wpdb->nextRows[] = ['id' => 50, 'task_id' => 9, 'person_id' => 7, 'status' => 'signed_up'];

        $this->command()->onCallback($this->tap('as:y:' . $id));

        self::assertSame('dj', $this->wpdb->updates[0]['data']['role_slugs']);
        self::assertStringContainsString("SELECT 9, 7, 'signed_up', '2026-07-20 12:00:00', 1", implode("\n", $this->wpdb->queries));
        self::assertSame(['p7@example.com'], $this->mailedTo);
        self::assertStringStartsWith('✅ Milla Virtanen · DJ', $this->lastText());
        self::assertStringContainsString('Added to the task. They got an email with their ticket.', $this->lastText());
        self::assertSame([], $this->transients);
    }

    public function testSomeoneWhoIsNotAnOrganizerIsRefused(): void
    {
        $this->wpdb->nextRows[] = $this->person(7, 'Milla Virtanen');

        $this->command()->onCommand(555, 555, '/assign Decorate ; Kim', true, AssignCommand::ADD);

        self::assertSame('Only organizers can add people to a task.', $this->lastText());
        self::assertSame([], $this->transients);
        $this->nothingWritten();
    }

    public function testATapFromSomeoneWhoIsNoLongerAnOrganizerWritesNothing(): void
    {
        $this->transients[AssignCommand::STATE_PREFIX . 'aaaaaaaaaaaaaaaa'] = [
            'user' => 555, 'mode' => 'assign', 'tasks' => [5], 'people' => [2], 'task' => 5, 'person' => 2,
        ];
        $this->wpdb->nextRows[] = $this->person(1, 'Owner', '', false);

        $this->command()->onCallback($this->tap('as:y:aaaaaaaaaaaaaaaa'));

        self::assertSame('Only organizers can add people to a task.', $this->lastCallTo('answerCallbackQuery')['text']);
        $this->nothingWritten();
    }

    public function testTheCommandIsRefusedInAGroup(): void
    {
        $this->command()->onCommand(-100, 555, '/assign Decorate ; Kim', false, AssignCommand::ADD);

        self::assertSame('Use /assign in a private chat with me.', $this->lastText());
        self::assertSame([], $this->wpdb->queries);
    }

    public function testAnAmbiguousNameAsksWithButtonsAndNeverGuesses(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextResults[] = [$this->task(5, 'decorate')];
        $this->wpdb->nextResults[] = [$this->person(7, 'Milla Virtanen'), $this->person(8, 'Milla Korhonen'), $this->person(2, 'Kim Lee')];

        $this->command()->onCommand(555, 555, '/assign decorate ; Milla', true, AssignCommand::ADD);

        self::assertSame('Which member?', $this->lastText());
        $id = $this->requestId();
        self::assertSame(['as:p:' . $id . ':7', 'as:p:' . $id . ':8', 'as:x:' . $id], $this->buttonData());
        $this->nothingWritten();

        // A member who was not offered cannot be slipped in.
        $this->wpdb->nextRows[] = $this->organizer();
        $this->command()->onCallback($this->tap('as:p:' . $id . ':2'));
        self::assertSame(0, $this->transients[AssignCommand::STATE_PREFIX . $id]['person']);

        // Choosing one leads to the confirmation, still with nothing written.
        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextRows[] = $this->task(5, 'decorate');
        $this->wpdb->nextRows[] = $this->person(8, 'Milla Korhonen');
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextResults[] = [['task_id' => 5, 'taken' => 1]];

        $this->command()->onCallback($this->tap('as:p:' . $id . ':8'));

        self::assertStringStartsWith('Add Milla Korhonen to Decorate', $this->lastText());
        self::assertSame(['as:y:' . $id, 'as:x:' . $id], $this->buttonData());
        $this->nothingWritten();
    }

    public function testAFullTaskIsRefusedWithNoButtons(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextResults[] = [$this->task(5, 'decorate')];
        $this->wpdb->nextResults[] = [$this->person(2, 'Kim Lee')];
        $this->wpdb->nextRows[] = $this->task(5, 'decorate');
        $this->wpdb->nextRows[] = $this->person(2, 'Kim Lee');
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextResults[] = [['task_id' => 5, 'taken' => 2]];

        $this->command()->onCommand(555, 555, '/assign Decorate Kim', true, AssignCommand::ADD);

        self::assertStringContainsString('That task is full (2 of 2 places taken). Nothing was added.', $this->lastText());
        self::assertSame([], $this->buttonData());
        self::assertSame([], $this->transients);
        $this->nothingWritten();
    }

    public function testCancelWritesNothing(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextResults[] = [$this->task(5, 'decorate')];
        $this->wpdb->nextResults[] = [$this->person(2, 'Kim Lee')];
        $this->wpdb->nextRows[] = $this->task(5, 'decorate');
        $this->wpdb->nextRows[] = $this->person(2, 'Kim Lee');
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextResults[] = [['task_id' => 5, 'taken' => 0]];

        $this->command()->onCommand(555, 555, '/assign Decorate ; Kim', true, AssignCommand::ADD);
        $id = $this->requestId();

        $this->command()->onCallback($this->tap('as:x:' . $id));

        self::assertSame('✖️ Cancelled. Nothing was changed.', $this->lastText());
        self::assertSame([], $this->transients);
        $this->nothingWritten();

        // The ✅ after a cancel finds nothing to do.
        $this->command()->onCallback($this->tap('as:y:' . $id));
        $this->nothingWritten();
    }

    public function testAnUnknownTaskListsTheTasksOfTheNextEvent(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextResults[] = [$this->task(5, 'decorate'), $this->task(9, 'dj', 1)];

        $this->command()->onCommand(555, 555, '/assign Bar ; Kim', true, AssignCommand::ADD);

        self::assertStringStartsWith('No task called “Bar”.', $this->lastText());
        self::assertStringContainsString('Decorate, DJ', $this->lastText());
        $this->nothingWritten();
    }

    public function testAnOrganizerTakesAMemberOffATask(): void
    {
        $kim = $this->person(2, 'Kim Lee');
        $this->options[\EventCrew\Support\Signature::OPTION] = 'The crew';

        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextResults[] = [$this->task(5, 'decorate')];
        $this->wpdb->nextResults[] = [$kim];
        $this->wpdb->nextRows[] = $this->task(5, 'decorate');
        $this->wpdb->nextRows[] = $kim;
        $this->wpdb->nextRows[] = ['id' => 50, 'task_id' => 5, 'person_id' => 2, 'status' => 'signed_up'];

        $this->command()->onCommand(555, 555, '/unassign Decorate ; kim', true, AssignCommand::REMOVE);

        self::assertStringStartsWith('Take Kim Lee off Decorate', $this->lastText());
        $id = $this->requestId();
        self::assertSame(['as:y:' . $id, 'as:x:' . $id], $this->buttonData());
        self::assertSame([], $this->wpdb->updates);

        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextRows[] = $this->task(5, 'decorate');
        $this->wpdb->nextRows[] = $this->task(5, 'decorate');
        $this->wpdb->nextRows[] = $kim;
        $this->wpdb->nextRows[] = ['id' => 50, 'task_id' => 5, 'person_id' => 2, 'status' => 'signed_up'];

        $this->command()->onCallback($this->tap('as:y:' . $id));

        self::assertSame('cancelled', $this->wpdb->updates[0]['data']['status']);
        self::assertSame(1, $this->wpdb->updates[0]['data']['changed_by']);
        self::assertStringStartsWith('✅ Kim Lee · Decorate', $this->lastText());

        // Kim hears it from the bot, framed like the other DMs that arrive.
        $toKim = array_values(array_filter(
            $this->telegramCalls,
            static fn (array $call): bool => 'sendMessage' === $call['method'] && 602 === (int) ($call['body']['chat_id'] ?? 0)
        ));
        self::assertCount(1, $toKim);
        $text = $toKim[0]['body']['text'];
        self::assertStringContainsString("Test Site\n\nHi Kim,\n\nAn organizer took you off Decorate · Sat 25 Jul.\n\nThe crew", $text);
    }

    public function testNoDmWhenThePersonHasNoTelegram(): void
    {
        $kim = ['telegram_user_id' => null, 'telegram_chat_id' => null] + $this->person(2, 'Kim Lee');

        $this->wpdb->nextRows[] = $this->task(5, 'decorate');
        $this->wpdb->nextRows[] = $kim;
        $this->wpdb->nextRows[] = ['id' => 50, 'task_id' => 5, 'person_id' => 2, 'status' => 'signed_up'];

        $result = $this->roleTaskService()->removePerson(
            \EventCrew\Models\Person::fromRow($this->organizer()),
            5,
            2
        );

        self::assertSame('removed', $result['code']);
        self::assertSame([], array_filter($this->telegramCalls, static fn (array $call): bool => 'sendMessage' === $call['method']));
    }

    public function testMatchingForgivesTyposAndPartsOfNames(): void
    {
        $people = array_map(
            static fn (array $r): \EventCrew\Models\Person => \EventCrew\Models\Person::fromRow($r),
            [$this->person(7, 'Milla Virtanen'), $this->person(8, 'Mikko Korhonen'), $this->person(2, 'Kim Lee')]
        );

        $ids = static fn (array $found): array => array_map(static fn ($p): int => $p->id, $found);

        self::assertSame([7], $ids(AssignCommand::matchPeople('milla virtanen', $people)));
        self::assertSame([7], $ids(AssignCommand::matchPeople('Milla V', $people)));
        self::assertSame([7], $ids(AssignCommand::matchPeople('Mila', $people)));
        self::assertSame([7, 8], $ids(AssignCommand::matchPeople('Mi', $people)));
        self::assertSame([], $ids(AssignCommand::matchPeople('Zed', $people)));
    }
}
