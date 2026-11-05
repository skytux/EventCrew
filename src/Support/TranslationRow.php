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
 * - maxLength, multiline: what TranslatedText::update() cuts the text to.
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

    /** @var array{source: string, lang: string, texts: array<string, string>, manual: array<string, bool>} */
    public readonly array $value;

    /**
     * @param array<string, mixed> $value
     * @param Closure(array{source: string, lang: string, texts: array<string, string>, manual: array<string, bool>}): void $save
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $group,
        array $value,
        public readonly Closure $save,
        public readonly int $maxLength = 0,
        public readonly bool $multiline = true
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
            default => $this->group,
        };
    }

    /**
     * Whether a site language has no text yet while there is a source: the
     * row "Translate missing" fills.
     */
    public function isMissing(): bool
    {
        if ('' === trim($this->value['source'])) {
            return false;
        }

        foreach (TranslatedText::languages() as $lang) {
            if ('' === trim($this->value['texts'][$lang] ?? '')) {
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
     * Stores $value through the save callback.
     *
     * @param array<string, mixed> $value
     */
    public function store(array $value): void
    {
        ($this->save)(TranslatedText::normalize($value));
    }
}
