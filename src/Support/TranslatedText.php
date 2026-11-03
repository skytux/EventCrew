<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * A text an organizer writes once, in any language, kept in every language
 * of the site.
 *
 * One stored value holds:
 * - source: the text as the organizer wrote it;
 * - texts: one text per language in LANGUAGES;
 * - manual: per language, true when that language's text was written or
 *   changed by hand. A manual text is not overwritten by a translation,
 *   except by "Translate again".
 *
 * On a save (update()), the language model (LlmClient) is asked once for the
 * source's language and its translation into every other language. A
 * language the source is already written in gets the source itself. The
 * model is asked only when there is something to fill: the source changed, a
 * language text that is not manual is empty, or "Translate again" was used.
 *
 * The switch AUTO_OPTION ("Translate texts automatically with the language
 * model", on by default) turns the model off for every text: nothing is sent,
 * and an empty language text gets the source as written. The same happens
 * when the model is not set up or its answer cannot be used; update() and
 * translate() then return the error, so the caller can say so.
 *
 * The settings field is drawn by field() and read back by fromPost(). Each
 * caller keeps its value in its own option; this class keeps nothing.
 */
final class TranslatedText
{
    /**
     * The site's languages, in the order their fields are shown. Every text
     * is kept in each of them. Add a language code here to add a language.
     */
    public const LANGUAGES = [Language::FINNISH, Language::ENGLISH];

    /** '1' (the default) translates with the language model; '0' does not. */
    public const AUTO_OPTION = 'eventcrew_translate_auto';

    /** The error when the switch is off: nothing was sent to the model. */
    public const ERROR_OFF = 'off';

    /** The error when the model answered with something that is not the expected JSON. */
    public const ERROR_BAD_ANSWER = 'bad_answer';

    /** The language names the model is told, by code. */
    private const NAMES = [
        Language::FINNISH => 'Finnish',
        Language::ENGLISH => 'English',
    ];

    /** Answer tokens allowed per language asked for. */
    private const TOKENS_PER_LANGUAGE = 1500;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly Logger $logger
    ) {
    }

    /** Whether texts are translated with the language model (the global switch). */
    public static function autoEnabled(): bool
    {
        return '1' === (string) get_option(self::AUTO_OPTION, '1');
    }

    /**
     * An empty value.
     *
     * @return array{source: string, texts: array<string, string>, manual: array<string, bool>}
     */
    public static function blank(): array
    {
        return [
            'source' => '',
            'texts' => array_fill_keys(self::LANGUAGES, ''),
            'manual' => array_fill_keys(self::LANGUAGES, false),
        ];
    }

    /**
     * A stored value in the full shape: every language present, a text that
     * is not a string read as ''.
     *
     * @return array{source: string, texts: array<string, string>, manual: array<string, bool>}
     */
    public static function normalize(mixed $stored): array
    {
        $value = self::blank();

        if (! is_array($stored)) {
            return $value;
        }

        $value['source'] = is_string($stored['source'] ?? null) ? $stored['source'] : '';
        $texts = is_array($stored['texts'] ?? null) ? $stored['texts'] : [];
        $manual = is_array($stored['manual'] ?? null) ? $stored['manual'] : [];

        foreach (self::LANGUAGES as $lang) {
            $value['texts'][$lang] = is_string($texts[$lang] ?? null) ? $texts[$lang] : '';
            $value['manual'][$lang] = (bool) ($manual[$lang] ?? false);
        }

        return $value;
    }

    /**
     * The text for $lang: that language's text, or the source when it is
     * empty.
     *
     * @param array<string, mixed> $value
     */
    public static function text(array $value, string $lang): string
    {
        $value = self::normalize($value);
        $text = $value['texts'][$lang] ?? '';

        return '' !== $text ? $text : $value['source'];
    }

    /**
     * Applies one save of the settings field.
     *
     * $texts are the language fields as posted (a language missing from it
     * keeps its stored text). A posted language text that differs from the
     * stored one was edited by hand and becomes manual; an emptied one stops
     * being manual. $again ("Translate again") translates every language,
     * manual ones included, and clears the manual marks.
     *
     * @param array<string, mixed> $previous The stored value.
     * @param array<string, string> $texts
     * @return array{
     *     value: array{source: string, texts: array<string, string>, manual: array<string, bool>},
     *     error: string
     * } error is '' when nothing had to be translated or the translation
     *     worked, else ERROR_OFF, ERROR_BAD_ANSWER, or an LlmClient error kind.
     */
    public function update(
        array $previous,
        string $source,
        array $texts,
        bool $again = false,
        int $maxLength = 0,
        bool $multiline = true
    ): array {
        $before = self::normalize($previous);
        $value = self::blank();
        $value['source'] = self::clean($source, $maxLength, $multiline);

        foreach (self::LANGUAGES as $lang) {
            $posted = array_key_exists($lang, $texts)
                ? self::clean((string) $texts[$lang], $maxLength, $multiline)
                : $before['texts'][$lang];

            $value['texts'][$lang] = $posted;
            $value['manual'][$lang] = match (true) {
                $again, '' === $posted => false,
                $posted !== $before['texts'][$lang] => true,
                default => $before['manual'][$lang],
            };
        }

        $sourceChanged = $value['source'] !== $before['source'];
        $fill = [];

        foreach (self::LANGUAGES as $lang) {
            if (! $value['manual'][$lang] && ($again || $sourceChanged || '' === $value['texts'][$lang])) {
                $fill[] = $lang;
            }
        }

        if ([] === $fill) {
            return ['value' => $value, 'error' => ''];
        }

        if ('' === $value['source']) {
            // No source: the texts that follow it are emptied with it.
            foreach ($fill as $lang) {
                $value['texts'][$lang] = '';
            }

            return ['value' => $value, 'error' => ''];
        }

        $result = $this->translate($value['source'], $maxLength, $multiline);

        foreach ($fill as $lang) {
            if ($result['ok']) {
                $value['texts'][$lang] = $result['texts'][$lang];
            } elseif ('' === $value['texts'][$lang]) {
                $value['texts'][$lang] = $value['source'];
            }
        }

        return ['value' => $value, 'error' => $result['error']];
    }

    /**
     * Translates $source into every language in LANGUAGES. The language it
     * is written in gets $source itself.
     *
     * @return array{ok: bool, lang: string, texts: array<string, string>, error: string}
     *     lang is the language the model found ('' when it was not asked or
     *     failed). texts is empty unless ok.
     */
    public function translate(string $source, int $maxLength = 0, bool $multiline = true): array
    {
        $failed = static fn (string $error): array => ['ok' => false, 'lang' => '', 'texts' => [], 'error' => $error];

        if (! self::autoEnabled()) {
            return $failed(self::ERROR_OFF);
        }

        $answer = $this->llm->chat(
            [
                ['role' => 'system', 'content' => self::prompt($maxLength)],
                ['role' => 'user', 'content' => $source],
            ],
            self::TOKENS_PER_LANGUAGE * (count(self::LANGUAGES) - 1)
        );

        if (! $answer['ok']) {
            return $failed($answer['error']);
        }

        $parsed = self::parse($answer['content']);

        if (null === $parsed) {
            $this->logger->warning('Translation: the language model answer could not be read.');

            return $failed(self::ERROR_BAD_ANSWER);
        }

        $out = [];

        foreach (self::LANGUAGES as $lang) {
            $out[$lang] = $lang === $parsed['lang']
                ? $source
                : self::clean($parsed['texts'][$lang], $maxLength, $multiline);
        }

        return ['ok' => true, 'lang' => $parsed['lang'], 'texts' => $out, 'error' => ''];
    }

    /**
     * Reads the model's JSON answer: the source's language code and a text
     * for every language in LANGUAGES except that one. Null when it is not
     * that shape.
     *
     * @return array{lang: string, texts: array<string, string>}|null
     */
    public static function parse(string $content): ?array
    {
        $content = trim($content);

        // Some models wrap JSON in a Markdown code fence.
        if (1 === preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $content, $matches)) {
            $content = $matches[1];
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            return null;
        }

        $lang = strtolower(trim(is_string($decoded['lang'] ?? null) ? $decoded['lang'] : ''));

        if (1 !== preg_match('/^[a-z]{2,3}$/', $lang)) {
            return null;
        }

        $texts = [];

        foreach (self::LANGUAGES as $code) {
            $text = is_string($decoded[$code] ?? null) ? trim($decoded[$code]) : '';

            if ('' === $text && $code !== $lang) {
                return null;
            }

            $texts[$code] = $text;
        }

        return ['lang' => $lang, 'texts' => $texts];
    }

    /**
     * Unifies line endings, trims, keeps one line when not $multiline, and
     * cuts to $maxLength characters (0 = no limit).
     */
    public static function clean(string $text, int $maxLength = 0, bool $multiline = true): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        if (! $multiline) {
            $text = (string) preg_replace('/\s*\n\s*/', ' ', $text);
        }

        $text = trim($text);

        if ($maxLength > 0) {
            $text = function_exists('mb_substr') ? mb_substr($text, 0, $maxLength) : substr($text, 0, $maxLength);
        }

        return $text;
    }

    /**
     * Reads the field drawn by field() from a posted array: the source, the
     * language texts and whether "Translate again" was used. The caller
     * sanitizes nothing more; update() trims and cuts.
     *
     * @return array{source: string, texts: array<string, string>, again: bool}|null
     *     null when the field was not posted.
     */
    public static function fromPost(mixed $raw, bool $multiline = true): ?array
    {
        if (! is_array($raw) || ! isset($raw['source'])) {
            return null;
        }

        $sanitize = static fn (mixed $text): string => $multiline
            ? sanitize_textarea_field(wp_unslash((string) $text))
            : sanitize_text_field(wp_unslash((string) $text));

        $texts = [];
        $posted = is_array($raw['texts'] ?? null) ? $raw['texts'] : [];

        foreach (self::LANGUAGES as $lang) {
            if (isset($posted[$lang]) && is_scalar($posted[$lang])) {
                $texts[$lang] = $sanitize($posted[$lang]);
            }
        }

        return [
            'source' => $sanitize(is_scalar($raw['source']) ? $raw['source'] : ''),
            'texts' => $texts,
            'again' => '1' === (string) ($raw['again'] ?? ''),
        ];
    }

    /**
     * The language's name for a label, in the admin's language.
     */
    public static function label(string $lang): string
    {
        return match ($lang) {
            Language::FINNISH => __('Finnish', 'eventcrew'),
            Language::ENGLISH => __('English', 'eventcrew'),
            default => $lang,
        };
    }

    /**
     * The admin notice sentence for an update() or translate() error, or ''
     * when there is nothing to say (no error, or the switch is off).
     */
    public static function errorNotice(string $error): string
    {
        if ('' === $error || self::ERROR_OFF === $error) {
            return '';
        }

        if (LlmClient::ERROR_NOT_CONFIGURED === $error) {
            return __('The texts were not translated because no language model is set up. Empty language fields now hold the text as you wrote it.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
        }

        return sprintf(
            /* translators: %s: the kind of error, e.g. timeout or http_401 */
            __('The language model could not translate the texts (%s). The language fields keep what they had, and empty ones now hold the text as you wrote it.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            $error
        );
    }

    /**
     * Prints the settings field: the source ("Write in any language"), then
     * one field per language, each with its "edited by hand" mark, and the
     * "Translate again" button. Posts as {$name}[source], {$name}[texts][lang]
     * and {$name}[again].
     *
     * @param array<string, mixed> $value
     */
    public static function field(string $name, string $id, array $value, int $maxLength, bool $multiline): void
    {
        $value = self::normalize($value);
        $input = static function (string $fieldName, string $fieldId, string $text) use ($maxLength, $multiline): void {
            if ($multiline) {
                printf(
                    '<textarea id="%s" name="%s" rows="4" maxlength="%d" class="large-text">%s</textarea>',
                    esc_attr($fieldId),
                    esc_attr($fieldName),
                    (int) $maxLength,
                    esc_textarea($text)
                );

                return;
            }

            printf(
                '<input type="text" id="%s" name="%s" maxlength="%d" value="%s" class="large-text">',
                esc_attr($fieldId),
                esc_attr($fieldName),
                (int) $maxLength,
                esc_attr($text)
            );
        };

        echo '<div class="ec-translated" style="max-width:48em">';
        printf(
            '<p><label for="%s"><strong>%s</strong></label></p>',
            esc_attr($id),
            esc_html__('Write in any language', 'eventcrew')
        );
        $input($name . '[source]', $id, $value['source']);
        printf('<p class="description"><span class="ec-char-count" data-ec-for="%s"></span></p>', esc_attr($id));

        foreach (self::LANGUAGES as $lang) {
            $langId = $id . '-' . $lang;
            printf(
                '<p style="margin-top:1em"><label for="%s">%s</label>%s</p>',
                esc_attr($langId),
                esc_html(self::label($lang)),
                $value['manual'][$lang]
                    ? ' <em>(' . esc_html__('edited by hand, not translated again', 'eventcrew') . ')</em>'
                    : ''
            );
            $input($name . '[texts][' . $lang . ']', $langId, $value['texts'][$lang]);
            printf(
                '<p class="description"><span class="ec-char-count" data-ec-for="%s"></span></p>',
                esc_attr($langId)
            );
        }

        printf('<input type="hidden" name="%s" value="">', esc_attr($name . '[again]'));
        printf(
            '<p><button type="button" class="button ec-translate-again" data-ec-again="%s">%s</button></p>',
            esc_attr($name . '[again]'),
            esc_html__('Translate again', 'eventcrew')
        );
        echo '</div>';
    }

    /**
     * Prints the script behind every "Translate again" button: it marks the
     * field and saves the form. Print it once, after the fields. With JS off
     * the button does nothing.
     */
    public static function script(): void
    {
        echo "<script>\n(function () {\n"
            . "    document.querySelectorAll('.ec-translate-again').forEach(function (button) {\n"
            . "        button.addEventListener('click', function () {\n"
            . "            var form = button.form;\n"
            . "            if (!form) { return; }\n"
            . "            var mark = form.querySelector('input[type=hidden][name=\"'"
            . " + button.getAttribute('data-ec-again') + '\"]');\n"
            . "            if (mark) { mark.value = '1'; }\n"
            . "            if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }\n"
            . "        });\n"
            . "    });\n"
            . "})();\n</script>\n";
    }

    /** The instruction sent to the model with the source. */
    private static function prompt(int $maxLength): string
    {
        $targets = [];
        $shape = [];

        foreach (self::LANGUAGES as $lang) {
            $name = self::NAMES[$lang] ?? $lang;
            $targets[] = $name . ' (' . $lang . ')';
            $shape[] = '"' . $lang . '": "the text in ' . $name . '"';
        }

        return 'You translate a short text written by the organizers of an event. '
            . 'First decide which language the text is written in. '
            . 'Then translate it into each of these languages: ' . implode(', ', $targets) . '. '
            . 'Keep the line breaks, links, names, dates, times and emoji exactly as they are. '
            . 'Do not add anything and do not leave anything out. '
            . ($maxLength > 0 ? 'Keep each translation at most ' . $maxLength . ' characters long. ' : '')
            . 'Leave out the language the text is already written in. '
            . 'Answer with JSON only, with no other text: '
            . '{"lang": "the two-letter code of the language the text is written in", ' . implode(', ', $shape) . '}';
    }
}
