<?php

declare(strict_types=1);

namespace EventCrew\Support;

use Closure;

/**
 * One row of the wp-admin Translations page: one translatable text.
 *
 * - id: unique on the page, made of letters, digits, '-', '_' and '.'
 *   (for example "email.followup.came");
 * - label: what the text is, in the admin's language;
 * - group: one of the GROUP_* keys, or any other short key (shown as it is);
 * - value: the text in TranslatedText's shape: source, lang (the source's
 *   language, '' when not known), texts per language, manual per language;
 * - save: called with the new value (the same shape) to store it;
 * - maxLength, multiline: what TranslatedText::update() cuts the text to;
 * - fixedSource: the source is the code's own text and cannot be changed on
 *   the page (the bot's texts); only the language texts are edited.
 * - required: placeholders, like '{email}', that the source and every
 *   language text must keep; a value without one is not stored
 *   (missingRequired()).
 *
 * Rows come from TranslationRegistry, which asks each provider for its rows
 * every time the page is drawn or saved, so a row always holds the stored
 * value.
 */
final class TranslationRow
{
    public const GROUP_EMAILS = 'emails';
    public const GROUP_TELEGRAM = 'telegram';
    public const GROUP_TEXTS = 'texts';
    public const GROUP_MESSAGES = 'messages';
    public const GROUP_BOT = 'bot';

    /** @var array{source: string, lang: string, texts: array<string, string>, manual: array<string, bool>} */
    public readonly array $value;

    /**
     * @param array<string, mixed> $value
     * @param Closure(array{source: string, lang: string, texts: array<string, string>, manual: array<string, bool>}): void $save
     * @param list<string> $required
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $group,
        array $value,
        public readonly Closure $save,
        public readonly int $maxLength = 0,
        public readonly bool $multiline = true,
        public readonly bool $fixedSource = false,
        public readonly array $required = []
    ) {
        $this->value = TranslatedText::normalize($value);
    }

    /** The group's name for the page, in the admin's language. */
    public function groupLabel(): string
    {
        return match ($this->group) {
            self::GROUP_EMAILS => __('Emails', 'eventcrew'),
            self::GROUP_TELEGRAM => __('Telegram bot', 'eventcrew'),
            self::GROUP_TEXTS => __('Texts', 'eventcrew'),
            self::GROUP_MESSAGES => __('Messages', 'eventcrew'),
            self::GROUP_BOT => __('Bot answers and buttons', 'eventcrew'),
            default => $this->group,
        };
    }

    /**
     * Whether a site language has no text yet (or only the source, copied in
     * after a failed translation) while there is a source: the row
     * "Translate missing" fills.
     */
    public function isMissing(): bool
    {
        if ('' === trim($this->value['source'])) {
            return false;
        }

        foreach (TranslatedText::languages() as $lang) {
            if ('' === trim($this->value['texts'][$lang] ?? '') || TranslatedText::isFallback($this->value, $lang)) {
                return true;
            }
        }

        return false;
    }

    /** Whether a site language's text was edited by hand. */
    public function hasManual(): bool
    {
        foreach (TranslatedText::languages() as $lang) {
            if ($this->value['manual'][$lang] ?? false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The required placeholders that the source or a language text of $value
     * does not contain. An empty text is not checked: it means the built-in
     * text, or a language not translated yet.
     *
     * @param array<string, mixed> $value
     * @return list<string>
     */
    public function missingRequired(array $value): array
    {
        $value = TranslatedText::normalize($value);
        $texts = array_filter(
            [$value['source'], ...array_values($value['texts'])],
            static fn (string $text): bool => '' !== trim($text)
        );
        $missing = [];

        foreach ($this->required as $tag) {
            foreach ($texts as $text) {
                if (! str_contains($text, $tag)) {
                    $missing[] = $tag;

                    break;
                }
            }
        }

        return $missing;
    }

    /**
     * Stores $value through the save callback.
     *
     * @param array<string, mixed> $value
     */
    public function store(array $value): void
    {
        ($this->save)(TranslatedText::normalize($value));
    }
}
