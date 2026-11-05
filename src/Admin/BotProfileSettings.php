<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Support\Language;
use EventCrew\Support\TranslatedText;
use EventCrew\Support\TranslationRow;
use EventCrew\Telegram\TelegramClient;

/**
 * The bot's About and Description texts, on the Telegram bot tab.
 *
 * Each is a written-once text (TranslatedText): the organizer writes it in
 * any language and it is kept in every site language. Saving sends each
 * language's text that changed to Telegram: the English text without a
 * language code (Telegram shows it to everyone whose language has no text of
 * its own), every other language with its own code. An empty text is sent as
 * "", which clears it on Telegram.
 *
 * Until version 1.99.7 the two texts were four plain fields, English and
 * Finnish (LEGACY_OPTIONS). migrate() turns them into the new value once:
 * both language texts are kept as they were and marked as edited by hand, and
 * the source is the English text. The old options are left in place.
 */
final class BotProfileSettings
{
    /**
     * field => [option, Bot API method, Telegram's length limit, more than one line].
     */
    public const FIELDS = [
        'about' => ['eventcrew_telegram_about', 'setMyShortDescription', 120, false],
        'description' => ['eventcrew_telegram_description', 'setMyDescription', 512, true],
    ];

    /** The options of the four old fields, by field and language. */
    public const LEGACY_OPTIONS = [
        'about' => [
            Language::ENGLISH => 'eventcrew_telegram_about_en',
            Language::FINNISH => 'eventcrew_telegram_about_fi',
        ],
        'description' => [
            Language::ENGLISH => 'eventcrew_telegram_description_en',
            Language::FINNISH => 'eventcrew_telegram_description_fi',
        ],
    ];

    /** The language whose text is sent without a language code. */
    public const DEFAULT_LANGUAGE = Language::ENGLISH;

    /**
     * The texts Telegram last accepted, keyed "telegram_{field}_{language}"
     * (the old field names, so the migration sends nothing again). A text is
     * sent only when it differs from this, so a send that failed is tried
     * again on the next save.
     */
    public const SENT_OPTION = 'eventcrew_telegram_profile_sent';

    /** The posted name of each field: telegram_profile[about], telegram_profile[description]. */
    public const POST_NAME = 'telegram_profile';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly TranslatedText $translator
    ) {
    }

    /** Set to '1' once migrate() has run. */
    public const MIGRATED_OPTION = 'eventcrew_telegram_profile_migrated';

    /**
     * Turns the four old options into the new values. Runs once per site
     * (MIGRATED_OPTION); a field whose new option exists already is left
     * alone, and a field with no old text gets nothing stored.
     */
    public static function migrate(): void
    {
        if ('1' === (string) get_option(self::MIGRATED_OPTION, '')) {
            return;
        }

        self::migrateFields();
        update_option(self::MIGRATED_OPTION, '1');
    }

    private static function migrateFields(): void
    {
        foreach (self::FIELDS as $field => [$option]) {
            if (is_array(get_option($option, null))) {
                continue;
            }

            $value = TranslatedText::blank();

            foreach (self::LEGACY_OPTIONS[$field] as $lang => $legacy) {
                $text = (string) get_option($legacy, '');
                $value['texts'][$lang] = $text;
                $value['manual'][$lang] = '' !== $text;
            }

            if ('' === implode('', $value['texts'])) {
                continue;
            }

            $value['source'] = $value['texts'][self::DEFAULT_LANGUAGE];
            update_option($option, $value);
        }
    }

    /**
     * The stored value of $field (TranslatedText::normalize()).
     *
     * @return array<string, mixed>
     */
    public static function value(string $field): array
    {
        $option = self::FIELDS[$field][0] ?? '';

        return TranslatedText::normalize('' === $option ? null : get_option($option, null));
    }

    /**
     * Saves both texts from the posted form, translating where needed, and
     * sends each changed language text to Telegram. A field that was not
     * posted is left as it is.
     *
     * @param mixed $posted The raw $_POST[POST_NAME].
     * @return array{ok: bool, message: string}
     *     ok is false when Telegram refused a text or the translation
     *     failed; message is what to add to the save notice ('' when there
     *     is nothing to say).
     */
    public function save(mixed $posted): array
    {
        $posted = is_array($posted) ? $posted : [];
        $sent = get_option(self::SENT_OPTION, []);
        $sent = is_array($sent) ? $sent : [];
        $changed = 0;
        $failed = [];
        $translateError = '';

        foreach (self::FIELDS as $field => [$option, , $max, $multiline]) {
            $result = $this->translator->save(self::value($field), $posted[$field] ?? null, $max, $multiline);

            if (null === $result) {
                continue;
            }

            update_option($option, $result['value']);

            if ('' === $translateError && '' !== $result['error']) {
                $translateError = $result['error'];
            }

            $changed += $this->push($field, $result['value'], $sent, $failed);
        }

        update_option(self::SENT_OPTION, $sent, false);

        return $this->outcome($changed, $failed, $translateError);
    }

    /**
     * Stores the value of $field as edited on the Translations page and
     * sends each changed language text to Telegram.
     *
     * @param array<string, mixed> $value A TranslatedText value.
     * @return array{ok: bool, message: string} As save().
     */
    public function store(string $field, array $value): array
    {
        if (! isset(self::FIELDS[$field])) {
            return ['ok' => false, 'message' => ''];
        }

        $value = TranslatedText::normalize($value);
        update_option(self::FIELDS[$field][0], $value);

        $sent = get_option(self::SENT_OPTION, []);
        $sent = is_array($sent) ? $sent : [];
        $failed = [];
        $changed = $this->push($field, $value, $sent, $failed);
        update_option(self::SENT_OPTION, $sent, false);

        return $this->outcome($changed, $failed, '');
    }

    /**
     * Both texts as rows of the wp-admin Translations page
     * (TranslationRegistry). Saving a row also sends it to Telegram.
     *
     * @return list<TranslationRow>
     */
    public function translationRows(): array
    {
        $labels = [
            'about' => __('Telegram bot: About', 'eventcrew'),
            'description' => __('Telegram bot: Description', 'eventcrew'),
        ];
        $rows = [];

        foreach (self::FIELDS as $field => [, , $max, $multiline]) {
            $rows[] = new TranslationRow(
                'telegram.' . $field,
                $labels[$field],
                TranslationRow::GROUP_TELEGRAM,
                self::value($field),
                function (array $value) use ($field): void {
                    $this->store($field, $value);
                },
                $max,
                $multiline
            );
        }

        return $rows;
    }

    /**
     * Sends each language text of $field that differs from what Telegram
     * last accepted ($sent, updated). Adds each refusal to $failed.
     *
     * @param array{texts: array<string, string>} $value
     * @param array<string, mixed> $sent
     * @param list<string> $failed
     * @return int How many texts were sent.
     */
    private function push(string $field, array $value, array &$sent, array &$failed): int
    {
        $method = self::FIELDS[$field][1];
        $changed = 0;

        foreach (TranslatedText::languages() as $lang) {
            $key = 'telegram_' . $field . '_' . $lang;
            $text = $value['texts'][$lang] ?? '';

            if ($text === (string) ($sent[$key] ?? '')) {
                continue;
            }

            $changed++;
            $code = self::DEFAULT_LANGUAGE === $lang ? '' : $lang;
            $answer = 'setMyDescription' === $method
                ? $this->telegram->setMyDescription($text, $code)
                : $this->telegram->setMyShortDescription($text, $code);

            if (null === $answer) {
                $failed[] = self::label($field, $lang) . ': ' . $this->telegram->lastError();

                continue;
            }

            $sent[$key] = $text;
        }

        return $changed;
    }

    /**
     * The result of a save: ok, and the notice text.
     *
     * @param list<string> $failed
     * @return array{ok: bool, message: string}
     */
    private function outcome(int $changed, array $failed, string $translateError): array
    {
        if ([] !== $failed) {
            $message = sprintf(
                /* translators: %s: each text Telegram refused, with Telegram's reason */
                __('Telegram did not accept these texts. They are sent again the next time you save: %s', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal.
                implode('; ', $failed)
            );
        } else {
            $message = $changed > 0
                ? __('The changed About and Description texts were sent to Telegram.', 'eventcrew')
                : '';
        }

        $notice = TranslatedText::errorNotice($translateError);

        return [
            'ok' => [] === $failed && '' === $notice,
            'message' => trim($notice . ' ' . $message),
        ];
    }

    /** "About (English)" and the like, for the save notice. */
    private static function label(string $field, string $lang): string
    {
        return match ($field . '_' . $lang) {
            'about_en' => __('About (English)', 'eventcrew'),
            'about_fi' => __('About (Finnish)', 'eventcrew'),
            'description_en' => __('Description (English)', 'eventcrew'),
            'description_fi' => __('Description (Finnish)', 'eventcrew'),
            default => ('about' === $field ? __('About', 'eventcrew') : __('Description', 'eventcrew'))
                . ' (' . TranslatedText::label($lang) . ')',
        };
    }
}
