<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\FollowupText;
use EventCrew\Support\Language;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\NewsletterText;
use EventCrew\Support\SiteLanguages;
use EventCrew\Tests\TestCase;

/**
 * The main text of the post-event email: the built-in text, the text on the
 * Messages page, the one-time move of the old Settings text to the Messages
 * page, and the one-time text for the next email. Options live in an array;
 * the language model is a stub of wp_remote_post.
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
        self::assertFalse($came['template']);
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

    // ---- The text on the Messages page ------------------------------------

    /** A text saved on the Messages page is used, in each language; the other variant keeps the built-in text. */
    public function testTheMessagesPageTextIsUsed(): void
    {
        $this->options[MessageTemplates::OPTION_PREFIX . 'followup_came'] = [
            'body' => [
                'source' => 'Thanks for {event}!',
                'texts' => ['fi' => 'Kiitos, {event}!', 'en' => 'Thanks for {event}!'],
                'manual' => ['fi' => false, 'en' => false],
            ],
        ];

        self::assertSame(
            ['text' => 'Kiitos, {event}!', 'next' => false, 'template' => true],
            $this->text()->text(FollowupText::CAME, Language::FINNISH)
        );
        self::assertSame(
            ['text' => 'Thanks for {event}!', 'next' => false, 'template' => true],
            $this->text()->text(FollowupText::CAME, Language::ENGLISH)
        );
        self::assertFalse($this->text()->text(FollowupText::MISSED, Language::ENGLISH)['template']);
    }

    // ---- The move of the old Settings text ---------------------------------

    /**
     * The text saved in the old Settings fields moves to the Messages page
     * once, with the greeting and the sign-off around it in each language.
     * The old option stays as a backup.
     */
    public function testTheOldSettingsTextMovesToTheMessagesPageOnce(): void
    {
        $old = [
            'source' => 'Thanks for {event}!',
            'texts' => ['fi' => 'Kiitos, {event}!', 'en' => 'Thanks for {event}!'],
            'manual' => ['fi' => true, 'en' => false],
        ];
        $this->options[FollowupText::SETTINGS_OPTIONS[FollowupText::CAME]] = $old;

        FollowupText::moveSettingsText();

        $body = $this->options[MessageTemplates::OPTION_PREFIX . 'followup_came']['body'];
        self::assertSame("Hei {first_name},\n\nKiitos, {event}!\n\nLämpimästi,\nTest Site", $body['texts']['fi']);
        self::assertSame("Hi {first_name},\n\nThanks for {event}!\n\nWarmly,\nTest Site", $body['texts']['en']);
        self::assertSame("Hi {first_name},\n\nThanks for {event}!\n\nWarmly,\nTest Site", $body['source']);
        self::assertSame(['fi' => true, 'en' => false], $body['manual']);
        self::assertSame($old, $this->options[FollowupText::SETTINGS_OPTIONS[FollowupText::CAME]], 'kept as a backup');
        self::assertArrayNotHasKey(
            MessageTemplates::OPTION_PREFIX . 'followup_missed',
            $this->options,
            'nothing was saved for the other one, so it keeps the built-in text'
        );

        // The email now reads the moved text.
        self::assertSame(
            [
                'text' => "Hi {first_name},\n\nThanks for {event}!\n\nWarmly,\nTest Site",
                'next' => false,
                'template' => true,
            ],
            $this->text()->text(FollowupText::CAME, Language::ENGLISH)
        );

        // It runs one time only: a later change on the Messages page is not overwritten.
        $this->options[MessageTemplates::OPTION_PREFIX . 'followup_came']['body'] = ['source' => 'Edited on the page.'];
        FollowupText::moveSettingsText();
        self::assertSame(
            ['source' => 'Edited on the page.'],
            $this->options[MessageTemplates::OPTION_PREFIX . 'followup_came']['body']
        );
    }

    /** A source written in Finnish gets the Finnish greeting and sign-off, not the English ones. */
    public function testTheMovedSourceGetsTheGreetingOfItsOwnLanguage(): void
    {
        $this->options[FollowupText::SETTINGS_OPTIONS[FollowupText::MISSED]] = [
            'source' => 'Kaipasimme sinua!',
            'texts' => ['fi' => 'Kaipasimme sinua!', 'en' => 'We missed you!'],
        ];

        FollowupText::moveSettingsText();

        $body = $this->options[MessageTemplates::OPTION_PREFIX . 'followup_missed']['body'];
        self::assertSame("Hei {first_name},\n\nKaipasimme sinua!\n\nLämpimästi,\nTest Site", $body['source']);
        self::assertSame("Hei {first_name},\n\nKaipasimme sinua!\n\nLämpimästi,\nTest Site", $body['texts']['fi']);
        self::assertSame("Hi {first_name},\n\nWe missed you!\n\nWarmly,\nTest Site", $body['texts']['en']);
    }

    /** A kind that already has a Messages page text keeps it; settings and subject stay. */
    public function testTheMoveKeepsATextAlreadyOnTheMessagesPage(): void
    {
        $this->options[FollowupText::SETTINGS_OPTIONS[FollowupText::CAME]] = ['source' => 'Old settings text.'];
        $this->options[FollowupText::SETTINGS_OPTIONS[FollowupText::MISSED]] = ['source' => 'Old sorry text.'];
        $this->options[MessageTemplates::OPTION_PREFIX . 'followup_came'] = ['body' => ['source' => 'Page text.']];
        $this->options[MessageTemplates::OPTION_PREFIX . 'followup_missed'] = [
            'subject' => ['source' => 'Page subject'],
            'settings' => ['x' => 1],
        ];

        FollowupText::moveSettingsText();

        self::assertSame(
            ['body' => ['source' => 'Page text.']],
            $this->options[MessageTemplates::OPTION_PREFIX . 'followup_came']
        );
        $missed = $this->options[MessageTemplates::OPTION_PREFIX . 'followup_missed'];
        self::assertSame(['source' => 'Page subject'], $missed['subject']);
        self::assertSame(['x' => 1], $missed['settings']);
        self::assertSame("Hi {first_name},\n\nOld sorry text.\n\nWarmly,\nTest Site", $missed['body']['source']);
        self::assertSame(1, $this->options[FollowupText::MOVED_OPTION]);
    }

    /** With nothing saved in the old fields, nothing is written but the done mark. */
    public function testWithNothingInTheOldFieldsTheMoveWritesNothing(): void
    {
        $this->options[FollowupText::SETTINGS_OPTIONS[FollowupText::MISSED]] = [
            'source' => '  ',
            'texts' => ['fi' => '', 'en' => ''],
        ];

        FollowupText::moveSettingsText();

        self::assertArrayNotHasKey(MessageTemplates::OPTION_PREFIX . 'followup_came', $this->options);
        self::assertArrayNotHasKey(MessageTemplates::OPTION_PREFIX . 'followup_missed', $this->options);
        self::assertSame(1, $this->options[FollowupText::MOVED_OPTION]);
    }

    // ---- The one-time text ------------------------------------------------

    public function testTheOneTimeTextComesFirst(): void
    {
        $this->options[MessageTemplates::OPTION_PREFIX . 'followup_came'] = ['body' => ['source' => 'Page text']];
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
        self::assertSame(
            ['text' => 'Kertateksti', 'next' => true, 'template' => false],
            $text->text(FollowupText::CAME, Language::FINNISH)
        );
        self::assertSame(
            ['text' => 'One-time text', 'next' => true, 'template' => false],
            $text->text(FollowupText::CAME, Language::ENGLISH)
        );
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
            ['text' => 'Thanks, see you soon!', 'next' => true, 'template' => false],
            $this->text()->text(FollowupText::CAME, Language::ENGLISH)
        );
        self::assertSame(
            ['text' => 'Kiitos, nähdään pian!', 'next' => true, 'template' => false],
            $this->text()->text(FollowupText::CAME, Language::FINNISH)
        );
    }

    /**
     * With a third site language (sv), the one-time text gets a Swedish text
     * of its own. A one-time text stored when the site had only Finnish and
     * English gives Swedish the main language's (Finnish) text.
     */
    public function testAThirdSiteLanguageGetsItsOwnOneTimeText(): void
    {
        $this->withModel();
        $this->options[SiteLanguages::OPTION] = ['fi', 'en', 'sv'];
        $this->answer = '{"lang": "fi", "en": "Thanks, see you soon!", "sv": "Tack, vi ses snart!"}';

        $this->text()->setNext(FollowupText::CAME, 'Kiitos, nähdään pian!', 9);

        self::assertSame('Tack, vi ses snart!', $this->text()->text(FollowupText::CAME, 'sv')['text']);
        self::assertSame('Thanks, see you soon!', $this->text()->text(FollowupText::CAME, Language::ENGLISH)['text']);

        $this->options[FollowupText::NEXT_OPTIONS[FollowupText::MISSED]] = [
            'fi' => 'Harmi',
            'en' => 'Sorry',
            'source' => 'en',
            'translated' => true,
            'rev' => 2,
        ];

        self::assertSame('Harmi', $this->text()->text(FollowupText::MISSED, Language::FINNISH)['text']);
        self::assertSame('Sorry', $this->text()->text(FollowupText::MISSED, Language::ENGLISH)['text']);
        self::assertSame('Harmi', $this->text()->text(FollowupText::MISSED, 'sv')['text']);
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
