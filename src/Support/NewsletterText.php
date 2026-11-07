<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The organizers' own text for the next newsletter.
 *
 * An organizer writes it once, in any language. When it is saved, it is
 * translated the same way as every other written-once text (TranslatedText:
 * the language model finds its language and translates it into every site
 * language in one request), and one text per site language is stored, keyed
 * by the language code. Each language's part of the newsletter uses its own
 * text. When the switch "Translate texts automatically with the language
 * model" is off, the model is not set up, or its answer cannot be used, the
 * text is stored as written for every language, and set() says so, so the
 * organizer can be told.
 *
 * A text stored before the site could have other languages holds only 'fi'
 * and 'en'. It reads the same way: a site language with no text of its own
 * gets one the way TranslatedText::text() finds it (the main language's
 * text, else the text as written).
 *
 * The text is kept in one option until the newsletter that carries it has
 * been sent; Newsletter then clears it (clearIfRevision(), so a text saved
 * while the newsletter was going out is kept for the next one).
 *
 * This is the one place that changes the text: the Telegram command, the
 * crew app's Admin tab and any later caller all go through set() and clear().
 *
 * The same one-time text is used by the post-event email (FollowupText): an
 * instance made with another option and action keeps its own text the same
 * way, for the next post-event email only.
 */
final class NewsletterText
{
    public const OPTION = 'eventcrew_newsletter_text';

    /** The longest text kept, in characters. A longer one is cut. */
    public const MAX_LENGTH = 2000;

    /**
     * Seconds to wait for the model when the translation runs after the
     * answer has gone out (translateAgain()), longer than a chat answer may
     * wait.
     */
    public const LATE_TIMEOUT = 60;

    /** Fired after the text is set or cleared. */
    public const CHANGED_ACTION = 'eventcrew/newsletter_text_changed';

    private readonly TranslatedText $translator;

    /**
     * $option is where the text is kept; $changedAction is fired after it is
     * set or cleared ('' fires nothing).
     */
    public function __construct(
        LlmClient $llm,
        Logger $logger,
        private readonly string $option = self::OPTION,
        private readonly string $changedAction = self::CHANGED_ACTION
    ) {
        $this->translator = new TranslatedText($llm, $logger);
    }

    /**
     * Saves $text as the text for the next newsletter, translated. An empty
     * text clears it.
     *
     * @return array<string, mixed> saved (bool), translated (bool), error
     *     (string), source (string), texts (language code => text, every
     *     site language) and the same texts under their language codes
     *     ('fi', 'en', 'sv' …). saved is false only for an empty text (which
     *     clears; texts is then []). translated is false when every language
     *     holds the text as written; error is then the error kind
     *     (TranslatedText::ERROR_OFF when the switch is off, LlmClient::ERROR_*
     *     or TranslatedText::ERROR_BAD_ANSWER).
     *
     *     With $translateNow false the model is not asked: the text is stored
     *     as written for every language, not translated, with error '', so
     *     translateAgain() can translate it later (after the answer to the
     *     organizer has gone out).
     */
    public function set(string $text, int $byPersonId, bool $translateNow = true): array
    {
        $text = self::clean($text);

        if ('' === $text) {
            $this->clear();

            return ['saved' => false, 'translated' => false, 'error' => '', 'source' => '', 'texts' => []];
        }

        $result = $translateNow ? $this->translate($text) : self::asWritten($text, '');
        $previous = $this->get();

        $stored = $result['texts'] + [
            'written' => $text,
            'source' => $result['source'],
            'translated' => $result['translated'],
            'by' => max(0, $byPersonId),
            'at' => (string) current_time('mysql'),
            'rev' => (null === $previous ? 0 : $previous['rev']) + 1,
        ];

        update_option($this->option, $stored, false);
        $this->changed();

        return $result['texts'] + [
            'saved' => true,
            'translated' => $result['translated'],
            'error' => $result['error'],
            'source' => $result['source'],
            'texts' => $result['texts'],
        ];
    }

    /**
     * Translates a stored text that is not translated (set() without
     * $translateNow, or one whose translation failed), keeping who saved it,
     * when and its revision. Nothing is done when there is no text, it is
     * already translated, or the model is not ready (switch off or not set
     * up). A text saved again while the model was asked is kept as it is.
     *
     * @return array{translated: bool, error: string, texts: array<string, string>}
     *     translated: whether the stored text is translated now. texts: the
     *     stored text in every site language ([] when there is none, or it
     *     was removed or saved again meanwhile).
     */
    public function translateAgain(int $timeout = self::LATE_TIMEOUT): array
    {
        $current = $this->get();

        if (null === $current) {
            return ['translated' => false, 'error' => '', 'texts' => []];
        }

        if ($current['translated'] || ! TranslatedText::modelReady()) {
            return ['translated' => $current['translated'], 'error' => '', 'texts' => $current['texts']];
        }

        $result = $this->translate($current['written'], $timeout);
        $latest = get_option($this->option, null);

        if (! is_array($latest) || (int) ($latest['rev'] ?? 0) !== $current['rev']) {
            // Removed or saved again while the model was asked.
            return ['translated' => false, 'error' => '', 'texts' => []];
        }

        if (! $result['translated']) {
            return ['translated' => false, 'error' => $result['error'], 'texts' => $current['texts']];
        }

        foreach (SiteLanguages::codes() as $lang) {
            unset($latest[$lang]);
        }

        update_option(
            $this->option,
            $result['texts'] + ['source' => $result['source'], 'translated' => true] + $latest,
            false
        );
        $this->changed();

        return ['translated' => true, 'error' => '', 'texts' => $result['texts']];
    }

    /**
     * The stored text, or null when there is none.
     *
     * @return array<string, mixed>|null texts (language code => text, one
     *     for every site language, main language first), the same texts under
     *     their language codes ('fi', 'en', 'sv' …, so the value is keyed by
     *     language as Newsletter::compose() reads it), source (the language
     *     code the model found, or '' when the text was not translated),
     *     translated (bool), written (the text as the organizer wrote it),
     *     by (int), at (string) and rev (int).
     */
    public function get(): ?array
    {
        $stored = get_option($this->option, null);

        if (! is_array($stored)) {
            return null;
        }

        $own = [];

        foreach ($stored as $lang => $text) {
            if (is_string($lang) && SiteLanguages::isKnown($lang) && is_string($text) && '' !== $text) {
                $own[$lang] = $text;
            }
        }

        if ([] === $own) {
            return null;
        }

        $source = (string) ($stored['source'] ?? '');
        $texts = [];

        foreach (SiteLanguages::codes() as $lang) {
            $texts[$lang] = self::inLanguage($own, $source, $lang);
        }

        $written = is_string($stored['written'] ?? null) && '' !== $stored['written']
            ? $stored['written']
            // Stored before the text as written was kept: an untranslated
            // text is the same in every language.
            : self::inLanguage($own, $source, '' !== $source ? $source : SiteLanguages::main());

        return $texts + [
            'texts' => $texts,
            'source' => $source,
            'translated' => (bool) ($stored['translated'] ?? false),
            'written' => $written,
            'by' => (int) ($stored['by'] ?? 0),
            'at' => (string) ($stored['at'] ?? ''),
            'rev' => (int) ($stored['rev'] ?? 0),
        ];
    }

    /**
     * The text of $texts (language code => text) for $lang, found the way
     * TranslatedText::text() finds it: $lang's own text, else the text in
     * the language it was written in ($source), else the main language's,
     * else the first one.
     *
     * @param array<string, string> $texts
     */
    public static function inLanguage(array $texts, string $source, string $lang): string
    {
        $written = $texts[$source] ?? (string) reset($texts);

        return TranslatedText::text(
            ['source' => $written, 'lang' => $source, 'texts' => $texts],
            $lang
        );
    }

    /**
     * $texts (language code => text) for a chat message or a screen: each
     * language's name ("Finnish:", "Svenska:") on its own line, then its
     * text, with an empty line between the languages.
     *
     * @param array<string, string> $texts
     */
    public static function listed(array $texts): string
    {
        $parts = [];

        foreach ($texts as $lang => $text) {
            $parts[] = SiteLanguages::label((string) $lang) . ":\n" . $text;
        }

        return implode("\n\n", $parts);
    }

    /** Removes the text. */
    public function clear(): void
    {
        if (null === $this->get()) {
            return;
        }

        delete_option($this->option);
        $this->changed();
    }

    /**
     * Removes the text only while it is still revision $rev: the newsletter
     * calls this after sending, so a text saved during the send survives.
     */
    public function clearIfRevision(int $rev): void
    {
        $current = $this->get();

        if (null !== $current && $current['rev'] === $rev) {
            delete_option($this->option);
        }
    }

    private function changed(): void
    {
        if ('' !== $this->changedAction) {
            do_action($this->changedAction);
        }
    }

    /**
     * Translates $text into every site language (TranslatedText), or keeps
     * it as written for every language when the switch is off or the model
     * fails.
     *
     * @return array{texts: array<string, string>, source: string, translated: bool, error: string}
     */
    private function translate(string $text, int $timeout = LlmClient::TIMEOUT): array
    {
        $result = $this->translator->translate($text, self::MAX_LENGTH, true, null, $timeout);

        if (! $result['ok']) {
            return self::asWritten($text, $result['error']);
        }

        $texts = [];

        foreach (SiteLanguages::codes() as $lang) {
            $texts[$lang] = '' !== ($result['texts'][$lang] ?? '') ? $result['texts'][$lang] : $text;
        }

        return ['texts' => $texts, 'source' => $result['lang'], 'translated' => true, 'error' => ''];
    }

    /**
     * $text as written for every site language, not translated.
     *
     * @return array{texts: array<string, string>, source: string, translated: bool, error: string}
     */
    private static function asWritten(string $text, string $error): array
    {
        return [
            'texts' => array_fill_keys(SiteLanguages::codes(), $text),
            'source' => '',
            'translated' => false,
            'error' => $error,
        ];
    }

    /** Trims the text, unifies line endings and cuts it to MAX_LENGTH. */
    private static function clean(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        return function_exists('mb_substr')
            ? mb_substr($text, 0, self::MAX_LENGTH)
            : substr($text, 0, self::MAX_LENGTH);
    }
}
