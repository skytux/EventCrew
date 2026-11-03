<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The organizers' own text for the next newsletter.
 *
 * An organizer writes it once, in Finnish or in English. When it is saved, the
 * language model (LlmClient) is asked which of the two languages it is in and
 * for a translation into the other one, and both versions are stored. The
 * newsletter's Finnish part uses the Finnish version and its English part the
 * English version. When the model is not set up or its answer cannot be used,
 * the text is stored as written for both languages, and set() says so, so the
 * organizer can be told.
 *
 * The text is kept in one option until the newsletter that carries it has
 * been sent; Newsletter then clears it (clearIfRevision(), so a text saved
 * while the newsletter was going out is kept for the next one).
 *
 * This is the one place that changes the text: the Telegram command, the
 * crew app's Admin tab and any later caller all go through set() and clear().
 */
final class NewsletterText
{
    public const OPTION = 'eventcrew_newsletter_text';

    /** The longest text kept, in characters. A longer one is cut. */
    public const MAX_LENGTH = 2000;

    /** Fired after the text is set or cleared. */
    public const CHANGED_ACTION = 'eventcrew/newsletter_text_changed';

    /** The instruction sent to the model with the text. */
    private const PROMPT = 'You translate a short newsletter text between Finnish and English. '
        . 'First decide whether the text is written in Finnish or in English. '
        . 'Then translate it into the other of those two languages. '
        . 'Keep the line breaks, links, names, dates, times and emoji exactly as they are. '
        . 'Do not add anything and do not leave anything out. '
        . 'Answer with JSON only, with no other text: '
        . '{"lang": "fi" or "en" (the language the text is written in), "translation": "the translated text"}';

    public function __construct(
        private readonly LlmClient $llm,
        private readonly Logger $logger
    ) {
    }

    /**
     * Saves $text as the text for the next newsletter, translated. An empty
     * text clears it.
     *
     * @return array{saved: bool, translated: bool, error: string, fi: string, en: string, source: string}
     *     saved is false only for an empty text (which clears). translated is
     *     false when both languages hold the text as written; error is then
     *     the model's error kind (LlmClient::ERROR_* or 'bad_answer').
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

        update_option(self::OPTION, $stored, false);
        do_action(self::CHANGED_ACTION);

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
     *     source is the language it was written in ('fi', 'en', or '' when
     *     the model was not asked).
     */
    public function get(): ?array
    {
        $stored = get_option(self::OPTION, null);

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

        delete_option(self::OPTION);
        do_action(self::CHANGED_ACTION);
    }

    /**
     * Removes the text only while it is still revision $rev: the newsletter
     * calls this after sending, so a text saved during the send survives.
     */
    public function clearIfRevision(int $rev): void
    {
        $current = $this->get();

        if (null !== $current && $current['rev'] === $rev) {
            delete_option(self::OPTION);
        }
    }

    /**
     * Asks the model for the language of $text and its translation.
     *
     * @return array{fi: string, en: string, source: string, translated: bool, error: string}
     */
    private function translate(string $text): array
    {
        $untranslated = static fn (string $error): array => [
            'fi' => $text,
            'en' => $text,
            'source' => '',
            'translated' => false,
            'error' => $error,
        ];

        $answer = $this->llm->chat(
            [
                ['role' => 'system', 'content' => self::PROMPT],
                ['role' => 'user', 'content' => $text],
            ],
            1500
        );

        if (! $answer['ok']) {
            return $untranslated($answer['error']);
        }

        $parsed = self::parse($answer['content']);

        if (null === $parsed) {
            $this->logger->warning('Newsletter text: the language model answer could not be read.');

            return $untranslated('bad_answer');
        }

        $translation = self::clean($parsed['translation']);

        return [
            'fi' => Language::FINNISH === $parsed['lang'] ? $text : $translation,
            'en' => Language::FINNISH === $parsed['lang'] ? $translation : $text,
            'source' => $parsed['lang'],
            'translated' => true,
            'error' => '',
        ];
    }

    /**
     * Reads the model's JSON answer. Null when it is not the expected shape.
     *
     * @return array{lang: string, translation: string}|null
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

        $lang = strtolower(trim((string) ($decoded['lang'] ?? '')));
        $translation = trim((string) ($decoded['translation'] ?? ''));

        if (! in_array($lang, [Language::FINNISH, Language::ENGLISH], true) || '' === $translation) {
            return null;
        }

        return ['lang' => $lang, 'translation' => $translation];
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
