<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\SiteLanguages;
use EventCrew\Telegram\BoardService;
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
        $old = ['/ticket', '/tickets', '/replace', '/gift', '/roles', '/tasks', '/cancel', '/language'];

        foreach ($old as $command) {
            $this->router()->dispatch([
                'message' => ['text' => $command, 'chat' => ['id' => 555, 'type' => 'private'], 'from' => ['id' => 555]],
            ]);
        }

        $answers = array_filter($this->telegramCalls, static fn (array $c): bool => 'sendMessage' === $c['method']);
        self::assertCount(count($old), $answers);

        foreach ($answers as $call) {
            self::assertStringStartsWith('EventCrew bot – commands', $call['body']['text']);
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
        self::assertContains('Kirjoitan sinulle suomeksi. Voit vaihtaa kielen napilla 🌐 Kieli komennon /me alla.', $texts);
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

        $buttons = array_merge(...$this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard']);
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

        $buttons = array_merge(...$this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard']);
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
