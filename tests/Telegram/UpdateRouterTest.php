<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\SiteLanguages;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\ClosedAccounts;
use EventCrew\Telegram\ConversationLog;
use EventCrew\Telegram\LanguageService;
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
        return $this->updateRouter();
    }

    /** A blocked person with Telegram id 555, as a people-table row. */
    private function blockedRow(): array
    {
        return [
            'id' => 7,
            'email' => 'blocked@example.test',
            'display_name' => 'Blocked',
            'email_verified_at' => '2026-01-01 00:00:00',
            'telegram_user_id' => 555,
            'telegram_chat_id' => 555,
            'banned_at' => '2026-08-01 00:00:00',
        ];
    }

    public function testWritingToTheBotRemovesTheClosedAccountMark(): void
    {
        ClosedAccounts::mark(555);
        ClosedAccounts::mark(777);
        $this->wpdb->nextRows[] = $this->blockedRow();

        $this->updateRouter(null, null, null, null, null, true)->dispatch([
            'callback_query' => ['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'j:5'],
        ]);

        self::assertFalse(ClosedAccounts::isClosed(555));
        self::assertTrue(ClosedAccounts::isClosed(777));
    }

    public function testABlockedPersonsButtonTapGetsOnlyTheBlockedPopup(): void
    {
        $this->wpdb->nextRows[] = $this->blockedRow();

        $this->updateRouter(null, null, null, null, null, true)->dispatch([
            'callback_query' => ['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'j:5'],
        ]);

        self::assertSame(['answerCallbackQuery'], $this->calledMethods());
        $popup = $this->lastCallTo('answerCallbackQuery');
        self::assertSame('Your account is blocked. Talk to an organizer.', $popup['text']);
        self::assertTrue($popup['show_alert']);
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testABlockedPersonsPrivateCommandGetsOnlyTheBlockedLine(): void
    {
        $this->wpdb->nextRows[] = $this->blockedRow();

        $this->updateRouter(null, null, null, null, null, true)->dispatch([
            'message' => [
                'text' => '/me',
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 555],
            ],
        ]);

        self::assertSame(['sendMessage'], $this->calledMethods());
        self::assertSame('Your account is blocked. Talk to an organizer.', $this->lastCallTo('sendMessage')['text']);
        self::assertSame(555, $this->lastCallTo('sendMessage')['chat_id']);
    }

    public function testABlockedPersonsGroupCommandIsIgnored(): void
    {
        $this->wpdb->nextRows[] = $this->blockedRow();

        $this->updateRouter(null, null, null, null, null, true)->dispatch([
            'message' => [
                'text' => '/board',
                'chat' => ['id' => -100123, 'type' => 'supergroup'],
                'from' => ['id' => 555],
            ],
        ]);

        self::assertSame([], $this->calledMethods());
        self::assertSame([], $this->wpdb->updates);
    }

    public function testAnEditedMessageIsNeverAnswered(): void
    {
        $this->updateRouter()->dispatch([
            'edited_message' => [
                'message_id' => 5,
                'text' => '/help',
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 555],
            ],
        ]);

        self::assertSame([], $this->calledMethods());
    }

    public function testAPersonWhoIsNotBlockedStillReachesTheBoard(): void
    {
        $row = $this->blockedRow();
        $row['banned_at'] = null;
        $this->wpdb->nextRows[] = $row;

        $this->updateRouter(null, null, null, null, null, true)->dispatch([
            'callback_query' => ['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'j:5'],
        ]);

        self::assertContains('answerCallbackQuery', $this->calledMethods());
        self::assertNotSame(
            'Your account is blocked. Talk to an organizer.',
            $this->lastCallTo('answerCallbackQuery')['text'] ?? ''
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

    public function testABoardDayButtonRedrawsTheBoard(): void
    {
        $this->router()->dispatch([
            'callback_query' => [
                'id' => 'cbq',
                'from' => ['id' => 555],
                'message' => ['message_id' => 5, 'chat' => ['id' => 100]],
                'data' => 'bd:2026-08-08',
            ],
        ]);

        self::assertSame(['answerCallbackQuery', 'editMessageText'], $this->calledMethods());
    }

    public function testARosterDayButtonGoesToTheRoster(): void
    {
        $this->router()->dispatch([
            'callback_query' => [
                'id' => 'cbq',
                'from' => ['id' => 555],
                'message' => ['message_id' => 42, 'chat' => ['id' => 555]],
                'data' => 'rd:2026-08-08',
            ],
        ]);

        // No linked person -> the roster's refusal, proof the roster handled it.
        self::assertStringContainsString('Only organizers', $this->lastCallTo('answerCallbackQuery')['text']);
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

    /** The board's 📝 Task info link (Owner 2026-11-07): the role list as a DM, no onboarding. */
    public function testTheTasksStartVerbSendsWhatEachTaskInvolves(): void
    {
        $this->router()->dispatch([
            'message' => [
                'text' => '/start tasks',
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 555, 'first_name' => 'Sam'],
            ],
        ]);

        self::assertArrayNotHasKey('eventcrew_tg_await_email_555', $this->transients);
        $dm = $this->lastCallTo('sendMessage');
        self::assertSame(555, $dm['chat_id']);
        self::assertMatchesRegularExpression('/What each task involves|no task descriptions/', (string) $dm['text']);
    }

    public function testAStartVerbRunsThatButton(): void
    {
        // An email's "Open in Telegram" link (t.me/<bot>?start=ticket) does
        // what the 🎟 button does: here the set-up nudge, as nobody is linked.
        $this->router()->dispatch([
            'message' => [
                'text' => '/start ticket',
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 555, 'first_name' => 'Sam'],
            ],
        ]);

        self::assertArrayNotHasKey('eventcrew_tg_await_email_555', $this->transients);
        self::assertStringContainsString('Set yourself up first', $this->lastCallTo('sendMessage')['text']);
    }

    public function testTheStartBoardVerbAndTheBoardButtonSendTheBoardToThatChat(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 999, 'message_id' => 7];

        foreach (['/start board', null] as $text) {
            $this->wpdb->nextResults[] = []; // render: no tasks
            $this->router()->dispatch(null === $text ? $this->tap('mn:board') : [
                'message' => ['text' => $text, 'chat' => ['id' => 555, 'type' => 'private'], 'from' => ['id' => 555]],
            ]);

            self::assertSame(555, $this->lastCallTo('sendMessage')['chat_id']);
            self::assertSame(['chat_id' => 999, 'message_id' => 7], $this->options[BoardService::BOARD_OPTION]);
        }

        self::assertArrayNotHasKey('eventcrew_tg_await_email_555', $this->transients);
    }

    public function testATaskStartPayloadShowsThatTaskAndSignsNobodyUp(): void
    {
        // The "A spot opened" email's Telegram link: t.me/<bot>?start=task-5.
        $this->router()->dispatch([
            'message' => [
                'text' => '/start task-5',
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 555, 'first_name' => 'Sam'],
            ],
        ]);

        self::assertArrayNotHasKey('eventcrew_tg_await_email_555', $this->transients);
        self::assertSame([], $this->wpdb->inserts);
        self::assertSame('That task is no longer available.', $this->lastCallTo('sendMessage')['text']);
    }

    public function testACancelStartLinkFromSomeoneUnknownGoesToTheSignUpFlow(): void
    {
        $this->router()->dispatch([
            'message' => [
                'text' => '/start cancel-5',
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 555, 'first_name' => 'Sam'],
            ],
        ]);

        self::assertSame([], $this->wpdb->updates);
        self::assertArrayHasKey('eventcrew_tg_await_email_555', $this->transients);
    }

    public function testATakeoverRequestStartPayloadOnlyShowsAndChangesNothing(): void
    {
        // The takeover request email's Telegram link: t.me/<bot>?start=tov-9.
        $this->router()->dispatch([
            'message' => [
                'text' => '/start tov-9',
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 555, 'first_name' => 'Sam'],
            ],
        ]);

        self::assertArrayNotHasKey('eventcrew_tg_await_email_555', $this->transients);
        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->wpdb->updates);
        self::assertSame('This link is no longer valid.', $this->lastCallTo('sendMessage')['text']);
    }

    public function testANightStartPayloadSpendsNothing(): void
    {
        // A free-entry email's Telegram link: t.me/<bot>?start=tkt-20261114.
        // Someone the bot does not know is told how to start; nothing is written.
        $this->router()->dispatch([
            'message' => [
                'text' => '/start tkt-20261114',
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 555, 'first_name' => 'Sam'],
            ],
        ]);

        self::assertArrayNotHasKey('eventcrew_tg_await_email_555', $this->transients);
        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->wpdb->updates);
        self::assertSame(['sendMessage'], $this->calledMethods());
    }

    public function testAPersonStartPayloadOnlyShowsAndOnlyToAnOrganizer(): void
    {
        // An organizer notice's Telegram link: t.me/<bot>?start=who-8. Someone
        // who is not an organizer sees no role buttons; nothing is written.
        $this->router()->dispatch([
            'message' => [
                'text' => '/start who-8',
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 555, 'first_name' => 'Sam'],
            ],
        ]);

        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->wpdb->updates);
        self::assertSame(['sendMessage'], $this->calledMethods());
        self::assertSame('Only organizers can change roles.', $this->lastCallTo('sendMessage')['text']);
    }

    public function testADoorListStartPayloadOnlyShowsAndOnlyToWhoMaySee(): void
    {
        // The ticket import email's Telegram link: t.me/<bot>?start=door-20260801.
        $this->router()->dispatch([
            'message' => [
                'text' => '/start door-20260801',
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 555, 'first_name' => 'Sam'],
            ],
        ]);

        self::assertArrayNotHasKey('eventcrew_tg_await_email_555', $this->transients);
        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->wpdb->updates);
        self::assertSame(['sendMessage'], $this->calledMethods());
        self::assertSame(
            'Only organizers and that night’s welcome crew can see who has a ticket.',
            $this->lastCallTo('sendMessage')['text']
        );
    }

    public function testABoardStartPayloadSendsTheBoardToThePrivateChat(): void
    {
        // A day tap in the group that could not reach the private chat:
        // t.me/<bot>?start=board-20260808.
        $this->wpdb->nextResults[] = []; // the board: no tasks

        $this->router()->dispatch([
            'message' => [
                'text' => '/start board-20260808',
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 555, 'first_name' => 'Sam'],
            ],
        ]);

        self::assertArrayNotHasKey('eventcrew_tg_await_email_555', $this->transients);
        self::assertSame(['sendMessage'], $this->calledMethods());
        self::assertSame(555, $this->lastCallTo('sendMessage')['chat_id']);
        self::assertSame('No open tasks right now. Check back soon!', $this->lastCallTo('sendMessage')['text']);
    }

    /**
     * "Not right? Tell us" (a no-show notice's button or its email link):
     * the bot asks what is wrong and keeps the question in the chat's log,
     * so the helper reads the answer as a reply and forwards it. With the
     * helper off it says to tell an organizer. It acts on nothing.
     */
    public function testTellUsAsksWhatIsWrongAndLogsTheQuestion(): void
    {
        $this->options[AssistantSettings::ENABLED_OPTION] = true;
        $this->options[AssistantSettings::URL_OPTION] = 'https://llm.test/v1';
        $this->options[AssistantSettings::MODEL_OPTION] = 'm';

        foreach (['/start tell', null] as $text) {
            $this->transients = [];
            $update = null === $text ? $this->tap('mn:tell') : [
                'message' => ['text' => $text, 'chat' => ['id' => 555, 'type' => 'private'], 'from' => ['id' => 555]],
            ];
            $this->updateRouter(null, null, null, null, null, true)->dispatch($update);

            self::assertSame(555, $this->lastCallTo('sendMessage')['chat_id']);
            self::assertStringContainsString('what is not right', $this->lastCallTo('sendMessage')['text']);
            $log = ConversationLog::entries(555);
            self::assertCount(1, $log);
            self::assertSame(ConversationLog::BOT, $log[0]['w']);
            self::assertArrayNotHasKey('eventcrew_tg_await_email_555', $this->transients);
        }

        self::assertSame([], $this->wpdb->updates);
        self::assertSame([], $this->wpdb->inserts);

        $this->options[AssistantSettings::FORWARD_OPTION] = false;
        $this->transients = [];
        $this->updateRouter(null, null, null, null, null, true)->dispatch($this->tap('mn:tell'));

        self::assertSame(
            'Please tell an organizer what is not right: they can correct it.',
            $this->lastCallTo('sendMessage')['text']
        );
        self::assertSame([], ConversationLog::entries(555));
    }

    public function testAnUnknownStartPayloadStillBeginsOnboarding(): void
    {
        $this->router()->dispatch([
            'message' => [
                'text' => '/start somethingelse',
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 555, 'first_name' => 'Sam'],
            ],
        ]);

        self::assertArrayHasKey('eventcrew_tg_await_email_555', $this->transients);
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

    public function testPrivateBoardCommandSendsTheBoardToThatChat(): void
    {
        // The group's board lives in 999; asking in a DM must not move it.
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 999, 'message_id' => 7];
        $this->wpdb->nextResults[] = []; // render: no tasks

        $this->router()->dispatch([
            'message' => [
                'text' => '/board',
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 555],
            ],
        ]);

        self::assertSame(555, $this->lastCallTo('sendMessage')['chat_id']);
        self::assertNotContains('deleteMessage', $this->calledMethods());
        self::assertSame(['chat_id' => 999, 'message_id' => 7], $this->options[BoardService::BOARD_OPTION]);
    }

    /**
     * A tap on a button under /me, /door or /people, in the private chat.
     *
     * @return array<string, mixed>
     */
    private function tap(string $data): array
    {
        return ['callback_query' => [
            'id' => 'cbq',
            'from' => ['id' => 555],
            'message' => ['chat' => ['id' => 555, 'type' => 'private']],
            'data' => $data,
        ]];
    }

    public function testTheTicketButtonReachesTheTicketService(): void
    {
        // Unlinked sender -> the ticket service answers with the set-up nudge,
        // which is proof the 🎟 button routed to it.
        $this->router()->dispatch($this->tap('mn:ticket'));

        // The tap itself is answered, so the button stops spinning.
        self::assertSame('cbq', $this->lastCallTo('answerCallbackQuery')['callback_query_id']);

        self::assertStringContainsString('Set yourself up first', $this->lastCallTo('sendMessage')['text']);
    }

    public function testTheGetTheAppButtonReachesTheProfileService(): void
    {
        // Unlinked sender -> the app invite answers with the set-up nudge.
        $this->router()->dispatch($this->tap('mn:app'));

        self::assertSame('cbq', $this->lastCallTo('answerCallbackQuery')['callback_query_id']);
        self::assertStringContainsString('Set yourself up first', $this->lastCallTo('sendMessage')['text']);
    }

    public function testTheWhoMayHoldButtonReachesThePermissionService(): void
    {
        $this->router()->dispatch($this->tap('mn:elig'));

        self::assertSame(
            'Only organizers can see who meets the role conditions.',
            $this->lastCallTo('sendMessage')['text']
        );
    }

    public function testTheRolesButtonReachesThePermissionService(): void
    {
        // Unlinked sender -> refused as not an organizer, proof the button routed.
        $this->router()->dispatch($this->tap('mn:roles'));

        self::assertSame('Only organizers can change roles.', $this->lastCallTo('sendMessage')['text']);
    }

    public function testPermCallbackReachesThePermissionService(): void
    {
        $this->router()->dispatch([
            'callback_query' => ['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'perm:pass:8'],
        ]);

        self::assertStringContainsString('Only organizers', $this->lastCallTo('answerCallbackQuery')['text']);
    }

    public function testARemovedCommandGetsTheHelpAnswer(): void
    {
        // /tickets and /tasks are commands again (Owner 2026-11-07).
        $old = ['/ticket', '/replace', '/gift', '/roles', '/cancel', '/language'];

        foreach ($old as $command) {
            $this->router()->dispatch([
                'message' => ['text' => $command, 'chat' => ['id' => 555, 'type' => 'private'], 'from' => ['id' => 555]],
            ]);
        }

        $answers = array_filter($this->telegramCalls, static fn (array $c): bool => 'sendMessage' === $c['method']);
        self::assertCount(count($old), $answers);

        foreach ($answers as $call) {
            self::assertStringStartsWith('Test Site bot – commands', $call['body']['text']);
        }
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

    public function testGiftCommandReachesTheGiftService(): void
    {
        // Unlinked sender -> the gift service refuses (not an organizer), which
        // is proof the 🎁 button routed to it.
        $this->router()->dispatch($this->tap('mn:gift'));

        self::assertStringContainsString('Only organizers', $this->lastCallTo('sendMessage')['text']);
    }

    public function testGiftCallbackReachesTheGiftService(): void
    {
        // Unlinked sender -> onSelect answers the callback with a refusal, proof
        // the gift: prefix routed to the gift service.
        $this->router()->dispatch([
            'callback_query' => ['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'gift:8'],
        ]);

        self::assertStringContainsString('Only organizers', $this->lastCallTo('answerCallbackQuery')['text']);
    }

    public function testGroupStartNudgesToThePrivateChat(): void
    {
        // /start in a group can't onboard (Telegram won't let the bot DM a
        // stranger), so it answers in the group rather than doing nothing.
        $this->options[BoardService::USERNAME_OPTION] = 'eventcrew_bot';

        $this->router()->dispatch([
            'message' => [
                'text' => '/start',
                'chat' => ['id' => -100, 'type' => 'supergroup'],
                'from' => ['id' => 555, 'first_name' => 'Sam'],
            ],
        ]);

        self::assertArrayNotHasKey('eventcrew_tg_await_email_555', $this->transients);
        self::assertSame(-100, $this->lastCallTo('sendMessage')['chat_id']);
    }

    public function testTheTakeOverButtonAsksWhoWithACancelButton(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 7, 'email' => 'cover@example.com', 'display_name' => 'Cover',
            'email_verified_at' => '2026-07-01 00:00:00', 'telegram_user_id' => 555,
        ];

        $this->router()->dispatch($this->tap('mn:replace'));

        $prompt = $this->lastCallTo('sendMessage');
        self::assertSame(555, $prompt['chat_id']);
        self::assertSame('mn:cancel', $prompt['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        self::assertArrayHasKey('eventcrew_tg_await_replace_target_555', $this->transients);

        $this->router()->dispatch($this->tap('mn:cancel'));

        self::assertArrayNotHasKey('eventcrew_tg_await_replace_target_555', $this->transients);
        self::assertSame('Stopped. Nothing is waiting for an answer.', $this->lastCallTo('sendMessage')['text']);
    }

    public function testTheNotificationsButtonReachesTheSettingsService(): void
    {
        $this->router()->dispatch($this->tap('mn:notif'));

        self::assertStringContainsString('Set yourself up first', $this->lastCallTo('sendMessage')['text']);
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

        // The person inserted: the email was captured. A newcomer is asked
        // the organizers' questions before the link goes out.
        self::assertCount(1, $this->wpdb->inserts);
        self::assertArrayHasKey('eventcrew_tg_await_join_555', $this->transients);
    }

    /** The newcomer's question buttons and a typed number reach the onboarding. */
    public function testTheNewcomersAnswersAreRouted(): void
    {
        $this->transients['eventcrew_tg_await_join_555'] = [
            'step' => 'times', 'person_id' => 1, 'email' => 'sam@example.com', 'name' => 'Sam', 'chat_id' => 555,
        ];

        $this->router()->dispatch([
            'message' => ['text' => 'lots', 'chat' => ['id' => 555, 'type' => 'private'], 'from' => ['id' => 555]],
        ]);
        self::assertSame('Send a number from 1 to 50, or tap Skip.', $this->lastCallTo('sendMessage')['text']);

        $this->router()->dispatch([
            'callback_query' => [
                'id' => 'cb1',
                'from' => ['id' => 555],
                'message' => ['message_id' => 40, 'chat' => ['id' => 555, 'type' => 'private']],
                'data' => 'nwq:s',
            ],
        ]);

        // Skip: the questions are done and the link is on its way.
        self::assertArrayNotHasKey('eventcrew_tg_await_join_555', $this->transients);
        self::assertCount(1, $this->wpdb->inserts, 'the confirmation token');
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

    /**
     * The duplicate-reply bug: on a slow host Telegram re-sends an update
     * before the first request has finished, and the second request reads the
     * old stored id. The claim must still refuse it.
     */
    public function testDropsARedeliveryEvenWhenTheStoredIdReadsStale(): void
    {
        $update = [
            'update_id' => 101,
            'message' => ['text' => '/start', 'chat' => ['id' => 556, 'type' => 'private'], 'from' => ['id' => 556]],
        ];

        $this->router()->dispatch($update);
        self::assertArrayHasKey('eventcrew_tg_await_email_556', $this->transients);

        unset($this->transients['eventcrew_tg_await_email_556']);
        $this->options[UpdateRouter::LAST_UPDATE_OPTION] = 0;
        $this->router()->dispatch($update);

        self::assertArrayNotHasKey('eventcrew_tg_await_email_556', $this->transients);
    }

    /**
     * Someone who has not chosen a language is asked "Suomi / English" before
     * their first private command runs, and the command is not dropped: it runs
     * as soon as they tap, in the language they tapped.
     */
    public function testAnUnchosenPrivateCommandAsksForTheLanguageFirstThenRuns(): void
    {
        $router = $this->languageRouter();

        $router->dispatch([
            'message' => ['text' => '/me', 'chat' => ['id' => 555, 'type' => 'private'], 'from' => ['id' => 555]],
        ]);

        // Only the question so far; the /me answer is held back.
        self::assertCount(1, $this->telegramCalls);
        $question = $this->lastCallTo('sendMessage');
        self::assertSame('Valitse kieli · Choose your language', $question['text']);
        self::assertSame(
            ['lang:fi', 'lang:en'],
            array_column($question['reply_markup']['inline_keyboard'][0], 'callback_data')
        );

        $router->dispatch([
            'callback_query' => [
                'id' => 'cbq',
                'from' => ['id' => 555],
                'message' => ['chat' => ['id' => 555]],
                'data' => 'lang:fi',
            ],
        ]);

        // No record yet, so the choice is remembered against the Telegram id.
        self::assertSame('fi', $this->transients['eventcrew_tg_language_555']);
        self::assertSame('Tallennettu.', $this->lastCallTo('answerCallbackQuery')['text']);

        $texts = $this->sentTexts();
        self::assertContains('Kirjoitan sinulle suomeksi. Vaihda kieli: /settings, 🌐 Vaihda kieli.', $texts);
        // The held /me ran after the choice, in Finnish.
        self::assertSame(
            'Aloita ensin: lähetä botille /start.',
            end($texts)
        );
    }

    public function testSomeoneWhoHasChosenIsNotAskedAgain(): void
    {
        $this->transients['eventcrew_tg_language_555'] = 'en';

        $this->languageRouter()->dispatch([
            'message' => ['text' => '/me', 'chat' => ['id' => 555, 'type' => 'private'], 'from' => ['id' => 555]],
        ]);

        self::assertNotContains('Valitse kieli · Choose your language', $this->sentTexts());
        self::assertStringContainsString('Set yourself up first', $this->lastCallTo('sendMessage')['text']);
    }

    public function testAGroupCommandIsNotHeldBackForTheQuestion(): void
    {
        $this->languageRouter()->dispatch([
            'message' => ['text' => '/me', 'chat' => ['id' => -100, 'type' => 'supergroup'], 'from' => ['id' => 555]],
        ]);

        self::assertNotContains('Valitse kieli · Choose your language', $this->sentTexts());
        self::assertStringContainsString('Set yourself up first', $this->lastCallTo('sendMessage')['text']);
    }

    public function testTheLanguageButtonAsksAgain(): void
    {
        $this->transients['eventcrew_tg_language_555'] = 'fi';

        $this->languageRouter()->dispatch($this->tap('mn:lang'));

        $question = $this->lastCallTo('sendMessage');
        self::assertSame('Valitse kieli · Choose your language', $question['text']);
        self::assertSame(555, $question['chat_id']);
    }

    /**
     * Q298: the language question offers every site language, main language
     * first, each in its own name, and a tap on one that is not Finnish or
     * English is stored like those two.
     */
    public function testTheLanguageQuestionOffersEverySiteLanguage(): void
    {
        $this->options[SiteLanguages::OPTION] = ['fi', 'en', 'sv'];

        $router = $this->languageRouter();
        $router->dispatch($this->tap('mn:lang'));

        $rows = $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'];
        self::assertSame('mn:set', array_pop($rows)[0]['callback_data']);
        $buttons = array_merge(...$rows);
        self::assertSame(['Suomi', 'English', 'Svenska'], array_column($buttons, 'text'));
        self::assertSame(['lang:fi', 'lang:en', 'lang:sv'], array_column($buttons, 'callback_data'));

        $router->dispatch([
            'callback_query' => [
                'id' => 'cbq',
                'from' => ['id' => 555],
                'message' => ['chat' => ['id' => 555]],
                'data' => 'lang:sv',
            ],
        ]);

        self::assertSame('sv', $this->transients['eventcrew_tg_language_555']);
    }

    /** The main language's button comes first, whichever language that is. */
    public function testTheLanguageQuestionPutsTheMainLanguageFirst(): void
    {
        $this->options[SiteLanguages::OPTION] = ['es', 'fi', 'en'];

        $this->languageRouter()->dispatch($this->tap('mn:lang'));

        $rows = $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'];
        array_pop($rows);
        $buttons = array_merge(...$rows);
        self::assertSame(['Español', 'Suomi', 'English'], array_column($buttons, 'text'));
    }

    private function languageRouter(): UpdateRouter
    {
        return $this->updateRouter(new LanguageService(new PersonRepository(), $this->client()));
    }

    /**
     * @return list<string>
     */
    private function sentTexts(): array
    {
        $texts = [];

        foreach ($this->telegramCalls as $call) {
            if ('sendMessage' === $call['method']) {
                $texts[] = (string) $call['body']['text'];
            }
        }

        return $texts;
    }

    public function testUnrecognisedUpdateDoesNothing(): void
    {
        $this->router()->dispatch(['edited_message' => ['text' => 'whatever']]);

        self::assertSame([], $this->telegramCalls);
        self::assertSame([], $this->wpdb->inserts);
    }
}
