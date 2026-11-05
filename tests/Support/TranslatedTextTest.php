<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\Language;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\TranslatedText;
use EventCrew\Tests\TestCase;

/**
 * A written-once text: the source in any language, one text per language,
 * and a per-language "edited by hand" mark. The model is a stub of
 * wp_remote_post; nothing leaves the test.
 */
final class TranslatedTextTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var list<string> */
    private array $requests = [];

    private string $answer = '';

    private int $status = 200;

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->requests = [];
        $this->answer = '';
        $this->status = 200;

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string => (string) json_encode($value));
        Functions\when('wp_remote_post')->alias(function (string $url, array $args): array {
            $this->requests[] = (string) $args['body'];

            return ['answer' => $this->answer];
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(fn (): int => $this->status);
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

    private function translator(): TranslatedText
    {
        return new TranslatedText(new LlmClient(new Logger()), new Logger());
    }

    /**
     * @param array<string, string> $texts
     * @param array<string, bool> $manual
     * @return array{source: string, lang: string, texts: array<string, string>, manual: array<string, bool>}
     */
    private static function value(string $source, array $texts, array $manual = []): array
    {
        return [
            'source' => $source,
            'lang' => '',
            'texts' => $texts + ['fi' => '', 'en' => ''],
            'manual' => $manual + ['fi' => false, 'en' => false],
        ];
    }

    /** A new text fills both languages; the source's own language gets the source. */
    public function testANewTextFillsEveryLanguage(): void
    {
        $this->withModel();
        $this->answer = '{"lang": "en", "fi": "Tervetuloa tanssimaan!"}';

        $result = $this->translator()->update(TranslatedText::blank(), 'Welcome to the dance!', []);

        self::assertSame('', $result['error']);
        self::assertSame('Tervetuloa tanssimaan!', $result['value']['texts']['fi']);
        self::assertSame('Welcome to the dance!', $result['value']['texts']['en']);
        self::assertSame(['fi' => false, 'en' => false], $result['value']['manual']);
        self::assertCount(1, $this->requests);
    }

    /**
     * A language text the admin changed by hand becomes manual, and a later
     * change of the source does not overwrite it.
     */
    public function testAHandEditedTextIsMarkedAndKept(): void
    {
        $this->withModel();
        $stored = self::value('Hello', ['fi' => 'Hei', 'en' => 'Hello']);

        $edited = $this->translator()->update($stored, 'Hello', ['fi' => 'Moikka', 'en' => 'Hello']);

        self::assertSame(['fi' => true, 'en' => false], $edited['value']['manual']);
        self::assertSame('Moikka', $edited['value']['texts']['fi']);
        self::assertSame([], $this->requests);

        $this->answer = '{"lang": "en", "fi": "Hyvää iltaa"}';
        $next = $this->translator()->update($edited['value'], 'Good evening', $edited['value']['texts']);

        self::assertSame('Moikka', $next['value']['texts']['fi']);
        self::assertTrue($next['value']['manual']['fi']);
        self::assertSame('Good evening', $next['value']['texts']['en']);
    }

    /** "Translate again" translates manual languages too and clears the marks. */
    public function testTranslateAgainOverwritesManualTexts(): void
    {
        $this->withModel();
        $this->answer = '{"lang": "en", "fi": "Hei"}';
        $stored = self::value('Hello', ['fi' => 'Moikka', 'en' => 'Hello'], ['fi' => true]);

        $result = $this->translator()->update($stored, 'Hello', $stored['texts'], true);

        self::assertSame('Hei', $result['value']['texts']['fi']);
        self::assertSame(['fi' => false, 'en' => false], $result['value']['manual']);
    }

    /** Emptying a language field clears its mark and it is filled again. */
    public function testAnEmptiedFieldIsFilledAgain(): void
    {
        $this->withModel();
        $this->answer = '{"lang": "en", "fi": "Hei"}';
        $stored = self::value('Hello', ['fi' => 'Moikka', 'en' => 'Hello'], ['fi' => true]);

        $result = $this->translator()->update($stored, 'Hello', ['fi' => '', 'en' => 'Hello']);

        self::assertSame('Hei', $result['value']['texts']['fi']);
        self::assertFalse($result['value']['manual']['fi']);
    }

    /**
     * The switch off: the model is set up but nothing is sent. Filled texts
     * are kept and empty ones get the source as written; no notice is shown.
     */
    public function testWithTheSwitchOffNothingIsSent(): void
    {
        $this->withModel();
        $this->options[TranslatedText::AUTO_OPTION] = '0';
        $stored = self::value('Hello', ['fi' => 'Hei', 'en' => 'Hello']);

        $result = $this->translator()->update($stored, 'Good evening', ['fi' => 'Hei', 'en' => '']);

        self::assertSame([], $this->requests);
        self::assertSame(TranslatedText::ERROR_OFF, $result['error']);
        self::assertSame('Hei', $result['value']['texts']['fi']);
        self::assertSame('Good evening', $result['value']['texts']['en']);
        self::assertSame('', TranslatedText::errorNotice($result['error']));
    }

    /** The model failing: what is there is kept, empty fields get the source, and a notice says so. */
    public function testAFailedTranslationKeepsTextsAndFillsEmptyOnes(): void
    {
        $this->withModel();
        $this->status = 500;
        $stored = self::value('Hello', ['fi' => 'Hei', 'en' => 'Hello']);

        $result = $this->translator()->update($stored, 'Good evening', ['fi' => 'Hei', 'en' => '']);

        self::assertSame('http_500', $result['error']);
        self::assertSame('Hei', $result['value']['texts']['fi']);
        self::assertSame('Good evening', $result['value']['texts']['en']);
        self::assertStringContainsString('http_500', TranslatedText::errorNotice($result['error']));
    }

    /** No model set up: nothing is sent, and the notice names that reason. */
    public function testWithoutAModelTheNoticeSaysSo(): void
    {
        $result = $this->translator()->update(TranslatedText::blank(), 'Tervetuloa', []);

        self::assertSame([], $this->requests);
        self::assertSame(LlmClient::ERROR_NOT_CONFIGURED, $result['error']);
        self::assertSame(['fi' => 'Tervetuloa', 'en' => 'Tervetuloa'], $result['value']['texts']);
        self::assertNotSame('', TranslatedText::errorNotice($result['error']));
    }

    /** An unreadable answer is a failure, not a translation. */
    public function testAnUnreadableAnswerIsAFailure(): void
    {
        $this->withModel();
        $this->answer = 'Sure! Hei!';

        $result = $this->translator()->update(TranslatedText::blank(), 'Hello', []);

        self::assertSame(TranslatedText::ERROR_BAD_ANSWER, $result['error']);
        self::assertSame(['fi' => 'Hello', 'en' => 'Hello'], $result['value']['texts']);
    }

    /** An unchanged save sends nothing. */
    public function testAnUnchangedSaveSendsNothing(): void
    {
        $this->withModel();
        $stored = self::value('Hello', ['fi' => 'Hei', 'en' => 'Hello']);

        $result = $this->translator()->update($stored, 'Hello', $stored['texts']);

        self::assertSame('', $result['error']);
        self::assertSame(TranslatedText::normalize($stored), $result['value']);
        self::assertSame([], $this->requests);
    }

    /** Emptying the source empties the texts that follow it, but not manual ones. */
    public function testAnEmptySourceEmptiesTheTextsThatFollowIt(): void
    {
        $stored = self::value('Hello', ['fi' => 'Moikka', 'en' => 'Hello'], ['fi' => true]);

        $result = $this->translator()->update($stored, '', $stored['texts']);

        self::assertSame(['fi' => 'Moikka', 'en' => ''], $result['value']['texts']);
    }

    public function testParse(): void
    {
        self::assertSame(
            ['lang' => 'sv', 'texts' => ['fi' => 'Hei', 'en' => 'Hi']],
            TranslatedText::parse('{"lang":"SV","fi":"Hei","en":"Hi"}')
        );
        self::assertSame(
            ['lang' => 'fi', 'texts' => ['fi' => '', 'en' => 'Hi']],
            TranslatedText::parse("```json\n{\"lang\":\"fi\",\"en\":\"Hi\"}\n```")
        );
        self::assertNull(TranslatedText::parse('{"lang":"fi","en":""}'));
        self::assertNull(TranslatedText::parse('{"lang":"sv","fi":"Hei"}'));
        self::assertNull(TranslatedText::parse('{"lang":"swedish","fi":"Hei","en":"Hi"}'));
        self::assertNull(TranslatedText::parse('Hei'));
    }

    /**
     * A language with no text of its own falls back to the source when the
     * source is in that language, else to the main language, else to the
     * source.
     */
    public function testTextFallsBackToTheMainLanguageThenTheSource(): void
    {
        $value = self::value('Hello', ['fi' => 'Hei']);

        self::assertSame('Hei', TranslatedText::text($value, 'fi'));
        self::assertSame('Hei', TranslatedText::text($value, 'en'));

        $value['lang'] = 'en';
        self::assertSame('Hello', TranslatedText::text($value, 'en'));

        self::assertSame('Hello', TranslatedText::text(self::value('Hello', []), 'en'));
        self::assertSame('Hello', TranslatedText::text(self::value('Hello', []), 'fi'));
    }

    /** The reader gets the current language, else the main language, else the source. */
    public function testForReaderUsesTheCurrentLanguage(): void
    {
        $value = self::value('Hello', ['fi' => 'Hei', 'en' => 'Hello there']);

        self::assertSame('Hei', TranslatedText::forReader($value));
        self::assertSame('Hello there', Language::run('en', static fn (): string => TranslatedText::forReader($value)));
        self::assertSame('Hello there', TranslatedText::forReader($value, 'en'));
        self::assertSame('Hei', TranslatedText::forReader(self::value('Hello', ['fi' => 'Hei']), 'en'));
        self::assertSame('Old text', TranslatedText::forReader('  Old text '));
        self::assertSame('', TranslatedText::forReader(null));
    }

    /** Every language once, in order, for a message in all of them. */
    public function testAllLanguages(): void
    {
        self::assertSame(
            ['fi' => 'Hei', 'en' => 'Hello'],
            TranslatedText::allLanguages(self::value('Hello', ['fi' => 'Hei', 'en' => 'Hello']))
        );
        self::assertSame(['fi' => 'Ovikoodi 1234'], TranslatedText::allLanguages('Ovikoodi 1234'));
        self::assertSame([], TranslatedText::allLanguages(''));
    }

    /**
     * A plain string stored before these values existed is the source, its
     * language unknown and nothing translated yet; the next save detects the
     * language and fills every language.
     */
    public function testAPlainStringIsMigratedOnTheNextSave(): void
    {
        $value = TranslatedText::normalize("Door code 1234\r\nThird floor");

        self::assertSame("Door code 1234\nThird floor", $value['source']);
        self::assertSame('', $value['lang']);
        self::assertFalse($value['detected']);
        self::assertSame(['fi' => '', 'en' => ''], $value['texts']);

        $this->withModel();
        $this->answer = '{"lang": "en", "fi": "Ovikoodi 1234\\nKolmas kerros"}';
        $result = $this->translator()->update('Door code 1234', 'Door code 1234', []);

        self::assertCount(1, $this->requests);
        self::assertSame('en', $result['value']['lang']);
        self::assertTrue($result['value']['detected']);
        self::assertSame("Ovikoodi 1234\nKolmas kerros", $result['value']['texts']['fi']);
        self::assertSame('Door code 1234', $result['value']['texts']['en']);
    }

    /** The shape keeps the old keys and adds lang and detected. */
    public function testTheStoredShape(): void
    {
        self::assertSame(
            [
                'source' => '',
                'lang' => '',
                'detected' => false,
                'texts' => ['fi' => '', 'en' => ''],
                'manual' => ['fi' => false, 'en' => false],
            ],
            TranslatedText::blank()
        );
        self::assertSame(TranslatedText::LANGUAGES, TranslatedText::languages());
        self::assertSame('fi', TranslatedText::mainLanguage());

        $old = TranslatedText::normalize(self::value('Hello', ['fi' => 'Hei', 'en' => 'Hello']));
        self::assertSame('', $old['lang']);
        self::assertFalse($old['detected']);

        $odd = TranslatedText::normalize(['source' => 'Hej', 'lang' => 'Swedish!', 'detected' => true]);
        self::assertSame('', $odd['lang']);
        self::assertFalse($odd['detected']);
    }

    /**
     * "Detect automatically": the model names the language and the value is
     * marked detected. A language chosen by hand is sent to the model, not
     * asked for, and the value is not marked detected.
     */
    public function testDetectedAndChosenLanguage(): void
    {
        $this->withModel();
        $this->answer = '{"lang": "fi", "en": "Welcome"}';

        $detected = $this->translator()->update(TranslatedText::blank(), 'Tervetuloa', [], false, 0, true, '');

        self::assertSame('fi', $detected['value']['lang']);
        self::assertTrue($detected['value']['detected']);
        self::assertSame('Tervetuloa', $detected['value']['texts']['fi']);
        self::assertSame('Welcome', $detected['value']['texts']['en']);

        $this->answer = '{"lang": "fi", "fi": "Hei"}';
        $chosen = $this->translator()->update(TranslatedText::blank(), 'Hi', [], false, 0, true, 'en');

        self::assertStringContainsString('English (en)', $this->requests[1]);
        self::assertSame('en', $chosen['value']['lang']);
        self::assertFalse($chosen['value']['detected']);
        self::assertSame('Hi', $chosen['value']['texts']['en']);
        self::assertSame('Hei', $chosen['value']['texts']['fi']);

        // Choosing the language by hand for an unchanged text sends nothing new.
        $again = $this->translator()->update(
            $detected['value'],
            'Tervetuloa',
            $detected['value']['texts'],
            false,
            0,
            true,
            'fi'
        );
        self::assertCount(2, $this->requests);
        self::assertFalse($again['value']['detected']);
        self::assertSame('fi', $again['value']['lang']);
    }

    /** A new source language translates again, but never over a text edited by hand. */
    public function testAChangedLanguageKeepsManualTexts(): void
    {
        $this->withModel();
        $this->answer = '{"lang": "en", "fi": "Hei"}';
        $stored = self::value('Hello', ['fi' => 'Moikka', 'en' => 'Hello'], ['fi' => true]);
        $stored['lang'] = 'fi';

        $result = $this->translator()->update($stored, 'Hello', $stored['texts'], false, 0, true, 'en');

        self::assertSame([], $this->requests);
        self::assertSame('Moikka', $result['value']['texts']['fi']);
        self::assertTrue($result['value']['manual']['fi']);
        self::assertSame('en', $result['value']['lang']);
    }

    /** The menu as posted: '' is "Detect automatically", a site language is kept, anything else is ''. */
    public function testFromPostReadsTheLanguageMenu(): void
    {
        Functions\when('wp_unslash')->returnArg();
        Functions\when('sanitize_textarea_field')->alias(static fn (string $text): string => trim($text));

        self::assertNull(TranslatedText::fromPost(['source' => 'Hi'])['lang']);
        self::assertSame('', TranslatedText::fromPost(['source' => 'Hi', 'lang' => ''])['lang']);
        self::assertSame('en', TranslatedText::fromPost(['source' => 'Hi', 'lang' => 'EN'])['lang']);
        self::assertSame('', TranslatedText::fromPost(['source' => 'Hi', 'lang' => 'sv'])['lang']);
        self::assertNull(TranslatedText::fromPost('Hi'));
    }
}
