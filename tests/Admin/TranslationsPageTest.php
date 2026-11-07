<?php

declare(strict_types=1);

namespace EventCrew\Tests\Admin;

use Brain\Monkey\Functions;
use EventCrew\Admin\TranslationsPage;
use EventCrew\Admin\View;
use EventCrew\Support\BackgroundJobs;
use EventCrew\Support\Language;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\SiteLanguages;
use EventCrew\Support\TranslatedText;
use EventCrew\Support\TranslationJob;
use EventCrew\Support\TranslationRegistry;
use EventCrew\Support\TranslationRow;
use EventCrew\Tests\FinnishCatalog;
use EventCrew\Tests\TestCase;

/**
 * The wp-admin Translations page: the language settings, saving one row,
 * "Translate missing" (it only starts the background job), and the rendered
 * table.
 */
final class TranslationsPageTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var list<string> The WP-Cron events asked for. */
    private array $scheduled = [];

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
        $this->scheduled = [];
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->alias(function (int $at, string $hook): bool {
            $this->scheduled[] = $hook;

            return true;
        });
        Functions\when('wp_create_nonce')->justReturn('nonce');
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

        return new TranslationsPage(new View(), $registry, new TranslationJob($registry));
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

    /**
     * With the model set up, the save handler stores the job and redirects
     * at once: the model is not asked during the request, and the notice
     * says the translation runs in the background.
     */
    public function testTheSaveHandlerQueuesTheTranslationAndRedirectsAtOnce(): void
    {
        $this->options[\EventCrew\Support\AssistantSettings::URL_OPTION] = 'https://llm.example.test/v1';
        $this->options[\EventCrew\Support\AssistantSettings::MODEL_OPTION] = 'test-model';
        $notices = [];
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_admin_referer')->justReturn(1);
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('set_transient')->alias(static function (string $key, array $value) use (&$notices): bool {
            $notices[] = $value;

            return true;
        });
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_safe_redirect')->alias(static function (string $url): never {
            throw new \RuntimeException('redirect ' . $url);
        });
        $before = $this->options['row.thanks'];
        $_POST = ['row_id' => 'thanks', 'row' => ['source' => 'Thank you so much', 'texts' => ['fi' => '']]];

        try {
            $this->page()->saveRow();
            self::fail('The handler redirects.');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('redirect https://site.test/wp-admin/admin.php', $e->getMessage());
        } finally {
            $_POST = [];
        }

        self::assertSame($before['texts'], $this->options['row.thanks']['texts'], 'the old text is kept');
        self::assertSame(
            BackgroundJobs::QUEUED,
            BackgroundJobs::latest(TranslatedText::jobTarget('thanks'))['status'] ?? null
        );
        self::assertSame('success', $notices[0]['type'] ?? null);
        self::assertStringContainsString('run in the background', (string) ($notices[0]['message'] ?? ''));
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
        $page = new TranslationsPage(new View(), $registry, new TranslationJob($registry));

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

    /**
     * The button only queues the job and asks WP-Cron for a run: nothing is
     * translated during the click (a request to the model throws here). A
     * second click while it runs starts nothing new.
     */
    public function testTranslateMissingStartsABackgroundJobOnce(): void
    {
        $notice = $this->page()->applyTranslateMissing();

        self::assertSame('success', $notice['type']);
        self::assertStringContainsString('runs in the background', $notice['message']);
        self::assertSame([TranslationJob::HOOK], $this->scheduled);
        $status = TranslationJob::status();
        self::assertNotNull($status);
        self::assertTrue($status['running']);
        self::assertSame(['thanks'], $status['queue']);
        self::assertSame(1, $status['total']);
        self::assertSame('', $this->options['row.thanks']['texts']['fi']);

        $notice = $this->page()->applyTranslateMissing();

        self::assertSame('warning', $notice['type']);
        self::assertStringContainsString('already running', $notice['message']);
        self::assertSame($status, TranslationJob::status(), 'the running job was not replaced');
    }

    /** The page says how far the job is, while it runs and after it ended. */
    public function testTheProgressIsShown(): void
    {
        $this->options[TranslationJob::OPTION] = [
            'running' => true,
            'total' => 280,
            'done' => 120,
            'failed' => 3,
            'error' => '',
            'queue' => ['thanks'],
        ];

        $data = $this->page()->data('', '');

        self::assertSame('Translating: 120 of 280 done. Not translated: 3.', $data['progress']);
        self::assertTrue($data['running']);
        self::assertStringContainsString('action=' . TranslationsPage::PROGRESS_ACTION, $data['progress_url']);
        self::assertStringContainsString('Translating: 120 of 280 done.', $this->rendered(['s' => '', 'show' => '']));

        $this->options[TranslationJob::OPTION] = [
            'running' => false,
            'total' => 280,
            'done' => 270,
            'failed' => 10,
            'error' => 'timeout',
            'queue' => [],
        ];

        self::assertSame(
            'Last translation: 270 of 280 done. Not translated: 10. The language model gave the error timeout.',
            $this->page()->data('', '')['progress']
        );
        self::assertSame('', $this->page()->data('', '')['progress_url']);
        self::assertSame(
            'Käännetään: 120 / 280 valmiina. Kääntämättä: 3.',
            Language::run('fi', static fn (): string => TranslationsPage::progressText([
                'running' => true,
                'total' => 280,
                'done' => 120,
                'failed' => 3,
                'error' => '',
            ]))
        );
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
        self::assertStringContainsString('Texts and buttons in several languages show 2 of the 3 site languages', $html);

        $html = $this->rendered(['s' => 'kiitos', 'show' => '']);
        self::assertStringContainsString('Showing 0 of 2 texts.', $html);
    }

    /** With two site languages there is no warning about the Telegram buttons. */
    public function testTwoLanguagesHaveNoButtonsWarning(): void
    {
        $this->options[SiteLanguages::OPTION] = ['fi', 'en'];

        self::assertStringNotContainsString('Telegram buttons show', $this->rendered(['s' => '', 'show' => '']));
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
