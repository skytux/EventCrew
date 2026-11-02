<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Support\Roles;

/**
 * /roletasks: the restricted-role tasks in the bot. Someone holding a role
 * sees its tasks and signs up after the credit question; someone without it
 * sees nothing and cannot sign up; an organizer adds a person who is not a
 * crew member, with or without an email; a member cannot add anyone.
 *
 * Settings > Roles has a "DJ" role set to "Only people with this role", and
 * task 9 is a DJ task. The clock reads 2026-07-20 12:00.
 */
final class RoleTaskBotTest extends TelegramTestCase
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
        Functions\when('sanitize_email')->alias(static fn (string $e): string => trim($e));
        Functions\when('is_email')->alias(
            static fn (string $e): bool => 1 === preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $e)
        );
        Functions\when('wp_generate_password')->justReturn('AbCdEf123456');
    }

    /**
     * @return array<string, mixed>
     */
    private function personRow(int $id, string $roles = '', bool $organizer = false): array
    {
        return [
            'id' => $id,
            'email' => 'p' . $id . '@example.com',
            'display_name' => 'Person ' . $id,
            'email_verified_at' => '2026-07-01 00:00:00',
            'is_organizer' => $organizer ? 1 : 0,
            'role_slugs' => $roles,
            'telegram_user_id' => 555,
            'telegram_chat_id' => 555,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function djTask(): array
    {
        return [
            'id' => 9,
            'task_date' => '2026-07-25',
            'role_slug' => 'dj',
            'capacity' => 1,
            'event_post_id' => null,
            'event_label' => 'Party',
            'starts_at' => null,
            'ends_at' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tap(string $data): array
    {
        return ['id' => 'cbq', 'from' => ['id' => 555], 'data' => $data, 'message' => ['chat' => ['id' => 555], 'message_id' => 3]];
    }

    /**
     * @return array<int, string>
     */
    private function buttonData(): array
    {
        $keyboard = $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'] ?? [];

        return array_column(array_merge(...$keyboard), 'callback_data');
    }

    public function testTheListShowsARoleTaskToSomeoneWithTheRole(): void
    {
        $this->wpdb->nextRows[] = $this->personRow(7, 'dj');
        $this->wpdb->nextResults[] = [
            array_merge($this->djTask(), ['id' => 5, 'role_slug' => 'decorate', 'capacity' => 2]),
            $this->djTask(),
        ];
        $this->wpdb->nextRows[] = null;   // isOn: not signed up yet

        $this->roleTaskBot()->start(555, 555);

        self::assertSame(['rt:s:9'], $this->buttonData());
    }

    public function testTheListIsEmptyForSomeoneWithoutTheRole(): void
    {
        $this->wpdb->nextRows[] = $this->personRow(7);
        $this->wpdb->nextResults[] = [$this->djTask()];

        $this->roleTaskBot()->start(555, 555);

        self::assertSame([], $this->buttonData());
        self::assertStringContainsString('no upcoming tasks for your roles', $this->lastCallTo('sendMessage')['text']);
    }

    public function testSomeoneWithoutTheRoleCannotSignUp(): void
    {
        $this->wpdb->nextRows[] = $this->personRow(7);
        $this->wpdb->nextRows[] = $this->djTask();

        $this->roleTaskBot()->onSelect($this->tap('rt:y:9'));

        self::assertSame('That task is no longer available.', $this->lastCallTo('answerCallbackQuery')['text']);
        self::assertStringNotContainsString('counts_credit)', implode("\n", $this->wpdb->queries));
    }

    public function testSigningUpAsksTheCreditQuestionThenJoinsWithTheAnswer(): void
    {
        $this->wpdb->nextRows[] = $this->personRow(7, 'dj');
        $this->wpdb->nextRows[] = $this->djTask();

        $this->roleTaskBot()->onSelect($this->tap('rt:s:9'));

        self::assertSame('Count toward free-entry credit?', $this->lastCallTo('sendMessage')['text']);
        // The answer buttons carry the list message (3) so it can be refreshed.
        self::assertSame(['rt:y:9:3', 'rt:n:9:3'], $this->buttonData());

        $this->wpdb->nextRows[] = $this->personRow(7, 'dj');   // the tapper
        $this->wpdb->nextRows[] = $this->djTask();             // ownTask
        $this->wpdb->nextRows[] = $this->djTask();             // claim: tasks->find
        $this->wpdb->nextRows[] = $this->personRow(7, 'dj');   // claim: people->find
        $this->wpdb->nextVars[] = 1;                           // join: capacity
        $this->wpdb->nextRows[] = null;                        // join: no row yet
        $this->wpdb->nextQueryResults[] = 1;                   // join: inserted

        $this->roleTaskBot()->onSelect($this->tap('rt:n:9'));

        self::assertStringContainsString("SELECT 9, 7, 'signed_up', '2026-07-20 12:00:00', 0", implode("\n", $this->wpdb->queries));
    }

    public function testAnsweringTheCreditQuestionRefreshesTheList(): void
    {
        $this->wpdb->nextRows[] = $this->personRow(7, 'dj');   // the tapper
        $this->wpdb->nextRows[] = $this->djTask();             // ownTask
        $this->wpdb->nextRows[] = $this->djTask();             // claim: tasks->find
        $this->wpdb->nextRows[] = $this->personRow(7, 'dj');   // claim: people->find
        $this->wpdb->nextVars[] = 1;                           // join: capacity
        $this->wpdb->nextRows[] = null;                        // join: no row yet
        $this->wpdb->nextQueryResults[] = 1;                   // join: inserted

        // The answer button carries the list message (3); the tap itself sits
        // on the question message (8).
        $tap = $this->tap('rt:n:9:3');
        $tap['message']['message_id'] = 8;
        $this->roleTaskBot()->onSelect($tap);

        $edit = $this->lastCallTo('editMessageText');
        self::assertSame(555, $edit['chat_id']);
        self::assertSame(3, $edit['message_id']);
    }

    /**
     * The organizer taps "Add someone", sends a name with an email, answers
     * the credit question, and the person is created, given the role, signed
     * up and emailed.
     */
    public function testAnOrganizerAddsANonMemberWithAnEmail(): void
    {
        $organizer = $this->personRow(1, '', true);

        $this->wpdb->nextRows[] = $organizer;
        $this->roleTaskBot()->onSelect($this->tap('rt:a:9'));

        $this->roleTaskBot()->capturePerson(555, 555, 'Kim Lee kim@example.com');
        self::assertSame(['rt:ay:9', 'rt:an:9'], $this->buttonData());

        $created = ['id' => 40, 'email' => 'kim@example.com', 'display_name' => 'Kim Lee'];
        $this->wpdb->insert_id = 40;
        $this->wpdb->nextRows[] = $organizer;                        // the tapper
        $this->wpdb->nextRows[] = $this->djTask();                   // addPerson: tasks->find
        $this->wpdb->nextRows[] = null;                              // findByEmail
        $this->wpdb->nextRows[] = $created;                          // people->find (new)
        $this->wpdb->nextRows[] = $this->djTask();                   // claim: tasks->find
        $this->wpdb->nextRows[] = ['role_slugs' => 'dj'] + $created; // claim: people->find
        $this->wpdb->nextVars[] = 1;
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextQueryResults[] = 1;
        $this->wpdb->nextRows[] = $this->djTask();                   // confirmation: task
        $this->wpdb->nextRows[] = ['id' => 50, 'task_id' => 9, 'person_id' => 40, 'status' => 'signed_up'];

        $this->roleTaskBot()->onSelect($this->tap('rt:ay:9'));

        $person = array_values(array_filter(
            $this->wpdb->inserts,
            static fn (array $i): bool => str_ends_with($i['table'], 'people')
        ));
        self::assertSame('Kim Lee', $person[0]['data']['display_name']);
        self::assertSame('kim@example.com', $person[0]['data']['email']);
        self::assertSame('dj', $this->wpdb->updates[0]['data']['role_slugs']);
        self::assertStringContainsString("SELECT 9, 40, 'signed_up', '2026-07-20 12:00:00', 1", implode("\n", $this->wpdb->queries));
        self::assertSame(['kim@example.com'], $this->mailedTo);
        self::assertSame('Added to the task. They got an email with their ticket.', $this->lastCallTo('sendMessage')['text']);
    }

    public function testAnOrganizerAddsANonMemberWithNoEmailAndNothingIsSent(): void
    {
        $organizer = $this->personRow(1, '', true);

        $this->wpdb->nextRows[] = $organizer;
        $this->roleTaskBot()->onSelect($this->tap('rt:a:9'));
        $this->roleTaskBot()->capturePerson(555, 555, 'Kim');

        $created = ['id' => 40, 'email' => 'added-abcdef123456@added.invalid', 'display_name' => 'Kim'];
        $this->wpdb->insert_id = 40;
        $this->wpdb->nextRows[] = $organizer;
        $this->wpdb->nextRows[] = $this->djTask();
        $this->wpdb->nextRows[] = $created;
        $this->wpdb->nextRows[] = $this->djTask();
        $this->wpdb->nextRows[] = ['role_slugs' => 'dj'] + $created;
        $this->wpdb->nextVars[] = 1;
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextQueryResults[] = 1;

        $this->roleTaskBot()->onSelect($this->tap('rt:an:9'));

        self::assertStringContainsString("SELECT 9, 40, 'signed_up', '2026-07-20 12:00:00', 0", implode("\n", $this->wpdb->queries));
        self::assertSame([], $this->mailedTo);
        self::assertSame('Added to the task. No email was given, so nothing was sent.', $this->lastCallTo('sendMessage')['text']);
    }

    public function testAMemberCannotAddSomeone(): void
    {
        $this->wpdb->nextRows[] = $this->personRow(7, 'dj');

        $this->roleTaskBot()->onSelect($this->tap('rt:a:9'));

        self::assertSame('Only organizers can add people to a task.', $this->lastCallTo('answerCallbackQuery')['text']);
        self::assertSame([], $this->transients);

        // A forged "add" answer without the prompt adds nobody either.
        $this->transients['eventcrew_tg_await_role_add_555'] = ['task' => 9, 'name' => 'Kim', 'email' => ''];
        $this->wpdb->nextRows[] = $this->personRow(7, 'dj');
        $this->wpdb->nextRows[] = $this->djTask();

        $this->roleTaskBot()->onSelect($this->tap('rt:ay:9'));

        self::assertSame([], $this->wpdb->inserts);
        self::assertSame('Only organizers can add people to a task.', $this->lastCallTo('sendMessage')['text']);
    }
}
