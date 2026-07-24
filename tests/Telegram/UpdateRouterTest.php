<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\RosterAssembler;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\OnboardingService;
use EventCrew\Telegram\ProfileService;
use EventCrew\Telegram\ReplacementService;
use EventCrew\Telegram\RosterService;
use EventCrew\Telegram\TicketRedemptionService;
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
        Functions\when('rest_url')->alias(
            static fn (string $path = ''): string => 'https://example.test/wp-json/' . $path
        );
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
                new Logger(),
                new ClaimNotifier(new TaskRepository(), new AssignmentRepository(), new Mailer(new Logger()), $this->client(), $this->standing()),
                $this->signup()
            ),
            new RosterService(
                new RosterAssembler(new TaskRepository(), new AssignmentRepository(), new PersonRepository(), $this->standing()),
                new TaskRepository(),
                new PersonRepository(),
                new AssignmentRepository(),
                $this->client()
            ),
            new ReplacementService(
                new AssignmentRepository(),
                new TaskRepository(),
                new PersonRepository(),
                new BoardService(
                    new TaskRepository(),
                    new AssignmentRepository(),
                    new PersonRepository(),
                    $this->client(),
                    new Logger(),
                    new ClaimNotifier(new TaskRepository(), new AssignmentRepository(), new Mailer(new Logger()), $this->client(), $this->standing()),
                    $this->signup()
                ),
                $this->client()
            ),
            new ProfileService(
                new PersonRepository(),
                new AssignmentRepository(),
                new TaskRepository(),
                $this->standing(),
                $this->client()
            ),
            new TicketRedemptionService(
                new PersonRepository(),
                new TaskRepository(),
                new RedemptionRepository(),
                $this->standing(),
                new FreeEntryGate(),
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

    public function testPrivateStartMePayloadGoesToTheProfileNotOnboarding(): void
    {
        // The "See my info" deep link lands as "/start me". It must not start
        // onboarding (no await-email transient); it answers with the summary,
        // here the set-up-first nudge since no person is linked.
        $this->router()->dispatch([
            'message' => [
                'text' => '/start me',
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 555, 'first_name' => 'Sam'],
            ],
        ]);

        self::assertArrayNotHasKey('eventcrew_tg_await_email_555', $this->transients);
        self::assertContains('sendMessage', $this->calledMethods());
    }

    public function testGroupBoardCommandSetsTheBoardChat(): void
    {
        $this->wpdb->nextResults[] = []; // repost render: no tasks

        $this->router()->dispatch([
            'message' => [
                'text' => '/board@eventcrew_bot',
                'chat' => ['id' => 999, 'type' => 'supergroup'],
                'from' => ['id' => 1],
            ],
        ]);

        self::assertSame(999, $this->options[BoardService::BOARD_OPTION]['chat_id']);
    }

    public function testGroupBoardCommandDeletesThePreviousBoard(): void
    {
        // A board already lives in this chat; re-running /board should post a
        // fresh one and remove the old, not leave both stacked.
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 999, 'message_id' => 7];
        $this->wpdb->nextResults[] = []; // repost render: no tasks

        $this->router()->dispatch([
            'message' => [
                'text' => '/board@eventcrew_bot',
                'chat' => ['id' => 999, 'type' => 'supergroup'],
                'from' => ['id' => 1],
            ],
        ]);

        self::assertContains('sendMessage', $this->calledMethods());
        self::assertSame(7, $this->lastCallTo('deleteMessage')['message_id']);
        self::assertSame(999, $this->lastCallTo('deleteMessage')['chat_id']);
    }

    public function testPrivateTicketCommandReachesTheTicketService(): void
    {
        // Unlinked sender -> the ticket service answers with the set-up nudge,
        // which is proof the /ticket command routed to it.
        $this->router()->dispatch([
            'message' => [
                'text' => '/ticket',
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 555],
            ],
        ]);

        self::assertStringContainsString('Set yourself up first', $this->lastCallTo('sendMessage')['text']);
    }

    public function testTicketCallbackReachesTheTicketService(): void
    {
        // Unlinked sender -> onSelect answers the callback with a refusal, proof
        // the tkt: prefix routed to the ticket service.
        $this->router()->dispatch([
            'callback_query' => ['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'tkt:2026-08-01'],
        ]);

        self::assertContains('answerCallbackQuery', $this->calledMethods());
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

        self::assertSame(
            'Only organizers or people rostered on the day can see the roster.',
            $this->lastCallTo('sendMessage')['text']
        );
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

    public function testDropsAnAlreadyProcessedUpdateId(): void
    {
        $update = [
            'update_id' => 100,
            'message' => ['text' => '/start', 'chat' => ['id' => 555, 'type' => 'private'], 'from' => ['id' => 555]],
        ];

        $this->router()->dispatch($update);
        self::assertArrayHasKey('eventcrew_tg_await_email_555', $this->transients);

        // Clear the side effect, then redeliver the same update_id.
        unset($this->transients['eventcrew_tg_await_email_555']);
        $this->router()->dispatch($update);

        self::assertArrayNotHasKey('eventcrew_tg_await_email_555', $this->transients);
    }

    public function testUnrecognisedUpdateDoesNothing(): void
    {
        $this->router()->dispatch(['edited_message' => ['text' => 'whatever']]);

        self::assertSame([], $this->telegramCalls);
        self::assertSame([], $this->wpdb->inserts);
    }
}
