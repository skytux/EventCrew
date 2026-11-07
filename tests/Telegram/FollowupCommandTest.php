<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\FollowupText;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Telegram\FollowupCommand;

/**
 * /followup. The language model is not set up here, so a saved text is
 * stored as written in both languages and the answer says so.
 */
final class FollowupCommandTest extends TelegramTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('delete_option')->alias(function (string $name): bool {
            unset($this->options[$name]);

            return true;
        });
    }

    /** @var list<\Closure> Work FollowupCommand leaves for after the webhook's answer. */
    private array $later = [];

    private function command(): FollowupCommand
    {
        return new FollowupCommand(
            new PersonRepository(),
            new FollowupText(new LlmClient(new Logger()), new Logger()),
            $this->client(),
            function (\Closure $work): void {
                $this->later[] = $work;
            }
        );
    }

    /**
     * A language model that answers $answer, but only when it is given at
     * least $needs seconds (a slow model: a shorter wait times out); null
     * $answer fails every time. Telegram calls are answered as before.
     */
    private function withModel(?string $answer, int $needs = 0): void
    {
        $this->options[AssistantSettings::URL_OPTION] = 'https://llm.example.test/v1';
        $this->options[AssistantSettings::MODEL_OPTION] = 'test-model';
        $this->telegramResults['sendMessage'] = ['message_id' => 77];
        $this->telegramResults['editMessageText'] = ['message_id' => 77];

        Functions\when('wp_remote_retrieve_response_code')->alias(
            static fn (mixed $response): int => is_array($response) ? $response['code'] : 200
        );
        Functions\when('wp_remote_retrieve_body')->alias(
            static fn (mixed $response): string => is_array($response) ? $response['body'] : (string) $response
        );
        Functions\when('wp_remote_post')->alias(function (string $url, array $args) use ($answer, $needs): array|string {
            if (str_starts_with($url, 'https://llm.example.test/')) {
                $ok = null !== $answer && (int) $args['timeout'] >= $needs;

                return [
                    'code' => $ok ? 200 : 504,
                    'body' => $ok ? (string) json_encode(['choices' => [['message' => ['content' => $answer]]]]) : '',
                ];
            }

            $method = substr($url, (int) strrpos($url, '/') + 1);
            $this->telegramCalls[] = ['method' => $method, 'body' => (array) json_decode((string) $args['body'], true)];

            return (string) json_encode(['ok' => true, 'result' => $this->telegramResults[$method] ?? true]);
        });
    }

    private function runLater(): void
    {
        foreach ($this->later as $work) {
            $work();
        }

        $this->later = [];
    }

    /**
     * Owner's report: the answer showed the text as typed for every language.
     * A model that needs more than a chat answer's wait used to time out
     * inside the webhook, and the text as written was listed under each
     * language. Now the answer says it is translating, and is edited to the
     * real translations once the model has answered.
     */
    public function testTheAnswerShowsEachLanguagesTranslationNotTheTypedText(): void
    {
        $this->withModel('{"lang": "en", "fi": "Kiitos kun tulit!"}', 45);
        $this->queuePerson(true);

        $this->command()->onCommand(555, 555, true, 'came Thanks for coming!');

        $first = $this->lastReply();
        self::assertStringContainsString('Translating', $first);
        self::assertStringNotContainsString("Finnish:\nThanks for coming!", $first);

        $this->runLater();

        $last = end($this->telegramCalls);
        self::assertNotFalse($last);
        self::assertSame('editMessageText', $last['method']);
        self::assertSame(77, $last['body']['message_id']);
        self::assertStringContainsString("Finnish:\nKiitos kun tulit!", $last['body']['text']);
        self::assertStringContainsString("English:\nThanks for coming!", $last['body']['text']);
        self::assertStringNotContainsString('Translating', $last['body']['text']);

        $stored = $this->options[FollowupText::NEXT_OPTIONS[FollowupText::CAME]];
        self::assertSame('Kiitos kun tulit!', $stored['fi'], 'the email gets the translation too');
        self::assertTrue($stored['translated']);
        self::assertSame(1, $stored['rev']);
    }

    /** A translation that fails says so, and the typed text is not listed as each language's. */
    public function testAFailedTranslationSaysEveryLanguageGetsTheTextAsWritten(): void
    {
        $this->withModel(null);
        $this->queuePerson(true);

        $this->command()->onCommand(555, 555, true, 'came Thanks for coming!');
        $this->runLater();

        $last = end($this->telegramCalls);
        self::assertNotFalse($last);
        self::assertSame('editMessageText', $last['method']);
        self::assertStringContainsString('Not translated', $last['body']['text']);
        self::assertStringContainsString('Thanks for coming!', $last['body']['text']);
        self::assertStringNotContainsString('Finnish:', $last['body']['text']);
        self::assertFalse($this->options[FollowupText::NEXT_OPTIONS[FollowupText::CAME]]['translated']);
    }

    /** The email tries a failed translation again before it goes out. */
    public function testAnUntranslatedTextIsTranslatedLater(): void
    {
        $this->storeNext(FollowupText::CAME, [
            'fi' => 'Thanks!',
            'en' => 'Thanks!',
            'source' => '',
            'translated' => false,
        ]);
        $this->withModel('{"lang": "en", "fi": "Kiitos!"}');

        $text = new FollowupText(new LlmClient(new Logger()), new Logger());
        $text->translatePending();

        self::assertSame('Kiitos!', $text->text(FollowupText::CAME, 'fi')['text']);
        self::assertTrue($text->next(FollowupText::CAME)['translated']);
        self::assertSame(1, $text->next(FollowupText::CAME)['rev']);
    }

    private function queuePerson(bool $organizer): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 9,
            'email' => 'org@example.test',
            'display_name' => 'Org',
            'telegram_user_id' => 555,
            'is_organizer' => $organizer ? 1 : 0,
        ];
    }

    private function lastReply(): string
    {
        $last = end($this->telegramCalls);

        return false === $last ? '' : (string) ($last['body']['text'] ?? '');
    }

    /** @param array<string, mixed> $value */
    private function storeNext(string $variant, array $value): void
    {
        $this->options[FollowupText::NEXT_OPTIONS[$variant]] = $value + [
            'source' => 'en',
            'translated' => true,
            'by' => 9,
            'at' => '2026-11-10 10:00:00',
            'rev' => 1,
        ];
    }

    public function testItIsRefusedInAGroup(): void
    {
        $this->command()->onCommand(555, -100, false, 'came Hello');

        self::assertStringContainsString('private chat', $this->lastReply());
        self::assertArrayNotHasKey(FollowupText::NEXT_OPTIONS[FollowupText::CAME], $this->options);
        self::assertSame([], $this->wpdb->queries);
    }

    public function testItIsRefusedToSomeoneWhoIsNotAnOrganizer(): void
    {
        $this->queuePerson(false);

        $this->command()->onCommand(555, 555, true, 'came Hello');

        self::assertStringContainsString('Only organizers', $this->lastReply());
        self::assertArrayNotHasKey(FollowupText::NEXT_OPTIONS[FollowupText::CAME], $this->options);
    }

    public function testATextIsSavedForOneVersion(): void
    {
        $this->queuePerson(true);

        $this->command()->onCommand(555, 555, true, "MISSED See you\nnext time!");

        $reply = $this->lastReply();
        self::assertStringContainsString('Sorry text (missed)', $reply);
        self::assertStringContainsString('next post-event email only', $reply);
        self::assertStringContainsString('Not translated', $reply);
        self::assertStringNotContainsString('Finnish:', $reply, 'the text as written is not shown as a translation');
        $stored = $this->options[FollowupText::NEXT_OPTIONS[FollowupText::MISSED]];
        self::assertSame("See you\nnext time!", $stored['en']);
        self::assertSame(9, $stored['by']);
        self::assertArrayNotHasKey(FollowupText::NEXT_OPTIONS[FollowupText::CAME], $this->options);
    }

    public function testAnUnknownVersionOrNoTextShowsTheUsage(): void
    {
        $this->queuePerson(true);
        $this->command()->onCommand(555, 555, true, 'maybe Hello');
        self::assertStringContainsString('/followup came', $this->lastReply());

        $this->queuePerson(true);
        $this->command()->onCommand(555, 555, true, 'came');
        self::assertStringContainsString('/followup came', $this->lastReply());

        self::assertArrayNotHasKey(FollowupText::NEXT_OPTIONS[FollowupText::CAME], $this->options);
    }

    public function testTheCommandAloneShowsBothTexts(): void
    {
        $this->storeNext(FollowupText::CAME, ['fi' => 'Moi', 'en' => 'Hi']);
        $this->queuePerson(true);

        $this->command()->onCommand(555, 555, true, '');

        $reply = $this->lastReply();
        self::assertStringContainsString("Finnish:\nMoi", $reply);
        self::assertStringContainsString("English:\nHi", $reply);
        self::assertStringContainsString('uses a one-time text', $reply);
        self::assertStringContainsString('uses the text on the Messages page', $reply, 'the sorry text');
        self::assertStringContainsString('We missed you at {event}', $reply);
    }

    public function testClearRemovesOneOrBoth(): void
    {
        $this->storeNext(FollowupText::CAME, ['fi' => 'Moi', 'en' => 'Hi']);
        $this->storeNext(FollowupText::MISSED, ['fi' => 'Harmi', 'en' => 'Sorry']);

        $this->queuePerson(true);
        $this->command()->onCommand(555, 555, true, 'came CLEAR');
        self::assertStringContainsString('removed', $this->lastReply());
        self::assertArrayNotHasKey(FollowupText::NEXT_OPTIONS[FollowupText::CAME], $this->options);
        self::assertArrayHasKey(FollowupText::NEXT_OPTIONS[FollowupText::MISSED], $this->options);

        $this->queuePerson(true);
        $this->command()->onCommand(555, 555, true, 'clear');
        self::assertStringContainsString('removed', $this->lastReply());
        self::assertArrayNotHasKey(FollowupText::NEXT_OPTIONS[FollowupText::MISSED], $this->options);
    }
}
