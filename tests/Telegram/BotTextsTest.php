<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\Language;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\SiteLanguages;
use EventCrew\Support\TranslatedText;
use EventCrew\Support\TranslationRegistry;
use EventCrew\Support\TranslationRow;
use EventCrew\Telegram\BotTexts;
use EventCrew\Tests\TestCase;

/**
 * The bot's answers and buttons on the Translations page (Q295): the
 * built-in .mo text is the default, a text edited by hand replaces it
 * through the gettext filter, and only edited or model-written texts are
 * stored.
 */
final class BotTextsTest extends TestCase
{
    private const BUTTON = 'Take over a task';
    private const WITH_NAME = '%s is now an organizer.';

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var list<string> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->requests = [];
        BotTexts::reset();

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string => (string) json_encode($value));
        Functions\when('wp_remote_post')->alias(function (string $url, array $args): array {
            $this->requests[] = (string) $args['body'];

            return ['answer' => '{"lang": "en", "fi": "x", "sv": "SV-TEXT"}'];
        });
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->alias(
            static fn (array $response): string => (string) json_encode(
                ['choices' => [['message' => ['content' => $response['answer']]]]]
            )
        );
    }

    private function registry(): TranslationRegistry
    {
        $registry = new TranslationRegistry(new TranslatedText(new LlmClient(new Logger()), new Logger()));
        $registry->add([BotTexts::class, 'translationRows']);

        return $registry;
    }

    private function rowFor(TranslationRegistry $registry, string $text): TranslationRow
    {
        foreach ($registry->rows() as $row) {
            if ($row->value['source'] === $text) {
                return $row;
            }
        }

        self::fail('No row for ' . $text);
    }

    public function testTheBotsStringsAreFound(): void
    {
        $texts = array_column(BotTexts::strings(), 'text', 'text');

        self::assertArrayHasKey(self::BUTTON, $texts);
        self::assertArrayHasKey(self::WITH_NAME, $texts);
        self::assertArrayNotHasKey('Bot text (%s)', $texts, 'the page\'s own label is not a bot text');
        self::assertGreaterThan(100, count($texts));
    }

    /** The English text is fixed; Finnish comes from the .mo; a language without one is empty. */
    public function testTheBuiltInTextsAreTheDefault(): void
    {
        $this->options[SiteLanguages::OPTION] = ['fi', 'en', 'sv'];
        $row = $this->rowFor($this->registry(), self::BUTTON);

        self::assertTrue($row->fixedSource);
        self::assertSame(TranslationRow::GROUP_BOT, $row->group);
        self::assertSame('Bot text (MenuButtons)', $row->label);
        self::assertSame(['fi' => 'Ota tehtävä', 'en' => self::BUTTON, 'sv' => ''], $row->value['texts']);
        self::assertFalse($row->hasManual());
        self::assertTrue($row->isMissing());
    }

    /** A Finnish text edited by hand is stored and replaces the .mo text; English is untouched. */
    public function testAnEditedTextReplacesTheBuiltInOne(): void
    {
        $registry = $this->registry();
        $id = $this->rowFor($registry, self::BUTTON)->id;

        $result = $registry->saveRow($id, 'A changed source', ['fi' => 'Ota tämä tehtävä', 'en' => self::BUTTON]);

        self::assertSame(['found' => true, 'error' => '', 'missing' => []], $result);
        self::assertSame([], $this->requests);
        self::assertSame(
            [self::BUTTON => ['texts' => ['fi' => 'Ota tämä tehtävä'], 'manual' => ['fi' => true]]],
            $this->options[BotTexts::OPTION],
            'only the edited text is stored; the source stays the code\'s'
        );
        self::assertSame(
            'Ota tämä tehtävä',
            Language::run('fi', static fn (): mixed => BotTexts::filter('Ota tehtävä', self::BUTTON))
        );
        self::assertSame(self::BUTTON, Language::run('en', static fn (): mixed => BotTexts::filter(self::BUTTON, self::BUTTON)));
        self::assertSame('x', BotTexts::filter('x', self::BUTTON), 'outside run(): the site\'s language, English here');
        self::assertSame('Other', BotTexts::filter('Other', 'Some other text'));
    }

    /** Emptying the edited text goes back to the .mo text and drops the stored one. */
    public function testEmptyingGoesBackToTheBuiltInText(): void
    {
        $registry = $this->registry();
        $id = $this->rowFor($registry, self::BUTTON)->id;
        $registry->saveRow($id, self::BUTTON, ['fi' => 'Ota tämä tehtävä']);

        $registry->saveRow($id, self::BUTTON, ['fi' => '']);

        self::assertSame([], $this->options[BotTexts::OPTION]);
        self::assertSame('Ota tehtävä', $this->rowFor($this->registry(), self::BUTTON)->value['texts']['fi']);
    }

    /** A text whose placeholders differ from the English one is not used. */
    public function testAWrongPlaceholderIsNotUsed(): void
    {
        BotTexts::store(self::WITH_NAME, ['texts' => ['fi' => 'Hänestä tuli järjestäjä.'], 'manual' => ['fi' => true]]);

        self::assertSame(
            'built-in',
            Language::run('fi', static fn (): mixed => BotTexts::filter('built-in', self::WITH_NAME))
        );

        BotTexts::store(self::WITH_NAME, ['texts' => ['fi' => '%s on nyt järjestäjä.'], 'manual' => ['fi' => true]]);

        self::assertSame(
            '%s on nyt järjestäjä.',
            Language::run('fi', static fn (): mixed => BotTexts::filter('built-in', self::WITH_NAME))
        );
    }

    /**
     * A language without a .mo file: the model's text is stored and used;
     * it is not marked as edited by hand.
     */
    public function testAModelTextIsUsedForALanguageWithoutAFile(): void
    {
        $this->options[SiteLanguages::OPTION] = ['fi', 'en', 'sv'];
        $this->options[AssistantSettings::URL_OPTION] = 'https://llm.example.test/v1';
        $this->options[AssistantSettings::MODEL_OPTION] = 'test-model';
        $registry = new TranslationRegistry(new TranslatedText(new LlmClient(new Logger()), new Logger()));
        $registry->add(fn (): array => [$this->rowFor($this->registry(), self::BUTTON)]);

        $result = $registry->translateMissing();

        self::assertSame(['filled' => 1, 'failed' => 0, 'error' => ''], $result);
        self::assertSame(
            [self::BUTTON => ['texts' => ['sv' => 'SV-TEXT'], 'manual' => ['sv' => false]]],
            $this->options[BotTexts::OPTION],
            'the Finnish answer is not stored: Finnish has its .mo file'
        );
        self::assertSame('SV-TEXT', Language::run('sv', static fn (): mixed => BotTexts::filter(self::BUTTON, self::BUTTON)));
        self::assertSame('Ota tehtävä', Language::run('fi', static fn (): mixed => BotTexts::filter('Ota tehtävä', self::BUTTON)));
    }
}
