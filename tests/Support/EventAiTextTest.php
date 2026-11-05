<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use EventCrew\Admin\AiPromptsPage;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\EventAiText;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\NewsletterWriter;
use EventCrew\Tests\Telegram\FakeEvents;
use EventCrew\Tests\TestCase;

/**
 * The texts the language model writes for each event: a message template's
 * own text (the "AI for each event" tick) and the organizers' placeholders.
 * The model server is a stub of wp_remote_post that answers from $answers in
 * order and records each request; nothing leaves the test. Options live in an
 * array, EventMesh events are fakes, and TestCase pins today to 2026-07-20.
 */
final class EventAiTextTest extends TestCase
{
    use FakeEvents;

    private const PROMPT = 'Write a short reminder for the crew of this event.';

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var list<string|int> Model answers in order; an int is an HTTP error status. */
    private array $answers = [];

    /** @var list<array<string, mixed>> The decoded model request bodies. */
    private array $requests = [];

    /** @var array<string, array<string, mixed>> What the templates filter lists. */
    private array $templates = [];

    /** @var array<int, mixed> What the DJ filter returns, per event id. */
    private array $djs = [];

    private bool $cron = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeEvents();
        $this->options = [
            AssistantSettings::URL_OPTION => 'https://llm.example.test/v1',
            AssistantSettings::MODEL_OPTION => 'test-model',
        ];
        $this->answers = [];
        $this->requests = [];
        $this->djs = [];
        $this->cron = true;
        $this->templates = [
            'reminder' => [
                EventAiText::SETTING_AI_EACH_EVENT => true,
                EventAiText::SETTING_AI_PROMPT => self::PROMPT,
                EventAiText::SETTING_LABEL => 'Crew reminder',
            ],
            'welcome' => [
                EventAiText::SETTING_AI_EACH_EVENT => false,
                EventAiText::SETTING_AI_PROMPT => 'Not used.',
            ],
        ];

        Functions\when('get_the_title')->justReturn('');
        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
        Functions\when('sanitize_textarea_field')->alias(static fn (string $value): string => trim($value));
        Functions\when('wp_doing_cron')->alias(fn (): bool => $this->cron);
        Functions\when('wp_remote_post')->alias(function (string $url, array $args): array {
            $this->requests[] = (array) json_decode((string) $args['body'], true);

            return ['answer' => array_shift($this->answers) ?? ''];
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(
            static fn (mixed $response): int => is_array($response) && is_int($response['answer'])
                ? $response['answer']
                : 200
        );
        Functions\when('wp_remote_retrieve_body')->alias(
            static fn (mixed $response): string => (string) json_encode(
                ['choices' => [['message' => ['content' => (string) $response['answer']]]]]
            )
        );
        Filters\expectApplied(EventAiText::TEMPLATES_FILTER)->zeroOrMoreTimes()->andReturnUsing(
            fn (): array => $this->templates
        );
        Filters\expectApplied(NewsletterWriter::DJ_FILTER)->zeroOrMoreTimes()->andReturnUsing(
            fn (mixed $value, int $postId): mixed => $this->djs[$postId] ?? []
        );
    }

    private function service(): EventAiText
    {
        return new EventAiText(new LlmClient(new Logger()), new Logger());
    }

    /** Events yesterday, tomorrow and in three days; today is 2026-07-20. */
    private function events(): void
    {
        $this->event(99, '2026-07-19', 'Past Party');
        $this->event(100, '2026-07-21', 'Party', [], 'A night of social dancing.');
        $this->event(101, '2026-07-23', 'Later Party');
        $this->djs[100] = [['name' => 'DJ Anna', 'description' => 'Anna plays swing.']];
    }

    private static function answer(string $fi, string $en): string
    {
        return (string) json_encode(['fi' => $fi, 'en' => $en]);
    }

    /** @return array<string, mixed> */
    private function stored(int $eventId, string $key): array
    {
        return $this->options[EventAiText::TEXTS_OPTION][$eventId]['items'][$key] ?? [];
    }

    public function testTheTextsAreWrittenTheDayBeforeTheEvent(): void
    {
        $this->events();
        $this->answers[] = self::answer('Huomenna tanssitaan.', 'Tomorrow we dance.');

        $this->service()->run();

        // Only tomorrow's event: not yesterday's, not the one in three days.
        self::assertCount(1, $this->requests);
        self::assertSame([100], array_keys($this->options[EventAiText::TEXTS_OPTION]));
        self::assertSame(['fi' => 'Huomenna tanssitaan.', 'en' => 'Tomorrow we dance.'], $this->stored(100, 'reminder')['ai']);
        self::assertSame('Huomenna tanssitaan.', EventAiText::text('reminder', 100, 'fi'));
        self::assertSame('Tomorrow we dance.', EventAiText::text('reminder', 100, 'en'));
        self::assertNull(EventAiText::text('reminder', 101, 'fi'));

        $system = (string) $this->requests[0]['messages'][0]['content'];
        $user = (string) $this->requests[0]['messages'][1]['content'];
        self::assertStringStartsWith(self::PROMPT, $system);
        self::assertStringContainsString('Finnish (fi), English (en)', $system);
        self::assertStringContainsString('{"fi":"the Finnish text","en":"the English text"}', $system);
        self::assertStringContainsString('21 July 2026, 21:00', $user);
        self::assertStringContainsString('Event: Party', $user);
        self::assertStringContainsString('- DJ Anna: Anna plays swing.', $user);
        self::assertStringContainsString('A night of social dancing.', $user);
    }

    public function testTodaysEventIsWrittenWhenTheDayBeforeWasMissed(): void
    {
        $this->event(100, '2026-07-20', 'Party Tonight');
        $this->answers[] = self::answer('Tänään.', 'Tonight.');

        $this->service()->run();

        self::assertSame('Tonight.', EventAiText::text('reminder', 100, 'en'));
    }

    public function testAWrittenTextIsNotAskedAgain(): void
    {
        $this->events();
        $this->answers[] = self::answer('Huomenna.', 'Tomorrow.');

        $this->service()->run();
        $this->service()->run();

        self::assertCount(1, $this->requests);
    }

    public function testNothingIsWrittenOutsideABackgroundRunOrWithoutAModel(): void
    {
        $this->events();
        $this->cron = false;

        $this->service()->run();

        $this->cron = true;
        unset($this->options[AssistantSettings::URL_OPTION]);

        $this->service()->run();

        self::assertSame([], $this->requests);
        self::assertArrayNotHasKey(EventAiText::TEXTS_OPTION, $this->options);
    }

    public function testNothingIsAskedWhenNoTemplateHasTheTickAndThereAreNoPlaceholders(): void
    {
        $this->events();
        $this->templates['reminder'][EventAiText::SETTING_AI_EACH_EVENT] = false;

        $this->service()->run();

        self::assertSame([], $this->requests);
        self::assertSame([], EventAiText::activeTemplates());
    }

    public function testATextChangedByHandWinsOverTheAiText(): void
    {
        $this->events();
        $this->answers[] = self::answer('Huomenna.', 'Tomorrow.');
        $this->service()->run();

        // The English box is sent back unchanged: that is not a change.
        $result = EventAiText::saveForm(100, 'reminder', false, ['fi' => 'Oma teksti.', 'en' => 'Tomorrow.'], 7);

        self::assertSame(EventAiText::SAVED, $result);
        self::assertSame('Oma teksti.', EventAiText::text('reminder', 100, 'fi'));
        self::assertSame('Tomorrow.', EventAiText::text('reminder', 100, 'en'));
        self::assertSame(['fi' => 'Oma teksti.'], $this->stored(100, 'reminder')['edited']);
        self::assertSame(7, $this->stored(100, 'reminder')['edited_by']);

        $item = EventAiText::overview()[0]['items'][0];
        self::assertSame('Crew reminder', $item['label']);
        self::assertSame(['fi' => true, 'en' => false], $item['edited']);
        self::assertSame(['fi' => 'Oma teksti.', 'en' => 'Tomorrow.'], $item['texts']);
        self::assertFalse($item['failed']);

        // A later run keeps the change, and asks nothing.
        $this->service()->run();
        self::assertCount(1, $this->requests);
        self::assertSame('Oma teksti.', EventAiText::text('reminder', 100, 'fi'));

        self::assertSame(EventAiText::BACK_TO_AI, EventAiText::saveForm(100, 'reminder', true, []));
        self::assertSame('Huomenna.', EventAiText::text('reminder', 100, 'fi'));
    }

    public function testTheWpAdminFormSavesTheSameWay(): void
    {
        $this->events();
        $this->answers[] = self::answer('Huomenna.', 'Tomorrow.');
        $this->service()->run();
        $page = new AiPromptsPage();

        $code = $page->applyEventText(
            ['event_id' => '100', 'key' => 'reminder', 'op' => 'save', 'texts' => ['fi' => 'Muutettu.', 'en' => 'Changed.']]
        );

        self::assertSame(EventAiText::SAVED, $code);
        self::assertSame('Changed.', EventAiText::text('reminder', 100, 'en'));
        self::assertSame(
            EventAiText::NOT_FOUND,
            $page->applyEventText(['event_id' => '100', 'key' => 'unknown', 'texts' => ['fi' => 'X']])
        );
        self::assertSame(
            EventAiText::NOT_FOUND,
            $page->applyEventText(['event_id' => '555', 'key' => 'reminder', 'texts' => ['fi' => 'X']])
        );
    }

    /** Messages > Coming events: "Write the AI texts now" asks at once, from a web request. */
    public function testWriteNowWritesTheTextsAndTheDayBeforeRunLeavesThem(): void
    {
        $this->events();
        $this->cron = false;
        $this->answers[] = self::answer('Myöhemmin tanssitaan.', 'Later we dance.');
        $type = '';

        // Event 101 is in three days: the day-before run has not reached it.
        $notice = (new AiPromptsPage($this->service()))->applyRewrite(['event_id' => '101'], $type);

        self::assertSame('success', $type);
        self::assertSame('The AI wrote 1 new text.', $notice);
        self::assertSame('Later we dance.', EventAiText::text('reminder', 101, 'en'));
        self::assertSame(1, $this->stored(101, 'reminder')['tries']);

        // The day before, the run finds it written and asks nothing.
        $this->cron = true;
        self::assertSame(0, $this->service()->writeFor(101));
        self::assertCount(1, $this->requests);
        self::assertSame('Later we dance.', EventAiText::text('reminder', 101, 'en'));
    }

    public function testWriteAgainWhenTheAiFailsKeepsTheOldText(): void
    {
        $this->events();
        $this->answers = [self::answer('Huomenna.', 'Tomorrow.'), 500];
        $this->service()->run();
        $type = '';

        $notice = (new AiPromptsPage($this->service()))->applyRewrite(
            ['event_id' => '100', 'key' => 'reminder'],
            $type
        );

        self::assertSame('error', $type);
        self::assertSame('The AI could not write 1 text; it keeps its old text.', $notice);
        self::assertCount(2, $this->requests);
        self::assertSame('Huomenna.', EventAiText::text('reminder', 100, 'fi'));
        self::assertSame(['fi' => 'Huomenna.', 'en' => 'Tomorrow.'], $this->stored(100, 'reminder')['ai']);
    }

    /** The page asks "This replaces your edit." first; after that, the edit is gone. */
    public function testWriteAgainReplacesAnEdit(): void
    {
        $this->events();
        $this->answers = [self::answer('Huomenna.', 'Tomorrow.'), self::answer('Taas huomenna.', 'Tomorrow again.')];
        $this->service()->run();
        EventAiText::saveForm(100, 'reminder', false, ['fi' => 'Oma teksti.', 'en' => 'Tomorrow.'], 7);
        $type = '';

        (new AiPromptsPage($this->service()))->applyRewrite(['event_id' => '100', 'key' => 'reminder'], $type);

        self::assertSame('success', $type);
        self::assertSame('Taas huomenna.', EventAiText::text('reminder', 100, 'fi'));
        self::assertSame([], $this->stored(100, 'reminder')['edited']);
        self::assertSame(0, $this->stored(100, 'reminder')['edited_by']);
        self::assertSame(['fi' => false, 'en' => false], EventAiText::overview()[0]['items'][0]['edited']);
    }

    public function testWriteNowAsksNothingWithoutAModelOrForAnUnknownEvent(): void
    {
        $this->events();
        $page = new AiPromptsPage($this->service());
        $type = '';

        $page->applyRewrite(['event_id' => '555'], $type);
        self::assertSame('error', $type);

        $type = '';
        $page->applyRewrite(['event_id' => '100', 'key' => 'welcome'], $type);
        self::assertSame('error', $type, 'A template with the tick off is not written.');

        unset($this->options[AssistantSettings::URL_OPTION]);
        $type = '';
        $notice = $page->applyRewrite(['event_id' => '100'], $type);

        self::assertSame('error', $type);
        self::assertStringContainsString('the AI is not set up', $notice);
        self::assertSame([], $this->requests);
        self::assertArrayNotHasKey(EventAiText::TEXTS_OPTION, $this->options);
    }

    /**
     * @return array<string, array{0: string|int}>
     */
    public static function failures(): array
    {
        return [
            'an HTTP error' => [500],
            'not JSON' => ['Tomorrow we dance.'],
            'a language missing' => [(string) json_encode(['fi' => 'Huomenna.'])],
            'a crew word' => [self::answer('Huomenna.', 'Every vol' . 'unteer is welcome.')],
            'a link' => [self::answer('Huomenna.', 'See https://site.example.test.')],
        ];
    }

    /**
     * @dataProvider failures
     */
    public function testWhenTheAiFailsTheNormalTextIsUsed(string|int $answer): void
    {
        $this->events();
        $this->answers[] = $answer;

        $this->service()->run();

        self::assertNull(EventAiText::text('reminder', 100, 'fi'));
        self::assertNull(EventAiText::text('reminder', 100, 'en'));
        self::assertSame([], $this->stored(100, 'reminder')['ai']);
        self::assertTrue(EventAiText::overview()[0]['items'][0]['failed']);
    }

    public function testAFailedTextIsAskedAgainAtMostMaxTriesTimes(): void
    {
        $this->events();
        $this->answers = [500, 500, 500, self::answer('Huomenna.', 'Tomorrow.')];

        for ($run = 0; $run < EventAiText::MAX_TRIES + 1; $run++) {
            $this->service()->run();
        }

        self::assertCount(EventAiText::MAX_TRIES, $this->requests);
        self::assertSame(EventAiText::MAX_TRIES, $this->stored(100, 'reminder')['tries']);
        self::assertNull(EventAiText::text('reminder', 100, 'fi'));
    }

    public function testAThrowableIsAFailure(): void
    {
        $this->events();
        Functions\when('wp_remote_post')->alias(static function (): never {
            throw new \RuntimeException('boom');
        });

        $this->service()->run();

        self::assertNull(EventAiText::text('reminder', 100, 'fi'));
    }

    public function testATemplateWithTheTickOffGetsNoText(): void
    {
        $this->events();
        $this->answers[] = self::answer('Huomenna.', 'Tomorrow.');
        $this->service()->run();

        $off = [EventAiText::SETTING_AI_EACH_EVENT => false, EventAiText::SETTING_AI_PROMPT => self::PROMPT];
        $empty = [EventAiText::SETTING_AI_EACH_EVENT => true, EventAiText::SETTING_AI_PROMPT => '  '];

        self::assertNull(EventAiText::text('reminder', 100, 'fi', $off));
        self::assertNull(EventAiText::text('reminder', 100, 'fi', $empty));
        self::assertSame('Huomenna.', EventAiText::text('reminder', 100, 'fi', $this->templates['reminder']));
        self::assertNull($this->service()->generate('welcome', 100));
        self::assertCount(1, $this->requests);
    }

    public function testGenerateWritesOneTemplatesTextAndStoresNothing(): void
    {
        $this->events();
        $this->answers[] = self::answer('Tervetuloa.', 'Welcome.');

        $texts = $this->service()->generate('any_kind', 101, [
            EventAiText::SETTING_AI_EACH_EVENT => true,
            EventAiText::SETTING_AI_PROMPT => 'Write a welcome.',
        ]);

        self::assertSame(['fi' => 'Tervetuloa.', 'en' => 'Welcome.'], $texts);
        self::assertStringStartsWith('Write a welcome.', (string) $this->requests[0]['messages'][0]['content']);
        self::assertArrayNotHasKey(EventAiText::TEXTS_OPTION, $this->options);
    }

    public function testOnlyValidPlaceholdersAreKept(): void
    {
        $kept = EventAiText::savePlaceholders([
            ['name' => '{DJ_Intro}', 'prompt' => 'Introduce the DJ.', 'fallback' => 'Great music.'],
            ['name' => 'dj_intro', 'prompt' => 'A second one with the same name.'],
            ['name' => '9lives', 'prompt' => 'Starts with a digit.'],
            ['name' => 'no-dash', 'prompt' => 'Has a dash.'],
            ['name' => 'no_prompt', 'prompt' => '  '],
            ['name' => '', 'prompt' => 'No name.'],
            'not a row',
        ]);

        self::assertSame([['name' => 'dj_intro', 'prompt' => 'Introduce the DJ.', 'fallback' => 'Great music.']], $kept);
        self::assertSame($kept, EventAiText::placeholders());

        $page = new AiPromptsPage();
        self::assertStringContainsString(
            'left out',
            $page->applyPlaceholders(['placeholders' => [['name' => 'ok'], ['name' => 'good', 'prompt' => 'P.']]])
        );
        self::assertSame('good', EventAiText::placeholders()[0]['name']);
    }

    public function testAPlaceholderIsWrittenForEachEventAndFilledIn(): void
    {
        $this->events();
        $this->templates = [];
        EventAiText::savePlaceholders([
            ['name' => 'dj_intro', 'prompt' => 'Introduce the DJ in one sentence.', 'fallback' => 'Good music.'],
        ]);
        $this->answers[] = self::answer('Anna soittaa swingiä.', 'Anna plays swing.');

        $this->service()->run();

        self::assertCount(1, $this->requests);
        self::assertStringStartsWith('Introduce the DJ in one sentence.', (string) $this->requests[0]['messages'][0]['content']);
        self::assertSame(
            'Tonight: Anna plays swing. {other}',
            EventAiText::fill('Tonight: {dj_intro} {other}', 100, 'en')
        );
        self::assertSame('Tänään: Anna soittaa swingiä.', EventAiText::fill('Tänään: {dj_intro}', 100, 'fi'));
        // No text for that event: the fallback.
        self::assertSame('Tonight: Good music.', EventAiText::fill('Tonight: {dj_intro}', 101, 'en'));
        self::assertSame('{dj_intro}', EventAiText::overview()[0]['items'][0]['label']);

        // A placeholder's text can be changed by hand too.
        EventAiText::saveForm(100, EventAiText::PLACEHOLDER_PREFIX . 'dj_intro', false, ['en' => 'Anna, live.']);
        self::assertSame('Tonight: Anna, live.', EventAiText::fill('Tonight: {dj_intro}', 100, 'en'));
    }

    public function testAFailedPlaceholderUsesItsFallback(): void
    {
        $this->events();
        $this->templates = [];
        EventAiText::savePlaceholders([['name' => 'dj_intro', 'prompt' => 'Introduce the DJ.', 'fallback' => 'Good music.']]);
        $this->answers[] = 500;

        $this->service()->run();

        self::assertSame('Tonight: Good music.', EventAiText::fill('Tonight: {dj_intro}', 100, 'en'));
    }

    public function testParseReadsTheAnswerRobustly(): void
    {
        $plain = self::answer('Hei.', 'Hi.');

        self::assertSame(['fi' => 'Hei.', 'en' => 'Hi.'], EventAiText::parse($plain));
        self::assertSame(['fi' => 'Hei.', 'en' => 'Hi.'], EventAiText::parse("```json\n" . $plain . "\n```"));
        self::assertSame(['fi' => 'Hei.', 'en' => 'Hi.'], EventAiText::parse('Here it is: ' . $plain . ' Done.'));
        self::assertNull(EventAiText::parse(self::answer('Hei.', '')));
        self::assertNull(EventAiText::parse(self::answer('Hei.', str_repeat('x', EventAiText::MAX_TEXT + 1))));
        self::assertNull(EventAiText::parse('[]'));
    }
}
