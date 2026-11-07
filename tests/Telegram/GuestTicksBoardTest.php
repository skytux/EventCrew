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
 * The Guest sign up and Anonymous display ticks on the Telegram board (Owner
 * 2026-11-07). Task 1 is Decorate (on the guest board, 2 places).
 */
final class GuestTicksBoardTest extends TelegramTestCase
{
    private RoleBoardWpdb $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new RoleBoardWpdb();
        $this->wpdb = $this->db;
        $GLOBALS['wpdb'] = $this->db;
        $this->boardWithRoles = true;
        $this->options[BoardService::CARDS_OPTION] = true;
        $this->telegramResults['sendMessage'] = ['message_id' => 62];
        $this->db->tasks = [[
            'id' => 1,
            'task_date' => '2026-08-01',
            'role_slug' => 'decorate',
            'capacity' => 2,
            'event_post_id' => null,
            'event_label' => 'Party',
            'starts_at' => null,
            'ends_at' => null,
            'notes' => '',
            'created_at' => '2026-07-01 00:00:00',
        ]];

        Functions\when('get_the_title')->justReturn('');
    }

    /**
     * @param array<string, mixed> $ticks
     */
    private function decorate(array $ticks): void
    {
        $this->options[Roles::OPTION_NAME] = [
            ['slug' => 'decorate', 'label' => 'Decorate', 'emoji' => '🎈'] + $ticks,
        ];
    }

    private function person(string $roles, bool $organizer = false): Person
    {
        return Person::fromRow([
            'id' => 7,
            'email' => 'p7@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'is_organizer' => $organizer ? 1 : 0,
            'role_slugs' => $roles,
            'telegram_user_id' => 555,
            'telegram_chat_id' => 555,
        ]);
    }

    /**
     * @param array<string, mixed> $board
     * @return list<string> The callback data of the buttons, day buttons and blanks left out.
     */
    private static function data(array $board): array
    {
        $data = [];

        foreach ($board['reply_markup']['inline_keyboard'] ?? $board['keyboard'] ?? [] as $row) {
            foreach ($row as $button) {
                $d = (string) ($button['callback_data'] ?? '');

                if ('' !== $d && BoardRenderer::SPACER_DATA !== $d && ! str_starts_with($d, 'bd:')) {
                    $data[] = $d;
                }
            }
        }

        return $data;
    }

    private function renderer(): BoardRenderer
    {
        return new BoardRenderer(new TaskRepository(), $this->roleTaskService());
    }

    public function testByDefaultGuestsGetTheButtonAndTheNames(): void
    {
        $this->decorate([]);
        $this->db->occupants = [['task_id' => 1, 'display_name' => 'Anna Virtanen']];

        $board = $this->renderer()->crewBoard();

        self::assertSame(['t:1'], self::data($board));
        self::assertStringContainsString("🎈 Decorate · 1/2\nAnna", $board['text']);
        self::assertStringNotContainsString('crew only', $board['text']);
    }

    public function testGuestSignUpOffShowsTheTaskWithNoButtonOnTheGroupBoard(): void
    {
        $this->decorate(['guest_signup' => false]);
        // The text lists only tasks someone has signed up for (Owner 2026-11-07).
        $this->db->occupants = [['task_id' => 1, 'display_name' => 'Anna Virtanen']];

        // Even when an organizer asks for it: the group's board is the guest board.
        $board = $this->renderer()->crewBoard('', true, $this->person('decorate', true));

        self::assertSame([], self::data($board));
        self::assertStringContainsString('🎈 Decorate · 1/2 · crew only', $board['text']);
    }

    public function testGuestSignUpOffKeepsTheButtonForTheRoleAndOrganizersInPrivate(): void
    {
        $this->decorate(['guest_signup' => false]);

        foreach ([$this->person('decorate'), $this->person('', true)] as $crew) {
            $board = $this->renderer()->crewBoard('', false, $crew);

            self::assertContains('t:1', self::data($board));
            self::assertStringNotContainsString('crew only', $board['text']);
        }
    }

    public function testGuestSignUpOffHasNoButtonForAGuestInPrivate(): void
    {
        $this->decorate(['guest_signup' => false]);
        $this->db->occupants = [['task_id' => 1, 'display_name' => 'Anna Virtanen']];

        $board = $this->renderer()->crewBoard('', false, $this->person('dj'));

        self::assertNotContains('t:1', self::data($board));
        self::assertStringContainsString('crew only', $board['text']);
    }

    public function testGuestSignUpOffIsIgnoredWhenTheRoleIsNotOnTheGuestBoard(): void
    {
        $this->decorate(['on_board' => false, 'guest_signup' => false]);

        $board = $this->renderer()->crewBoard();

        self::assertStringNotContainsString('crew only', $board['text']);
        self::assertStringNotContainsString('Decorate', $board['text']);
    }

    public function testAnonymousDisplayShowsGuestsACountInPlaceOfTheNames(): void
    {
        $this->decorate(['anonymous' => true]);
        $this->db->occupants = [['task_id' => 1, 'display_name' => 'Anna Virtanen'], ['task_id' => 1, 'display_name' => 'Ben']];

        $group = $this->renderer()->crewBoard();
        $guest = $this->renderer()->crewBoard('', false, $this->person('dj'));

        foreach ([$group, $guest] as $board) {
            self::assertStringContainsString("🎈 Decorate · 2/2\n2 signed up", $board['text']);
            self::assertStringNotContainsString('Anna', $board['text']);
        }
    }

    public function testAnonymousDisplayStillShowsTheNamesToTheRoleAndOrganizers(): void
    {
        $this->decorate(['anonymous' => true]);
        $this->db->occupants = [['task_id' => 1, 'display_name' => 'Anna Virtanen']];

        foreach ([$this->person('decorate'), $this->person('', true)] as $crew) {
            $board = $this->renderer()->crewBoard('', false, $crew);

            self::assertStringContainsString("🎈 Decorate · 1/2\nAnna", $board['text']);
        }
    }
}
