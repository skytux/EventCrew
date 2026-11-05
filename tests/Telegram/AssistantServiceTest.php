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
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\HelpPage;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\OrganizerGuide;
use EventCrew\Support\PrivacyPolicy;
use EventCrew\Telegram\AssistantService;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\ChatForwarder;
use EventCrew\Telegram\ConversationLog;
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
        Functions\when('wp_parse_url')->alias(static fn (string $url, int $part = -1): mixed => parse_url($url, $part));
        Functions\when('post_type_exists')->justReturn(false);
        Functions\when('get_posts')->justReturn([]);
        Functions\when('get_the_title')->justReturn('');
        Functions\when('add_action')->justReturn(true);
        Functions\when('admin_url')->alias(
            static fn (string $path = ''): string => 'https://example.test/wp-admin/' . $path
        );
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
            false,
            new ChatForwarder(
                new PersonRepository(),
                new Mailer(new Logger(), new EmailTemplate(new Logger())),
                $this->client(),
                new Logger()
            )
        );
    }

    private function person(bool $organizer = false, string $roles = ''): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'is_organizer' => $organizer ? 1 : 0,
            'telegram_user_id' => 555,
            'role_slugs' => $roles,
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

    public function testInTheGroupTheNextMessageCarriesWhatThisPersonWroteBefore(): void
    {
        $this->reply(true, true, 'Saturday at 20:00.');
        $this->reply(true, true, 'Until 02:00.');

        $this->ask(self::groupMessage('@crewbot when is the next night?'));
        $this->ask(self::groupMessage('@crewbot and until when?'));

        self::assertStringContainsString('(nothing yet)', $this->systemPrompt(0));

        $second = $this->systemPrompt(1);
        self::assertStringContainsString('what this person wrote to you in this group', $second);
        self::assertStringContainsString('Person: @crewbot when is the next night?', $second);
        self::assertStringContainsString('Bot: Saturday at 20:00.', $second);
        self::assertStringNotContainsString('Person: @crewbot and until when?', $second);
    }

    public function testTheGroupRecordIsApartFromThePrivateChat(): void
    {
        $this->reply(true, true, 'Noted.');
        $this->reply(true, true, 'Saturday.');
        $this->reply(true, true, 'Hello again.');

        $this->ask(self::privateMessage('a private worry'));
        $this->ask(self::groupMessage('@crewbot when is the next night?'));
        $this->ask(self::privateMessage('hi'));

        self::assertStringNotContainsString('a private worry', $this->systemPrompt(1));
        self::assertStringContainsString('(nothing yet)', $this->systemPrompt(1));
        self::assertStringContainsString('a private worry', $this->systemPrompt(2));
        self::assertStringNotContainsString('when is the next night', $this->systemPrompt(2));
    }

    public function testInTheGroupEachPersonHasTheirOwnRecord(): void
    {
        $this->reply(true, true, 'Saturday.');
        $this->reply(true, true, 'Yes.');

        $this->ask(self::groupMessage('@crewbot when is the next night?'));
        $other = self::groupMessage('@crewbot is there parking?');
        $other['from'] = ['id' => 777, 'first_name' => 'Kim'];
        $this->ask($other);

        self::assertStringNotContainsString('when is the next night', $this->systemPrompt(1));
        self::assertStringContainsString('(nothing yet)', $this->systemPrompt(1));
    }

    public function testInTheGroupAMessageNotForTheBotIsNotKept(): void
    {
        $this->reply(false, true, 'Have fun!');

        $this->ask(self::groupMessage('see you all tonight'));

        self::assertSame([], $this->transientsStartingWith(\EventCrew\Telegram\ConversationLog::PREFIX));
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

    public function testThePromptNamesThePersonsRoles(): void
    {
        $this->options['eventcrew_roles'] = [
            ['slug' => 'decorate', 'label' => 'Decorate'],
            ['slug' => 'dj', 'label' => 'DJ', 'restricted' => true],
            ['slug' => 'host', 'label' => 'Host', 'restricted' => true],
        ];
        $this->person(false, 'dj,host');
        $this->reply(true, true, 'Hi Sam.');

        $this->ask(self::privateMessage('hello'));

        self::assertStringContainsString('Welcome crew: no. Roles: DJ, Host.', $this->systemPrompt(0));
    }

    public function testAnOrganizerInAPrivateChatGetsTheOrganizerGuideAndTheInfrastructureNote(): void
    {
        $this->options[HelpPage::INFRASTRUCTURE_OPTION] = 'The site runs on a free host.';
        $this->person(true);
        $this->reply(true, true, 'Here is how.');

        $this->ask(self::privateMessage('how do I add a task?'));

        $prompt = $this->systemPrompt(0);
        self::assertStringContainsString('ORGANIZER GUIDE', $prompt);
        self::assertStringContainsString((string) strtok(OrganizerGuide::short(), "\n"), $prompt);
        self::assertStringContainsString(
            "INFRASTRUCTURE NOTE (written by the organizers):\nThe site runs on a free host.",
            $prompt
        );
        self::assertStringNotContainsString('](#', $prompt);
    }

    public function testAnOrganizerWithoutAnInfrastructureNoteGetsOnlyTheGuide(): void
    {
        $this->person(true);
        $this->reply(true, true, 'Here is how.');

        $this->ask(self::privateMessage('how do I add a task?'));

        self::assertStringContainsString('ORGANIZER GUIDE', $this->systemPrompt(0));
        self::assertStringNotContainsString('INFRASTRUCTURE NOTE', $this->systemPrompt(0));
    }

    public function testSomeoneWhoIsNotAnOrganizerGetsNoOrganizerGuide(): void
    {
        $this->options[HelpPage::INFRASTRUCTURE_OPTION] = 'The site runs on a free host.';
        $this->person(false);
        $this->reply(true, true, 'Hi.');

        $this->ask(self::privateMessage('how do I add a task?'));

        self::assertStringNotContainsString('ORGANIZER GUIDE', $this->systemPrompt(0));
        self::assertStringNotContainsString('free host', $this->systemPrompt(0));
    }

    public function testAnOrganizerInTheGroupGetsNoOrganizerGuide(): void
    {
        $this->options[HelpPage::INFRASTRUCTURE_OPTION] = 'The site runs on a free host.';
        $this->person(true);
        $this->reply(true, true, 'Hi.');

        $this->ask(self::groupMessage('@crewbot how do I add a task?'));

        self::assertStringNotContainsString('ORGANIZER GUIDE', $this->systemPrompt(0));
        self::assertStringNotContainsString('free host', $this->systemPrompt(0));
    }

    public function testTheFactsGiveTheOfficialEmailAddressFromThePrivacyContact(): void
    {
        $this->options[PrivacyPolicy::CONTACT_OPTION] = 'crew@example.org';
        $this->reply(true, true, 'Write to crew@example.org.');

        $this->ask(self::privateMessage('what is your email?'));

        self::assertStringContainsString(
            "## Contact\nThe official email address: crew@example.org",
            $this->systemPrompt(0)
        );
    }

    public function testWithoutAPrivacyContactTheFactsHaveNoContact(): void
    {
        $this->reply(true, true, 'I do not know.');

        $this->ask(self::privateMessage('what is your email?'));

        self::assertStringNotContainsString('## Contact', $this->systemPrompt(0));
    }

    public function testTheBotKnowsItsName(): void
    {
        $this->options[BoardService::NAME_OPTION] = 'Crew Bot';
        $this->options[BoardService::USERNAME_OPTION] = 'crewbot';
        $this->reply(true, true, 'Hi, I am Crew Bot.');

        $this->ask(self::privateMessage('who are you?'));

        self::assertStringContainsString('Your name is Crew Bot (@crewbot).', $this->systemPrompt(0));
    }

    public function testWithoutAKnownNameThePromptNamesNone(): void
    {
        $this->reply(true, true, 'Hi.');

        $this->ask(self::privateMessage('who are you?'));

        self::assertStringNotContainsString('Your name is', $this->systemPrompt(0));
    }

    public function testAPersonWithoutRolesIsSaidToHaveNone(): void
    {
        $this->person();
        $this->reply(true, true, 'Hi Sam.');

        $this->ask(self::privateMessage('hello'));

        self::assertStringContainsString('Roles: none.', $this->systemPrompt(0));
    }

    public function testThePromptSaysToLeaveOutWhatIsForOtherRolesWithoutMentioningIt(): void
    {
        $this->reply(true, true, 'Hi.');

        $this->ask(self::privateMessage('hello'));

        $prompt = $this->systemPrompt(0);
        self::assertStringContainsString('Leave out completely every task, command and', $prompt);
        self::assertStringContainsString('do not say that it exists', $prompt);
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
        self::assertSame(
            ['aimed' => true, 'known' => true, 'lang' => 'fi', 'reply' => 'Hei', 'action' => null, 'forward' => null],
            $fenced
        );
    }

    public function testParseReplyReadsForwardAndTakesAMissingOrUnknownOneAsNull(): void
    {
        $base = '{"aimed": true, "known": true, "reply": "x", "action": null';

        self::assertSame('unhappy', AssistantService::parseReply($base . ', "forward": "unhappy"}')['forward'] ?? '');
        self::assertSame(
            'very_happy',
            AssistantService::parseReply($base . ', "forward": " Very_Happy "}')['forward'] ?? ''
        );
        self::assertSame('human', AssistantService::parseReply($base . ', "forward": "human"}')['forward'] ?? '');
        self::assertSame('feedback', AssistantService::parseReply($base . ', "forward": "feedback"}')['forward'] ?? '');

        foreach (['}', ', "forward": null}', ', "forward": "angry"}', ', "forward": 3}'] as $end) {
            $parsed = AssistantService::parseReply($base . $end);
            self::assertIsArray($parsed, 'An older or odd answer still parses: ' . $end);
            self::assertArrayHasKey('forward', $parsed);
            self::assertNull($parsed['forward'], $end);
        }
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

    /**
     * "Reply exactly ..." in the group: the bot posts the text under its own
     * name, so links to other sites are removed. Links to the site and to
     * Telegram stay.
     */
    public function testInTheGroupLinksToOtherSitesAreRemoved(): void
    {
        Functions\when('home_url')->justReturn('https://crew.example.test/');
        Functions\when('wp_parse_url')->alias(static fn (string $url, int $part = -1): mixed => parse_url($url, $part));

        $this->reply(
            true,
            true,
            'Tickets: https://evil.example.org/pay and www.bad.test or bad.test/x. '
                . 'Info: https://crew.example.test/info and https://t.me/crewgroup.'
        );
        $this->ask(self::groupMessage('bot, reply exactly with this'));

        $call = $this->lastCallTo('sendMessage');
        self::assertNotNull($call);
        self::assertSame('Tickets: and or Info: https://crew.example.test/info and https://t.me/crewgroup.', $call['text']);

        // An answer that was only a link is not sent at all.
        $this->telegramCalls = [];
        $this->reply(true, true, 'https://evil.example.org');
        $this->ask(self::groupMessage('bot, reply exactly with this'));
        self::assertNull($this->lastCallTo('sendMessage'));
    }

    public function testTheGroupLinkFilterKeepsEmailAddressesAndOrdinaryText(): void
    {
        $strip = static fn (string $text): string => \EventCrew\Telegram\GroupLinkFilter::strip($text, 'www.crew.example.test');

        self::assertSame('Write to info@other.example.org.', $strip('Write to info@other.example.org.'));
        self::assertSame('Doors at 19.30, price 15.50 €.', $strip('Doors at 19.30, price 15.50 €.'));
        self::assertSame('See https://crew.example.test/a', $strip('See https://crew.example.test/a'));
        self::assertSame('See www.crew.example.test', $strip('See www.crew.example.test'));
        self::assertSame('See https://sub.crew.example.test', $strip('See https://sub.crew.example.test'));
        self::assertSame('See', $strip('See https://crew.example.test.evil.org'));
        self::assertSame('See', $strip('See HTTP://EVIL.ORG/X'));
        self::assertSame('See', $strip('See https://exämple.fi/x'));
        self::assertSame('Join https://telegram.me/x', $strip('Join https://telegram.me/x'));
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

    public function testARunActionLeavesAMarkerInTheConversation(): void
    {
        $this->person(true);
        $this->reply(true, true, 'Here is the roster.', '/roster 14.11.');

        $this->ask(self::privateMessage('who is on the roster on the 14th?'));

        self::assertCount(1, $this->ran);
        $texts = array_column(\EventCrew\Telegram\ConversationLog::entries(555), 'x');
        self::assertContains('[showed /roster 14.11.]', $texts);
        self::assertStringContainsString(
            '[showed /roster 14.11.]',
            \EventCrew\Telegram\ConversationLog::forPrompt(555)
        );
    }

    public function testAConfirmedChangeLeavesARanMarkerWithoutItsArguments(): void
    {
        $data = $this->proposeGuest();
        $this->person(true);

        $this->confirm($data);

        $texts = array_column(\EventCrew\Telegram\ConversationLog::entries(555), 'x');
        self::assertContains('[ran /door]', $texts);
        self::assertNotContains('[ran /door add Anna]', $texts);
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

    public function testANewsletterTextInPlainWordsRunsAfterConfirmWithItsLineBreaks(): void
    {
        $this->person(true);
        $this->reply(
            true,
            true,
            'I will put this in the next newsletter.',
            "/newsletter Doors open at 19.\n\nBring water."
        );

        $this->ask(self::privateMessage('add to the newsletter: doors open at 19, bring water'));

        self::assertSame([], $this->ran, 'Nothing runs before Confirm.');
        self::assertSame(
            ["I will put this in the next newsletter.\n\n➡️ /newsletter Doors open at 19.\n\nBring water."],
            $this->sentTexts()
        );

        $this->person(true);
        $this->confirm((string) $this->lastSentMarkup()['inline_keyboard'][0][0]['callback_data']);

        self::assertCount(1, $this->ran);
        self::assertSame("/newsletter Doors open at 19.\n\nBring water.", $this->ran[0]['message']['text']);
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

    // ---- forwarding a private chat to the organizers ----------------------

    /** @var array<int, array{to: string, subject: string, body: string}> */
    private array $mails = [];

    /**
     * The person (Telegram id 555) and two organizers, answered by what the
     * query asks for rather than from the queues, and every email captured.
     */
    /**
     * @param array<string, mixed> $leeExtra columns added to the organizer Lee's row
     * @param array<string, mixed> $kimExtra columns added to the organizer Kim's row
     */
    private function forwardWorld(bool $verified = true, array $leeExtra = [], array $kimExtra = []): void
    {
        $person = [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => $verified ? '2026-07-01 00:00:00' : null,
            'is_organizer' => 0,
            'telegram_user_id' => 555,
        ];
        $organizers = [
            [
                'id' => 1,
                'email' => 'lee@example.com',
                'display_name' => 'Lee',
                'email_verified_at' => '2026-01-01 00:00:00',
                'is_organizer' => 1,
                'telegram_chat_id' => 501,
            ] + $leeExtra,
            [
                'id' => 2,
                'email' => 'kim@example.com',
                'display_name' => 'Kim',
                'email_verified_at' => '2026-01-01 00:00:00',
                'is_organizer' => 1,
            ] + $kimExtra,
        ];

        $wpdb = new class ($person, $organizers) extends \FakeWpdb {
            /**
             * @param array<string, mixed> $person
             * @param array<int, array<string, mixed>> $organizers
             */
            public function __construct(private array $person, private array $organizers)
            {
            }

            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- wpdb's own method name.
            public function get_row(string $sql, string $output = 'OBJECT'): mixed
            {
                if (str_contains($sql, 'telegram_user_id = 555')) {
                    $this->queries[] = $sql;

                    return $this->person;
                }

                return parent::get_row($sql, $output);
            }

            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- wpdb's own method name.
            public function get_results(string $sql, string $output = 'OBJECT'): mixed
            {
                if (str_contains($sql, 'is_organizer = 1')) {
                    $this->queries[] = $sql;

                    return $this->organizers;
                }

                return parent::get_results($sql, $output);
            }
        };
        $this->wpdb = $wpdb;
        $GLOBALS['wpdb'] = $wpdb;

        $this->mails = [];
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

            return true;
        });
    }

    private function forwardReply(string $reply, ?string $forward): void
    {
        $this->llmReplies[] = (string) json_encode([
            'aimed' => true,
            'known' => true,
            'lang' => 'en',
            'reply' => $reply,
            'action' => null,
            'forward' => $forward,
        ]);
    }

    /** @return array<int, string> Texts sent to one chat. */
    private function textsTo(int $chatId): array
    {
        return array_values(array_map(
            static fn (array $call): string => (string) ($call['body']['text'] ?? ''),
            array_filter(
                $this->telegramCalls,
                static fn (array $call): bool => 'sendMessage' === $call['method']
                    && $chatId === (int) ($call['body']['chat_id'] ?? 0)
            )
        ));
    }

    public function testThePrivatePromptExplainsForwardAndTheGroupPromptDoesNot(): void
    {
        $this->reply(true, true, 'Hello!');
        $this->reply(true, true, 'Saturday.');

        $this->ask(self::privateMessage('hi'));
        $this->ask(self::groupMessage('@crewbot when is the next night?'));

        self::assertStringContainsString(
            '"forward": null or "feedback", "unhappy", "very_happy" or "human"',
            $this->systemPrompt(0)
        );
        self::assertStringContainsString('"forward" sends this chat to the organizers.', $this->systemPrompt(0));
        self::assertStringNotContainsString('forward', $this->systemPrompt(1));
    }

    public function testAnUnhappyPersonsChatIsEmailedToEveryOrganizerAndThePersonIsTold(): void
    {
        $this->forwardWorld();
        ConversationLog::add(555, ConversationLog::PERSON, 'is there a task on saturday?', time() - 600);
        ConversationLog::add(555, ConversationLog::BOT, 'Yes: Clean at 22:00.', time() - 590);
        $this->forwardReply('I am sorry to hear that.', 'unhappy');

        $this->ask(self::privateMessage('the cleaning last week was badly organised'));

        $toPerson = $this->textsTo(555);
        self::assertCount(1, $toPerson);
        self::assertStringContainsString('I am sorry to hear that.', $toPerson[0]);
        self::assertStringContainsString('I have passed this chat on to the organizers.', $toPerson[0]);

        self::assertSame(['lee@example.com', 'kim@example.com'], array_column($this->mails, 'to'));
        self::assertSame('Bot chat: Sam is unhappy', $this->mails[0]['subject']);

        $body = $this->mails[0]['body'];
        $first = strpos($body, 'is there a task on saturday?');
        $current = strpos($body, 'the cleaning last week was badly organised');
        $answer = strpos($body, 'I am sorry to hear that.');
        self::assertNotFalse($first);
        self::assertNotFalse($current);
        self::assertNotFalse($answer);
        self::assertTrue($first < $current && $current < $answer, 'The transcript is oldest first.');
        self::assertStringContainsString('Yes: Clean at 22:00.', $body);

        // A short Telegram message to the organizer who has a chat; none to the other.
        $toLee = $this->textsTo(501);
        self::assertCount(1, $toLee);
        self::assertStringContainsString('I emailed you a transcript of my private chat with Sam.', $toLee[0]);
        self::assertStringNotContainsString('badly organised', $toLee[0]);
    }

    public function testAnOrganizerWhoTurnedTheChatRowsTelegramOffGetsOnlyTheEmail(): void
    {
        $this->forwardWorld(true, ['notify_prefs' => '{"chat_forward":{"dm":false,"email":true}}']);
        $this->forwardReply('Thanks!', 'human');

        $this->ask(self::privateMessage('can I talk to a person?'));

        self::assertSame(['lee@example.com', 'kim@example.com'], array_column($this->mails, 'to'));
        self::assertSame([], $this->textsTo(501));
    }

    public function testAnOrganizerWhoTurnedTheChatEmailOffGetsNeitherAndTheOthersStillDo(): void
    {
        // The Telegram message only points at the email, so it does not come alone.
        $this->forwardWorld(true, ['notify_prefs' => '{"chat_forward":{"dm":true,"email":false}}']);
        $this->forwardReply('Thanks!', 'human');

        $this->ask(self::privateMessage('can I talk to a person?'));

        self::assertSame(['kim@example.com'], array_column($this->mails, 'to'));
        self::assertSame([], $this->textsTo(501));
        self::assertStringContainsString('I have passed this chat on to the organizers.', $this->textsTo(555)[0]);
    }

    public function testWhenNoOrganizerWantsTheChatThePersonIsNotToldItWasPassedOn(): void
    {
        $off = ['notify_prefs' => '{"chat_forward":{"dm":false,"email":false}}'];
        $this->forwardWorld(true, $off, $off);
        $this->forwardReply('Thanks!', 'human');

        $this->ask(self::privateMessage('can I talk to a person?'));

        self::assertSame([], $this->mails);
        self::assertStringNotContainsString('passed this chat on', $this->textsTo(555)[0]);
    }

    public function testEachKindNamesItselfInTheSubject(): void
    {
        $subjects = [];

        foreach (
            [
                'feedback' => 'Bot chat: feedback from Sam',
                'very_happy' => 'Bot chat: Sam is very happy',
                'human' => 'Bot chat: Sam wants a human',
            ] as $kind => $subject
        ) {
            $this->forwardWorld();
            $this->transients = [];
            $this->forwardReply('Thanks!', $kind);
            $this->ask(self::privateMessage('a message'));
            $subjects[$kind] = $this->mails[0]['subject'] ?? '';
            self::assertSame($subject, $subjects[$kind]);
        }
    }

    public function testTheForwardedChatIsTheBuiltInTextWhenNothingIsStored(): void
    {
        $this->forwardWorld();
        $this->options[EmailTemplate::HTML_OPTION] = false;
        $this->forwardReply('Thanks!', 'human');

        $this->ask(self::privateMessage('a message'));

        self::assertSame('Bot chat: Sam wants a human', $this->mails[0]['subject']);
        self::assertMatchesRegularExpression(
            '/^Sam \(sam@example\.com\) wrote to the bot in a private chat\. Here is that chat, oldest first\.\n\n'
            . '\[\d{4}-\d\d-\d\d \d\d:\d\d\] Sam: a message\n\n\[\d{4}-\d\d-\d\d \d\d:\d\d\] Bot: Thanks!/',
            $this->mails[0]['body']
        );
        self::assertSame(
            ["📣 Test Site\n\nHi Lee,\n\nI emailed you a transcript of my private chat with Sam."],
            $this->textsTo(501)
        );
    }

    public function testTheForwardedChatUsesAStoredText(): void
    {
        $this->forwardWorld();
        $this->options[EmailTemplate::HTML_OPTION] = false;
        $this->options[MessageTemplates::OPTION_PREFIX . 'chat_forward'] = [
            'subject' => ['source' => 'Chat with {name}'],
            'body' => ['source' => "{name} <{email}>\n\n{transcript}"],
        ];
        $this->options[MessageTemplates::OPTION_PREFIX . 'chat_forward_dm'] = [
            'body' => ['source' => 'Check your email about {name}.'],
        ];
        $this->forwardReply('Thanks!', 'unhappy');

        $this->ask(self::privateMessage('a message'));

        self::assertSame('Chat with Sam', $this->mails[0]['subject']);
        self::assertMatchesRegularExpression('/^Sam <sam@example\.com>\n\n\[[^]]+\] Sam: a message/', $this->mails[0]['body']);
        self::assertSame(["📣 Test Site\n\nHi Lee,\n\nCheck your email about Sam."], $this->textsTo(501));
    }

    public function testAtMostOneForwardPerPersonPerHour(): void
    {
        $this->forwardWorld();
        $this->forwardReply('Noted.', 'feedback');
        $this->forwardReply('Noted again.', 'feedback');

        $this->ask(self::privateMessage('the music was great'));
        $this->ask(self::privateMessage('and the lights too'));

        self::assertCount(2, $this->mails, 'Two organizers, once.');
        $texts = $this->textsTo(555);
        self::assertStringContainsString('passed this chat on', $texts[0]);
        self::assertSame('Noted again.', $texts[1], 'The second answer does not claim a forward.');
        self::assertArrayHasKey(ChatForwarder::LIMIT_PREFIX . '555', $this->transients);

        // The hour is over: the next one goes out again.
        unset($this->transients[ChatForwarder::LIMIT_PREFIX . '555']);
        $this->forwardReply('Noted once more.', 'feedback');
        $this->ask(self::privateMessage('one more thing'));

        self::assertCount(4, $this->mails);
    }

    public function testSwitchedOffNothingIsForwardedAndThePromptDoesNotOfferIt(): void
    {
        $this->forwardWorld();
        $this->options[AssistantSettings::FORWARD_OPTION] = false;
        $this->forwardReply('I am sorry to hear that.', 'unhappy');

        $this->ask(self::privateMessage('this was bad'));

        self::assertSame([], $this->mails);
        self::assertSame(['I am sorry to hear that.'], $this->textsTo(555));
        self::assertStringNotContainsString('"forward"', $this->systemPrompt(0));
    }

    public function testAPersonWithoutAConfirmedEmailIsNotForwarded(): void
    {
        $this->forwardWorld(false);
        $this->forwardReply('An organizer can help.', 'human');

        $this->ask(self::privateMessage('can I talk to a human?'));

        self::assertSame([], $this->mails);
        self::assertSame(['An organizer can help.'], $this->textsTo(555));
    }

    public function testNothingIsForwardedFromTheGroup(): void
    {
        $this->forwardWorld();
        $this->forwardReply('Sorry!', 'unhappy');

        $this->ask(self::groupMessage('@crewbot this night was bad'));

        self::assertSame([], $this->mails);
        self::assertSame([], $this->transientsStartingWith(ChatForwarder::LIMIT_PREFIX));
    }

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
