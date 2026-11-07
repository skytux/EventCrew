<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\Roles;
use EventCrew\Telegram\BoardRenderer;
use EventCrew\Telegram\BoardService;
use EventCrew\Tests\Support\RoleBoardWpdb;

/**
 * The tasks of a person's roles in their private copy of the board (Owner
 * 2026-11-07): one message, the role tasks under the same event headings with
 * RoleTaskBot's ✋ / ❌ / ➕ buttons; no third message after /board; a ✋
 * answer redraws the board. The group's board lists the special tasks as
 * lines with no button.
 *
 * Roles: Decorate (on the guest board), DJ and VJ (restricted). Task 1 is
 * Decorate, task 2 DJ, task 3 VJ, all on Sat 1 Aug unless a test moves one.
 */
final class PrivateBoardRoleTasksTest extends TelegramTestCase
{
    private RoleBoardWpdb $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new RoleBoardWpdb();
        $this->wpdb = $this->db;
        $GLOBALS['wpdb'] = $this->db;
        $this->boardWithRoles = true;

        $this->options[Roles::OPTION_NAME] = [
            ['slug' => 'decorate', 'label' => 'Decorate', 'emoji' => '🎈'],
            ['slug' => 'dj', 'label' => 'DJ', 'emoji' => '🎧', 'restricted' => true],
            ['slug' => 'vj', 'label' => 'VJ', 'emoji' => '📽', 'restricted' => true],
        ];
        $this->options[BoardService::CARDS_OPTION] = true;
        $this->telegramResults['sendMessage'] = ['message_id' => 62];
        $this->db->tasks = [
            $this->taskRow(1, 'decorate', 2),
            $this->taskRow(2, 'dj', 1),
            $this->taskRow(3, 'vj', 1),
        ];

        Functions\when('get_the_title')->justReturn('');
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(int $id, string $role, int $capacity, string $date = '2026-08-01'): array
    {
        return [
            'id' => $id,
            'task_date' => $date,
            'role_slug' => $role,
            'capacity' => $capacity,
            'event_post_id' => null,
            'event_label' => 'Party',
            'starts_at' => null,
            'ends_at' => null,
            'notes' => '',
            'created_at' => '2026-07-01 00:00:00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function personRow(string $roles, bool $organizer = false): array
    {
        return [
            'id' => 7,
            'email' => 'p7@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'is_organizer' => $organizer ? 1 : 0,
            'role_slugs' => $roles,
            'telegram_user_id' => 555,
            'telegram_chat_id' => 555,
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, string> Button label => callback_data, blanks left out.
     */
    private static function buttons(array $body): array
    {
        $buttons = [];

        foreach ($body['reply_markup']['inline_keyboard'] ?? $body['keyboard'] ?? [] as $row) {
            foreach ($row as $button) {
                if (isset($button['callback_data']) && BoardRenderer::SPACER_DATA !== $button['callback_data']) {
                    $buttons[(string) $button['text']] = (string) $button['callback_data'];
                }
            }
        }

        return $buttons;
    }

    private function renderer(): BoardRenderer
    {
        return new BoardRenderer(new TaskRepository(), $this->roleTaskService());
    }

    // --- change 1: names on their own line -----------------------------------

    public function testTheNamesAreOnTheLineUnderTheTask(): void
    {
        $this->db->occupants = [['task_id' => 1, 'display_name' => 'Anna Virtanen'], ['task_id' => 1, 'display_name' => 'Ben']];

        $board = $this->renderer()->crewBoard();

        self::assertStringContainsString("🎈 Decorate · 2/2\nAnna, Ben\n", $board['text']);
        self::assertStringContainsString("🎧 DJ · 0/1\n–", $board['text']);
        self::assertStringNotContainsString(': ', $board['text']);
    }

    // --- the group's board ----------------------------------------------------

    public function testTheGroupBoardListsTheSpecialTasksWithoutButtons(): void
    {
        $person = Person::fromRow($this->personRow('dj', true));

        $board = $this->renderer()->crewBoard('', true, $person);

        self::assertStringContainsString("🎧 DJ · 0/1\n–", $board['text']);
        self::assertStringContainsString("📽 VJ · 0/1\n–", $board['text']);
        self::assertStringNotContainsString('✋', $board['text']);
        $data = self::buttons($board);
        self::assertSame(['t:1'], array_values(array_filter($data, static fn (string $d): bool => ! str_starts_with($d, 'bd:'))));
    }

    public function testTheGroupBoardDoesNotShowADayWithOnlySpecialTasks(): void
    {
        $this->db->tasks[1] = $this->taskRow(2, 'dj', 1, '2026-08-02');

        $board = $this->renderer()->crewBoard('2026-08-02', true);

        self::assertStringContainsString('Sat 1 Aug', $board['text']);
        self::assertStringNotContainsString('DJ', $board['text']);
    }

    // --- the private board ----------------------------------------------------

    public function testThePrivateBoardListsMyRoleTasksWithSignUpAndOtherSpecialTasksAsLines(): void
    {
        $this->db->person = $this->personRow('dj');

        $this->boardService()->sendPrivate(555);

        self::assertSame(['sendMessage'], $this->calledMethods());
        $sent = $this->lastCallTo('sendMessage');
        self::assertStringContainsString("🎧 DJ · 0/1\n–", $sent['text']);
        self::assertStringContainsString("📽 VJ · 0/1\n–", $sent['text']);
        self::assertStringContainsString('✋ sign up · ❌ cancel', $sent['text']);
        self::assertStringNotContainsString('➕', $sent['text']);
        $buttons = self::buttons($sent);
        self::assertSame('t:1', $buttons['🎈 Decorate'] ?? null);
        self::assertSame('rt:s:2', $buttons['✋ 🎧 DJ'] ?? null);
        self::assertNotContains('rt:s:3', $buttons);
        self::assertNotContains('rt:a:2', $buttons);
    }

    public function testATaskImOnHasTheCancelButton(): void
    {
        $this->db->person = $this->personRow('dj');
        $this->db->onTask = [2 => true];
        $this->db->occupants = [['task_id' => 2, 'display_name' => 'Sam']];

        $this->boardService()->sendPrivate(555);

        $sent = $this->lastCallTo('sendMessage');
        self::assertStringContainsString("🎧 DJ · 1/1\nSam", $sent['text']);
        self::assertSame('rt:c:2', self::buttons($sent)['❌ 🎧 DJ'] ?? null);
    }

    public function testAnOrganizerGetsAddSomeoneOnTheSpecialTasksOnly(): void
    {
        $this->db->person = $this->personRow('', true);

        $this->boardService()->sendPrivate(555);

        $sent = $this->lastCallTo('sendMessage');
        $buttons = self::buttons($sent);
        self::assertSame('rt:a:2', $buttons['➕ 🎧 DJ'] ?? null);
        self::assertSame('rt:a:3', $buttons['➕ 📽 VJ'] ?? null);
        self::assertSame('t:1', $buttons['🎈 Decorate'] ?? null);
        self::assertNotContains('rt:a:1', $buttons);
        self::assertNotContains('rt:s:2', $buttons);
        // Only what there is to tap is explained.
        self::assertStringContainsString("\n➕ add someone\n", $sent['text']);
        self::assertStringNotContainsString('✋', $sent['text']);
    }

    public function testSomeoneNotFoundGetsThePlainBoard(): void
    {
        $this->boardService()->sendPrivate(555);

        $sent = $this->lastCallTo('sendMessage');
        self::assertSame(['🎈 Decorate' => 't:1'], self::buttons($sent));
        self::assertStringNotContainsString('✋', $sent['text']);
    }

    public function testTheDayButtonsIncludeADayWithOnlyMyRoleTasks(): void
    {
        $this->db->person = $this->personRow('dj');
        $this->db->tasks[1] = $this->taskRow(2, 'dj', 1, '2026-08-02');

        $this->boardService()->sendPrivate(555);

        $first = self::buttons($this->lastCallTo('sendMessage'));
        self::assertContains('bd:2026-08-02', $first);
        self::assertNotContains('rt:s:2', $first);
        // Only one row of day buttons: the board's.
        self::assertSame([], array_filter($first, static fn (string $d): bool => str_starts_with($d, 'rt:d:')));

        // The day tap on the private board shows that day with the DJ task.
        $this->boardService()->onPickDate([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'data' => 'bd:2026-08-02',
            'message' => ['message_id' => 62, 'chat' => ['id' => 555, 'type' => 'private']],
        ]);

        $edit = $this->lastCallTo('editMessageText');
        self::assertStringContainsString("🎧 DJ · 0/1\n–", $edit['text']);
        self::assertSame('rt:s:2', self::buttons($edit)['✋ 🎧 DJ'] ?? null);
    }

    public function testBoardInAPrivateChatIsOneTextMessageWithTheRoleTasks(): void
    {
        $this->db->person = $this->personRow('dj');

        $this->updateRouter()->dispatch([
            'message' => [
                'text' => '/board',
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 555],
            ],
        ]);

        $sends = array_values(array_filter(
            $this->telegramCalls,
            static fn (array $call): bool => 'sendMessage' === $call['method']
        ));
        self::assertCount(1, $sends);
        self::assertSame('rt:s:2', self::buttons($sends[0]['body'])['✋ 🎧 DJ'] ?? null);
    }

    public function testAnsweringTheCreditQuestionRedrawsThePrivateBoard(): void
    {
        $this->db->person = $this->personRow('dj');
        $this->wpdb->nextVars[] = 1;            // join: capacity
        $this->wpdb->nextQueryResults[] = 1;    // join: inserted

        // The answer button carries the board message (62); the tap sits on
        // the question message (8).
        $this->roleTaskBot()->onSelect([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'data' => 'rt:n:2:62',
            'message' => ['message_id' => 8, 'chat' => ['id' => 555, 'type' => 'private']],
        ]);

        $edit = $this->lastCallTo('editMessageText');
        self::assertNotNull($edit);
        self::assertSame(62, $edit['message_id']);
        self::assertStringContainsString('📅 Sat 1 Aug', $edit['text']);
        self::assertSame('t:1', self::buttons($edit)['🎈 Decorate'] ?? null);
        self::assertSame('rt:c:2', self::buttons($edit)['❌ 🎧 DJ'] ?? null);
    }
}
