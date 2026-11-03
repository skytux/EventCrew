<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\NewsletterText;
use EventCrew\Tests\TestCase;

/**
 * The organizers' text for the next newsletter: written once, translated by
 * the language model when it is saved, stored in both languages. The model is
 * a stub of wp_remote_post; nothing leaves the test.
 */
final class NewsletterTextTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var list<string> */
    private array $requests = [];

    private string $answer = '';

    /** @var list<string> */
    private array $actions = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->requests = [];
        $this->answer = '';
        $this->actions = [];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
        Functions\when('delete_option')->alias(function (string $name): bool {
            unset($this->options[$name]);

            return true;
        });
        Functions\when('do_action')->alias(function (string $hook): void {
            $this->actions[] = $hook;
        });
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string => (string) json_encode($value));
        Functions\when('wp_remote_post')->alias(function (string $url, array $args): array {
            $this->requests[] = (string) $args['body'];

            return ['answer' => $this->answer];
        });
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->alias(
            static fn (array $response): string => (string) json_encode(
                ['choices' => [['message' => ['content' => $response['answer']]]]]
            )
        );
    }

    private function withModel(): void
    {
        $this->options[AssistantSettings::URL_OPTION] = 'https://llm.example.test/v1';
        $this->options[AssistantSettings::MODEL_OPTION] = 'test-model';
    }

    private function text(): NewsletterText
    {
        return new NewsletterText(new LlmClient(new Logger()), new Logger());
    }

    /**
     * A Finnish text is kept as written for Finnish, the model's translation
     * becomes the English, and the change is announced.
     */
    public function testAFinnishTextIsTranslatedIntoEnglish(): void
    {
        $this->withModel();
        $this->answer = '{"lang": "fi", "translation": "See you on the dance floor!"}';

        $result = $this->text()->set('  Nähdään tanssilattialla!  ', 7);

        self::assertTrue($result['saved']);
        self::assertTrue($result['translated']);
        self::assertSame('Nähdään tanssilattialla!', $result['fi']);
        self::assertSame('See you on the dance floor!', $result['en']);
        self::assertCount(1, $this->requests);
        $request = json_decode($this->requests[0], true);
        self::assertSame('Nähdään tanssilattialla!', $request['messages'][1]['content']);

        $stored = $this->text()->get();
        self::assertNotNull($stored);
        self::assertSame('fi', $stored['source']);
        self::assertSame(7, $stored['by']);
        self::assertSame(1, $stored['rev']);
        self::assertSame([NewsletterText::CHANGED_ACTION], $this->actions);
    }

    /** An English text, answered inside a code fence, fills the Finnish side. */
    public function testAnEnglishTextIsTranslatedIntoFinnish(): void
    {
        $this->withModel();
        $this->answer = "```json\n{\"lang\": \"en\", \"translation\": \"Tervetuloa!\"}\n```";

        $result = $this->text()->set('Welcome!', 7);

        self::assertTrue($result['translated']);
        self::assertSame('Tervetuloa!', $result['fi']);
        self::assertSame('Welcome!', $result['en']);
    }

    /**
     * No model set up: nothing is sent anywhere, both languages hold the text
     * as written, and the result says why.
     */
    public function testWithoutAModelBothLanguagesKeepTheTextAsWritten(): void
    {
        $result = $this->text()->set('Tervetuloa!', 7);

        self::assertTrue($result['saved']);
        self::assertFalse($result['translated']);
        self::assertSame(LlmClient::ERROR_NOT_CONFIGURED, $result['error']);
        self::assertSame('Tervetuloa!', $result['fi']);
        self::assertSame('Tervetuloa!', $result['en']);
        self::assertSame([], $this->requests);

        $stored = $this->text()->get();
        self::assertNotNull($stored);
        self::assertFalse($stored['translated']);
    }

    /** An answer that is not the expected JSON is not used as a translation. */
    public function testAnUnreadableAnswerKeepsTheTextAsWritten(): void
    {
        $this->withModel();
        $this->answer = 'Sure! Here is the translation: Welcome!';

        $result = $this->text()->set('Tervetuloa!', 7);

        self::assertFalse($result['translated']);
        self::assertSame('bad_answer', $result['error']);
        self::assertSame('Tervetuloa!', $result['en']);
    }

    /** "clear" and an empty text both remove the stored text. */
    public function testAnEmptyTextClears(): void
    {
        $this->text()->set('Tervetuloa!', 7);
        self::assertNotNull($this->text()->get());

        $result = $this->text()->set("  \n ", 7);

        self::assertFalse($result['saved']);
        self::assertNull($this->text()->get());
        self::assertArrayNotHasKey(NewsletterText::OPTION, $this->options);
    }

    /**
     * After sending, only the text the newsletter carried is cleared: one
     * saved again during the send (a higher revision) stays for the next one.
     */
    public function testClearIfRevisionKeepsANewerText(): void
    {
        $this->text()->set('First', 7);
        $this->text()->set('Second', 7);

        $this->text()->clearIfRevision(1);
        self::assertSame('Second', $this->text()->get()['en'] ?? null);

        $this->text()->clearIfRevision(2);
        self::assertNull($this->text()->get());
    }

    public function testParseRejectsAnotherLanguage(): void
    {
        self::assertNull(NewsletterText::parse('{"lang": "sv", "translation": "Välkommen"}'));
        self::assertNull(NewsletterText::parse('{"lang": "fi", "translation": ""}'));
        self::assertSame(
            ['lang' => 'en', 'translation' => 'Hei'],
            NewsletterText::parse('{"lang":"EN","translation":"Hei"}')
        );
    }
}
