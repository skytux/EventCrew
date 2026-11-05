<?php

declare(strict_types=1);

namespace EventCrew\Tests\Admin;

use Brain\Monkey\Functions;
use EventCrew\Admin\TranslationsPage;
use EventCrew\Admin\View;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\SiteLanguages;
use EventCrew\Support\TranslatedText;
use EventCrew\Support\TranslationRegistry;
use EventCrew\Support\TranslationRow;
use EventCrew\Tests\FinnishCatalog;
use EventCrew\Tests\TestCase;

/**
 * The wp-admin Translations page: the language settings, saving one row,
 * "Translate missing" with AI translation off, and the rendered table.
 */
final class TranslationsPageTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [
            'row.hello' => [
                'source' => 'Hello',
                'lang' => 'en',
                'texts' => ['fi' => 'Hei', 'en' => 'Hello'],
                'manual' => ['fi' => true, 'en' => false],
            ],
            'row.thanks' => ['source' => 'Thank you', 'texts' => ['fi' => '', 'en' => 'Thank you']],
        ];

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
        Functions\when('admin_url')->alias(
            static fn (string $path = ''): string => 'https://site.test/wp-admin/' . $path
        );
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('sanitize_textarea_field')->alias(static fn (string $value): string => trim($value));
        // No model is set up: a request to it would be a test failure.
        Functions\when('wp_remote_post')->alias(static function (): never {
            throw new \RuntimeException('The model was asked.');
        });
    }

    private function page(): TranslationsPage
    {
        $registry = new TranslationRegistry(new TranslatedText(new LlmClient(new Logger()), new Logger()));
        $registry->add(fn (): array => [
            $this->row('hello', 'Greeting', TranslationRow::GROUP_TEXTS),
            $this->row('thanks', 'Thanks email', TranslationRow::GROUP_EMAILS),
        ]);

        return new TranslationsPage(new View(), $registry);
    }

    /** @param list<string> $required */
    private function row(string $id, string $label, string $group, array $required = []): TranslationRow
    {
        return new TranslationRow(
            $id,
            $label,
            $group,
            (array) $this->options['row.' . $id],
            function (array $value) use ($id): void {
                $this->options['row.' . $id] = $value;
            },
            required: $required
        );
    }

    public function testSettingsAreSaved(): void
    {
        $notice = $this->page()->applySettings(['languages' => 'fi, en sv', 'detect' => '1']);

        self::assertSame('success', $notice['type']);
        self::assertSame(['fi', 'en', 'sv'], $this->options[SiteLanguages::OPTION]);
        self::assertSame('0', $this->options[TranslatedText::AUTO_OPTION], 'the box was not ticked');
        self::assertSame('1', $this->options[SiteLanguages::DETECT_OPTION]);
    }

    /** Unknown codes are left out and named; only unknown codes keep the languages as they were. */
    public function testUnknownLanguageCodesAreReported(): void
    {
        $notice = $this->page()->applySettings(['languages' => 'en, xx', 'translate_auto' => '1']);

        self::assertSame('error', $notice['type']);
        self::assertStringContainsString('xx', $notice['message']);
        self::assertSame(['en'], $this->options[SiteLanguages::OPTION]);

        $this->page()->applySettings(['languages' => 'yy']);

        self::assertSame(['en'], $this->options[SiteLanguages::OPTION]);
    }

    /** A saved row is edited by hand; an id that is not on the page saves nothing. */
    public function testARowIsSaved(): void
    {
        $notice = $this->page()->applyRow('thanks', ['source' => 'Thank you', 'texts' => ['fi' => 'Kiitos']]);

        self::assertSame(['type' => 'success', 'message' => 'Saved: Thanks email.'], $notice);
        self::assertSame('Kiitos', $this->options['row.thanks']['texts']['fi']);
        self::assertTrue($this->options['row.thanks']['manual']['fi']);

        $notice = $this->page()->applyRow('nope', ['source' => 'x']);
        self::assertSame('error', $notice['type']);
    }

    /** A text without a required placeholder is not saved, and the notice names the placeholder. */
    public function testARowWithoutARequiredPlaceholderIsNotSaved(): void
    {
        $before = ['source' => 'Now {email}.', 'texts' => ['fi' => 'Nyt {email}.', 'en' => 'Now {email}.']];
        $this->options['row.moved'] = $before;
        $registry = new TranslationRegistry(new TranslatedText(new LlmClient(new Logger()), new Logger()));
        $registry->add(fn (): array => [
            $this->row('moved', 'Address moved', TranslationRow::GROUP_EMAILS, ['{email}']),
        ]);
        $page = new TranslationsPage(new View(), $registry);

        $notice = $page->applyRow('moved', ['source' => 'Now {email}.', 'texts' => ['fi' => 'Nyt uusi osoite.']]);

        self::assertSame('error', $notice['type']);
        self::assertStringContainsString('{email}', $notice['message']);
        self::assertSame($before, $this->options['row.moved']);
    }

    public function testTranslateMissingNeedsAiTranslation(): void
    {
        $this->options[TranslatedText::AUTO_OPTION] = '0';

        $notice = $this->page()->applyTranslateMissing();

        self::assertSame('error', $notice['type']);
        self::assertStringContainsString('AI translation is off', $notice['message']);
        self::assertSame('', $this->options['row.thanks']['texts']['fi']);
    }

    public function testTheDataFollowsTheFilter(): void
    {
        $data = $this->page()->data('', TranslationRegistry::SHOW_MISSING);

        self::assertSame(['fi', 'en'], $data['languages']);
        self::assertSame(2, $data['total']);
        self::assertSame(['thanks'], array_map(static fn (TranslationRow $row): string => $row->id, $data['rows']));
        self::assertSame('', $this->page()->data('', 'bogus')['show'], 'an unknown filter shows every row');
    }

    /** The settings, the search form, a column per language, and the two badges. */
    public function testThePageIsRendered(): void
    {
        $this->options[SiteLanguages::OPTION] = ['fi', 'en', 'sv'];
        $html = $this->rendered(['s' => '', 'show' => '']);

        self::assertStringContainsString('value="fi, en, sv"', $html);
        self::assertStringContainsString('Svenska', $html);
        self::assertStringContainsString('name="s"', $html);
        self::assertStringContainsString('Greeting', $html);
        self::assertStringContainsString('Thanks email', $html);
        self::assertStringContainsString('edited by hand', $html);
        self::assertStringContainsString('not translated yet', $html);
        self::assertStringContainsString('row[texts][sv]', $html);
        self::assertStringContainsString('Translate missing', $html);
        self::assertStringContainsString('Showing 2 of 2 texts.', $html);

        $html = $this->rendered(['s' => 'kiitos', 'show' => '']);
        self::assertStringContainsString('Showing 0 of 2 texts.', $html);
    }

    /** @param array<string, string> $get */
    private function rendered(array $get): string
    {
        Functions\when('esc_html_e')->alias(static function (string $text): void {
            echo esc_html(FinnishCatalog::translate($text));
        });
        Functions\when('esc_attr_e')->alias(static function (string $text): void {
            echo esc_attr(FinnishCatalog::translate($text));
        });
        Functions\when('selected')->alias(static function (mixed $a, mixed $b): void {
            echo (string) $a === (string) $b ? ' selected="selected"' : '';
        });
        Functions\when('checked')->alias(static function (mixed $a, mixed $b = true): void {
            echo (bool) $a === (bool) $b ? ' checked="checked"' : '';
        });
        Functions\when('disabled')->alias(static function (mixed $a, mixed $b = true): void {
            echo (bool) $a === (bool) $b ? ' disabled="disabled"' : '';
        });
        Functions\when('wp_nonce_field')->alias(static function (string $action): void {
            echo '<input type="hidden" name="_wpnonce" value="' . esc_attr(md5($action)) . '">';
        });
        Functions\when('submit_button')->alias(static function (string $text): void {
            echo '<button type="submit" class="button button-primary">' . esc_html($text) . '</button>';
        });
        $_GET = $get;

        ob_start();

        try {
            $this->page()->render();
        } finally {
            $_GET = [];
            $html = (string) ob_get_clean();
        }

        return $html;
    }
}
