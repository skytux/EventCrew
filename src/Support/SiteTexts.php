<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Telegram\TicketController;
use EventCrew\Web\PwaController;

/**
 * The single texts in Settings that people outside wp-admin read: the
 * signature, the ticket note, the infrastructure note and the app's name.
 *
 * Each is a written-once text (TranslatedText): written in any language and
 * kept in every site language. A reader gets the text in the language of the
 * person it is for (Language::current()), else in the main language, else
 * the text as written.
 *
 * Until these were TranslatedText values each option held one plain string.
 * TranslatedText::normalize() reads such a string as the source, with its
 * language unknown, so it keeps working unchanged until the next save, which
 * detects its language and translates it.
 */
final class SiteTexts
{
    /**
     * Posted field name => [option, longest text in characters (0: no
     * limit), more than one line].
     */
    public const FIELDS = [
        'signature' => [Signature::OPTION, 0, true],
        'ticket_note' => [TicketController::NOTE_OPTION, 0, true],
        'infrastructure_note' => [HelpPage::INFRASTRUCTURE_OPTION, 0, true],
        'app_name' => [PwaController::NAME_OPTION, 0, false],
        'app_short_name' => [PwaController::SHORT_NAME_OPTION, PwaController::SHORT_NAME_MAX, false],
    ];

    /**
     * The stored value of $field (TranslatedText::normalize()).
     *
     * @return array<string, mixed>
     */
    public static function value(string $field): array
    {
        $option = self::FIELDS[$field][0] ?? '';
        $stored = '' === $option ? null : get_option($option, null);
        $value = TranslatedText::normalize($stored);

        // Before the signature was a TranslatedText value, its Finnish
        // wording had an option of its own (Signature::FINNISH_OPTION), and
        // the main option was used for every other language. Until the next
        // save, that Finnish wording is the Finnish text, as edited by hand,
        // and the main option is read as English.
        if (Signature::OPTION === $option && is_string($stored)) {
            $finnish = trim(str_replace(["\r\n", "\r"], "\n", (string) get_option(Signature::FINNISH_OPTION, '')));

            if ('' !== $finnish && array_key_exists(Language::FINNISH, $value['texts'])) {
                $value['lang'] = Language::ENGLISH;
                $value['texts'][Language::FINNISH] = $finnish;
                $value['manual'][Language::FINNISH] = true;
            }
        }

        return $value;
    }

    /**
     * The text of $field in $lang (default: the current language, else the
     * main language), or '' when none is set.
     */
    public static function text(string $field, ?string $lang = null): string
    {
        return TranslatedText::forReader(self::value($field), $lang);
    }

    /**
     * The text of $field in every site language, each once, for a message
     * written in all of them.
     *
     * @return array<string, string> Language => text.
     */
    public static function allLanguages(string $field): array
    {
        return TranslatedText::allLanguages(self::value($field));
    }

    /**
     * Saves every field from the posted form, translating where needed. A
     * field that was not posted is left as it is.
     *
     * @param array<string, mixed> $posted The raw $_POST.
     * @return string The first translation error kind ('' when none), for TranslatedText::errorNotice().
     */
    public static function save(TranslatedText $translator, array $posted): string
    {
        $error = '';

        foreach (self::FIELDS as $field => [$option, $max, $multiline]) {
            $result = $translator->save(self::value($field), $posted[$field] ?? null, $max, $multiline, 'text.' . $field);

            if (null === $result) {
                continue;
            }

            update_option($option, $result['value']);

            if ('' === $error && '' !== $result['error']) {
                $error = $result['error'];
            }
        }

        return $error;
    }

    /**
     * Every field as a row of the wp-admin Translations page
     * (TranslationRegistry).
     *
     * @return list<TranslationRow>
     */
    public static function translationRows(): array
    {
        $labels = [
            'signature' => __('Signature', 'eventcrew'),
            'ticket_note' => __('Ticket note', 'eventcrew'),
            'infrastructure_note' => __('Infrastructure', 'eventcrew'),
            'app_name' => __('App name', 'eventcrew'),
            'app_short_name' => __('App short name', 'eventcrew'),
        ];
        $rows = [];

        foreach (self::FIELDS as $field => [$option, $max, $multiline]) {
            $rows[] = new TranslationRow(
                'text.' . $field,
                $labels[$field] ?? $field,
                TranslationRow::GROUP_TEXTS,
                self::value($field),
                static function (array $value) use ($option): void {
                    update_option($option, $value);
                },
                $max,
                $multiline
            );
        }

        return $rows;
    }
}
