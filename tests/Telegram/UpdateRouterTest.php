<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\Logger;
use EventCrew\Support\RosterAssembler;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\OnboardingService;
use EventCrew\Telegram\RosterService;
use EventCrew\Telegram\UpdateRouter;

/**
 * The router is wired to the real onboarding and board services and asserted
 * through their side effects, because "which collaborator ran" is exactly the
 * routing decision under test and a stubbed-out effect would prove nothing.
 */
final class UpdateRouterTest extends TelegramTestCase
{
    /** @var array<string, mixed> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->transients = [];

        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->transients[$key] = $value;

            return true;
        });
        Functions\when('get_transient')->alias(fn (string $key): mixed => $this->transients[$key] ?? false);
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);

            return true;
        });
        Functions\when('get_the_title')->justReturn('');
        Functions\when('sanitize_email')->alias(static fn (string $email): string => trim($email));
        Functions\when('is_email')->alias(
            static fn (string $email): bool => 1 === preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)
        );
        Functions\when('rest_url')->alias(static fn (string $path = ''): string => 'https://example.test/wp-json/' . $path);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_mail')->justReturn(true);
    }

    private function router(): UpdateRouter
    {
        return new UpdateRouter(
            new OnboardingService(new PersonRepository(), new AuthTokenRepository(), $this->client(), new Logger()),
            new BoardService(
                new TaskRepository(),
                new AssignmentRepository(),
                new PersonRepository(),
                $this->client(),
                new Logger()
            ),
            new RosterService(
                new RosterAssembler(new TaskRepository(), new AssignmentRepository(), new PersonRepository()),
                new TaskRepository(),
                new PersonRepository(),
                $this->client()
            )
        );
    }

    public function testCallbackQueryGoesToJoinLeave(): void
    {
        $this->router()->dispatch([
            'callback_query' => ['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'j:5'],
        ]);

        // No verified person -> a refusal alert, which is proof the board's
        // join handler ran.
        self::assertContains('answerCallbackQuery', $this->calledMethods());
    }

    public function testPrivateStartBeginsOnboarding(): void
    {
        $this->router()->dispatch([
            'message' => [
                'text' => '/start',
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 555, 'first_name' => 'Sam'],
            ],
        ]);

        self::assertArrayHasKey('eventcrew_tg_await_email_555', $this->transients);
    }

    public function testGroupBoardCommandSetsTheBoardChat(): void
    {
        $this->wpdb->nextResults[] = []; // refresh render: no tasks

        $this->router()->dispatch([
            'message' => [
                'text' => '/board@eventcrew_bot',
                'chat' => ['id' => 999, 'type' => 'supergroup'],
                'from' => ['id' => 1],
            ],
        ]);

        self::assertSame(999, $this->options[BoardService::BOARD_OPTION]['chat_id']);
    }

    public function testRosterCommandReachesRosterService(): void
    {
        // Unlinked sender -> the roster service refuses, which is proof it ran.
        $this->router()->dispatch([
            'message' => [
                'text' => '/roster',
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 555],
            ],
        ]);

        self::assertSame('Only organizers can see the roster.', $this->lastCallTo('sendMessage')['text']);
    }

    public function testPrivateEmailWhileAwaitingIsCaptured(): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = ['chat_id' => 555, 'name' => 'Sam'];

        $this->router()->dispatch([
            'message' => [
                'text' => 'sam@example.com',
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 555],
            ],
        ]);

        // Person + token inserted: the email was captured.
        self::assertCount(2, $this->wpdb->inserts);
    }

    public function testPrivateChatterWhenNotAwaitingIsIgnored(): void
    {
        $this->router()->dispatch([
            'message' => [
                'text' => 'hello there',
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 555],
            ],
        ]);

        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->telegramCalls);
    }

    public function testBotAddedToAGroupCapturesTheBoardChat(): void
    {
        $this->wpdb->nextResults[] = []; // refresh render: no tasks

        $this->router()->dispatch([
            'my_chat_member' => [
                'chat' => ['id' => -1002000, 'type' => 'supergroup'],
                'new_chat_member' => ['status' => 'member'],
            ],
        ]);

        self::assertSame(-1002000, $this->options[BoardService::BOARD_OPTION]['chat_id']);
    }

    public function testUnrecognisedUpdateDoesNothing(): void
    {
        $this->router()->dispatch(['edited_message' => ['text' => 'whatever']]);

        self::assertSame([], $this->telegramCalls);
        self::assertSame([], $this->wpdb->inserts);
    }
}
