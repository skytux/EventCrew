<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * A text an organizer writes once, in any language, kept in every language
 * of the site.
 *
 * One stored value holds:
 * - source: the text as the organizer wrote it;
 * - lang: the language code of the source ('' when not known yet);
 * - detected: true when the language model chose lang, false when the
 *   organizer chose it in the language menu, when language detection is off
 *   (then it is the main language), or when it is not known;
 * - texts: one text per site language (languages(), set on the Translations
 *   page);
 * - manual: per language, true when that language's text was written or
 *   changed by hand. A manual text is never overwritten by a translation,
 *   except by "Translate again".
 *
 * A value stored before lang and detected existed reads with lang '' (found
 * on the next save). A plain string (a field that was a plain text before)
 * reads as the source, with no texts yet.
 *
 * A language added to the site has an empty, not manual text in every stored
 * value, so the next update() (a save) or fillMany() ("Translate missing" on
 * the Translations page, many texts per request) fills it. The texts of a language taken off the site are
 * kept in the value, unused, so adding it back brings them back.
 *
 * On a save (update()), the language model (LlmClient) is asked once for the
 * source's language (unless the organizer chose it, or detection is off) and
 * its translation into every other site language. The source's own language
 * gets the source itself. The model is asked only when there is something to
 * fill: the source or its language changed, a language text that is not
 * manual is empty, or "Translate again" was used.
 *
 * The switch AUTO_OPTION ("Translate texts automatically with the language
 * model", on by default) turns the model off for every text: nothing is sent,
 * and an empty language text gets the source as written. The same happens
 * when the model is not set up or its answer cannot be used; update() and
 * translate() then return the error, so the caller can say so.
 *
 * Readers use forReader(): the text in the reader's language, else in the
 * main language (the first site language), else the source.
 *
 * The settings field is drawn by field() and read back by fromPost(). Each
 * caller keeps its value in its own option; this class keeps nothing.
 */
final class TranslatedText
{
    /**
     * The default site languages. Deprecated: the languages are a setting
     * now; read them with languages().
     */
    public const LANGUAGES = SiteLanguages::DEFAULT;

    /** '1' (the default) translates with the language model; '0' does not. */
    public const AUTO_OPTION = 'eventcrew_translate_auto';

    /** The error when the switch is off: nothing was sent to the model. */
    public const ERROR_OFF = 'off';

    /** The error when the model answered with something that is not the expected JSON. */
    public const ERROR_BAD_ANSWER = 'bad_answer';

    /** Answer tokens allowed per language asked for. */
    private const TOKENS_PER_LANGUAGE = 1500;

    /** The BackgroundJobs kind of a queued translation (run by TranslationRegistry::runJob()). */
    public const JOB = 'translate_row';

    /**
     * @param bool $background When true, update() with a row id does not wait
     *        for the language model: it queues the translation as a
     *        background job (BackgroundJobs) and leaves the stored value as
     *        it is until the job is done. See inBackground().
     */
    public function __construct(
        private readonly LlmClient $llm,
        private readonly Logger $logger,
        private readonly bool $background = false
    ) {
    }

    /**
     * The same translator, but one whose update() queues the translation
     * instead of waiting for it (the wp-admin save handlers use it, so the
     * page answers at once).
     */
    public function inBackground(): self
    {
        return new self($this->llm, $this->logger, true);
    }

    /** Whether update() queues translations (inBackground()). */
    public function isBackground(): bool
    {
        return $this->background;
    }

    /**
     * Whether a translation would be sent to the language model now: the
     * switch is on and a model is set up. When it is not, nothing is queued,
     * since the answer comes at once without the model.
     */
    public static function modelReady(): bool
    {
        return self::autoEnabled() && AssistantSettings::isConfigured();
    }

    /** The BackgroundJobs target of the translation of row $row. */
    public static function jobTarget(string $row): string
    {
        return 'row:' . $row;
    }

    /**
     * The site's languages, in the order their fields are shown, the main
     * language first (SiteLanguages). Every text is kept in each of them.
     *
     * @return list<string>
     */
    public static function languages(): array
    {
        return SiteLanguages::codes();
    }

    /** The main language: the first site language. */
    public static function mainLanguage(): string
    {
        return SiteLanguages::main();
    }

    /** Whether texts are translated with the language model (the global switch). */
    public static function autoEnabled(): bool
    {
        return '1' === (string) get_option(self::AUTO_OPTION, '1');
    }

    /**
     * An empty value.
     *
     * @return array{
     *     source: string,
     *     lang: string,
     *     detected: bool,
     *     texts: array<string, string>,
     *     manual: array<string, bool>
     * }
     */
    public static function blank(): array
    {
        $languages = self::languages();

        return [
            'source' => '',
            'lang' => '',
            'detected' => false,
            'texts' => array_fill_keys($languages, ''),
            'manual' => array_fill_keys($languages, false),
        ];
    }

    /**
     * A stored value in the full shape: every site language present, a text
     * that is not a string read as ''. A plain string (a field stored before
     * it was translated) becomes the source.
     *
     * @return array{
     *     source: string,
     *     lang: string,
     *     detected: bool,
     *     texts: array<string, string>,
     *     manual: array<string, bool>
     * }
     */
    public static function normalize(mixed $stored): array
    {
        $value = self::blank();

        if (is_string($stored)) {
            $value['source'] = trim(str_replace(["\r\n", "\r"], "\n", $stored));

            return $value;
        }

        if (! is_array($stored)) {
            return $value;
        }

        $value['source'] = is_string($stored['source'] ?? null) ? $stored['source'] : '';
        $value['lang'] = self::code($stored['lang'] ?? null);
        $value['detected'] = '' !== $value['lang'] && (bool) ($stored['detected'] ?? false);
        $texts = is_array($stored['texts'] ?? null) ? $stored['texts'] : [];
        $manual = is_array($stored['manual'] ?? null) ? $stored['manual'] : [];

        foreach (self::languages() as $lang) {
            $value['texts'][$lang] = is_string($texts[$lang] ?? null) ? $texts[$lang] : '';
            $value['manual'][$lang] = (bool) ($manual[$lang] ?? false);
        }

        // Texts of languages taken off the site are kept after them.
        foreach ($texts as $lang => $text) {
            if (
                is_string($lang)
                && ! isset($value['texts'][$lang])
                && '' !== self::code($lang)
                && is_string($text)
                && '' !== $text
            ) {
                $value['texts'][$lang] = $text;
                $value['manual'][$lang] = (bool) ($manual[$lang] ?? false);
            }
        }

        return $value;
    }

    /**
     * The text for $lang: that language's text; else the source when it is
     * written in $lang; else the main language's text; else the source.
     *
     * @param array<string, mixed>|string $value A value, or a plain string.
     */
    public static function text(array|string $value, string $lang): string
    {
        $value = self::normalize($value);

        $text = $value['texts'][$lang] ?? '';

        if ('' !== $text) {
            return $text;
        }

        if ($lang === $value['lang']) {
            return $value['source'];
        }

        $main = $value['texts'][self::mainLanguage()] ?? '';

        return '' !== $main ? $main : $value['source'];
    }

    /**
     * The text of a stored value for the person reading it: in $lang, or in
     * the language text is being composed in now (Language::current()), or
     * in the main language outside any Language::run(). Falls back as text()
     * does. Takes the raw option value, a plain string included.
     */
    public static function forReader(mixed $stored, ?string $lang = null): string
    {
        $lang ??= Language::current() ?? self::mainLanguage();

        return trim(self::text(is_string($stored) ? $stored : (is_array($stored) ? $stored : []), $lang));
    }

    /**
     * The text in every site language, in order, for a message written in
     * all of them at once. A text the same as an earlier one is left out.
     *
     * @return array<string, string> By language code; empty texts left out.
     */
    public static function allLanguages(mixed $stored): array
    {
        $out = [];

        foreach (self::languages() as $lang) {
            $text = self::forReader($stored, $lang);

            if ('' !== $text && ! in_array($text, $out, true)) {
                $out[$lang] = $text;
            }
        }

        return $out;
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
     * $sourceLang is the language menu: '' is "Detect automatically", a code
     * is a language chosen by hand, and null (the menu was not posted) keeps
     * the stored choice.
     *
     * @param array<string, mixed> $previous The stored value.
     * @param array<string, string> $texts
     * @return array{
     *     value: array{
     *         source: string,
     *         lang: string,
     *         detected: bool,
     *         texts: array<string, string>,
     *         manual: array<string, bool>
     *     },
     *     error: string
     * } error is '' when nothing had to be translated or the translation
     *     worked, else ERROR_OFF, ERROR_BAD_ANSWER, or an LlmClient error kind.
     *     queued is there, true, when the translation was queued (see below).
     *
     * $refill: languages to translate even when nothing else asks for it
     * (not the source's language, nor a text edited by hand). "Try again" of
     * a failed background job uses it: the failed run already stored the
     * source in the empty language texts.
     *
     * In the background (inBackground()) with a $row id, when there is
     * something to translate and the model is ready, the translation is
     * queued as a BackgroundJobs job (target jobTarget($row)) and value is
     * $previous as it was: the old text stays in use until the job is done,
     * and the job then stores what this call would have stored. A value
     * that had no source yet gets the new source at once (its language
     * texts follow it, as text() falls back to the source).
     *
     * @param list<string> $refill
     */
    public function update(
        array|string $previous,
        string $source,
        array $texts,
        bool $again = false,
        int $maxLength = 0,
        bool $multiline = true,
        ?string $sourceLang = null,
        array $refill = [],
        ?string $row = null
    ): array {
        $plan = self::plan($previous, $source, $texts, $again, $maxLength, $multiline, $sourceLang, $refill);

        if ([] === $plan['fill']) {
            return ['value' => $plan['value'], 'error' => ''];
        }

        if ($this->background && null !== $row && '' !== $row && self::modelReady()) {
            BackgroundJobs::enqueue(self::JOB, self::jobTarget($row), [
                'row' => $row,
                'source' => $source,
                'texts' => $texts,
                'again' => $again,
                'lang' => $sourceLang,
                'refill' => $refill,
            ]);
            $before = self::normalize($previous);

            return [
                'value' => '' === $before['source'] ? $plan['value'] : $before,
                'error' => '',
                'queued' => true,
            ];
        }

        return self::apply($plan, $this->translate($plan['value']['source'], $maxLength, $multiline, $plan['lang']));
    }

    /**
     * $value with the input of the translation of row $row still waiting in
     * the background, if there is one: what the organizer last saved, for
     * the settings field. The stored value itself is not changed.
     *
     * @param array<string, mixed>|string $value
     * @return array{source: string, lang: string, detected: bool, texts: array<string, string>, manual: array<string, bool>}
     */
    public static function withPending(array|string $value, string $row): array
    {
        $value = self::normalize($value);
        $job = BackgroundJobs::latest(self::jobTarget($row));

        if (! BackgroundJobs::isActive($job)) {
            return $value;
        }

        $args = $job['args'];
        $value['source'] = is_string($args['source'] ?? null) ? $args['source'] : $value['source'];

        foreach (is_array($args['texts'] ?? null) ? $args['texts'] : [] as $lang => $text) {
            if (is_string($lang) && isset($value['texts'][$lang]) && is_string($text)) {
                $value['texts'][$lang] = $text;
            }
        }

        if (is_string($args['lang'] ?? null)) {
            $value['lang'] = $args['lang'];
            $value['detected'] = false;
        }

        return $value;
    }

    /**
     * "Translate missing" for many stored values with one request to the
     * model: for each value, what update() does when it is saved with
     * nothing changed (each empty language text that was not edited by hand
     * is filled). A value with nothing to fill is not sent.
     *
     * @param array<string, array{value: array<string, mixed>|string, maxLength: int, multiline: bool}> $items
     *     Keyed by any string, for example a row id.
     * @return array{
     *     values: array<string, array{value: array<string, mixed>, error: string}>,
     *     error: string
     * } values: per key, as update() returns it; an item the answer left out
     *     or got wrong has the error ERROR_BAD_ANSWER. error: the request's
     *     own error kind ('' when the request worked or was not needed);
     *     then every item sent has that error.
     */
    public function fillMany(array $items, int $timeout = LlmClient::TIMEOUT): array
    {
        $values = [];
        $plans = [];
        $ask = [];

        foreach ($items as $key => $item) {
            $key = (string) $key;
            $plan = self::plan(
                $item['value'],
                self::normalize($item['value'])['source'],
                [],
                false,
                $item['maxLength'],
                $item['multiline'],
                null
            );
            $values[$key] = ['value' => $plan['value'], 'error' => ''];

            if ([] !== $plan['fill']) {
                $plans[$key] = $plan;
                $ask[$key] = [
                    'source' => $plan['value']['source'],
                    'lang' => $plan['lang'],
                    'maxLength' => $item['maxLength'],
                    'multiline' => $item['multiline'],
                ];
            }
        }

        if ([] === $ask) {
            return ['values' => $values, 'error' => ''];
        }

        $answers = $this->translateMany($ask, $timeout);

        foreach ($plans as $key => $plan) {
            $values[$key] = self::apply($plan, $answers['results'][$key]);
        }

        return ['values' => $values, 'error' => $answers['error']];
    }

    /**
     * What update() works out before asking the model: the new value, the
     * source's language, and the languages to fill. When there is nothing
     * to fill, value is final.
     *
     * @param array<string, mixed>|string $previous
     * @param array<string, string> $texts
     * @return array{
     *     value: array{
     *         source: string,
     *         lang: string,
     *         detected: bool,
     *         texts: array<string, string>,
     *         manual: array<string, bool>
     *     },
     *     lang: string,
     *     detected: bool,
     *     fill: list<string>
     * }
     */
    private static function plan(
        array|string $previous,
        string $source,
        array $texts,
        bool $again,
        int $maxLength,
        bool $multiline,
        ?string $sourceLang,
        array $refill = []
    ): array {
        $before = self::normalize($previous);
        // Texts of languages that are not site languages (any more) stay.
        $value = $before;
        $value['source'] = self::clean($source, $maxLength, $multiline);
        $value['lang'] = '';
        $value['detected'] = false;
        $sourceChanged = $value['source'] !== $before['source'];

        // The language of the source: chosen by hand, kept from before, the
        // main language when detection is off, or '' when it has to be
        // detected.
        $chosen = null === $sourceLang
            ? ($before['detected'] ? '' : $before['lang'])
            : self::code($sourceLang);

        if ('' !== $chosen) {
            $lang = $chosen;
            $detected = false;
        } elseif (! $sourceChanged && $before['detected']) {
            $lang = $before['lang'];
            $detected = true;
        } elseif (! SiteLanguages::detectEnabled()) {
            $lang = self::mainLanguage();
            $detected = false;
        } else {
            $lang = '';
            $detected = false;
        }

        $langChanged = '' !== $lang && $lang !== $before['lang'];

        foreach (self::languages() as $code) {
            $posted = array_key_exists($code, $texts)
                ? self::clean((string) $texts[$code], $maxLength, $multiline)
                : $before['texts'][$code];

            $value['texts'][$code] = $posted;
            $value['manual'][$code] = match (true) {
                $again, '' === $posted => false,
                $posted !== $before['texts'][$code] => true,
                default => $before['manual'][$code],
            };
        }

        if ('' === $value['source']) {
            // No source: the texts that follow it are emptied with it.
            foreach (self::languages() as $code) {
                if (! $value['manual'][$code]) {
                    $value['texts'][$code] = '';
                }
            }

            return ['value' => $value, 'lang' => $lang, 'detected' => $detected, 'fill' => []];
        }

        $fill = [];

        foreach (self::languages() as $code) {
            if (
                $code !== $lang
                && ! $value['manual'][$code]
                && (
                    $again
                    || $sourceChanged
                    || $langChanged
                    || '' === $value['texts'][$code]
                    || in_array($code, $refill, true)
                )
            ) {
                $fill[] = $code;
            }
        }

        if ([] === $fill) {
            return [
                'value' => self::withSourceLanguage($value, $lang, $detected),
                'lang' => $lang,
                'detected' => $detected,
                'fill' => [],
            ];
        }

        return ['value' => $value, 'lang' => $lang, 'detected' => $detected, 'fill' => $fill];
    }

    /**
     * The languages update() would translate for this input ([] when it
     * would ask the model nothing).
     *
     * @param array<string, mixed>|string $previous
     * @param array<string, string> $texts
     * @param list<string> $refill
     * @return list<string>
     */
    public static function fillFor(
        array|string $previous,
        string $source,
        array $texts,
        bool $again,
        int $maxLength,
        bool $multiline,
        ?string $sourceLang,
        array $refill = []
    ): array {
        return self::plan($previous, $source, $texts, $again, $maxLength, $multiline, $sourceLang, $refill)['fill'];
    }

    /**
     * Puts a translate() result into a plan() with languages to fill. When
     * the translation failed, an empty language text gets the source.
     *
     * @param array{value: array<string, mixed>, lang: string, detected: bool, fill: list<string>} $plan
     * @param array{ok: bool, lang: string, texts: array<string, string>, error: string} $result
     * @return array{value: array<string, mixed>, error: string}
     */
    private static function apply(array $plan, array $result): array
    {
        $value = $plan['value'];
        $lang = $plan['lang'];
        $detected = $plan['detected'];
        $fill = $plan['fill'];

        if ($result['ok']) {
            if ('' === $lang) {
                $lang = $result['lang'];
                $detected = true;
            }

            foreach ($fill as $code) {
                if ($code !== $lang) {
                    $value['texts'][$code] = $result['texts'][$code];
                }
            }

            return ['value' => self::withSourceLanguage($value, $lang, $detected), 'error' => ''];
        }

        foreach ($fill as $code) {
            if ('' === $value['texts'][$code]) {
                $value['texts'][$code] = $value['source'];
            }
        }

        return ['value' => self::withSourceLanguage($value, $lang, $detected), 'error' => $result['error']];
    }

    /**
     * Sets the source language, and gives that language (when it is a site
     * language) the source itself as its text, unless that text was edited
     * by hand (on the Translations page, where every language has a cell).
     *
     * @param array{source: string, lang: string, detected: bool, texts: array<string, string>, manual: array<string, bool>} $value
     * @return array{source: string, lang: string, detected: bool, texts: array<string, string>, manual: array<string, bool>}
     */
    private static function withSourceLanguage(array $value, string $lang, bool $detected): array
    {
        $value['lang'] = $lang;
        $value['detected'] = '' !== $lang && $detected;

        if (
            '' !== $lang
            && array_key_exists($lang, $value['texts'])
            && ! ($value['manual'][$lang] && '' !== $value['texts'][$lang])
        ) {
            $value['texts'][$lang] = $value['source'];
            $value['manual'][$lang] = false;
        }

        return $value;
    }

    /**
     * Translates $source into every site language. The language it is
     * written in gets $source itself. $lang is the source's language when it
     * is known; null or '' asks the model to find it, or takes the main
     * language when language detection is off (SiteLanguages::detectEnabled()).
     *
     * @return array{ok: bool, lang: string, texts: array<string, string>, error: string}
     *     lang is the source's language ('' when it was not asked or
     *     failed). texts is empty unless ok.
     */
    public function translate(string $source, int $maxLength = 0, bool $multiline = true, ?string $lang = null): array
    {
        $failed = static fn (string $error): array => ['ok' => false, 'lang' => '', 'texts' => [], 'error' => $error];

        if (! self::autoEnabled()) {
            return $failed(self::ERROR_OFF);
        }

        $lang = self::code($lang);

        if ('' === $lang && ! SiteLanguages::detectEnabled()) {
            $lang = self::mainLanguage();
        }

        $targets = array_values(array_diff(self::languages(), [$lang]));

        if ('' !== $lang && [] === $targets) {
            // The source is in the only site language: nothing to ask.
            return ['ok' => true, 'lang' => $lang, 'texts' => [$lang => $source], 'error' => ''];
        }

        $answer = $this->llm->chat(
            [
                ['role' => 'system', 'content' => self::prompt($maxLength, $lang)],
                ['role' => 'user', 'content' => $source],
            ],
            self::TOKENS_PER_LANGUAGE * max(1, count($targets))
        );

        if (! $answer['ok']) {
            return $failed($answer['error']);
        }

        $parsed = self::parse($answer['content'], $lang);

        if (null === $parsed) {
            $this->logger->warning('Translation: the language model answer could not be read.');

            return $failed(self::ERROR_BAD_ANSWER);
        }

        $out = [];

        foreach (self::languages() as $code) {
            $out[$code] = $code === $parsed['lang']
                ? $source
                : self::clean($parsed['texts'][$code], $maxLength, $multiline);
        }

        return ['ok' => true, 'lang' => $parsed['lang'], 'texts' => $out, 'error' => ''];
    }

    /**
     * translate() for many texts with one request: a JSON list goes in (one
     * item per text: id, lang, text, and max when the text has a length
     * limit) and a JSON list comes out (one object per text, matched by id).
     * A text the answer leaves out or gets wrong fails alone, with
     * ERROR_BAD_ANSWER; the others keep their translation.
     *
     * @param array<string, array{source: string, lang: string, maxLength: int, multiline: bool}> $items
     * @return array{
     *     results: array<string, array{ok: bool, lang: string, texts: array<string, string>, error: string}>,
     *     error: string
     * } results: per key, as translate() returns it. error: the request's
     *     own error kind, '' when the request worked.
     */
    private function translateMany(array $items, int $timeout): array
    {
        $failed = static fn (string $error): array => ['ok' => false, 'lang' => '', 'texts' => [], 'error' => $error];
        $failAll = static fn (string $error): array => [
            'results' => array_map(static fn (): array => $failed($error), $items),
            'error' => $error,
        ];

        if (! self::autoEnabled()) {
            return $failAll(self::ERROR_OFF);
        }

        $results = [];
        $list = [];
        $keys = [];
        $tokens = 0;

        foreach ($items as $key => $item) {
            $lang = self::code($item['lang']);

            if ('' === $lang && ! SiteLanguages::detectEnabled()) {
                $lang = self::mainLanguage();
            }

            $items[$key]['lang'] = $lang;
            $targets = array_values(array_diff(self::languages(), [$lang]));

            if ('' !== $lang && [] === $targets) {
                $results[$key] = ['ok' => true, 'lang' => $lang, 'texts' => [$lang => $item['source']], 'error' => ''];

                continue;
            }

            $id = (string) (count($list) + 1);
            $keys[$id] = $key;
            $entry = ['id' => $id, 'lang' => $lang, 'text' => $item['source']];

            if ($item['maxLength'] > 0) {
                $entry['max'] = $item['maxLength'];
            }

            $list[] = $entry;
            $length = function_exists('mb_strlen') ? mb_strlen($item['source']) : strlen($item['source']);
            $tokens += min(self::TOKENS_PER_LANGUAGE, 100 + $length) * max(1, count($targets));
        }

        if ([] === $list) {
            return ['results' => $results, 'error' => ''];
        }

        $answer = $this->llm->chat(
            [
                ['role' => 'system', 'content' => self::batchPrompt()],
                ['role' => 'user', 'content' => (string) wp_json_encode($list)],
            ],
            $tokens,
            $timeout
        );

        if (! $answer['ok']) {
            return $failAll($answer['error']);
        }

        $content = trim($answer['content']);

        // Some models wrap JSON in a Markdown code fence.
        if (1 === preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $content, $matches)) {
            $content = $matches[1];
        }

        $decoded = json_decode($content, true);

        if (is_array($decoded) && is_array($decoded['items'] ?? null)) {
            $decoded = $decoded['items'];
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            $this->logger->warning('Translation: the language model answer could not be read.');

            return $failAll(self::ERROR_BAD_ANSWER);
        }

        $answers = [];

        foreach ($decoded as $entry) {
            if (is_array($entry) && is_scalar($entry['id'] ?? null) && isset($keys[(string) $entry['id']])) {
                $answers[$keys[(string) $entry['id']]] = $entry;
            }
        }

        foreach ($keys as $key) {
            $item = $items[$key];
            $parsed = isset($answers[$key]) ? self::parseDecoded($answers[$key], $item['lang']) : null;

            if (null === $parsed) {
                $results[$key] = $failed(self::ERROR_BAD_ANSWER);

                continue;
            }

            $out = [];

            foreach (self::languages() as $code) {
                $out[$code] = $code === $parsed['lang']
                    ? $item['source']
                    : self::clean($parsed['texts'][$code], $item['maxLength'], $item['multiline']);
            }

            $results[$key] = ['ok' => true, 'lang' => $parsed['lang'], 'texts' => $out, 'error' => ''];
        }

        return ['results' => $results, 'error' => ''];
    }

    /**
     * Reads the model's JSON answer: the source's language code and a text
     * for every site language except that one. Null when it is not that
     * shape. With $lang (the language chosen by hand), that language is the
     * source's whatever the model said.
     *
     * @return array{lang: string, texts: array<string, string>}|null
     */
    public static function parse(string $content, ?string $lang = null): ?array
    {
        $lang = self::code($lang);
        $content = trim($content);

        // Some models wrap JSON in a Markdown code fence.
        if (1 === preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $content, $matches)) {
            $content = $matches[1];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? self::parseDecoded($decoded, $lang) : null;
    }

    /**
     * parse() on the decoded JSON of one text.
     *
     * @param array<mixed> $decoded
     * @return array{lang: string, texts: array<string, string>}|null
     */
    private static function parseDecoded(array $decoded, string $lang): ?array
    {
        if ('' === $lang) {
            $lang = self::code($decoded['lang'] ?? null);

            if ('' === $lang) {
                return null;
            }
        }

        $texts = [];

        foreach (self::languages() as $code) {
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
     * language menu, the language texts and whether "Translate again" was
     * used. The caller sanitizes nothing more; update() trims and cuts.
     *
     * @return array{source: string, lang: ?string, texts: array<string, string>, again: bool}|null
     *     null when the field was not posted. lang is null when the menu was
     *     not posted, '' for "Detect automatically", else a language code.
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

        foreach (self::languages() as $lang) {
            if (isset($posted[$lang]) && is_scalar($posted[$lang])) {
                $texts[$lang] = $sanitize($posted[$lang]);
            }
        }

        $lang = null;

        if (isset($raw['lang']) && is_scalar($raw['lang'])) {
            $lang = self::code($raw['lang']);
            $lang = in_array($lang, self::languages(), true) ? $lang : '';
        }

        return [
            'source' => $sanitize(is_scalar($raw['source']) ? $raw['source'] : ''),
            'lang' => $lang,
            'texts' => $texts,
            'again' => '1' === (string) ($raw['again'] ?? ''),
        ];
    }

    /**
     * Saves one posted field drawn by field(): reads it (fromPost()) and
     * applies it to $previous (update()).
     *
     * $row is the TranslationRegistry row id of the text, so that in the
     * background the translation is queued (see update()).
     *
     * @param array<string, mixed>|string $previous
     * @return array{value: array<string, mixed>, error: string}|null null when the field was not posted.
     */
    public function save(
        array|string $previous,
        mixed $raw,
        int $maxLength = 0,
        bool $multiline = true,
        ?string $row = null
    ): ?array {
        $input = self::fromPost($raw, $multiline);

        if (null === $input) {
            return null;
        }

        return $this->update(
            $previous,
            $input['source'],
            $input['texts'],
            $input['again'],
            $maxLength,
            $multiline,
            $input['lang'],
            [],
            $row
        );
    }

    /**
     * The language's name for a label, in the admin's language.
     */
    public static function label(string $lang): string
    {
        return SiteLanguages::label($lang);
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
     * Prints the settings field: the source ("Write in any language"), the
     * language menu ("Detect automatically" or a language; after detection
     * it reads "Finnish, detected"), then one field per other language, each
     * with its "edited by hand" mark, and the "Translate again" button.
     * Posts as {$name}[source], {$name}[lang], {$name}[texts][lang] and
     * {$name}[again]. $maxLength 0 means no limit. $placeholder, when not
     * '', is shown in the empty source field.
     *
     * $row, the TranslationRegistry row id of the text, shows the state of
     * its background translation: while it waits, the field holds what was
     * last saved, with "Translating…"; a failed one says so, with "Try again".
     *
     * @param array<string, mixed>|string $value
     */
    public static function field(
        string $name,
        string $id,
        array|string $value,
        int $maxLength,
        bool $multiline,
        string $placeholder = '',
        string $row = ''
    ): void {
        $job = '' === $row ? null : BackgroundJobs::latest(self::jobTarget($row));
        $value = '' === $row ? self::normalize($value) : self::withPending($value, $row);
        $again = BackgroundJobs::isActive($job) && true === ($job['args']['again'] ?? false) ? '1' : '';
        $limit = $maxLength > 0 ? sprintf(' maxlength="%d"', $maxLength) : '';
        $input = static function (
            string $fieldName,
            string $fieldId,
            string $text,
            string $hint = ''
        ) use (
            $limit,
            $multiline
        ): void {
            $hint = '' === $hint ? '' : sprintf(' placeholder="%s"', esc_attr($hint));

            if ($multiline) {
                printf(
                    '<textarea id="%s" name="%s" rows="4"%s%s class="large-text">%s</textarea>',
                    esc_attr($fieldId),
                    esc_attr($fieldName),
                    $limit, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from an int above.
                    $hint, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
                    esc_textarea($text)
                );

                return;
            }

            printf(
                '<input type="text" id="%s" name="%s"%s%s value="%s" class="large-text">',
                esc_attr($fieldId),
                esc_attr($fieldName),
                $limit, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from an int above.
                $hint, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
                esc_attr($text)
            );
        };

        $byHand = $value['detected'] ? '' : $value['lang'];

        echo '<div class="ec-translated" style="max-width:48em">';
        printf(
            '<p><label for="%s"><strong>%s</strong></label> %s</p>',
            esc_attr($id),
            esc_html__('Write in any language', 'eventcrew'),
            BackgroundJobs::badge($job) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge().
        );
        $input($name . '[source]', $id, $value['source'], $placeholder);
        printf('<p class="description"><span class="ec-char-count" data-ec-for="%s"></span></p>', esc_attr($id));

        printf(
            '<p><label for="%1$s">%2$s</label> <select id="%1$s" name="%3$s" class="ec-translated-lang">',
            esc_attr($id . '-lang'),
            esc_html__('Language of this text', 'eventcrew'),
            esc_attr($name . '[lang]')
        );
        printf(
            '<option value=""%s>%s</option>',
            selected('', $byHand, false),
            esc_html(
                $value['detected']
                    ? sprintf(
                        /* translators: %s: a language name, e.g. Finnish */
                        __('%s, detected', 'eventcrew'),
                        self::label($value['lang'])
                    )
                    : __('Detect automatically', 'eventcrew')
            )
        );

        foreach (self::languages() as $lang) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($lang),
                selected($lang, $byHand, false),
                esc_html(self::label($lang))
            );
        }

        echo '</select></p>';

        foreach (self::languages() as $lang) {
            $langId = $id . '-' . $lang;
            $hidden = $lang === $value['lang'] && ! $value['manual'][$lang] ? ' hidden' : '';
            printf('<div class="ec-translated-text" data-ec-lang="%s"%s>', esc_attr($lang), esc_attr($hidden));
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
            echo '</div>';
        }

        printf('<input type="hidden" name="%s" value="%s">', esc_attr($name . '[again]'), esc_attr($again));
        printf(
            '<p><button type="button" class="button ec-translate-again" data-ec-again="%s">%s</button></p>',
            esc_attr($name . '[again]'),
            esc_html__('Translate again', 'eventcrew')
        );
        echo '</div>';
    }

    /**
     * Prints the script behind every "Translate again" button (it marks the
     * field and saves the form) and every language menu (a language chosen
     * by hand hides that language's translation field, as the source is that
     * text). Print it once, after the fields. With JS off the button does
     * nothing and every field stays as drawn.
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
            . "    document.querySelectorAll('.ec-translated-lang').forEach(function (menu) {\n"
            . "        var box = menu.closest('.ec-translated');\n"
            . "        if (!box) { return; }\n"
            . "        menu.addEventListener('change', function () {\n"
            . "            box.querySelectorAll('.ec-translated-text').forEach(function (part) {\n"
            . "                part.hidden = '' !== menu.value && part.getAttribute('data-ec-lang') === menu.value;\n"
            . "            });\n"
            . "        });\n"
            . "    });\n"
            . "})();\n</script>\n";
    }

    /**
     * A language code as stored: two or three lower-case letters, or ''.
     */
    private static function code(mixed $value): string
    {
        $code = strtolower(trim(is_scalar($value) ? (string) $value : ''));

        return 1 === preg_match('/^[a-z]{2,3}$/', $code) ? $code : '';
    }

    /** The English name of a language for the model. */
    private static function name(string $lang): string
    {
        return SiteLanguages::name($lang);
    }

    /** The instruction sent to the model with a list of texts (translateMany()). */
    private static function batchPrompt(): string
    {
        $targets = [];
        $shape = [];

        foreach (self::languages() as $code) {
            $name = self::name($code);
            $targets[] = $name . ' (' . $code . ')';
            $shape[] = '"' . $code . '": "the text in ' . $name . '"';
        }

        return 'You translate short texts written by the organizers of an event. '
            . 'You get a JSON list. Each item has "id", "text" and "lang": the code of the language the text is '
            . 'written in, or "" when it is not known; then first decide which language the text is written in. '
            . 'Translate each text into each of these languages: ' . implode(', ', $targets) . ', '
            . 'leaving out the language the text is already written in. '
            . 'Keep the line breaks, links, names, dates, times, emoji and placeholders such as {name} or %s '
            . 'exactly as they are. '
            . 'Do not add anything and do not leave anything out. '
            . 'When an item has "max", keep each of its translations at most that many characters long. '
            . 'Answer with JSON only, with no other text: a list with one object per item, '
            . '{"id": "the item\'s id", "lang": "the code of the language the text is written in", '
            . implode(', ', $shape) . '}';
    }

    /** The instruction sent to the model with the source. */
    private static function prompt(int $maxLength, string $lang = ''): string
    {
        $targets = [];
        $shape = [];

        foreach (self::languages() as $code) {
            if ($code === $lang) {
                continue;
            }

            $name = self::name($code);
            $targets[] = $name . ' (' . $code . ')';
            $shape[] = '"' . $code . '": "the text in ' . $name . '"';
        }

        $known = '' !== $lang;

        return 'You translate a short text written by the organizers of an event. '
            . ($known
                ? 'The text is written in ' . self::name($lang) . ' (' . $lang . '). '
                : 'First decide which language the text is written in. ')
            . ($known ? 'Translate' : 'Then translate')
            . ' it into each of these languages: ' . implode(', ', $targets) . '. '
            . 'Keep the line breaks, links, names, dates, times and emoji exactly as they are. '
            . 'Do not add anything and do not leave anything out. '
            . ($maxLength > 0 ? 'Keep each translation at most ' . $maxLength . ' characters long. ' : '')
            . ($known ? '' : 'Leave out the language the text is already written in. ')
            . 'Answer with JSON only, with no other text: '
            . '{"lang": '
            . ($known ? '"' . $lang . '"' : '"the two-letter code of the language the text is written in"')
            . ', ' . implode(', ', $shape) . '}';
    }
}
