<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The organizers' own text for the next newsletter.
 *
 * An organizer writes it once, in any language. When it is saved, it is
 * translated the same way as every other written-once text (TranslatedText:
 * the language model finds its language and translates it into Finnish and
 * English), and both versions are stored. The newsletter's Finnish part uses
 * the Finnish version and its English part the English version. When the
 * switch "Translate texts automatically with the language model" is off, the
 * model is not set up, or its answer cannot be used, the text is stored as
 * written for both languages, and set() says so, so the organizer can be told.
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
     * @return array{saved: bool, translated: bool, error: string, fi: string, en: string, source: string}
     *     saved is false only for an empty text (which clears). translated is
     *     false when both languages hold the text as written; error is then
     *     the error kind (TranslatedText::ERROR_OFF when the switch is
     *     off, LlmClient::ERROR_* or TranslatedText::ERROR_BAD_ANSWER).
     */
    public function set(string $text, int $byPersonId): array
    {
        $text = self::clean($text);

        if ('' === $text) {
            $this->clear();

            return ['saved' => false, 'translated' => false, 'error' => '', 'fi' => '', 'en' => '', 'source' => ''];
        }

        $result = $this->translate($text);
        $previous = $this->get();

        $stored = [
            'fi' => $result['fi'],
            'en' => $result['en'],
            'source' => $result['source'],
            'translated' => $result['translated'],
            'by' => max(0, $byPersonId),
            'at' => (string) current_time('mysql'),
            'rev' => (null === $previous ? 0 : $previous['rev']) + 1,
        ];

        update_option($this->option, $stored, false);
        $this->changed();

        return [
            'saved' => true,
            'translated' => $result['translated'],
            'error' => $result['error'],
            'fi' => $result['fi'],
            'en' => $result['en'],
            'source' => $result['source'],
        ];
    }

    /**
     * The stored text, or null when there is none.
     *
     * @return array{fi: string, en: string, source: string, translated: bool, by: int, at: string, rev: int}|null
     *     source is the language code the model found ('fi', 'en', another
     *     code, or '' when the text was not translated).
     */
    public function get(): ?array
    {
        $stored = get_option($this->option, null);

        if (! is_array($stored)) {
            return null;
        }

        $fi = (string) ($stored['fi'] ?? '');
        $en = (string) ($stored['en'] ?? '');

        if ('' === $fi && '' === $en) {
            return null;
        }

        return [
            'fi' => '' !== $fi ? $fi : $en,
            'en' => '' !== $en ? $en : $fi,
            'source' => (string) ($stored['source'] ?? ''),
            'translated' => (bool) ($stored['translated'] ?? false),
            'by' => (int) ($stored['by'] ?? 0),
            'at' => (string) ($stored['at'] ?? ''),
            'rev' => (int) ($stored['rev'] ?? 0),
        ];
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
     * Translates $text into Finnish and English (TranslatedText), or keeps
     * it as written for both when the switch is off or the model fails.
     *
     * @return array{fi: string, en: string, source: string, translated: bool, error: string}
     */
    private function translate(string $text): array
    {
        $result = $this->translator->translate($text, self::MAX_LENGTH);

        if (! $result['ok']) {
            return ['fi' => $text, 'en' => $text, 'source' => '', 'translated' => false, 'error' => $result['error']];
        }

        return [
            'fi' => $result['texts'][Language::FINNISH] ?? $text,
            'en' => $result['texts'][Language::ENGLISH] ?? $text,
            'source' => $result['lang'],
            'translated' => true,
            'error' => '',
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
