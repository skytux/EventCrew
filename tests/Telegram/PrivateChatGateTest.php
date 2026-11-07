<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\Logger;
use EventCrew\Support\MessageActions;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\GroupChat;
use EventCrew\Telegram\PrivateChatGate;
use EventCrew\Telegram\StartCode;

/**
 * The private chat gate (Owner, 2026-11-07, Q354-Q358): only linked members
 * and people in the crew group get answers in a private chat; a person with
 * a valid weekly code but not in the group gets only the group button.
 */
final class PrivateChatGateTest extends TelegramTestCase
{
    /** @var array<string, mixed> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->transients = [];
        $this->options[GroupChat::OPTION] = -100123;
        $this->options[BoardService::GROUP_LINK_OPTION] = 'https://t.me/crewgroup';
        $this->options[BoardService::USERNAME_OPTION] = 'crew_bot';

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
        Functions\when('wp_mail')->justReturn(true);
    }

    private function gate(): PrivateChatGate
    {
        return new PrivateChatGate($this->client(), new PersonRepository(), new Logger(), $this->boardService());
    }

    /** @return array<string, mixed> */
    private function privateMessage(string $text, int $from = 777): array
    {
        return ['message' => [
            'text' => $text,
            'chat' => ['id' => $from, 'type' => 'private'],
            'from' => ['id' => $from, 'first_name' => 'Stranger'],
        ]];
    }

    /** @return array<string, mixed> */
    private function memberRow(): array
    {
        return [
            'id' => 7,
            'email' => 'member@example.test',
            'display_name' => 'Member',
            'email_verified_at' => '2026-01-01 00:00:00',
            'telegram_user_id' => 777,
            'telegram_chat_id' => 777,
        ];
    }

    public function testALinkedMemberPassesWithoutAMembershipCheck(): void
    {
        $this->wpdb->nextRows[] = $this->memberRow();

        self::assertTrue($this->gate()->admits($this->privateMessage('/me')));
        self::assertNotContains('getChatMember', $this->calledMethods());
    }

    public function testSomeoneInTheGroupPassesAndStaysOpenFor24Hours(): void
    {
        $this->telegramResults['getChatMember'] = ['status' => 'member'];

        self::assertTrue($this->gate()->admits($this->privateMessage('/start')));
        self::assertSame(['getChatMember'], $this->calledMethods());
        self::assertSame(-100123, $this->telegramCalls[0]['body']['chat_id']);
        self::assertSame(777, $this->telegramCalls[0]['body']['user_id']);
        self::assertArrayHasKey(PrivateChatGate::PASS_PREFIX . 777, $this->transients);
        self::assertSame(DAY_IN_SECONDS, PrivateChatGate::PASS_TTL);

        // The next message within the window is not checked again.
        $this->telegramCalls = [];
        $this->telegramResults['getChatMember'] = ['status' => 'left'];

        self::assertTrue($this->gate()->admits($this->privateMessage('me@example.test')));
        self::assertSame([], $this->calledMethods());

        // Once the window is over, the check runs again.
        unset($this->transients[PrivateChatGate::PASS_PREFIX . 777]);

        self::assertFalse($this->gate()->admits($this->privateMessage('me@example.test')));
        self::assertSame(['getChatMember'], $this->calledMethods());
    }

    public function testAdministratorsCreatorsAndRestrictedMembersCountAsInTheGroup(): void
    {
        foreach (['creator', 'administrator', 'restricted'] as $i => $status) {
            $this->telegramResults['getChatMember'] = ['status' => $status, 'is_member' => true];

            self::assertTrue($this->gate()->admits($this->privateMessage('/start', 800 + $i)), $status);
        }

        $this->telegramResults['getChatMember'] = ['status' => 'restricted', 'is_member' => false];
        self::assertFalse($this->gate()->admits($this->privateMessage('/start', 900)));

        $this->telegramResults['getChatMember'] = ['status' => 'kicked'];
        self::assertFalse($this->gate()->admits($this->privateMessage('/start', 901)));
    }

    public function testAStrangerWithoutACodeGetsNoAnswerAtAll(): void
    {
        $this->telegramResults['getChatMember'] = ['status' => 'left'];
        $router = $this->updateRouter(null, null, null, null, null, false, null, $this->gate());

        $router->dispatch($this->privateMessage('/start'));
        $router->dispatch($this->privateMessage('Hello, can I sign up?'));
        $router->dispatch($this->privateMessage('/start onboard'));

        self::assertSame(['getChatMember', 'getChatMember', 'getChatMember'], $this->calledMethods());
        self::assertSame([], $this->wpdb->inserts);
        self::assertArrayNotHasKey(PrivateChatGate::PASS_PREFIX . 777, $this->transients);
    }

    public function testAStrangersButtonTapIsAnsweredWithNoText(): void
    {
        $this->telegramResults['getChatMember'] = ['status' => 'left'];
        $router = $this->updateRouter(null, null, null, null, null, false, null, $this->gate());

        $router->dispatch(['callback_query' => [
            'id' => 'cbq',
            'from' => ['id' => 777],
            'data' => 'menu:me',
            'message' => ['message_id' => 3, 'chat' => ['id' => 777, 'type' => 'private']],
        ]]);

        self::assertSame(['getChatMember', 'answerCallbackQuery'], $this->calledMethods());
        self::assertSame('', $this->lastCallTo('answerCallbackQuery')['text']);
        self::assertSame('cbq', $this->lastCallTo('answerCallbackQuery')['callback_query_id']);
    }

    public function testAStrangerWithAValidCodeGetsOnlyTheGroupButton(): void
    {
        $this->telegramResults['getChatMember'] = ['status' => 'left'];
        $router = $this->updateRouter(null, null, null, null, null, false, null, $this->gate());

        $router->dispatch($this->privateMessage('/start task-12_' . StartCode::current()));

        self::assertSame(['getChatMember', 'sendMessage'], $this->calledMethods());
        $sent = $this->lastCallTo('sendMessage');
        self::assertSame(777, $sent['chat_id']);
        self::assertSame(PrivateChatGate::joinGroupText(), $sent['text']);
        self::assertSame(
            [[['text' => PrivateChatGate::joinGroupButton(), 'url' => 'https://t.me/crewgroup']]],
            $sent['reply_markup']['inline_keyboard']
        );
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testAStrangerWithAnOldCodeGetsNoAnswer(): void
    {
        $this->telegramResults['getChatMember'] = ['status' => 'left'];
        $old = StartCode::current(time() - 15 * DAY_IN_SECONDS);

        $this->updateRouter(null, null, null, null, null, false, null, $this->gate())
            ->dispatch($this->privateMessage('/start onboard_' . $old));

        self::assertSame(['getChatMember'], $this->calledMethods());
    }

    public function testLastWeeksCodeIsValidAndOlderCodesAreNot(): void
    {
        $now = 1_794_000_000;

        self::assertTrue(StartCode::isValid(StartCode::current($now), $now));
        self::assertTrue(StartCode::isValid(StartCode::current($now - 7 * DAY_IN_SECONDS), $now));
        self::assertFalse(StartCode::isValid(StartCode::current($now - 14 * DAY_IN_SECONDS), $now));
        self::assertFalse(StartCode::isValid('zzzzzzzz', $now));
        self::assertNotSame(StartCode::current($now), StartCode::current($now - 7 * DAY_IN_SECONDS));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', StartCode::current($now));
    }

    public function testSplitTakesTheCodeOffAndLeavesOtherPayloadsAlone(): void
    {
        self::assertSame(['task-12', 'abcdef01'], StartCode::split('task-12_abcdef01'));
        self::assertSame(['board-20261114', 'abcdef01'], StartCode::split('board-20261114_abcdef01'));
        self::assertSame(['me', ''], StartCode::split('me'));
        self::assertSame(['login-abcdefghijklmnop_abcdef01', ''], StartCode::split('login-abcdefghijklmnop_abcdef01'));
    }

    public function testSomeoneInTheGroupWithACodeReachesTheVerbBehindIt(): void
    {
        $this->telegramResults['getChatMember'] = ['status' => 'member'];

        $this->updateRouter(null, null, null, null, null, false, null, $this->gate())
            ->dispatch($this->privateMessage('/start board-20261114_' . StartCode::current()));

        // board-<Ymd> opens that day of the board in the private chat, as it
        // does without the code.
        self::assertSame('getChatMember', $this->calledMethods()[0]);
        self::assertContains('sendMessage', $this->calledMethods());
        self::assertNotSame(PrivateChatGate::joinGroupText(), $this->lastCallTo('sendMessage')['text']);
    }

    public function testDeepLinksForEmailsAndTheWebCarryTheCode(): void
    {
        $code = StartCode::current();

        self::assertSame('https://t.me/crew_bot?start=task-12_' . $code, MessageActions::startUrl('task', 12));
        self::assertSame(
            'https://t.me/crew_bot?start=board-20261114_' . $code,
            MessageActions::payloadUrl('board-20261114')
        );

        $this->options[PrivateChatGate::ENABLED_OPTION] = '0';

        self::assertSame('https://t.me/crew_bot?start=task-12', MessageActions::startUrl('task', 12));
    }

    public function testWithTheSettingOffEveryoneIsHandledAsBefore(): void
    {
        $this->options[PrivateChatGate::ENABLED_OPTION] = '0';
        $this->telegramResults['getChatMember'] = ['status' => 'left'];

        self::assertTrue($this->gate()->admits($this->privateMessage('/start')));
        self::assertSame([], $this->calledMethods());
    }

    public function testWithNoKnownGroupTheGateIsOff(): void
    {
        unset($this->options[GroupChat::OPTION]);

        self::assertTrue($this->gate()->admits($this->privateMessage('/start')));
        self::assertSame([], $this->calledMethods());
        self::assertSame('https://t.me/crew_bot?start=task-12', MessageActions::startUrl('task', 12));
    }

    public function testGroupChatsAreNotAffected(): void
    {
        $this->telegramResults['getChatMember'] = ['status' => 'left'];

        self::assertTrue($this->gate()->admits(['message' => [
            'text' => '/board',
            'chat' => ['id' => -100123, 'type' => 'supergroup'],
            'from' => ['id' => 777],
        ]]));
        self::assertTrue($this->gate()->admits(['callback_query' => [
            'id' => 'cbq',
            'from' => ['id' => 777],
            'data' => 'j:5',
            'message' => ['message_id' => 3, 'chat' => ['id' => -100123, 'type' => 'supergroup']],
        ]]));
        self::assertSame([], $this->calledMethods());
    }

    public function testAFailedMembershipCheckCountsAsNotInTheGroupAndIsLoggedOnce(): void
    {
        $this->telegramErrors['getChatMember'] = 'Bad Request: chat not found';

        self::assertFalse($this->gate()->admits($this->privateMessage('/start', 801)));
        self::assertFalse($this->gate()->admits($this->privateMessage('/start', 802)));

        $logs = array_filter(
            (array) ($this->options['eventcrew_recent_logs'] ?? []),
            static fn (mixed $row): bool => str_contains((string) json_encode($row), 'membership check failed')
        );
        self::assertCount(1, $logs);
        self::assertNotContains('sendMessage', $this->calledMethods());
    }
}
