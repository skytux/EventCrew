<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssistantKnowledge;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\DoorAccess;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Telegram\AssistantService;
use EventCrew\Telegram\HelpService;

/**
 * Plain messages read by the language model. The model server is never
 * called: wp_remote_post answers /chat/completions from $llmReplies.
 */
final class AssistantServiceTest extends TelegramTestCase
{
    /** @var array<string, mixed> */
    private array $transients = [];

    /** @var array<int, array{url: string, args: array<string, mixed>}> */
    private array $llmCalls = [];

    /** @var array<int, string> Model answers (the "content"), used in turn. */
    private array $llmReplies = [];

    private int $llmStatus = 200;

    /** @var array<int, array<string, mixed>> Updates an action ran. */
    private array $ran = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->transients = [];
        $this->llmCalls = [];
        $this->llmReplies = [];
        $this->llmStatus = 200;
        $this->ran = [];

        $this->options[AssistantSettings::ENABLED_OPTION] = true;
        $this->options[AssistantSettings::URL_OPTION] = 'https://llm.example.test/v1';
        $this->options[AssistantSettings::MODEL_OPTION] = 'test-model';

        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->transients[$key] = $value;

            return true;
        });
        Functions\when('get_transient')->alias(fn (string $key): mixed => $this->transients[$key] ?? false);
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);

            return true;
        });
        Functions\when('post_type_exists')->justReturn(false);
        Functions\when('get_posts')->justReturn([]);
        Functions\when('get_the_title')->justReturn('');
        Functions\when('add_action')->justReturn(true);
        Functions\when('rest_url')->alias(
            static fn (string $path = ''): string => 'https://example.test/wp-json/' . $path
        );
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_remote_retrieve_response_code')->alias(
            fn (mixed $response): int => is_string($response) && str_starts_with($response, '{"choices"')
                ? $this->llmStatus
                : 200
        );

        // The Telegram half as in TelegramTestCase; the model half from
        // $llmReplies. Nothing leaves the test.
        Functions\when('wp_remote_post')->alias(
            function (string $url, array $args): string {
                if (str_ends_with($url, '/chat/completions')) {
                    $this->llmCalls[] = ['url' => $url, 'args' => $args];

                    return (string) json_encode([
                        'choices' => [['message' => ['content' => array_shift($this->llmReplies) ?? '']]],
                    ]);
                }

                $method = substr($url, (int) strrpos($url, '/') + 1);
                $this->telegramCalls[] = [
                    'method' => $method,
                    'body' => (array) json_decode((string) $args['body'], true),
                ];

                return (string) json_encode(['ok' => true, 'result' => $this->telegramResults[$method] ?? true]);
            }
        );
    }

    private function help(): HelpService
    {
        return new HelpService(
            new PersonRepository(),
            new DoorAccess(new TaskRepository(), new AssignmentRepository()),
            $this->client()
        );
    }

    private function service(): AssistantService
    {
        return new AssistantService(
            $this->client(),
            new LlmClient(new Logger()),
            new AssistantKnowledge(new TaskRepository()),
            $this->help(),
            new Logger(),
            false
        );
    }

    private function person(bool $organizer = false): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'is_organizer' => $organizer ? 1 : 0,
            'telegram_user_id' => 555,
        ];
    }

    /**
     * @param array<string, mixed>|null $action
     */
    private function reply(bool $aimed, bool $known, string $reply, ?string $action = null, string $lang = 'en'): void
    {
        $this->llmReplies[] = (string) json_encode(
            ['aimed' => $aimed, 'known' => $known, 'lang' => $lang, 'reply' => $reply, 'action' => $action]
        );
    }

    /** @return array<string, mixed> */
    private static function privateMessage(string $text): array
    {
        return [
            'message_id' => 40,
            'text' => $text,
            'chat' => ['id' => 555, 'type' => 'private'],
            'from' => ['id' => 555, 'first_name' => 'Sam'],
        ];
    }

    /** @return array<string, mixed> */
    private static function groupMessage(string $text): array
    {
        return [
            'message_id' => 41,
            'text' => $text,
            'chat' => ['id' => -100123, 'type' => 'supergroup'],
            'from' => ['id' => 555, 'first_name' => 'Sam'],
        ];
    }

    /** @param array<string, mixed> $message */
    private function ask(array $message): bool
    {
        return $this->service()->onMessage($message, null, function (array $update): void {
            $this->ran[] = $update;
        });
    }

    /** @return array<int, string> */
    private function sentTexts(): array
    {
        return array_values(array_map(
            static fn (array $call): string => (string) ($call['body']['text'] ?? ''),
            array_filter($this->telegramCalls, static fn (array $call): bool => 'sendMessage' === $call['method'])
        ));
    }

    /** @return array<string, mixed>|null */
    private function lastSentMarkup(): ?array
    {
        $call = $this->lastCallTo('sendMessage');

        return is_array($call['reply_markup'] ?? null) ? $call['reply_markup'] : null;
    }

    // ---- what reaches the model ----------------------------------------

    public function testTheRequestShapeAndNoKeyMeansNoAuthorizationHeader(): void
    {
        $this->reply(true, true, 'Hello!');

        $this->ask(self::privateMessage('hi there'));

        self::assertCount(1, $this->llmCalls);
        self::assertSame('https://llm.example.test/v1/chat/completions', $this->llmCalls[0]['url']);
        self::assertLessThan(60, $this->llmCalls[0]['args']['timeout']);
        self::assertArrayNotHasKey('Authorization', $this->llmCalls[0]['args']['headers']);

        $body = json_decode((string) $this->llmCalls[0]['args']['body'], true);
        self::assertSame('test-model', $body['model']);
        self::assertFalse($body['chat_template_kwargs']['enable_thinking']);
        self::assertLessThanOrEqual(0.3, $body['temperature']);
        self::assertSame('hi there', $body['messages'][1]['content']);
        self::assertSame(['Hello!'], $this->sentTexts());
    }

    public function testTheModelIsToldToAskWhichOneInsteadOfPicking(): void
    {
        $this->reply(true, true, 'Which one?');

        $this->ask(self::privateMessage('sign me up for a task'));

        $body = json_decode((string) $this->llmCalls[0]['args']['body'], true);
        self::assertStringContainsString('"action" is null and "reply" asks which one', $body['messages'][0]['content']);
        self::assertStringContainsString('Never pick one for the person.', $body['messages'][0]['content']);
    }

    public function testTheModelIsToldAGreetingSaysPlainWordsWork(): void
    {
        $this->reply(true, true, 'Hello!');

        $this->ask(self::privateMessage('hi'));

        $body = json_decode((string) $this->llmCalls[0]['args']['body'], true);
        self::assertStringContainsString('can simply write what they want in their own words', $body['messages'][0]['content']);
        self::assertStringContainsString('Do not answer a greeting with only a list of commands.', $body['messages'][0]['content']);
    }

    // ---- the conversation (24 hours, private only) ----------------------

    private function systemPrompt(int $call): string
    {
        $body = json_decode((string) $this->llmCalls[$call]['args']['body'], true);

        return (string) $body['messages'][0]['content'];
    }

    public function testTheNextMessageCarriesWhatWasSaidBefore(): void
    {
        $this->reply(true, true, 'Saturday has Decorate and Clean.');
        $this->reply(true, true, 'Clean it is.');

        $this->ask(self::privateMessage('what tasks are on saturday?'));
        $this->ask(self::privateMessage('the second one'));

        self::assertStringContainsString(
            "CONVERSATION (this private chat, last 24 hours):\n(nothing yet)",
            $this->systemPrompt(0)
        );

        $second = $this->systemPrompt(1);
        self::assertStringContainsString('Person: what tasks are on saturday?', $second);
        self::assertStringContainsString('Bot: Saturday has Decorate and Clean.', $second);
        // The new message goes once, as the user turn, not also in the history.
        self::assertStringNotContainsString('Person: the second one', $second);
        self::assertStringContainsString('You remember this private chat for 24 hours', $second);
    }

    public function testMessagesOlderThan24HoursAreNotSent(): void
    {
        \EventCrew\Telegram\ConversationLog::add(555, 'person', 'an old question', time() - DAY_IN_SECONDS - 60);
        \EventCrew\Telegram\ConversationLog::add(555, 'person', 'a recent question', time() - 3600);
        $this->reply(true, true, 'Hello!');

        $this->ask(self::privateMessage('hi'));

        self::assertStringNotContainsString('an old question', $this->systemPrompt(0));
        self::assertStringContainsString('a recent question', $this->systemPrompt(0));
    }

    public function testTheRecordKeepsAtMostTheNewestHundredMessages(): void
    {
        for ($i = 1; $i <= 105; $i++) {
            \EventCrew\Telegram\ConversationLog::add(555, 'person', 'message ' . $i);
        }

        $entries = \EventCrew\Telegram\ConversationLog::entries(555);

        self::assertCount(100, $entries);
        self::assertSame('message 6', $entries[0]['x']);
        self::assertSame('message 105', $entries[99]['x']);
    }

    public function testNothingFromTheGroupIsKept(): void
    {
        $this->reply(true, true, 'Saturday.');

        $this->ask(self::groupMessage('@crewbot when is the next night?'));

        self::assertSame([], $this->transientsStartingWith(\EventCrew\Telegram\ConversationLog::PREFIX));
        self::assertStringNotContainsString('CONVERSATION', $this->systemPrompt(0));
    }

    public function testHelpSaysPlainWordsWorkAndTheChatIsRemembered(): void
    {
        $this->help()->onHelp(555, 555, true);

        $text = $this->sentTexts()[0];
        self::assertStringContainsString('You can also just write what you want', $text);
        self::assertStringContainsString('I remember our private chat for 24 hours', $text);
    }

    public function testHelpWithoutTheHelperDoesNotMentionPlainWords(): void
    {
        $this->options[AssistantSettings::ENABLED_OPTION] = false;

        $this->help()->onHelp(555, 555, true);

        self::assertStringNotContainsString('I remember our private chat', $this->sentTexts()[0]);
    }

    /** @return array<string, mixed> */
    private function transientsStartingWith(string $prefix): array
    {
        return array_filter(
            $this->transients,
            static fn (string $key): bool => str_starts_with($key, $prefix),
            ARRAY_FILTER_USE_KEY
        );
    }

    public function testTheKeyIsSentAsABearerToken(): void
    {
        AssistantSettings::saveKey('sk-test');
        $this->reply(true, true, 'Hello!');

        $this->ask(self::privateMessage('hi'));

        self::assertSame('Bearer sk-test', $this->llmCalls[0]['args']['headers']['Authorization']);
    }

    public function testSwitchedOffNothingHappens(): void
    {
        $this->options[AssistantSettings::ENABLED_OPTION] = false;

        self::assertFalse($this->ask(self::privateMessage('hi')));
        self::assertSame([], $this->llmCalls);
        self::assertSame([], $this->telegramCalls);
    }

    public function testNotConfiguredNothingHappens(): void
    {
        $this->options[AssistantSettings::MODEL_OPTION] = '';

        self::assertFalse($this->ask(self::privateMessage('hi')));
        self::assertSame([], $this->llmCalls);
    }

    public function testCommandsAndBotsAndChannelsAreNotForTheModel(): void
    {
        self::assertFalse(AssistantService::wants(self::privateMessage('/board')));
        self::assertFalse(AssistantService::wants(self::privateMessage('   ')));
        self::assertFalse(AssistantService::wants(
            ['from' => ['id' => 9, 'is_bot' => true]] + self::privateMessage('hi')
        ));
        self::assertFalse(AssistantService::wants(
            ['chat' => ['id' => -5, 'type' => 'channel']] + self::privateMessage('hi')
        ));
        self::assertTrue(AssistantService::wants(self::privateMessage('hi')));
        self::assertTrue(AssistantService::wants(self::groupMessage('hi')));
    }

    public function testThroughTheRouterACommandNeverReachesTheModel(): void
    {
        $this->updateRouter(null, $this->service())->dispatch(['message' => self::privateMessage('/help')]);

        self::assertSame([], $this->llmCalls);
        self::assertContains('sendMessage', $this->calledMethods());
    }

    public function testThroughTheRouterAnAnswerToABotQuestionNeverReachesTheModel(): void
    {
        $this->transients['eventcrew_tg_await_email_555'] = 1;
        Functions\when('sanitize_email')->returnArg(1);
        Functions\when('is_email')->justReturn(false);

        $this->updateRouter(null, $this->service())->dispatch(['message' => self::privateMessage('not an email')]);

        self::assertSame([], $this->llmCalls);
    }

    public function testThroughTheRouterAPlainPrivateMessageReachesTheModel(): void
    {
        $this->reply(true, true, 'The next night is on Saturday.');

        $this->updateRouter(null, $this->service())->dispatch(['message' => self::privateMessage('when is the next night?')]);

        self::assertCount(1, $this->llmCalls);
        self::assertSame(['The next night is on Saturday.'], $this->sentTexts());
    }

    public function testWithoutTheHelperAPlainPrivateMessageGetsNoAnswerAsBefore(): void
    {
        $this->updateRouter()->dispatch(['message' => self::privateMessage('when is the next night?')]);

        self::assertSame([], $this->llmCalls);
        self::assertSame([], $this->sentTexts());
    }

    // ---- private answers ------------------------------------------------

    public function testUnknownInPrivateSaysSoAndPointsToHelp(): void
    {
        $this->reply(true, false, 'I do not know the parking situation.');

        $this->ask(self::privateMessage('where can I park?'));

        self::assertSame(["I do not know the parking situation.\n\n/help"], $this->sentTexts());
    }

    public function testUnknownInPrivateWithNoWordsUsesTheFixedSentence(): void
    {
        $this->reply(true, false, '');

        $this->ask(self::privateMessage('where can I park?'));

        self::assertSame(['I do not know that. /help lists the commands, or ask an organizer.'], $this->sentTexts());
    }

    public function testBadJsonInPrivateFallsBackToCommands(): void
    {
        $this->llmReplies[] = 'Sure! The next night is Saturday.';

        $this->ask(self::privateMessage('when?'));

        self::assertSame(['Right now I only understand commands: /help'], $this->sentTexts());
    }

    public function testAnHttpErrorInPrivateFallsBackToCommands(): void
    {
        $this->llmStatus = 503;
        $this->reply(true, true, 'never shown');

        $this->ask(self::privateMessage('when?'));

        self::assertSame(['Right now I only understand commands: /help'], $this->sentTexts());
    }

    public function testParseReply(): void
    {
        self::assertNull(AssistantService::parseReply(''));
        self::assertNull(AssistantService::parseReply('no json here'));
        self::assertNull(AssistantService::parseReply('{"aimed": "yes", "known": true, "reply": "x"}'));
        self::assertNull(AssistantService::parseReply('{"aimed": true, "known": true}'));
        self::assertNull(AssistantService::parseReply('{"aimed": true, "known": true, "reply": "x", "action": 5}'));
        self::assertNull(AssistantService::parseReply('{"aimed": true, "known": true, "reply": "x"'));

        $fenced = AssistantService::parseReply("```json\n{\"aimed\": true, \"known\": true, \"lang\": \"FI\", \"reply\": \" Hei \", \"action\": \"null\"}\n```");
        self::assertSame(['aimed' => true, 'known' => true, 'lang' => 'fi', 'reply' => 'Hei', 'action' => null], $fenced);
    }

    // ---- the group ------------------------------------------------------

    public function testInTheGroupAMessageNotForTheBotGetsSilence(): void
    {
        $this->reply(false, true, 'Have fun!');

        $this->ask(self::groupMessage('see you all tonight'));

        self::assertSame([], $this->telegramCalls);
    }

    public function testInTheGroupAnUnknownAnswerGetsSilence(): void
    {
        $this->reply(true, false, 'I do not know.');

        $this->ask(self::groupMessage('bot, where can I park?'));

        self::assertSame([], $this->telegramCalls);
    }

    public function testInTheGroupBadJsonOrAnErrorGetsSilence(): void
    {
        $this->llmReplies[] = 'not json';
        $this->ask(self::groupMessage('bot, when?'));

        $this->llmStatus = 500;
        $this->reply(true, true, 'never shown');
        $this->ask(self::groupMessage('bot, when?'));

        self::assertCount(2, $this->llmCalls);
        self::assertSame([], $this->telegramCalls);
    }

    public function testInTheGroupAKnownAnswerForTheBotIsAReply(): void
    {
        $this->reply(true, true, 'Saturday at 20:00.');

        $this->ask(self::groupMessage('bot, when is the next night?'));

        $call = $this->lastCallTo('sendMessage');
        self::assertNotNull($call);
        self::assertSame('Saturday at 20:00.', $call['text']);
        self::assertSame(41, $call['reply_parameters']['message_id']);
    }

    public function testInTheGroupAnActionIsNeverRun(): void
    {
        $this->person(true);
        $this->reply(true, true, 'Done.', '/door add Anna');

        $this->ask(self::groupMessage('bot, add Anna to the guest list'));

        self::assertSame([], $this->ran);
        self::assertSame([], $this->transients === [] ? [] : array_filter(
            array_keys($this->transients),
            static fn (string $key): bool => str_starts_with($key, AssistantService::PENDING_PREFIX)
        ));
        self::assertNotContains('ACTIONS:', explode("\n", (string) json_decode(
            (string) $this->llmCalls[0]['args']['body'],
            true
        )['messages'][0]['content']));
    }

    // ---- actions --------------------------------------------------------

    public function testAShowOnlyActionRunsStraightAway(): void
    {
        $this->person();
        $this->reply(true, true, 'Here are your tasks.', '/me');

        $this->ask(self::privateMessage('what am I doing next week?'));

        self::assertSame(['Here are your tasks.'], $this->sentTexts());
        self::assertCount(1, $this->ran);
        self::assertSame('/me', $this->ran[0]['message']['text']);
        self::assertSame(555, $this->ran[0]['message']['from']['id']);
    }

    public function testAMenuActionRunsAsATap(): void
    {
        $this->person();
        $this->reply(true, true, 'Here you go.', 'menu:ticket');

        $this->ask(self::privateMessage('I want my free ticket'));

        self::assertCount(1, $this->ran);
        self::assertSame('mn:ticket', $this->ran[0]['callback_query']['data']);
    }

    public function testAnUnknownActionIsRefusedAndNothingRuns(): void
    {
        $this->person();
        $this->reply(true, true, 'Deleting.', '/purge all');

        $this->ask(self::privateMessage('delete everyone'));

        self::assertSame([], $this->ran);
        self::assertSame(['I cannot do that for you. /help lists what you can do.'], $this->sentTexts());
    }

    public function testAnActionThePersonMayNotUseIsRefused(): void
    {
        $this->person();
        $this->reply(true, true, 'Here is the guest list.', 'menu:guestlist');

        $this->ask(self::privateMessage('show me the guest list'));

        self::assertSame([], $this->ran);
        self::assertSame(['I cannot do that for you. /help lists what you can do.'], $this->sentTexts());
    }

    public function testAChangeWaitsForConfirm(): void
    {
        $this->person(true);
        $this->reply(true, true, 'I will add Anna with one friend.', '/door add Anna +1');

        $this->ask(self::privateMessage('add Anna and a friend to the guest list'));

        self::assertSame([], $this->ran, 'Nothing runs before Confirm.');
        self::assertSame(["I will add Anna with one friend.\n\n➡️ /door add Anna +1"], $this->sentTexts());

        $buttons = $this->lastSentMarkup()['inline_keyboard'][0];
        self::assertMatchesRegularExpression('/^ai:ok:[0-9a-f]{16}$/', $buttons[0]['callback_data']);
        self::assertMatchesRegularExpression('/^ai:no:[0-9a-f]{16}$/', $buttons[1]['callback_data']);

        $id = substr($buttons[0]['callback_data'], 6);
        self::assertSame(
            ['user' => 555, 'action' => '/door add Anna +1'],
            $this->transients[AssistantService::PENDING_PREFIX . $id]
        );
    }

    /** Proposes /door add Anna and returns the Confirm button's data. */
    private function proposeGuest(): string
    {
        $this->person(true);
        $this->reply(true, true, 'Adding Anna.', '/door add Anna');
        $this->ask(self::privateMessage('add Anna to the guest list'));

        return (string) $this->lastSentMarkup()['inline_keyboard'][0][0]['callback_data'];
    }

    /** @return array<string, mixed> */
    private static function tap(string $data, int $fromId = 555): array
    {
        return [
            'id' => 'cb1',
            'from' => ['id' => $fromId, 'first_name' => 'Sam'],
            'message' => ['message_id' => 77, 'chat' => ['id' => 555, 'type' => 'private'], 'text' => 'Adding Anna.'],
            'data' => $data,
        ];
    }

    private function confirm(string $data, int $fromId = 555): void
    {
        $this->service()->onConfirm(self::tap($data, $fromId), function (array $update): void {
            $this->ran[] = $update;
        });
    }

    public function testConfirmRunsTheCheckedActionOnce(): void
    {
        $data = $this->proposeGuest();
        $this->person(true);

        $this->confirm($data);

        self::assertCount(1, $this->ran);
        self::assertSame('/door add Anna', $this->ran[0]['message']['text']);
        self::assertSame(555, $this->ran[0]['message']['from']['id']);

        // A second tap finds nothing.
        $this->confirm($data);

        self::assertCount(1, $this->ran);
        self::assertSame('This question has expired. Please write again.', $this->lastCallTo('answerCallbackQuery')['text']);
    }

    public function testCancelRunsNothing(): void
    {
        $data = str_replace('ai:ok:', 'ai:no:', $this->proposeGuest());

        $this->confirm($data);

        self::assertSame([], $this->ran);
        self::assertSame('Cancelled.', $this->lastCallTo('answerCallbackQuery')['text']);
    }

    public function testAnExpiredConfirmRunsNothing(): void
    {
        $data = $this->proposeGuest();
        $this->transients = [];

        $this->confirm($data);

        self::assertSame([], $this->ran);
        $answer = $this->lastCallTo('answerCallbackQuery');
        self::assertSame('This question has expired. Please write again.', $answer['text']);
        self::assertTrue($answer['show_alert']);
    }

    public function testSomeoneElsesConfirmRunsNothing(): void
    {
        $data = $this->proposeGuest();

        $this->confirm($data, 999);

        self::assertSame([], $this->ran);
        self::assertSame('This question has expired. Please write again.', $this->lastCallTo('answerCallbackQuery')['text']);

        // And the owner can still use it.
        $this->person(true);
        $this->confirm($data);
        self::assertCount(1, $this->ran);
    }

    public function testAForgedConfirmIdRunsNothing(): void
    {
        $this->proposeGuest();

        $this->confirm('ai:ok:0000000000000000');
        $this->confirm('ai:ok:../../etc');

        self::assertSame([], $this->ran);
    }

    public function testConfirmChecksPermissionAgain(): void
    {
        $data = $this->proposeGuest();
        // No longer an organizer when the button is tapped.
        $this->person(false);

        $this->confirm($data);

        self::assertSame([], $this->ran);
        self::assertSame(
            'That can no longer be done. /help lists what you can do.',
            $this->lastCallTo('answerCallbackQuery')['text']
        );
    }

    public function testConfirmThroughTheRouterReachesTheHelper(): void
    {
        $data = $this->proposeGuest();

        $this->updateRouter(null, $this->service())->dispatch(['callback_query' => self::tap($data, 999)]);

        self::assertSame('This question has expired. Please write again.', $this->lastCallTo('answerCallbackQuery')['text']);
    }

    // ---- limits ---------------------------------------------------------

    public function testPrivateLimitGivesOneNoticeThenSilence(): void
    {
        $this->options[AssistantSettings::PRIVATE_LIMIT_OPTION] = 2;

        for ($i = 0; $i < 4; $i++) {
            $this->reply(true, true, 'Answer ' . $i);
            $this->ask(self::privateMessage('question ' . $i));
        }

        self::assertCount(2, $this->llmCalls);
        self::assertSame(
            ['Answer 0', 'Answer 1', 'That is all I can answer this hour. Commands still work: /help'],
            $this->sentTexts()
        );
    }

    public function testGroupLimitIsSilent(): void
    {
        $this->options[AssistantSettings::GROUP_LIMIT_OPTION] = 1;

        for ($i = 0; $i < 3; $i++) {
            $this->reply(true, true, 'Answer ' . $i);
            $this->ask(self::groupMessage('bot, question ' . $i));
        }

        self::assertCount(1, $this->llmCalls);
        self::assertSame(['Answer 0'], $this->sentTexts());
    }

    public function testTheLimitIsPerPerson(): void
    {
        $this->options[AssistantSettings::PRIVATE_LIMIT_OPTION] = 1;

        $this->reply(true, true, 'A');
        $this->ask(self::privateMessage('one'));
        $this->reply(true, true, 'B');
        $this->ask(['from' => ['id' => 556], 'chat' => ['id' => 556, 'type' => 'private']] + self::privateMessage('two'));

        self::assertCount(2, $this->llmCalls);
    }

    // ---- nothing is logged ----------------------------------------------

    public function testTheMessageTextAndTheKeyAreNeverLogged(): void
    {
        AssistantSettings::saveKey('sk-very-secret');
        $this->llmStatus = 401;
        $this->reply(true, true, 'never shown');

        $file = (string) tempnam(sys_get_temp_dir(), 'eclog');
        $before = ini_set('error_log', $file);

        try {
            $this->ask(self::privateMessage('my private question'));
            $this->llmReplies[] = 'garbage';
            $this->llmStatus = 200;
            $this->ask(self::privateMessage('another private question'));
        } finally {
            ini_set('error_log', false === $before ? '' : $before);
        }

        $logged = (string) file_get_contents($file);
        unlink($file);

        self::assertStringContainsString('http_401', $logged, 'The kind of error is logged.');
        self::assertStringContainsString('bad_json', $logged);

        self::assertStringNotContainsString('private question', $logged);
        self::assertStringNotContainsString('sk-very-secret', $logged);
        self::assertStringNotContainsString('garbage', $logged);
    }
}
