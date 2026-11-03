<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\FollowupText;
use EventCrew\Support\Language;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\NewsletterText;
use EventCrew\Support\TranslatedText;
use EventCrew\Tests\TestCase;

/**
 * The main text of the post-event email: the built-in text, the text in
 * Settings, and the one-time text for the next email. Options live in an
 * array; the language model is a stub of wp_remote_post.
 */
final class FollowupTextTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    private string $answer = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->answer = '';

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
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('sanitize_textarea_field')->alias(static fn (string $value): string => trim($value));
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string => (string) json_encode($value));
        Functions\when('wp_remote_post')->alias(fn (): array => ['answer' => $this->answer]);
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

    private function text(): FollowupText
    {
        return new FollowupText(new LlmClient(new Logger()), new Logger());
    }

    // ---- The built-in text ------------------------------------------------

    /** With nothing saved, the text is the email's old text, in each language. */
    public function testWithNothingSavedTheTextIsTheOldText(): void
    {
        $came = $this->text()->text(FollowupText::CAME, Language::ENGLISH);
        self::assertFalse($came['next']);
        self::assertSame(
            'Thank you for coming to {event} on {date}. It was lovely to share the evening and the dance floor'
                . ' with you, and we hope some of that warmth went home with you.'
                . "\n\n"
                . 'The next evenings are already on their way. Have a look at what is coming and secure your spot'
                . ' for your next ecstatic dance. We would love to dance with you again.',
            $came['text']
        );

        $missed = $this->text()->text(FollowupText::MISSED, Language::ENGLISH)['text'];
        self::assertStringStartsWith('We missed you at {event} on {date}. We are sorry', $missed);
        self::assertStringEndsWith('The next ones are below. We hope to see you soon.', $missed);

        self::assertStringStartsWith(
            'Kiitos, että olit mukana',
            $this->text()->text(FollowupText::CAME, Language::FINNISH)['text']
        );
        self::assertStringStartsWith(
            'Kaipasimme sinua',
            $this->text()->text(FollowupText::MISSED, Language::FINNISH)['text']
        );
    }

    public function testFillReplacesTheEventAndTheDate(): void
    {
        self::assertSame(
            'See you at Dance {x} on 14.11.2026, Dance!',
            FollowupText::fill('See you at {event} on {date}, Dance!', 'Dance {x}', '14.11.2026')
        );
    }

    public function testSettingsShowTheBuiltInTextUntilOneIsSaved(): void
    {
        self::assertSame(FollowupText::defaultValue(FollowupText::CAME), FollowupText::settings(FollowupText::CAME));

        $this->options[FollowupText::SETTINGS_OPTIONS[FollowupText::CAME]] = TranslatedText::blank();
        self::assertSame(
            FollowupText::defaultValue(FollowupText::CAME),
            FollowupText::settings(FollowupText::CAME),
            'an empty stored value is the built-in text'
        );
    }

    // ---- The text in Settings ---------------------------------------------

    public function testTheTextInSettingsIsUsed(): void
    {
        $this->options[FollowupText::SETTINGS_OPTIONS[FollowupText::CAME]] = [
            'source' => 'Thanks for {event}!',
            'texts' => ['fi' => 'Kiitos, {event}!', 'en' => 'Thanks for {event}!'],
            'manual' => ['fi' => false, 'en' => false],
        ];

        self::assertSame(
            ['text' => 'Kiitos, {event}!', 'next' => false],
            $this->text()->text(FollowupText::CAME, Language::FINNISH)
        );
        self::assertSame(
            ['text' => 'Thanks for {event}!', 'next' => false],
            $this->text()->text(FollowupText::CAME, Language::ENGLISH)
        );
        self::assertStringStartsWith(
            'We missed you at',
            $this->text()->text(FollowupText::MISSED, Language::ENGLISH)['text'],
            'the other text is still the built-in one'
        );
    }

    /** An empty language falls back to the source; no text at all to the built-in text. */
    public function testAnEmptyLanguageFallsBackToTheSource(): void
    {
        $this->options[FollowupText::SETTINGS_OPTIONS[FollowupText::MISSED]] = [
            'source' => 'Sorry we missed you.',
            'texts' => ['fi' => '', 'en' => ''],
            'manual' => ['fi' => false, 'en' => false],
        ];

        self::assertSame('Sorry we missed you.', $this->text()->text(FollowupText::MISSED, Language::FINNISH)['text']);

        $this->options[FollowupText::SETTINGS_OPTIONS[FollowupText::MISSED]] = [
            'source' => '   ',
            'texts' => ['fi' => '', 'en' => ''],
            'manual' => ['fi' => false, 'en' => false],
        ];

        self::assertStringStartsWith(
            'Kaipasimme sinua',
            $this->text()->text(FollowupText::MISSED, Language::FINNISH)['text']
        );
    }

    /** The built-in text posted back unchanged is not stored, nor is an empty one. */
    public function testSavingTheBuiltInTextOrNothingStoresNothing(): void
    {
        $default = FollowupText::defaultValue(FollowupText::CAME);
        $error = $this->text()->saveSettings([
            FollowupText::CAME => ['source' => $default['source'], 'texts' => $default['texts']],
        ]);

        self::assertSame('', $error);
        self::assertArrayNotHasKey(FollowupText::SETTINGS_OPTIONS[FollowupText::CAME], $this->options);

        $this->options[FollowupText::SETTINGS_OPTIONS[FollowupText::MISSED]] = [
            'source' => 'Old',
            'texts' => ['fi' => 'Vanha', 'en' => 'Old'],
            'manual' => ['fi' => false, 'en' => false],
        ];
        $this->text()->saveSettings([
            FollowupText::MISSED => ['source' => '', 'texts' => ['fi' => '', 'en' => '']],
        ]);

        self::assertArrayNotHasKey(FollowupText::SETTINGS_OPTIONS[FollowupText::MISSED], $this->options);
    }

    /** A new text is translated and stored; a field not posted is left alone. */
    public function testANewTextIsTranslatedAndStored(): void
    {
        $this->withModel();
        $this->answer = '{"lang": "en", "fi": "Kiitos, että tulit {event}-iltaan."}';
        $this->options[FollowupText::SETTINGS_OPTIONS[FollowupText::MISSED]] = ['source' => 'Kept'];

        $error = $this->text()->saveSettings([
            FollowupText::CAME => [
                'source' => 'Thanks for coming to {event}.',
                'texts' => FollowupText::defaultValue(FollowupText::CAME)['texts'],
            ],
        ]);

        self::assertSame('', $error);
        $stored = $this->options[FollowupText::SETTINGS_OPTIONS[FollowupText::CAME]];
        self::assertSame('Thanks for coming to {event}.', $stored['source']);
        self::assertSame('Kiitos, että tulit {event}-iltaan.', $stored['texts']['fi']);
        self::assertSame('Thanks for coming to {event}.', $stored['texts']['en']);
        self::assertSame(['source' => 'Kept'], $this->options[FollowupText::SETTINGS_OPTIONS[FollowupText::MISSED]]);
    }

    /**
     * With the translation switched off, the source is stored, an emptied
     * language falls back to it, and the error says the translation is off.
     */
    public function testWithTranslationOffAnEmptiedLanguageUsesTheSource(): void
    {
        $this->options[TranslatedText::AUTO_OPTION] = '0';

        $error = $this->text()->saveSettings([
            FollowupText::CAME => ['source' => 'Thanks!', 'texts' => ['fi' => '', 'en' => '']],
        ]);

        self::assertSame(TranslatedText::ERROR_OFF, $error);
        self::assertSame(
            ['text' => 'Thanks!', 'next' => false],
            $this->text()->text(FollowupText::CAME, Language::FINNISH)
        );
        self::assertSame(
            ['text' => 'Thanks!', 'next' => false],
            $this->text()->text(FollowupText::CAME, Language::ENGLISH)
        );
    }

    // ---- The one-time text ------------------------------------------------

    public function testTheOneTimeTextComesFirst(): void
    {
        $this->options[FollowupText::SETTINGS_OPTIONS[FollowupText::CAME]] = ['source' => 'Settings text'];
        $this->options[FollowupText::NEXT_OPTIONS[FollowupText::CAME]] = [
            'fi' => 'Kertateksti',
            'en' => 'One-time text',
            'source' => 'en',
            'translated' => true,
            'by' => 9,
            'at' => '2026-11-10 10:00:00',
            'rev' => 3,
        ];

        $text = $this->text();
        self::assertTrue($text->hasNext(FollowupText::CAME));
        self::assertFalse($text->hasNext(FollowupText::MISSED));
        self::assertSame(['text' => 'Kertateksti', 'next' => true], $text->text(FollowupText::CAME, Language::FINNISH));
        self::assertSame(['text' => 'One-time text', 'next' => true], $text->text(FollowupText::CAME, Language::ENGLISH));
        self::assertSame([FollowupText::CAME => 3, FollowupText::MISSED => 0], $text->revisions());
    }

    /** No language model: the one-time text is stored as written in both languages. */
    public function testSetNextWithoutAModelKeepsTheTextAsWritten(): void
    {
        $result = $this->text()->setNext(FollowupText::MISSED, 'See you soon!', 9);

        self::assertTrue($result['saved']);
        self::assertFalse($result['translated']);
        self::assertSame(LlmClient::ERROR_NOT_CONFIGURED, $result['error']);
        $stored = $this->options[FollowupText::NEXT_OPTIONS[FollowupText::MISSED]];
        self::assertSame('See you soon!', $stored['fi']);
        self::assertSame('See you soon!', $stored['en']);
        self::assertSame(9, $stored['by']);
    }

    public function testSetNextIsTranslatedLikeTheNewsletterText(): void
    {
        $this->withModel();
        $this->answer = '{"lang": "fi", "en": "Thanks, see you soon!"}';

        $result = $this->text()->setNext(FollowupText::CAME, 'Kiitos, nähdään pian!', 9);

        self::assertTrue($result['translated']);
        self::assertSame(
            ['text' => 'Thanks, see you soon!', 'next' => true],
            $this->text()->text(FollowupText::CAME, Language::ENGLISH)
        );
        self::assertSame(
            ['text' => 'Kiitos, nähdään pian!', 'next' => true],
            $this->text()->text(FollowupText::CAME, Language::FINNISH)
        );
    }

    public function testAnUnknownVariantIsNotSaved(): void
    {
        self::assertFalse($this->text()->setNext('maybe', 'Hello', 9)['saved']);
        self::assertSame([], array_intersect_key($this->options, array_flip(FollowupText::NEXT_OPTIONS)));
    }

    public function testClearNextRemovesOneOrBoth(): void
    {
        $text = $this->text();
        $text->setNext(FollowupText::CAME, 'A', 9);
        $text->setNext(FollowupText::MISSED, 'B', 9);

        $text->clearNext(FollowupText::CAME);
        self::assertFalse($text->hasNext(FollowupText::CAME));
        self::assertTrue($text->hasNext(FollowupText::MISSED));

        $text->clearNext();
        self::assertFalse($text->hasNext(FollowupText::MISSED));
    }

    /** Only the revision a send started with is removed; a text saved during it stays. */
    public function testClearIfRevisionsKeepsANewerText(): void
    {
        $text = $this->text();
        $text->setNext(FollowupText::CAME, 'A', 9);
        $text->setNext(FollowupText::MISSED, 'B', 9);
        $started = $text->revisions();

        $text->setNext(FollowupText::MISSED, 'B, changed', 9);
        $text->clearIfRevisions($started);

        self::assertFalse($text->hasNext(FollowupText::CAME));
        self::assertSame('B, changed', $text->next(FollowupText::MISSED)['en'] ?? null);
        self::assertSame(NewsletterText::MAX_LENGTH, FollowupText::MAX_LENGTH);
    }
}
