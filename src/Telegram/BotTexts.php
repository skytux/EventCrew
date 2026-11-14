<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Support\Language;
use EventCrew\Support\SiteLanguages;
use EventCrew\Support\TranslatedText;
use EventCrew\Support\TranslationRow;

/**
 * The bot's short answers and buttons on the Translations page (Owner, Q295).
 *
 * The texts are the single __() strings in src/Telegram/ (not this file's
 * own labels). Each one is a row whose source is the English text in the
 * code. A language's text is, in
 * this order: the text edited by hand; for a language the plugin has no
 * .mo file for, the text the language model wrote; otherwise the built-in
 * translation from the .mo file. Only the first two are stored (OPTION,
 * keyed by the English text), so a better .mo file still reaches every text
 * nobody edited.
 *
 * The stored texts are put in place by a gettext_eventcrew filter. A stored
 * text whose %s / %1$d placeholders differ from the English text is not used,
 * so a wrong edit cannot break the sprintf() the code wraps it in.
 */
final class BotTexts
{
    /** array<string, array{texts: array<string, string>, manual: array<string, bool>}>, by English text. */
    public const OPTION = 'eventcrew_bot_texts';

    private const ID_PREFIX = 'bot.';

    private const PLACEHOLDER = '/%(?:\d+\$)?[-+ 0#\']*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/';

    /** @var list<array{text: string, file: string}>|null */
    private static ?array $strings = null;

    /** @var array<string, bool> */
    private static array $catalogs = [];

    /** True while the built-in texts are read, so the filter steps aside. */
    private static bool $bypass = false;

    /**
     * The stored option, read once per request: get_option() unserializes
     * it on every call, and the filter runs on every __() of the plugin.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $stored = null;

    public static function boot(): void
    {
        add_filter('gettext_eventcrew', [self::class, 'filter'], 10, 2);

        // Whoever writes the option, the next read sees it.
        foreach (['add_option_', 'update_option_', 'delete_option_'] as $hook) {
            add_action($hook . self::OPTION, [self::class, 'forgetStored']);
        }
    }

    /** Drops the stored option read in this request. */
    public static function forgetStored(): void
    {
        self::$stored = null;
    }

    /**
     * The gettext_eventcrew filter: the stored text of $text in the current
     * language, or $translation.
     */
    public static function filter(mixed $translation, mixed $text): mixed
    {
        if (self::$bypass || ! is_string($text)) {
            return $translation;
        }

        $stored = self::stored();

        if (! isset($stored[$text])) {
            return $translation;
        }

        $lang = Language::current() ?? self::siteLanguage();
        $own = self::storedText($stored[$text], $lang);

        if ('' === $own || ! self::samePlaceholders($text, $own)) {
            return $translation;
        }

        return $own;
    }

    /**
     * The rows for the Translations page, one per bot text.
     *
     * @return list<TranslationRow>
     */
    public static function translationRows(): array
    {
        $strings = self::strings();
        $stored = self::stored();
        $languages = TranslatedText::languages();
        $builtIn = [];

        foreach ($languages as $lang) {
            $builtIn[$lang] = self::builtIn($lang, array_column($strings, 'text'));
        }

        $rows = [];

        foreach ($strings as $string) {
            $text = $string['text'];
            $value = TranslatedText::blank();
            $value['source'] = $text;
            $value['lang'] = Language::ENGLISH;

            foreach ($languages as $lang) {
                $own = isset($stored[$text]) ? self::storedText($stored[$text], $lang) : '';
                $value['texts'][$lang] = '' !== $own ? $own : $builtIn[$lang][$text];
                $value['manual'][$lang] = (bool) ($stored[$text]['manual'][$lang] ?? false);
            }

            $rows[] = new TranslationRow(
                self::ID_PREFIX . substr(md5($text), 0, 12),
                sprintf(
                    /* translators: %s: the part of the bot the text is in, e.g. "MenuButtons" */
                    __('Bot text (%s)', 'eventcrew'),
                    $string['file']
                ),
                TranslationRow::GROUP_BOT,
                $value,
                static function (array $value) use ($text): void {
                    self::store($text, $value);
                },
                0,
                str_contains($text, "\n"),
                true
            );
        }

        return $rows;
    }

    /**
     * Stores the texts of $text that are edited by hand, and the texts of the
     * languages without a .mo file; drops the rest.
     *
     * @param array{texts: array<string, string>, manual: array<string, bool>} $value
     */
    public static function store(string $text, array $value): void
    {
        $keep = ['texts' => [], 'manual' => []];

        foreach ($value['texts'] as $lang => $own) {
            $manual = (bool) ($value['manual'][$lang] ?? false);

            if ('' === $own || (! $manual && self::hasCatalog($lang))) {
                continue;
            }

            $keep['texts'][$lang] = $own;
            $keep['manual'][$lang] = $manual;
        }

        $stored = self::stored();

        if ([] === $keep['texts']) {
            unset($stored[$text]);
        } else {
            $stored[$text] = $keep;
        }

        update_option(self::OPTION, $stored);
        self::$stored = null;
    }

    /** Forgets what was read in this request (for tests). */
    public static function reset(): void
    {
        self::$strings = null;
        self::$catalogs = [];
        self::$stored = null;
    }

    /**
     * The single __() strings in src/Telegram/, each once, with the file it
     * is first found in.
     *
     * @return list<array{text: string, file: string}>
     */
    public static function strings(): array
    {
        if (null !== self::$strings) {
            return self::$strings;
        }

        $found = [];
        $files = glob(EVENTCREW_PLUGIN_DIR . 'src/Telegram/*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            if (__FILE__ === $file) {
                continue;
            }

            $code = (string) file_get_contents($file);
            preg_match_all(
                "/\b(?:__|esc_html__|esc_attr__)\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*,\s*'eventcrew'\s*\)/",
                $code,
                $matches
            );

            foreach ($matches[1] as $literal) {
                $text = str_replace(["\\'", '\\\\'], ["'", '\\'], $literal);
                $found[$text] ??= basename($file, '.php');
            }
        }

        self::$strings = [];

        foreach ($found as $text => $file) {
            self::$strings[] = ['text' => (string) $text, 'file' => $file];
        }

        return self::$strings;
    }

    /**
     * @param array{texts?: array<string, string>, manual?: array<string, bool>} $entry
     */
    private static function storedText(array $entry, string $lang): string
    {
        $own = (string) ($entry['texts'][$lang] ?? '');

        if ('' === $own) {
            return '';
        }

        // A text not edited by hand is used only while there is no .mo file.
        return ($entry['manual'][$lang] ?? false) || ! self::hasCatalog($lang) ? $own : '';
    }

    /**
     * The built-in text of each of $texts in $lang: the .mo file's, English
     * for English, '' for a language without a .mo file.
     *
     * @param list<string> $texts
     * @return array<string, string>
     */
    private static function builtIn(string $lang, array $texts): array
    {
        if (Language::ENGLISH !== $lang && ! self::hasCatalog($lang)) {
            return array_fill_keys($texts, '');
        }

        self::$bypass = true;

        try {
            return (array) Language::run($lang, static function () use ($texts): array {
                $out = [];

                foreach ($texts as $text) {
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- the bot's own strings, read back.
                    $out[$text] = __($text, 'eventcrew');
                }

                return $out;
            });
        } finally {
            self::$bypass = false;
        }
    }

    /** @return array<string, array{texts?: array<string, string>, manual?: array<string, bool>}> */
    private static function stored(): array
    {
        if (null === self::$stored) {
            $stored = get_option(self::OPTION, []);
            self::$stored = is_array($stored) ? $stored : [];
        }

        return self::$stored;
    }

    private static function hasCatalog(string $lang): bool
    {
        return self::$catalogs[$lang] ??= SiteLanguages::hasCatalog($lang);
    }

    /** The site language of the locale WordPress is in, English when none. */
    private static function siteLanguage(): string
    {
        $locale = function_exists('determine_locale') ? (string) determine_locale() : 'en_US';

        foreach (SiteLanguages::codes() as $code) {
            if (SiteLanguages::locale($code) === $locale) {
                return $code;
            }
        }

        return Language::ENGLISH;
    }

    private static function samePlaceholders(string $a, string $b): bool
    {
        preg_match_all(self::PLACEHOLDER, str_replace('%%', '', $a), $first);
        preg_match_all(self::PLACEHOLDER, str_replace('%%', '', $b), $second);
        sort($first[0]);
        sort($second[0]);

        return $first[0] === $second[0];
    }
}
