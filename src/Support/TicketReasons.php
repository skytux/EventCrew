<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The four {reason} lines of the ticket email (TicketText::REASON_TAG): why
 * the person has a ticket. One line per kind of ticket - bought, guest list,
 * crew task and free-entry credit.
 *
 * Each line is set in wp-admin Settings > Email, next to the ticket email
 * text, the same way: a written-once text (TranslatedText), written in any
 * language and translated into every site language, or edited by hand per
 * language. Until someone saves another line, the built-in line
 * (defaultText()) is used, in the language of the email part; a language
 * field left empty also gets the built-in line in that language.
 */
final class TicketReasons
{
    public const BOUGHT = 'bought';
    public const GUEST = 'guest';
    public const TASK = 'task';
    public const CREDIT = 'credit';

    /** Every kind, in the order they are shown in Settings. */
    public const KINDS = [self::BOUGHT, self::GUEST, self::TASK, self::CREDIT];

    /** The option of each kind's line is this prefix and the kind. */
    public const OPTION_PREFIX = 'eventcrew_ticket_reason_';

    /** The posted name of the settings fields: ticket_reason[bought][source], ... */
    public const POST_NAME = 'ticket_reason';

    /** The longest line kept, in characters. */
    public const MAX_LENGTH = 300;

    private readonly TranslatedText $translator;

    /**
     * @param bool $background Queue the translations of saveSettings() in
     *        the background (TranslatedText::inBackground()).
     */
    public function __construct(LlmClient $llm, Logger $logger, bool $background = false)
    {
        $this->translator = new TranslatedText($llm, $logger, $background);
    }

    public static function option(string $kind): string
    {
        return self::OPTION_PREFIX . $kind;
    }

    /** The built-in line of $kind, in the current language. */
    public static function defaultText(string $kind): string
    {
        return match ($kind) {
            self::GUEST => __('Thank you for coming! You are on the guest list.', 'eventcrew'),
            self::TASK => __('Thank you for taking a task!', 'eventcrew'),
            self::CREDIT => __('You spent a free-entry credit on this night.', 'eventcrew'),
            default => __('Thank you for buying a ticket!', 'eventcrew'),
        };
    }

    /** The label of $kind's field, in the admin's language. */
    public static function label(string $kind): string
    {
        return match ($kind) {
            self::GUEST => __('Line for a guest-list ticket', 'eventcrew'),
            self::TASK => __('Line for a crew ticket', 'eventcrew'),
            self::CREDIT => __('Line for a free-entry ticket', 'eventcrew'),
            default => __('Line for a bought ticket', 'eventcrew'),
        };
    }

    /**
     * The built-in line as a settings value: the English line as the source
     * and each site language's own line, none of them edited by hand.
     *
     * @return array<string, mixed> A TranslatedText value.
     */
    public static function defaultValue(string $kind): array
    {
        $value = TranslatedText::blank();
        $value['source'] = (string) Language::run(Language::ENGLISH, static fn (): string => self::defaultText($kind));
        $value['lang'] = Language::ENGLISH;

        foreach (TranslatedText::languages() as $lang) {
            $value['texts'][$lang] = SiteLanguages::hasCatalog($lang)
                ? (string) Language::run($lang, static fn (): string => self::defaultText($kind))
                : '';
        }

        return $value;
    }

    /**
     * The wp-admin value of $kind: the stored value, or the built-in one when
     * nothing (or only empty fields) is stored.
     *
     * @return array<string, mixed> A TranslatedText value.
     */
    public static function settings(string $kind): array
    {
        $stored = get_option(self::option($kind), null);

        if (! is_array($stored)) {
            return self::defaultValue($kind);
        }

        $value = TranslatedText::normalize($stored);

        return '' === $value['source'] . implode('', $value['texts']) ? self::defaultValue($kind) : $value;
    }

    /**
     * Every kind's wp-admin value.
     *
     * @return array<string, array<string, mixed>> Kind => TranslatedText value.
     */
    public static function allSettings(): array
    {
        $values = [];

        foreach (self::KINDS as $kind) {
            $values[$kind] = self::settings($kind);
        }

        return $values;
    }

    /** The line of $kind the email uses in $lang. */
    public static function text(string $kind, string $lang): string
    {
        $value = self::settings($kind);
        $text = trim((string) ($value['texts'][$lang] ?? ''));

        if ('' === $text) {
            $text = (string) Language::run($lang, static fn (): string => self::defaultText($kind));
        }

        return $text;
    }

    /**
     * Saves every line from the posted form, translating where needed. A
     * line that is the built-in one again, or empty, is not stored, so the
     * built-in line (in every language) applies. A line that was not posted
     * is left as it is.
     *
     * @param mixed $posted The raw $_POST[POST_NAME].
     * @return string The first translation error kind ('' when none), for TranslatedText::errorNotice().
     */
    public function saveSettings(mixed $posted): string
    {
        if (! is_array($posted)) {
            return '';
        }

        $error = '';

        foreach (self::KINDS as $kind) {
            $result = $this->translator->save(
                self::settings($kind),
                $posted[$kind] ?? null,
                self::MAX_LENGTH,
                false,
                'email.ticket_reason.' . $kind
            );

            if (null === $result) {
                continue;
            }

            $value = $result['value'];

            if ($value === self::defaultValue($kind) || '' === $value['source'] . implode('', $value['texts'])) {
                delete_option(self::option($kind));
            } else {
                update_option(self::option($kind), $value);
            }

            if ('' === $error && '' !== $result['error']) {
                $error = $result['error'];
            }
        }

        return $error;
    }

    /**
     * The lines as rows of the wp-admin Translations page
     * (TranslationRegistry).
     *
     * @return list<TranslationRow>
     */
    public static function translationRows(): array
    {
        $rows = [];

        foreach (self::KINDS as $kind) {
            $rows[] = new TranslationRow(
                'email.ticket_reason.' . $kind,
                self::label($kind),
                TranslationRow::GROUP_EMAILS,
                self::settings($kind),
                static function (array $value) use ($kind): void {
                    update_option(self::option($kind), $value);
                },
                self::MAX_LENGTH,
                false
            );
        }

        return $rows;
    }
}
