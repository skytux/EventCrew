<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\FollowupText;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\SiteLanguages;
use EventCrew\Support\TranslatedText;
use EventCrew\Support\TranslationRegistry;
use EventCrew\Support\TranslationRow;
use EventCrew\Tests\TestCase;

/**
 * The rows of the Translations page: where they come from, the search and
 * filters, saving one row, and "Translate missing" for a batch of rows
 * (translateRows(): one request for many texts). The model is a stub of
 * wp_remote_post; nothing leaves the test.
 */
final class TranslationRegistryTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var list<string> */
    private array $requests = [];

    /** @var list<string> The model's answers, one per request, in order; the last one repeats. */
    private array $answers = [];

    private int $status = 200;

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->requests = [];
        $this->answers = [];
        $this->status = 200;

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
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string => (string) json_encode($value));
        Functions\when('wp_remote_post')->alias(function (string $url, array $args): array {
            $this->requests[] = (string) $args['body'];
            $answer = $this->answers[count($this->requests) - 1] ?? (string) end($this->answers);

            return ['answer' => $answer];
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(fn (): int => $this->status);
        Functions\when('wp_remote_retrieve_body')->alias(
            static fn (array $response): string => (string) json_encode(
                ['choices' => [['message' => ['content' => $response['answer']]]]]
            )
        );
    }

    private function withModel(string ...$answers): void
    {
        $this->options[AssistantSettings::URL_OPTION] = 'https://llm.example.test/v1';
        $this->options[AssistantSettings::MODEL_OPTION] = 'test-model';
        $this->answers = $answers;
    }

    private function registry(): TranslationRegistry
    {
        return new TranslationRegistry(new TranslatedText(new LlmClient(new Logger()), new Logger()));
    }

    /**
     * A row stored in $this->options under "row.$id".
     *
     * @param array<string, mixed> $value
     */
    private function row(
        string $id,
        array $value,
        string $group = TranslationRow::GROUP_TEXTS,
        array $required = []
    ): TranslationRow {
        $this->options['row.' . $id] ??= $value;

        return new TranslationRow(
            $id,
            'Label ' . $id,
            $group,
            (array) $this->options['row.' . $id],
            function (array $value) use ($id): void {
                $this->options['row.' . $id] = $value;
            },
            required: $required
        );
    }

    /** A registry with one row "moved" that must keep {email}. */
    private function withRequired(): TranslationRegistry
    {
        $registry = $this->registry();
        $registry->add(fn (): array => [
            $this->row(
                'moved',
                ['source' => 'Now {email}.', 'lang' => 'en', 'texts' => ['fi' => '', 'en' => 'Now {email}.']],
                TranslationRow::GROUP_EMAILS,
                ['{email}']
            ),
        ]);

        return $registry;
    }

    /** A posted text without a required placeholder is not stored and the model is not asked. */
    public function testARowWithoutARequiredPlaceholderIsNotSaved(): void
    {
        $this->withModel('{"lang": "en", "fi": "Nyt {email}."}');
        $registry = $this->withRequired();
        $registry->find('moved'); // Reading the rows stores the row's first value.
        $before = $this->stored('moved');

        $result = $registry->saveRow('moved', 'Now {email}.', ['fi' => 'Nyt uusi osoite.']);

        self::assertSame(['found' => true, 'error' => '', 'missing' => ['{email}']], $result);
        self::assertSame([], $this->requests);
        self::assertSame($before, $this->stored('moved'));
    }

    /** A translation that drops a required placeholder is not stored, on save and on "Translate missing". */
    public function testATranslationWithoutARequiredPlaceholderIsNotStored(): void
    {
        $this->withModel(
            '{"lang": "en", "fi": "Nyt uusi osoite."}',
            '[{"id": "1", "lang": "en", "fi": "Nyt uusi osoite."}]'
        );
        $before = $this->withRequired()->find('moved')?->value;

        $result = $this->withRequired()->saveRow('moved', 'Now {email}.', []);

        self::assertSame(['{email}'], $result['missing']);
        self::assertSame($before, TranslatedText::normalize($this->stored('moved')));

        $result = $this->withRequired()->translateRows(['moved']);

        self::assertSame(['done' => [], 'failed' => ['moved'], 'error' => ''], $result);
        self::assertSame('', $this->stored('moved')['texts']['fi']);
    }

    /** @return array<string, mixed> */
    private function stored(string $id): array
    {
        return (array) $this->options['row.' . $id];
    }

    /** A registry with the rows a, b (Emails) and c, built from $this->options every time it is read. */
    private function sample(): TranslationRegistry
    {
        $this->options['row.a'] ??= [
            'source' => 'Hello',
            'lang' => 'en',
            'texts' => ['fi' => 'Hei', 'en' => 'Hello'],
            'manual' => ['fi' => true, 'en' => false],
        ];
        $this->options['row.b'] ??= ['source' => 'Thank you', 'texts' => ['fi' => '', 'en' => 'Thank you']];
        $this->options['row.c'] ??= ['source' => '', 'texts' => ['fi' => '', 'en' => '']];

        $registry = $this->registry();
        $registry->add(fn (): array => [$this->row('a', []), $this->row('b', [], TranslationRow::GROUP_EMAILS)]);
        $registry->add(fn (): array => [$this->row('c', [])]);

        return $registry;
    }

    /** @param list<TranslationRow> $rows @return list<string> */
    private static function ids(array $rows): array
    {
        return array_map(static fn (TranslationRow $row): string => $row->id, $rows);
    }

    public function testRowsComeFromEveryProviderInOrder(): void
    {
        $registry = $this->sample();
        $registry->add(fn (): array => [$this->row('a', ['source' => 'Other'])]);

        self::assertSame(['a', 'b', 'c'], self::ids($registry->rows()), 'a taken id is left out');
        self::assertSame('Hei', $registry->find('a')?->value['texts']['fi']);
        self::assertNull($registry->find('zzz'));
        self::assertSame('Emails', $registry->find('b')?->groupLabel());
    }

    /** Code outside the plugin can add rows with the filter; anything not a row is dropped. */
    public function testTheFilterCanAddRows(): void
    {
        $extra = $this->row('extra', ['source' => 'Extra']);
        Functions\when('apply_filters')->alias(
            static fn (string $name, array $rows): array => TranslationRegistry::FILTER === $name
                ? [...$rows, $extra, 'not a row']
                : $rows
        );

        self::assertSame(['a', 'b', 'c', 'extra'], self::ids($this->sample()->rows()));
    }

    public function testSearchAndFilters(): void
    {
        $rows = $this->sample()->rows();

        self::assertSame(['a', 'b', 'c'], self::ids(TranslationRegistry::filter($rows, '', '')));
        self::assertSame(['a'], self::ids(TranslationRegistry::filter($rows, 'HEI', '')), 'a language text, any case');
        self::assertSame(['b'], self::ids(TranslationRegistry::filter($rows, 'thank', '')), 'the source');
        self::assertSame(['c'], self::ids(TranslationRegistry::filter($rows, 'label c', '')), 'the label');
        self::assertSame(['b'], self::ids(TranslationRegistry::filter($rows, '', TranslationRegistry::SHOW_MISSING)));
        self::assertSame(['a'], self::ids(TranslationRegistry::filter($rows, '', TranslationRegistry::SHOW_MANUAL)));
        self::assertSame(['b'], self::ids(TranslationRegistry::filter($rows, '', TranslationRegistry::SHOW_EMAILS)));
        self::assertSame([], self::ids(TranslationRegistry::filter($rows, 'hello', TranslationRegistry::SHOW_EMAILS)));
    }

    /** A text changed on the page is edited by hand; the model is not asked when nothing is empty. */
    public function testSavingARowMarksTheChangedTextAsEditedByHand(): void
    {
        $this->withModel('{"lang": "en", "fi": "MODEL"}');

        $result = $this->sample()->saveRow('a', 'Hello', ['fi' => 'Hei', 'en' => 'Hello there']);

        self::assertSame(['found' => true, 'error' => '', 'missing' => []], $result);
        self::assertSame([], $this->requests);
        self::assertSame(['fi' => 'Hei', 'en' => 'Hello there'], $this->stored('a')['texts']);
        self::assertSame(['fi' => true, 'en' => true], $this->stored('a')['manual']);
        self::assertSame(
            ['found' => false, 'error' => '', 'missing' => []],
            $this->sample()->saveRow('zzz', 'x', [])
        );
    }

    /** A new source is translated again, except the texts edited by hand. */
    public function testANewSourceKeepsTheTextsEditedByHand(): void
    {
        $this->withModel('{"lang": "en", "fi": "MODEL"}');
        // The language was detected, so a new source is detected again.
        $this->options['row.a'] = [
            'source' => 'Hello',
            'lang' => 'en',
            'detected' => true,
            'texts' => ['fi' => 'Hei', 'en' => 'Hello'],
            'manual' => ['fi' => true, 'en' => false],
        ];

        $this->sample()->saveRow('a', 'Good morning', []);

        self::assertCount(1, $this->requests);
        self::assertSame(['fi' => 'Hei', 'en' => 'Good morning'], $this->stored('a')['texts']);
        self::assertTrue($this->stored('a')['manual']['fi']);
        self::assertSame('en', $this->stored('a')['lang']);
    }

    /**
     * The texts one request sent: the JSON list in the user message.
     *
     * @return list<array<string, mixed>>
     */
    private function sent(int $request): array
    {
        $body = json_decode($this->requests[$request], true);

        return json_decode((string) $body['messages'][1]['content'], true);
    }

    /** "Translate missing" fills the empty texts only; rows with nothing missing are not sent. */
    public function testTranslateMissingFillsOnlyTheEmptyTexts(): void
    {
        $this->withModel('[{"id": "1", "lang": "en", "fi": "Kiitos"}]');
        $registry = $this->sample();

        self::assertSame(['b'], $registry->missingIds());

        $result = $registry->translateRows(['a', 'b', 'c']);

        self::assertSame(['done' => ['a', 'c', 'b'], 'failed' => [], 'error' => ''], $result);
        self::assertCount(1, $this->requests, 'only row b had an empty text');
        self::assertSame([['id' => '1', 'lang' => '', 'text' => 'Thank you']], $this->sent(0));
        self::assertSame(['fi' => 'Kiitos', 'en' => 'Thank you'], $this->stored('b')['texts']);
        self::assertSame(['fi' => false, 'en' => false], $this->stored('b')['manual']);
        self::assertSame('en', $this->stored('b')['lang'], 'the language the model found');
        self::assertSame('Hei', $this->stored('a')['texts']['fi']);
        self::assertSame([], $this->sample()->missingIds());
        self::assertSame(['done' => ['b'], 'failed' => [], 'error' => ''], $this->sample()->translateRows(['b']));
        self::assertCount(1, $this->requests, 'a filled row is not sent again');
    }

    /** Many texts go in one request, and the answer is matched by id, in any order. */
    public function testManyTextsGoInOneRequest(): void
    {
        $this->withModel('[{"id": "2", "lang": "en", "fi": "Kaksi"}, {"id": "1", "lang": "en", "fi": "Yksi"}]');
        $this->options['row.one'] = ['source' => 'One', 'lang' => 'en', 'texts' => ['fi' => '', 'en' => 'One']];
        $this->options['row.two'] = ['source' => 'Two', 'lang' => 'en', 'texts' => ['fi' => '', 'en' => 'Two']];
        $registry = $this->registry();
        $registry->add(fn (): array => [$this->row('one', []), $this->row('two', [])]);

        $result = $registry->translateRows(['one', 'two']);

        self::assertSame(['done' => ['one', 'two'], 'failed' => [], 'error' => ''], $result);
        self::assertCount(1, $this->requests);
        self::assertSame(['One', 'Two'], array_column($this->sent(0), 'text'));
        self::assertSame('Yksi', $this->stored('one')['texts']['fi']);
        self::assertSame('Kaksi', $this->stored('two')['texts']['fi']);
    }

    /** A text the answer leaves out fails alone; the other text of the request is stored. */
    public function testATextMissingFromTheAnswerFailsAlone(): void
    {
        $this->withModel('[{"id": "1", "lang": "en", "fi": "Yksi"}]');
        $this->options['row.one'] = ['source' => 'One', 'lang' => 'en', 'texts' => ['fi' => '', 'en' => 'One']];
        $this->options['row.two'] = ['source' => 'Two', 'lang' => 'en', 'texts' => ['fi' => '', 'en' => 'Two']];
        $registry = $this->registry();
        $registry->add(fn (): array => [$this->row('one', []), $this->row('two', [])]);

        $result = $registry->translateRows(['one', 'two']);

        self::assertSame(['done' => ['one'], 'failed' => ['two'], 'error' => ''], $result);
        self::assertSame('Yksi', $this->stored('one')['texts']['fi']);
        self::assertSame('', $this->stored('two')['texts']['fi']);
    }

    /**
     * A third language added to the site: every row has an empty column,
     * and "Translate missing" fills it, also for a built-in email text. The
     * texts edited by hand stay.
     */
    public function testAThirdLanguageIsFilledForEveryRow(): void
    {
        $this->options[SiteLanguages::OPTION] = ['fi', 'en', 'sv'];
        $this->withModel((string) json_encode(array_map(
            static fn (int $id): array => [
                'id' => (string) $id,
                'lang' => 'en',
                'fi' => 'MODEL-FI',
                'sv' => 'MODEL-SV',
            ],
            [1, 2, 3, 4]
        )));
        $registry = $this->sample();
        // The post-event email's two texts, as the Messages page rows.
        $registry->add(static fn (): array => array_values(array_filter(
            MessageTemplates::translationRows(),
            static fn (TranslationRow $row): bool => str_starts_with($row->id, 'message.followup_')
                && str_ends_with($row->id, '.body')
        )));

        self::assertSame(
            ['a', 'b', 'message.followup_came.body', 'message.followup_missed.body'],
            self::ids(TranslationRegistry::filter($registry->rows(), '', TranslationRegistry::SHOW_MISSING))
        );

        $result = $registry->translateRows($registry->missingIds());

        self::assertSame(4, count($result['done']));
        self::assertSame([], $result['failed']);
        self::assertCount(1, $this->requests, 'the four texts went in one request');
        self::assertSame(['fi' => 'Hei', 'en' => 'Hello', 'sv' => 'MODEL-SV'], $this->stored('a')['texts']);
        self::assertSame(['fi' => 'MODEL-FI', 'en' => 'Thank you', 'sv' => 'MODEL-SV'], $this->stored('b')['texts']);

        $came = MessageTemplates::value(FollowupText::TEMPLATES[FollowupText::CAME], MessageTemplates::BODY);
        self::assertSame('MODEL-SV', $came['texts']['sv']);
        self::assertNotSame('MODEL-FI', $came['texts']['fi'], 'the shipped Finnish text was not empty');
        self::assertSame([], TranslationRegistry::filter($registry->rows(), '', TranslationRegistry::SHOW_MISSING));

        $prompt = (string) json_encode(json_decode($this->requests[0], true));
        self::assertStringContainsString('sv', $prompt);
    }

    /** The request fails: nothing of it is stored, and every text in it failed. */
    public function testAFailedRequestStoresNothing(): void
    {
        $this->options[SiteLanguages::OPTION] = ['fi', 'en', 'sv'];
        $this->withModel('{"lang": "en", "fi": "x", "sv": "x"}');
        $this->status = 500;
        $before = $this->sample();
        $before->rows();
        $stored = $this->options;

        $result = $before->translateRows($before->missingIds());

        self::assertSame(['done' => [], 'failed' => ['a', 'b'], 'error' => 'http_500'], $result);
        self::assertCount(1, $this->requests);
        self::assertSame($stored['row.a'], $this->options['row.a']);
        self::assertSame($stored['row.b'], $this->options['row.b']);
    }

    /** With language detection off, the model is told the main language instead of asked. */
    public function testDetectionOffNamesTheMainLanguage(): void
    {
        $this->options[SiteLanguages::DETECT_OPTION] = '0';
        $this->options[SiteLanguages::OPTION] = ['en', 'fi'];
        $this->withModel('[{"id": "1", "fi": "Kiitos"}]');

        $result = $this->sample()->translateRows(['b']);

        self::assertSame(['done' => ['b'], 'failed' => [], 'error' => ''], $result);
        self::assertSame('en', $this->sent(0)[0]['lang']);
        self::assertSame('en', $this->stored('b')['lang']);
        self::assertSame('Kiitos', $this->stored('b')['texts']['fi']);
    }

    /** AI translation off: "Translate missing" asks nothing and stores nothing. */
    public function testAutoOffTranslatesNothing(): void
    {
        $this->options[TranslatedText::AUTO_OPTION] = '0';
        $this->withModel('[{"id": "1", "lang": "en", "fi": "Kiitos"}]');

        $result = $this->sample()->translateRows(['b']);

        self::assertSame(['done' => [], 'failed' => ['b'], 'error' => TranslatedText::ERROR_OFF], $result);
        self::assertSame([], $this->requests);
        self::assertSame('', $this->stored('b')['texts']['fi']);
    }
}
