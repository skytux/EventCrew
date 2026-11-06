<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\GroupChat;

/**
 * The crew's group kept in its own option (GroupChat), so that deleting or
 * rewriting the board option no longer makes the site forget its group.
 */
final class GroupChatTest extends TelegramTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_the_title')->justReturn('');
    }

    private function board(): BoardService
    {
        return new BoardService(
            new TaskRepository(),
            new AssignmentRepository(),
            new PersonRepository(),
            $this->client(),
            new Logger(),
            new ClaimNotifier(
                new TaskRepository(),
                new AssignmentRepository(),
                new Mailer(new Logger(), new EmailTemplate(new Logger())),
                $this->client(),
                $this->standing()
            ),
            $this->signup()
        );
    }

    public function testAddingTheBotToAGroupStoresTheGroupInItsOwnOption(): void
    {
        $this->wpdb->nextResults[] = []; // no tasks

        $this->board()->onBotMembershipChange([
            'chat' => ['id' => -300, 'type' => 'supergroup'],
            'new_chat_member' => ['status' => 'member'],
        ]);

        self::assertSame(-300, $this->options[GroupChat::OPTION]);
    }

    public function testBoardInAGroupStoresTheGroup(): void
    {
        $this->wpdb->nextResults[] = []; // no tasks

        $this->board()->repostInto(-400);

        self::assertSame(-400, $this->options[GroupChat::OPTION]);
    }

    public function testWithTheBoardOptionGoneTheBoardIsPostedInTheStoredGroup(): void
    {
        // The board option was deleted; the group option was not.
        $this->options[GroupChat::OPTION] = '-500';
        $this->telegramResults['sendMessage'] = ['message_id' => 9];
        $this->wpdb->nextResults[] = []; // no tasks

        $board = $this->board();

        self::assertTrue($board->hasHome());
        self::assertTrue($board->refresh());
        self::assertSame(-500, $this->telegramCalls[0]['body']['chat_id']);
        self::assertSame(['chat_id' => -500, 'message_id' => 9], $this->options[BoardService::BOARD_OPTION]);
    }

    public function testTheLockStillHoldsWhenOnlyTheGroupOptionIsLeft(): void
    {
        $this->options[GroupChat::OPTION] = -500;

        $this->board()->onBotMembershipChange([
            'chat' => ['id' => -600, 'type' => 'supergroup'],
            'new_chat_member' => ['status' => 'member'],
        ]);

        self::assertSame(-500, $this->options[GroupChat::OPTION]);
        self::assertArrayNotHasKey(BoardService::BOARD_OPTION, $this->options);
        self::assertSame([['method' => 'leaveChat', 'body' => ['chat_id' => -600]]], $this->telegramCalls);
    }

    public function testTheMigrationCopiesTheBoardsChat(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => -700, 'message_id' => 3];

        GroupChat::migrate();

        self::assertSame(-700, $this->options[GroupChat::OPTION]);
    }

    public function testTheMigrationLeavesAStoredGroupAlone(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => -700, 'message_id' => 3];
        $this->options[GroupChat::OPTION] = -800;

        GroupChat::migrate();

        self::assertSame(-800, $this->options[GroupChat::OPTION]);
    }

    public function testTheMigrationDoesNothingWithoutABoardChat(): void
    {
        GroupChat::migrate();

        self::assertArrayNotHasKey(GroupChat::OPTION, $this->options);
    }

    public function testAnUpgradeToASupergroupMovesTheGroupAndTheBoard(): void
    {
        $this->options[GroupChat::OPTION] = -900;
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => -900, 'message_id' => 12];

        self::assertTrue(GroupChat::migrated(-900, -1009000));

        self::assertSame(-1009000, $this->options[GroupChat::OPTION]);
        self::assertSame(['chat_id' => -1009000, 'message_id' => 0], $this->options[BoardService::BOARD_OPTION]);
    }

    public function testAnotherGroupsUpgradeChangesNothing(): void
    {
        $this->options[GroupChat::OPTION] = -900;

        self::assertFalse(GroupChat::migrated(-111, -1001111));

        self::assertSame(-900, $this->options[GroupChat::OPTION]);
    }

    public function testTheMigrateServiceMessagesMoveTheGroup(): void
    {
        $this->options[GroupChat::OPTION] = -900;

        // In the old group: "moved to". Then in the new supergroup: "moved from".
        $this->updateRouter()->dispatch([
            'message' => ['chat' => ['id' => -900, 'type' => 'group'], 'migrate_to_chat_id' => -1009000],
        ]);

        self::assertSame(-1009000, $this->options[GroupChat::OPTION]);

        $this->options[GroupChat::OPTION] = -900;

        $this->updateRouter()->dispatch([
            'message' => ['chat' => ['id' => -1009000, 'type' => 'supergroup'], 'migrate_from_chat_id' => -900],
        ]);

        self::assertSame(-1009000, $this->options[GroupChat::OPTION]);
    }

    public function testAnApiErrorWithANewChatIdMovesTheGroupAndSendsAgain(): void
    {
        $this->options[GroupChat::OPTION] = -900;
        $sent = [];

        Functions\when('wp_remote_post')->alias(function (string $url, array $args) use (&$sent): string {
            $body = (array) json_decode((string) $args['body'], true);
            $sent[] = $body['chat_id'];

            if (-900 === $body['chat_id']) {
                return (string) json_encode([
                    'ok' => false,
                    'error_code' => 400,
                    'description' => 'Bad Request: group chat was upgraded to a supergroup chat',
                    'parameters' => ['migrate_to_chat_id' => -1009000],
                ]);
            }

            return (string) json_encode(['ok' => true, 'result' => ['message_id' => 4]]);
        });

        $result = $this->client()->sendMessage(-900, 'Hello');

        self::assertSame(['message_id' => 4], $result);
        self::assertSame([-900, -1009000], $sent);
        self::assertSame(-1009000, $this->options[GroupChat::OPTION]);
    }

    public function testAnEditInAMovedChatIsNotSentAgain(): void
    {
        $sent = 0;

        Functions\when('wp_remote_post')->alias(function () use (&$sent): string {
            $sent++;

            return (string) json_encode([
                'ok' => false,
                'description' => 'Bad Request: group chat was upgraded to a supergroup chat',
                'parameters' => ['migrate_to_chat_id' => -1009000],
            ]);
        });

        self::assertNull($this->client()->editMessageText(-900, 5, 'Hello'));
        self::assertSame(1, $sent);
    }
}
