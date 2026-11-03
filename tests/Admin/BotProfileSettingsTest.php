<?php

declare(strict_types=1);

namespace EventCrew\Tests\Admin;

use Brain\Monkey\Functions;
use EventCrew\Admin\BotProfileSettings;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\TranslatedText;
use EventCrew\Tests\Telegram\TelegramTestCase;

/**
 * The bot's About and Description: the migration from the four old fields,
 * and what a save sends to Telegram (English without a language code,
 * Finnish with "fi", only what changed).
 */
final class BotProfileSettingsTest extends TelegramTestCase
{
    /** The model's answer to a translation request; '' = no model in the test. */
    private string $llmAnswer = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->llmAnswer = '';
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('sanitize_textarea_field')->alias(static fn (string $value): string => trim($value));
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
    }

    private function settings(): BotProfileSettings
    {
        return new BotProfileSettings(
            $this->client(),
            new TranslatedText(new LlmClient(new Logger()), new Logger())
        );
    }

    /** Sends the model's requests to $llmAnswer and the rest to the captured Telegram stub. */
    private function withModel(string $answer): void
    {
        $this->options[AssistantSettings::URL_OPTION] = 'https://llm.example.test/v1';
        $this->options[AssistantSettings::MODEL_OPTION] = 'test-model';
        $this->llmAnswer = $answer;

        $calls = &$this->telegramCalls;
        Functions\when('wp_remote_post')->alias(function (string $url, array $args) use (&$calls): string {
            if (str_starts_with($url, 'https://llm.example.test/')) {
                return (string) json_encode(['choices' => [['message' => ['content' => $this->llmAnswer]]]]);
            }

            $calls[] = [
                'method' => substr($url, (int) strrpos($url, '/') + 1),
                'body' => (array) json_decode((string) $args['body'], true),
            ];

            return (string) json_encode(['ok' => true, 'result' => true]);
        });
    }

    /** @return list<array{string, string, string}> [method, text, language_code] per Telegram call. */
    private function sent(): array
    {
        return array_map(
            static fn (array $call): array => [
                $call['method'],
                (string) ($call['body']['short_description'] ?? $call['body']['description'] ?? ''),
                (string) ($call['body']['language_code'] ?? ''),
            ],
            $this->telegramCalls
        );
    }

    /**
     * The four old fields become two values: both texts kept and marked as
     * edited by hand, the English one as the source. Nothing is sent again.
     */
    public function testTheOldFieldsAreMigrated(): void
    {
        $this->options['eventcrew_telegram_about_en'] = 'Crew bot';
        $this->options['eventcrew_telegram_about_fi'] = 'Tiimibotti';
        $this->options['eventcrew_telegram_description_en'] = '';
        $this->options['eventcrew_telegram_description_fi'] = "Rivi 1\nRivi 2";

        BotProfileSettings::migrate();

        self::assertSame(
            [
                'source' => 'Crew bot',
                'texts' => ['fi' => 'Tiimibotti', 'en' => 'Crew bot'],
                'manual' => ['fi' => true, 'en' => true],
            ],
            $this->options['eventcrew_telegram_about']
        );
        self::assertSame(
            [
                'source' => '',
                'texts' => ['fi' => "Rivi 1\nRivi 2", 'en' => ''],
                'manual' => ['fi' => true, 'en' => false],
            ],
            $this->options['eventcrew_telegram_description']
        );
        self::assertSame('Crew bot', $this->options['eventcrew_telegram_about_en']);
        self::assertSame('1', $this->options[BotProfileSettings::MIGRATED_OPTION]);
        self::assertSame([], $this->telegramCalls);
    }

    /** The migration runs once, and never overwrites a value saved since. */
    public function testTheMigrationRunsOnce(): void
    {
        $this->options['eventcrew_telegram_about_en'] = 'Crew bot';
        BotProfileSettings::migrate();
        $this->options['eventcrew_telegram_about'] = TranslatedText::blank();

        BotProfileSettings::migrate();

        self::assertSame(TranslatedText::blank(), $this->options['eventcrew_telegram_about']);
    }

    /** A site that never set the texts gets nothing stored. */
    public function testNothingToMigrate(): void
    {
        BotProfileSettings::migrate();

        self::assertArrayNotHasKey('eventcrew_telegram_about', $this->options);
        self::assertArrayNotHasKey('eventcrew_telegram_description', $this->options);
    }

    /**
     * After the migration a save with the same texts sends nothing: the
     * texts Telegram accepted are still recorded under the old field names.
     */
    public function testAnUnchangedSaveAfterMigrationSendsNothing(): void
    {
        $this->options['eventcrew_telegram_about_en'] = 'Crew bot';
        $this->options['eventcrew_telegram_about_fi'] = 'Tiimibotti';
        $this->options[BotProfileSettings::SENT_OPTION] = [
            'telegram_about_en' => 'Crew bot',
            'telegram_about_fi' => 'Tiimibotti',
        ];
        BotProfileSettings::migrate();

        $result = $this->settings()->save([
            'about' => ['source' => 'Crew bot', 'texts' => ['fi' => 'Tiimibotti', 'en' => 'Crew bot']],
        ]);

        self::assertSame(['ok' => true, 'message' => ''], $result);
        self::assertSame([], $this->telegramCalls);
    }

    /** A translated save sends English with no language code and Finnish with "fi". */
    public function testASaveSendsEachLanguage(): void
    {
        $this->withModel('{"lang": "en", "fi": "Tervetuloa"}');

        $result = $this->settings()->save([
            'about' => ['source' => 'Welcome', 'texts' => ['fi' => '', 'en' => '']],
        ]);

        self::assertTrue($result['ok']);
        self::assertSame(
            [
                ['setMyShortDescription', 'Tervetuloa', 'fi'],
                ['setMyShortDescription', 'Welcome', ''],
            ],
            $this->sent()
        );
        self::assertSame(
            ['telegram_about_fi' => 'Tervetuloa', 'telegram_about_en' => 'Welcome'],
            $this->options[BotProfileSettings::SENT_OPTION]
        );
    }

    /**
     * No model: both languages get the text as written, it is still sent,
     * and the notice says it was not translated.
     */
    public function testWithoutAModelTheTextIsSentAsWrittenWithANotice(): void
    {
        $result = $this->settings()->save([
            'description' => ['source' => "Line 1\nLine 2", 'texts' => ['fi' => '', 'en' => '']],
        ]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('not translated', $result['message']);
        self::assertSame(
            [
                ['setMyDescription', "Line 1\nLine 2", 'fi'],
                ['setMyDescription', "Line 1\nLine 2", ''],
            ],
            $this->sent()
        );
    }

    /** A text Telegram refuses is reported and not recorded, so it is sent again next time. */
    public function testARefusedTextIsTriedAgain(): void
    {
        $this->options[TranslatedText::AUTO_OPTION] = '0';
        $this->telegramErrors['setMyShortDescription'] = 'Bad Request: too long';

        $result = $this->settings()->save([
            'about' => ['source' => 'Hi', 'texts' => ['fi' => 'Hei', 'en' => 'Hi']],
        ]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('Bad Request: too long', $result['message']);
        self::assertSame([], $this->options[BotProfileSettings::SENT_OPTION]);
    }

    /** A field that was not posted is left alone. */
    public function testAFieldNotPostedIsLeftAlone(): void
    {
        $this->options['eventcrew_telegram_about'] = [
            'source' => 'Hi',
            'texts' => ['fi' => 'Hei', 'en' => 'Hi'],
            'manual' => ['fi' => false, 'en' => false],
        ];

        $result = $this->settings()->save(null);

        self::assertSame(['ok' => true, 'message' => ''], $result);
        self::assertSame('Hei', BotProfileSettings::value('about')['texts']['fi']);
        self::assertSame([], $this->telegramCalls);
    }
}
