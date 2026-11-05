<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The main text of the post-event email (FollowupMail): one for the people
 * who came (CAME, the thank-you) and one for the people who did not (MISSED,
 * the sorry-we-missed-you).
 *
 * The text is the part between the greeting ("Hi Anna,") and the sign-off
 * ("Warmly," and the site's name). {event} in it becomes the event's name and
 * {date} its date. The greeting, the buttons, the coming events, the
 * announcement invite and the footer are not part of it.
 *
 * Each text comes from one of three places, the first that has one:
 *
 * 1. The one-time text for the next post-event email, set by an organizer in
 *    the crew app's Admin tab or with the bot's /followup. It is kept the same
 *    way as the newsletter's own text (a NewsletterText with its own option):
 *    translated when it is saved, and removed by FollowupMail after the next
 *    night's emails have gone out, or when that night is stopped or too late.
 * 2. The text in wp-admin Settings > Email, a written-once text
 *    (TranslatedText): written in any language and translated into every site
 *    language by the language model, or edited by hand per language.
 * 3. The built-in text (defaultText()), in the language of the email part.
 *
 * Until someone saves another text in Settings, the email is exactly what it
 * was before these texts could be changed.
 */
final class FollowupText
{
    public const CAME = 'came';
    public const MISSED = 'missed';

    /** Both texts, in the order they are shown. */
    public const VARIANTS = [self::CAME, self::MISSED];

    /** The wp-admin texts (TranslatedText values), by variant. */
    public const SETTINGS_OPTIONS = [
        self::CAME => 'eventcrew_followup_text_came',
        self::MISSED => 'eventcrew_followup_text_missed',
    ];

    /** The one-time texts for the next post-event email (NewsletterText values), by variant. */
    public const NEXT_OPTIONS = [
        self::CAME => 'eventcrew_followup_next_came',
        self::MISSED => 'eventcrew_followup_next_missed',
    ];

    /** The longest text kept, in characters. */
    public const MAX_LENGTH = NewsletterText::MAX_LENGTH;

    /** The posted name of the settings fields: followup_text[came], followup_text[missed]. */
    public const POST_NAME = 'followup_text';

    /** Replaced with the event's name. */
    public const EVENT_TAG = '{event}';

    /** Replaced with the event's date, like 14.11.2026. */
    public const DATE_TAG = '{date}';

    /** @var array<string, NewsletterText> The one-time texts, by variant. */
    private array $next = [];

    private readonly TranslatedText $translator;

    public function __construct(LlmClient $llm, Logger $logger)
    {
        foreach (self::NEXT_OPTIONS as $variant => $option) {
            $this->next[$variant] = new NewsletterText($llm, $logger, $option, '');
        }

        $this->translator = new TranslatedText($llm, $logger);
    }

    public static function isVariant(string $variant): bool
    {
        return in_array($variant, self::VARIANTS, true);
    }

    /**
     * The built-in text of $variant, in the current language, with {event}
     * and {date} in it. These are the texts the email had before they could
     * be changed.
     */
    public static function defaultText(string $variant): string
    {
        if (self::CAME === $variant) {
            return sprintf(
                /* translators: 1: the event's name, 2: its date, e.g. 14.11.2026 */
                __('Thank you for coming to %1$s on %2$s. It was lovely to share the evening and the dance floor with you, and we hope some of that warmth went home with you.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                self::EVENT_TAG,
                self::DATE_TAG
            ) . "\n\n"
                . __('The next evenings are already on their way. Have a look at what is coming and secure your spot for your next ecstatic dance. We would love to dance with you again.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
        }

        return sprintf(
            /* translators: 1: the event's name, 2: its date, e.g. 14.11.2026 */
            __('We missed you at %1$s on %2$s. We are sorry you could not make it this time. We understand it may have been frustrating, and we hope all is well with you.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            self::EVENT_TAG,
            self::DATE_TAG
        ) . "\n\n"
            . __('There are more evenings to come, and there is a place for you on the dance floor. The next ones are below. We hope to see you soon.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
    }

    /**
     * The built-in text as a settings value: the English text as the source
     * and each site language's own text, none of them edited by hand. A site
     * language the plugin has no translation file for gets an empty text, so
     * the language model fills it ("Translate missing"); until then the
     * English text is used.
     *
     * @return array{source: string, lang: string, texts: array<string, string>, manual: array<string, bool>}
     */
    public static function defaultValue(string $variant): array
    {
        $value = TranslatedText::blank();
        $value['source'] = (string) Language::run(
            Language::ENGLISH,
            static fn (): string => self::defaultText($variant)
        );
        $value['lang'] = Language::ENGLISH;

        foreach (TranslatedText::languages() as $lang) {
            $value['texts'][$lang] = SiteLanguages::hasCatalog($lang)
                ? (string) Language::run($lang, static fn (): string => self::defaultText($variant))
                : '';
        }

        return $value;
    }

    /**
     * The wp-admin text of $variant: the stored value, or the built-in one
     * when nothing (or only empty fields) is stored.
     *
     * @return array{source: string, lang: string, texts: array<string, string>, manual: array<string, bool>}
     */
    public static function settings(string $variant): array
    {
        $option = self::SETTINGS_OPTIONS[$variant] ?? '';
        $stored = '' === $option ? null : get_option($option, null);

        if (! is_array($stored)) {
            return self::defaultValue($variant);
        }

        $value = TranslatedText::normalize($stored);

        return '' === $value['source'] . implode('', $value['texts']) ? self::defaultValue($variant) : $value;
    }

    /**
     * The one-time text of $variant for the next post-event email, or null.
     *
     * @return array{fi: string, en: string, source: string, translated: bool, by: int, at: string, rev: int}|null
     */
    public function next(string $variant): ?array
    {
        return isset($this->next[$variant]) ? $this->next[$variant]->get() : null;
    }

    /**
     * The text of $variant the next email uses in $lang, with {event} and
     * {date} still in it, and whether it is the one-time text.
     *
     * @return array{text: string, next: bool}
     */
    public function text(string $variant, string $lang): array
    {
        $next = $this->next($variant);

        if (null !== $next) {
            $text = Language::FINNISH === $lang ? $next['fi'] : $next['en'];

            return ['text' => $text, 'next' => true];
        }

        $text = TranslatedText::text(self::settings($variant), $lang);

        if ('' === trim($text)) {
            $text = (string) Language::run($lang, static fn (): string => self::defaultText($variant));
        }

        return ['text' => $text, 'next' => false];
    }

    /** Whether the next email uses a one-time text for $variant. */
    public function hasNext(string $variant): bool
    {
        return null !== $this->next($variant);
    }

    /**
     * Saves $text as the one-time text of $variant for the next post-event
     * email, translated the same way as the newsletter's text. An empty text
     * removes it.
     *
     * @return array{saved: bool, translated: bool, error: string, fi: string, en: string, source: string}
     */
    public function setNext(string $variant, string $text, int $byPersonId): array
    {
        if (! isset($this->next[$variant])) {
            return ['saved' => false, 'translated' => false, 'error' => '', 'fi' => '', 'en' => '', 'source' => ''];
        }

        return $this->next[$variant]->set($text, $byPersonId);
    }

    /** Removes the one-time text of $variant, or of both when $variant is null. */
    public function clearNext(?string $variant = null): void
    {
        foreach ($this->next as $key => $text) {
            if (null === $variant || $key === $variant) {
                $text->clear();
            }
        }
    }

    /**
     * The revision of each one-time text (0 when there is none), so the texts
     * a send used can be removed after it without removing one saved during it.
     *
     * @return array<string, int>
     */
    public function revisions(): array
    {
        $revisions = [];

        foreach (self::VARIANTS as $variant) {
            $next = $this->next($variant);
            $revisions[$variant] = null === $next ? 0 : $next['rev'];
        }

        return $revisions;
    }

    /**
     * Removes each one-time text that is still the revision in $revisions.
     *
     * @param array<string, mixed> $revisions
     */
    public function clearIfRevisions(array $revisions): void
    {
        foreach ($this->next as $variant => $text) {
            $rev = (int) ($revisions[$variant] ?? 0);

            if ($rev > 0) {
                $text->clearIfRevision($rev);
            }
        }
    }

    /**
     * Saves both wp-admin texts from the posted form, translating where
     * needed. A text that is the built-in one again, or empty, is not stored,
     * so the built-in text (in every language) applies. A field that was not
     * posted is left as it is.
     *
     * @param mixed $posted The raw $_POST[POST_NAME].
     * @return string The translation error kind ('' when none), for TranslatedText::errorNotice().
     */
    public function saveSettings(mixed $posted): string
    {
        $posted = is_array($posted) ? $posted : [];
        $error = '';

        foreach (array_keys(self::SETTINGS_OPTIONS) as $variant) {
            $input = TranslatedText::fromPost($posted[$variant] ?? null, true);

            if (null === $input) {
                continue;
            }

            $result = $this->translator->update(
                self::settings($variant),
                $input['source'],
                $input['texts'],
                $input['again'],
                self::MAX_LENGTH,
                true
            );
            self::store($variant, $result['value']);

            if ('' === $error && '' !== $result['error']) {
                $error = $result['error'];
            }
        }

        return $error;
    }

    /**
     * Stores the wp-admin text of $variant. A text that is the built-in one,
     * or empty, is not stored, so the built-in text (in every language)
     * applies.
     *
     * @param array<string, mixed> $value A TranslatedText value.
     */
    public static function store(string $variant, array $value): void
    {
        $option = self::SETTINGS_OPTIONS[$variant] ?? '';

        if ('' === $option) {
            return;
        }

        $value = TranslatedText::normalize($value);

        if (
            $value === self::defaultValue($variant)
            || '' === $value['source'] . implode('', $value['texts'])
        ) {
            delete_option($option);
        } else {
            update_option($option, $value);
        }
    }

    /**
     * Both texts as rows of the wp-admin Translations page (TranslationRegistry).
     *
     * @return list<TranslationRow>
     */
    public static function translationRows(): array
    {
        $labels = [
            self::CAME => __('Post-event email: the text for people who came', 'eventcrew'),
            self::MISSED => __('Post-event email: the text for people who did not come', 'eventcrew'),
        ];
        $rows = [];

        foreach (self::VARIANTS as $variant) {
            $rows[] = new TranslationRow(
                'email.followup.' . $variant,
                $labels[$variant],
                TranslationRow::GROUP_EMAILS,
                self::settings($variant),
                static function (array $value) use ($variant): void {
                    self::store($variant, $value);
                },
                self::MAX_LENGTH,
                true
            );
        }

        return $rows;
    }

    /** $text with {event} and {date} replaced. */
    public static function fill(string $text, string $event, string $date): string
    {
        return strtr($text, [self::EVENT_TAG => $event, self::DATE_TAG => $date]);
    }
}
