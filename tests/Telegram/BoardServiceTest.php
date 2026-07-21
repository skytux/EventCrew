<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\TelegramClient;

final class BoardServiceTest extends TelegramTestCase
{
    /** @var array<int, array{to: string, body: string}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mails = [];

        // eventName() reaches for the linked post's title; empty makes it fall
        // back to the typed label, which keeps these tests free of posts.
        Functions\when('get_the_title')->justReturn('');
        Functions\when('rest_url')->alias(static fn (string $path = ''): string => 'https://example.test/wp-json/' . $path);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = ['to' => $to, 'body' => $body];

            return true;
        });
    }

    private function board(): BoardService
    {
        return new BoardService(
            new TaskRepository(),
            new AssignmentRepository(),
            new PersonRepository(),
            $this->client(),
            new Logger(),
            new Mailer(new Logger())
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(int $id, ?int $eventPostId, string $label): array
    {
        return [
            'id' => $id,
            'task_date' => '2026-08-01',
            'role_slug' => 'decorate',
            'capacity' => 2,
            'event_post_id' => $eventPostId,
            'event_label' => $label,
            'starts_at' => null,
            'ends_at' => null,
            'notes' => '',
            'created_at' => '2026-07-01 00:00:00',
        ];
    }

    private function verifiedPerson(int $telegramId): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => $telegramId,
        ];
    }

    // --- render -------------------------------------------------------------

    public function testRenderShowsNoEventHeadingsForASingleEvent(): void
    {
        $this->wpdb->nextResults[] = [
            $this->taskRow(1, 100, 'Party'),
            $this->taskRow(2, 100, 'Party'),
        ];
        $this->wpdb->nextResults[] = []; // occupancyFor

        $rendered = $this->board()->render();

        self::assertStringNotContainsString('📅', $rendered['text']);
        // One button row per task, no deep-link row (no username cached).
        self::assertCount(2, $rendered['keyboard']);
    }

    public function testRenderGroupsByEventWhenMoreThanOneIsOpen(): void
    {
        $this->wpdb->nextResults[] = [
            $this->taskRow(1, 100, 'Party A'),
            $this->taskRow(2, 200, 'Party B'),
        ];
        $this->wpdb->nextResults[] = [];

        $rendered = $this->board()->render();

        self::assertStringContainsString('📅', $rendered['text']);
    }

    public function testRenderAddsTheDeepLinkButtonWhenTheUsernameIsKnown(): void
    {
        $this->options[BoardService::USERNAME_OPTION] = 'eventcrew_bot';
        $this->wpdb->nextResults[] = [$this->taskRow(1, 100, 'Party')];
        $this->wpdb->nextResults[] = [];

        $rendered = $this->board()->render();

        $last = end($rendered['keyboard']);
        self::assertStringContainsString('t.me/eventcrew_bot?start=onboard', $last[0]['url']);
    }

    public function testRenderReportsAnEmptyBoard(): void
    {
        $this->wpdb->nextResults[] = []; // no upcoming tasks

        $rendered = $this->board()->render();

        self::assertSame([], $rendered['keyboard']);
    }

    // --- onJoinLeave --------------------------------------------------------

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function callbackQuery(string $data, int $telegramId = 555): array
    {
        return [
            'id' => 'cbq1',
            'from' => ['id' => $telegramId],
            'data' => $data,
        ];
    }

    public function testJoinRefusesAnUnverifiedPerson(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'email_verified_at' => null,
            'telegram_user_id' => 555,
        ];

        $this->board()->onJoinLeave($this->callbackQuery('j:5'));

        self::assertSame([], $this->wpdb->inserts);
        self::assertContains('answerCallbackQuery', $this->calledMethods());
        self::assertTrue((bool) $this->lastCallTo('answerCallbackQuery')['show_alert']);
    }

    public function testJoinRefusesAnUnknownPerson(): void
    {
        // No person row queued -> findByTelegramUserId returns null.
        $this->board()->onJoinLeave($this->callbackQuery('j:5'));

        self::assertSame([], $this->wpdb->inserts);
        self::assertContains('answerCallbackQuery', $this->calledMethods());
    }

    public function testJoinRefusesWhenItClashesWithAnExistingSlot(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->verifiedPerson(555);
        $this->wpdb->nextVars[] = 1; // hasOverlapping count > 0

        $this->board()->onJoinLeave($this->callbackQuery('j:5'));

        self::assertSame([], $this->wpdb->inserts);
        // Nothing changed, so the board is not refreshed.
        self::assertNotContains('editMessageText', $this->calledMethods());
    }

    public function testJoinClaimsTheSlotAndRefreshesTheBoard(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->verifiedPerson(555);
        $this->wpdb->nextVars[] = 0; // hasOverlapping: no clash
        $this->wpdb->nextVars[] = 2; // taskCapacity
        $this->wpdb->nextQueryResults[] = 1; // conditional insert wrote one row

        $this->board()->onJoinLeave($this->callbackQuery('j:5'));

        self::assertContains('answerCallbackQuery', $this->calledMethods());
        // A successful change edits the existing board message in place.
        self::assertContains('editMessageText', $this->calledMethods());
    }

    public function testLeaveCancelsTheSlotWithoutDeletingAndRefreshes(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->verifiedPerson(555);
        // cancel(): findFor -> an occupying row, then the task's start time.
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];
        $this->wpdb->nextRows[] = ['starts_at' => '2026-09-01 20:00:00', 'task_date' => '2026-09-01'];

        $this->board()->onJoinLeave($this->callbackQuery('l:5'));

        // The row is updated (status), never deleted.
        self::assertSame([], $this->wpdb->deletes);
        self::assertNotSame([], $this->wpdb->updates);
        self::assertContains('editMessageText', $this->calledMethods());
    }

    public function testSignupSendsAConfirmationEmailWithATicketLink(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->verifiedPerson(555);
        $this->wpdb->nextVars[] = 0; // hasOverlapping
        $this->wpdb->nextVars[] = 2; // taskCapacity
        // join(): findFor -> null, then the conditional insert
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextQueryResults[] = 1;
        // sendSignupEmail: tasks->find, then findFor for the ticket id
        $this->wpdb->nextRows[] = $this->taskRow(5, 100, 'Party');
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];

        $this->board()->onJoinLeave($this->callbackQuery('j:5'));

        self::assertCount(1, $this->mails);
        self::assertSame('sam@example.com', $this->mails[0]['to']);
        self::assertStringContainsString('wp-json/eventcrew/v1/ticket?token=', $this->mails[0]['body']);
    }

    public function testADisabledPersonSigningUpGetsNoEmail(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => 555,
            'disabled_at' => '2026-07-10 09:00:00',
        ];
        $this->wpdb->nextVars[] = 0; // hasOverlapping
        $this->wpdb->nextVars[] = 2; // taskCapacity
        $this->wpdb->nextRows[] = null; // join findFor
        $this->wpdb->nextQueryResults[] = 1;

        $this->board()->onJoinLeave($this->callbackQuery('j:5'));

        self::assertSame([], $this->mails);
    }

    public function testToggleJoinsWhenNotYetSignedUp(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->verifiedPerson(555);
        // findFor (toggle) -> null (unqueued), then the join path:
        $this->wpdb->nextVars[] = 0; // hasOverlapping
        $this->wpdb->nextVars[] = 2; // taskCapacity
        $this->wpdb->nextQueryResults[] = 1; // conditional insert

        $this->board()->onJoinLeave($this->callbackQuery('t:5'));

        self::assertContains('editMessageText', $this->calledMethods());
    }

    public function testToggleCancelsWhenAlreadySignedUp(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->verifiedPerson(555);
        // findFor (toggle occupancy check) -> an occupying row, so it's a cancel.
        $this->wpdb->nextRows[] = ['id' => 3, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];
        // cancel() re-reads findFor, then the task's start time.
        $this->wpdb->nextRows[] = ['id' => 3, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];
        $this->wpdb->nextRows[] = ['starts_at' => '2026-09-01 20:00:00', 'task_date' => '2026-09-01'];

        $this->board()->onJoinLeave($this->callbackQuery('t:5'));

        self::assertSame([], $this->wpdb->deletes);
        self::assertNotSame([], $this->wpdb->updates);
        self::assertContains('editMessageText', $this->calledMethods());
    }

    public function testToggleReactivatesAFreedRow(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->verifiedPerson(555);
        // Toggle occupancy check -> a cancelled (non-occupying) row, so it's a
        // join; join() then reactivates it.
        $this->wpdb->nextRows[] = ['id' => 3, 'task_id' => 5, 'person_id' => 7, 'status' => 'cancelled'];
        $this->wpdb->nextVars[] = 0; // hasOverlapping
        $this->wpdb->nextVars[] = 2; // taskCapacity
        // join(): findFor -> the same cancelled row, then the guarded UPDATE.
        $this->wpdb->nextRows[] = ['id' => 3, 'task_id' => 5, 'person_id' => 7, 'status' => 'cancelled'];
        $this->wpdb->nextQueryResults[] = 1;

        $this->board()->onJoinLeave($this->callbackQuery('t:5'));

        // Reactivated via a guarded UPDATE statement, never a fresh INSERT.
        self::assertSame([], $this->wpdb->inserts);
        self::assertStringContainsString('UPDATE', implode("\n", $this->wpdb->queries));
        self::assertContains('editMessageText', $this->calledMethods());
    }

    public function testIgnoresMalformedCallbackData(): void
    {
        $this->board()->onJoinLeave($this->callbackQuery('nonsense'));

        self::assertSame([], $this->wpdb->inserts);
        // Still answers, so the tapping client's spinner stops.
        self::assertSame(['answerCallbackQuery'], $this->calledMethods());
    }

    // --- refresh / setBoardChat ---------------------------------------------

    public function testRefreshPostsAndStoresTheMessageIdWhenNoBoardExists(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 0];
        $this->telegramResults['sendMessage'] = ['message_id' => 77];
        $this->wpdb->nextResults[] = []; // no tasks

        $this->board()->refresh();

        self::assertContains('sendMessage', $this->calledMethods());
        self::assertSame(77, $this->options[BoardService::BOARD_OPTION]['message_id']);
    }

    public function testRefreshIsInertWithoutABoardChat(): void
    {
        $this->board()->refresh();

        self::assertSame([], $this->telegramCalls);
    }

    public function testRefreshIsInertWithoutAToken(): void
    {
        $this->options[TelegramClient::TOKEN_OPTION] = '';
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];

        $this->board()->refresh();

        self::assertSame([], $this->telegramCalls);
    }

    public function testSetBoardChatStoresTheChatAndClearsAnyMessageId(): void
    {
        $this->board()->setBoardChat(321);

        self::assertSame(
            ['chat_id' => 321, 'message_id' => 0],
            $this->options[BoardService::BOARD_OPTION]
        );
    }

    // --- onBotMembershipChange ----------------------------------------------

    public function testBotAddedToAGroupCapturesItAndPostsTheBoard(): void
    {
        $this->wpdb->nextResults[] = []; // refresh render: no tasks

        $this->board()->onBotMembershipChange([
            'chat' => ['id' => -1002000, 'type' => 'supergroup'],
            'new_chat_member' => ['status' => 'member'],
        ]);

        self::assertSame(-1002000, $this->options[BoardService::BOARD_OPTION]['chat_id']);
        self::assertContains('sendMessage', $this->calledMethods());
    }

    public function testBotRemovedFromAGroupDoesNotCaptureIt(): void
    {
        $this->board()->onBotMembershipChange([
            'chat' => ['id' => -1002000, 'type' => 'supergroup'],
            'new_chat_member' => ['status' => 'left'],
        ]);

        self::assertArrayNotHasKey(BoardService::BOARD_OPTION, $this->options);
        self::assertSame([], $this->telegramCalls);
    }

    public function testBotAddedToAPrivateChatIsIgnored(): void
    {
        $this->board()->onBotMembershipChange([
            'chat' => ['id' => 555, 'type' => 'private'],
            'new_chat_member' => ['status' => 'member'],
        ]);

        self::assertArrayNotHasKey(BoardService::BOARD_OPTION, $this->options);
    }
}
