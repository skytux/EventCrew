<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The site's languages, set on the wp-admin Translations page.
 *
 * - The languages, in order (OPTION). The first one is the main language.
 *   Every written-once text (TranslatedText) is kept in each of them, and a
 *   person may choose any of them. The default is Finnish, then English.
 * - AI translation on or off: the existing switch TranslatedText::AUTO_OPTION.
 * - AI language detection on or off (DETECT_OPTION). When it is off, a text
 *   is taken to be written in the main language, and the language model is
 *   not asked which language it is.
 *
 * A language can be used when it is in KNOWN: a two-letter code with its
 * WordPress locale. The plugin's own strings come from
 * languages/eventcrew-{locale}.mo; a language without that file shows them
 * in English.
 */
final class SiteLanguages
{
    /** The language codes, in order: a list of strings. */
    public const OPTION = 'eventcrew_site_languages';

    /** '1' (the default) lets the language model say which language a text is in; '0' takes the main language. */
    public const DETECT_OPTION = 'eventcrew_translate_detect';

    /** The languages when nothing is stored. */
    public const DEFAULT = [Language::FINNISH, Language::ENGLISH];

    /**
     * Every language a site can use: code => [English name, its own name,
     * WordPress locale]. The English name is what the language model is told.
     */
    public const KNOWN = [
        'ar' => ['Arabic', 'العربية', 'ar'],
        'bg' => ['Bulgarian', 'Български', 'bg_BG'],
        'bn' => ['Bengali', 'বাংলা', 'bn_BD'],
        'ca' => ['Catalan', 'Català', 'ca'],
        'cs' => ['Czech', 'Čeština', 'cs_CZ'],
        'da' => ['Danish', 'Dansk', 'da_DK'],
        'de' => ['German', 'Deutsch', 'de_DE'],
        'el' => ['Greek', 'Ελληνικά', 'el'],
        'en' => ['English', 'English', 'en_US'],
        'es' => ['Spanish', 'Español', 'es_ES'],
        'et' => ['Estonian', 'Eesti', 'et'],
        'eu' => ['Basque', 'Euskara', 'eu'],
        'fa' => ['Persian', 'فارسی', 'fa_IR'],
        'fi' => ['Finnish', 'Suomi', 'fi'],
        'fr' => ['French', 'Français', 'fr_FR'],
        'gl' => ['Galician', 'Galego', 'gl_ES'],
        'he' => ['Hebrew', 'עברית', 'he_IL'],
        'hi' => ['Hindi', 'हिन्दी', 'hi_IN'],
        'hr' => ['Croatian', 'Hrvatski', 'hr'],
        'hu' => ['Hungarian', 'Magyar', 'hu_HU'],
        'id' => ['Indonesian', 'Bahasa Indonesia', 'id_ID'],
        'is' => ['Icelandic', 'Íslenska', 'is_IS'],
        'it' => ['Italian', 'Italiano', 'it_IT'],
        'ja' => ['Japanese', '日本語', 'ja'],
        'ko' => ['Korean', '한국어', 'ko_KR'],
        'lt' => ['Lithuanian', 'Lietuvių', 'lt_LT'],
        'lv' => ['Latvian', 'Latviešu', 'lv'],
        'ms' => ['Malay', 'Bahasa Melayu', 'ms_MY'],
        'nb' => ['Norwegian (Bokmål)', 'Norsk bokmål', 'nb_NO'],
        'nl' => ['Dutch', 'Nederlands', 'nl_NL'],
        'pl' => ['Polish', 'Polski', 'pl_PL'],
        'pt' => ['Portuguese', 'Português', 'pt_BR'],
        'ro' => ['Romanian', 'Română', 'ro_RO'],
        'ru' => ['Russian', 'Русский', 'ru_RU'],
        'sk' => ['Slovak', 'Slovenčina', 'sk_SK'],
        'sl' => ['Slovenian', 'Slovenščina', 'sl_SI'],
        'sq' => ['Albanian', 'Shqip', 'sq'],
        'sr' => ['Serbian', 'Српски', 'sr_RS'],
        'sv' => ['Swedish', 'Svenska', 'sv_SE'],
        'th' => ['Thai', 'ไทย', 'th'],
        'tr' => ['Turkish', 'Türkçe', 'tr_TR'],
        'uk' => ['Ukrainian', 'Українська', 'uk'],
        'vi' => ['Vietnamese', 'Tiếng Việt', 'vi'],
        'zh' => ['Chinese (Simplified)', '简体中文', 'zh_CN'],
    ];

    /**
     * The site's languages, main language first. A stored list that is not
     * a list, or holds no known language, reads as DEFAULT.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        $stored = function_exists('get_option') ? get_option(self::OPTION, null) : null;
        $codes = is_array($stored) ? self::clean($stored) : [];

        return [] === $codes ? self::DEFAULT : $codes;
    }

    /** The main language: the first one. */
    public static function main(): string
    {
        return self::codes()[0];
    }

    public static function isKnown(string $code): bool
    {
        return isset(self::KNOWN[$code]);
    }

    /** Whether $code is one of the site's languages. */
    public static function isSiteLanguage(string $code): bool
    {
        return in_array($code, self::codes(), true);
    }

    /** The WordPress locale of $code, en_US for an unknown code. */
    public static function locale(string $code): string
    {
        return self::KNOWN[$code][2] ?? 'en_US';
    }

    /** The English name of $code (what the language model is told), or the code itself. */
    public static function name(string $code): string
    {
        return self::KNOWN[$code][0] ?? $code;
    }

    /**
     * The name for a wp-admin label: Finnish and English in the admin's
     * language, every other language in its own name ("Svenska").
     */
    public static function label(string $code): string
    {
        return match ($code) {
            Language::FINNISH => __('Finnish', 'eventcrew'),
            Language::ENGLISH => __('English', 'eventcrew'),
            default => self::KNOWN[$code][1] ?? $code,
        };
    }

    /**
     * The name of $code written in that language itself ("Suomi",
     * "Svenska"), or the code itself for an unknown code.
     */
    public static function ownName(string $code): string
    {
        return self::KNOWN[$code][1] ?? $code;
    }

    /**
     * The languages a person can choose for themselves (the bot's language
     * question and the crew page's language switch): every site language,
     * main language first, each with its own name. The names are not
     * translated, so a reader can find theirs whichever language is on.
     *
     * @return array<string, string> code => own name
     */
    public static function choices(): array
    {
        $choices = [];

        foreach (self::codes() as $code) {
            $choices[$code] = self::ownName($code);
        }

        return $choices;
    }

    /** Whether the language model is asked which language a text is written in. */
    public static function detectEnabled(): bool
    {
        return '1' === (string) get_option(self::DETECT_OPTION, '1');
    }

    /**
     * Whether the plugin's own strings exist in $code: English always, any
     * other language when languages/eventcrew-{locale}.mo is in the plugin
     * or in WordPress's languages/plugins folder. Without it they show in
     * English.
     */
    public static function hasCatalog(string $code): bool
    {
        if (Language::ENGLISH === $code) {
            return true;
        }

        if (! self::isKnown($code)) {
            return false;
        }

        $file = 'eventcrew-' . self::locale($code) . '.mo';
        $folders = [];

        if (defined('EVENTCREW_PLUGIN_DIR')) {
            $folders[] = EVENTCREW_PLUGIN_DIR . 'languages/';
        }

        if (defined('WP_LANG_DIR')) {
            $folders[] = rtrim((string) WP_LANG_DIR, '/') . '/plugins/';
        }

        foreach ($folders as $folder) {
            if (is_readable($folder . $file)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reads the languages typed in the settings field: codes separated by
     * commas or spaces, in order. A code typed twice counts once.
     *
     * @return array{codes: list<string>, unknown: list<string>}
     *     unknown holds every typed code that is not in KNOWN.
     */
    public static function parse(string $typed): array
    {
        $codes = [];
        $unknown = [];

        foreach (preg_split('/[\s,;]+/', strtolower($typed)) ?: [] as $code) {
            if ('' === $code) {
                continue;
            }

            if (! self::isKnown($code)) {
                $unknown[] = $code;

                continue;
            }

            if (! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return ['codes' => $codes, 'unknown' => array_values(array_unique($unknown))];
    }

    /**
     * Stores the three settings. An empty list stores nothing for the
     * languages, so DEFAULT applies.
     *
     * @param list<string> $codes
     */
    public static function save(array $codes, bool $translate, bool $detect): void
    {
        $codes = self::clean($codes);

        if ([] === $codes) {
            delete_option(self::OPTION);
        } else {
            update_option(self::OPTION, $codes);
        }

        update_option(TranslatedText::AUTO_OPTION, $translate ? '1' : '0');
        update_option(self::DETECT_OPTION, $detect ? '1' : '0');
    }

    /**
     * The known codes in $codes, in order, each once.
     *
     * @param array<mixed> $codes
     * @return list<string>
     */
    private static function clean(array $codes): array
    {
        $out = [];

        foreach ($codes as $code) {
            $code = is_string($code) ? strtolower(trim($code)) : '';

            if (self::isKnown($code) && ! in_array($code, $out, true)) {
                $out[] = $code;
            }
        }

        return $out;
    }
}
