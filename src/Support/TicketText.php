<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The text of the ticket email (TicketMail): one text for every kind of
 * ticket - a ticket bought on Holvi, a guest-list ticket, a crew ticket and a
 * free-entry credit's ticket.
 *
 * It is set in wp-admin Settings > Email as a written-once text
 * (TranslatedText): written in any language and translated into every site
 * language by the language model, or edited by hand per language. Until
 * someone saves another text there, the built-in text (defaultText()) is
 * used, in the language of the email part.
 *
 * Placeholders, filled per ticket by fill():
 * - {first_name}: the holder's first name;
 * - {event}: the event's name;
 * - {date}: the event's date, like 14.11.2026;
 * - {start_time}: the event's start time, like 19:00;
 * - {reason}: why they have a ticket, one of four lines (TicketReasons);
 * - {tasks}: a crew member's tasks on the night, as a list;
 * - {plus_ones}: how many people a guest may bring, like "2 people";
 * - {organization}: the organization's name (Organization).
 *
 * An empty {first_name} is taken out with the space in front of it ("Hi
 * {first_name}," becomes "Hi,"). Any other empty placeholder leaves out its
 * whole paragraph, so no empty line or half sentence is left.
 *
 * A first paragraph written in *stars* on one line is the greeting: the email
 * draws it on its own line in italics.
 */
final class TicketText
{
    /** The wp-admin text (a TranslatedText value). */
    public const OPTION = 'eventcrew_ticket_text';

    /** The posted name of the settings field: ticket_text[source], ... */
    public const POST_NAME = 'ticket_text';

    /** The longest text kept, in characters. */
    public const MAX_LENGTH = NewsletterText::MAX_LENGTH;

    public const FIRST_NAME_TAG = '{first_name}';
    public const EVENT_TAG = '{event}';
    public const DATE_TAG = '{date}';
    public const START_TIME_TAG = EventTimes::START_TAG;
    public const REASON_TAG = '{reason}';
    public const TASKS_TAG = '{tasks}';
    public const PLUS_ONES_TAG = '{plus_ones}';
    public const ORGANIZATION_TAG = Organization::TAG;

    /** Every placeholder, in the order they are listed under the field. */
    public const TAGS = [
        self::FIRST_NAME_TAG,
        self::EVENT_TAG,
        self::DATE_TAG,
        self::START_TIME_TAG,
        self::REASON_TAG,
        self::TASKS_TAG,
        self::PLUS_ONES_TAG,
        self::ORGANIZATION_TAG,
    ];

    private readonly TranslatedText $translator;

    /**
     * @param bool $background Queue the translations of saveSettings() in
     *        the background (TranslatedText::inBackground()).
     */
    public function __construct(LlmClient $llm, Logger $logger, bool $background = false)
    {
        $this->translator = new TranslatedText($llm, $logger, $background);
    }

    /**
     * The built-in text, in the current language: the wording the ticket
     * email had before it could be changed, with the placeholders in it.
     */
    public static function defaultText(): string
    {
        return implode("\n\n", [
            /* translators: %s: the ticket holder's first name */
            '*' . sprintf(__('Hi %s,', 'eventcrew'), self::FIRST_NAME_TAG) . '*',
            self::REASON_TAG,
            sprintf(
                /* translators: 1: the event's name, 2: its date, e.g. 14.11.2026 */
                __('Here is your ticket to %1$s on %2$s. Show the QR code below at the door.', 'eventcrew'),
                self::EVENT_TAG,
                self::DATE_TAG
            ),
            __('Your tasks on this night:', 'eventcrew') . "\n" . self::TASKS_TAG,
            /* translators: %s: how many people the guest may bring, e.g. "2 people" */
            sprintf(__('You can bring %s with you.', 'eventcrew'), self::PLUS_ONES_TAG),
        ]);
    }

    /**
     * The built-in text as a settings value: the English text as the source
     * and each site language's own text, none of them edited by hand.
     *
     * @return array<string, mixed> A TranslatedText value.
     */
    public static function defaultValue(): array
    {
        $value = TranslatedText::blank();
        $value['source'] = (string) Language::run(Language::ENGLISH, static fn (): string => self::defaultText());
        $value['lang'] = Language::ENGLISH;

        foreach (TranslatedText::languages() as $lang) {
            $value['texts'][$lang] = SiteLanguages::hasCatalog($lang)
                ? (string) Language::run($lang, static fn (): string => self::defaultText())
                : '';
        }

        return $value;
    }

    /**
     * The wp-admin text: the stored value, or the built-in one when nothing
     * (or only empty fields) is stored.
     *
     * @return array<string, mixed> A TranslatedText value.
     */
    public static function settings(): array
    {
        $stored = get_option(self::OPTION, null);

        if (! is_array($stored)) {
            return self::defaultValue();
        }

        $value = TranslatedText::normalize($stored);

        return '' === $value['source'] . implode('', $value['texts']) ? self::defaultValue() : $value;
    }

    /** The text the email uses in $lang, placeholders still in it. */
    public static function text(string $lang): string
    {
        $text = TranslatedText::text(self::settings(), $lang);

        if ('' === trim($text)) {
            $text = (string) Language::run($lang, static fn (): string => self::defaultText());
        }

        return $text;
    }

    /**
     * Saves the wp-admin text from the posted form, translating where needed.
     * A text that is the built-in one again, or empty, is not stored, so the
     * built-in text (in every language) applies. Nothing changes when the
     * field was not posted.
     *
     * @param mixed $posted The raw $_POST[POST_NAME].
     * @return string The translation error kind ('' when none), for TranslatedText::errorNotice().
     */
    public function saveSettings(mixed $posted): string
    {
        $result = $this->translator->save(self::settings(), $posted, self::MAX_LENGTH, true, 'email.ticket');

        if (null === $result) {
            return '';
        }

        $value = $result['value'];

        if ($value === self::defaultValue() || '' === $value['source'] . implode('', $value['texts'])) {
            delete_option(self::OPTION);
        } else {
            update_option(self::OPTION, $value);
        }

        return $result['error'];
    }

    /**
     * The text as a row of the wp-admin Translations page
     * (TranslationRegistry).
     *
     * @return list<TranslationRow>
     */
    public static function translationRows(): array
    {
        return [
            new TranslationRow(
                'email.ticket',
                __('Ticket email text', 'eventcrew'),
                TranslationRow::GROUP_EMAILS,
                self::settings(),
                static function (array $value): void {
                    update_option(self::OPTION, $value);
                },
                self::MAX_LENGTH,
                true
            ),
        ];
    }

    /**
     * $text with its placeholders replaced by $values (placeholder => text).
     * A placeholder missing from $values counts as empty. An empty
     * {first_name} is taken out with the space in front of it; any other
     * empty placeholder leaves out its whole paragraph. A list (lines that
     * start with "- ") put into a paragraph becomes a paragraph of its own.
     *
     * @param array<string, string> $values
     */
    public static function fill(string $text, array $values): string
    {
        $values = array_map(static fn (string $value): string => trim($value), $values);
        $filled = [];

        foreach (preg_split('/\n\s*\n/', str_replace(["\r\n", "\r"], "\n", trim($text))) ?: [] as $paragraph) {
            if (self::hasEmptyTag($paragraph, $values)) {
                continue;
            }

            if ('' === ($values[self::FIRST_NAME_TAG] ?? '')) {
                // "Hi {first_name}," becomes "Hi,"; "See you, {first_name}." becomes "See you.".
                $paragraph = (string) preg_replace(
                    '/[ \t]*,?[ \t]*\{first_name\}[ \t]*([,.!?:;])/',
                    '$1',
                    $paragraph
                );
                $paragraph = (string) preg_replace('/\{first_name\}[,.!?:;]?[ \t]*/', '', $paragraph);
            }

            $paragraph = strtr($paragraph, array_intersect_key($values, array_flip(self::TAGS)));
            $paragraph = trim(self::separateLists($paragraph));

            if ('' !== $paragraph) {
                $filled[] = $paragraph;
            }
        }

        return implode("\n\n", $filled);
    }

    /**
     * Whether $paragraph holds a placeholder other than {first_name} whose
     * value is empty.
     *
     * @param array<string, string> $values
     */
    private static function hasEmptyTag(string $paragraph, array $values): bool
    {
        foreach (self::TAGS as $tag) {
            if (self::FIRST_NAME_TAG !== $tag && str_contains($paragraph, $tag) && '' === ($values[$tag] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /** $paragraph with a blank line between its "- " lines and its other lines. */
    private static function separateLists(string $paragraph): string
    {
        $groups = [];
        $previous = null;

        foreach (explode("\n", $paragraph) as $line) {
            $isItem = str_starts_with(trim($line), '- ');

            if (null === $previous || $isItem !== $previous) {
                $groups[] = [];
            }

            $groups[count($groups) - 1][] = $line;
            $previous = $isItem;
        }

        return implode("\n\n", array_map(static fn (array $lines): string => implode("\n", $lines), $groups));
    }
}
