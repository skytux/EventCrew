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
 * 2. The text saved on the Messages page for the kinds followup_came and
 *    followup_missed (MessageTemplates). That text is the whole email text,
 *    with the greeting and the sign-off.
 * 3. The built-in text (defaultText()), in the language of the email part.
 *
 * Until someone saves a text on the Messages page, the email is exactly what
 * it was before these texts could be changed.
 *
 * The text used to be edited in wp-admin Settings > Email. moveSettingsText()
 * moves a text saved there to the Messages page once, and keeps the old
 * option as a backup.
 */
final class FollowupText
{
    public const CAME = 'came';
    public const MISSED = 'missed';

    /** Both texts, in the order they are shown. */
    public const VARIANTS = [self::CAME, self::MISSED];

    /**
     * The old wp-admin Settings texts (TranslatedText values), by variant.
     * Read only by moveSettingsText(), and kept as a backup.
     */
    public const SETTINGS_OPTIONS = [
        self::CAME => 'eventcrew_followup_text_came',
        self::MISSED => 'eventcrew_followup_text_missed',
    ];

    /** Set once moveSettingsText() has run, so it runs one time only. */
    public const MOVED_OPTION = 'eventcrew_followup_text_moved';

    /** The Messages page kind (MessageTemplates) of each variant. */
    public const TEMPLATES = [
        self::CAME => 'followup_came',
        self::MISSED => 'followup_missed',
    ];

    /** The one-time texts for the next post-event email (NewsletterText values), by variant. */
    public const NEXT_OPTIONS = [
        self::CAME => 'eventcrew_followup_next_came',
        self::MISSED => 'eventcrew_followup_next_missed',
    ];

    /** The longest text kept, in characters. */
    public const MAX_LENGTH = NewsletterText::MAX_LENGTH;

    /** Replaced with the event's name. */
    public const EVENT_TAG = '{event}';

    /** Replaced with the event's date, like 14.11.2026. */
    public const DATE_TAG = '{date}';

    /** @var array<string, NewsletterText> The one-time texts, by variant. */
    private array $next = [];

    public function __construct(LlmClient $llm, Logger $logger)
    {
        foreach (self::NEXT_OPTIONS as $variant => $option) {
            $this->next[$variant] = new NewsletterText($llm, $logger, $option, '');
        }
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
     * One time only: moves each text saved in the old wp-admin Settings
     * fields to the Messages page, as the text of the kind's template. The
     * greeting and the sign-off are added around it in each language, as the
     * email did. A kind that already has a text on the Messages page keeps
     * it. The old options are not deleted; they stay as a backup.
     */
    public static function moveSettingsText(): void
    {
        if (get_option(self::MOVED_OPTION, false)) {
            return;
        }

        foreach (self::SETTINGS_OPTIONS as $variant => $option) {
            $old = get_option($option, null);
            $id = self::TEMPLATES[$variant];

            if (! is_array($old) || null !== MessageTemplates::stored($id)[MessageTemplates::BODY]) {
                continue;
            }

            $old = TranslatedText::normalize($old);

            if ('' === trim($old['source'] . implode('', $old['texts']))) {
                continue;
            }

            $wrap = static fn (string $text, string $lang): string => '' === trim($text)
                ? ''
                : (string) Language::run($lang, static fn (): string => MessageTemplates::followupBody($text));
            $body = $old;
            // The source is wrapped in its own language when it is known.
            $body['source'] = $wrap($old['source'], '' !== $old['lang'] ? $old['lang'] : Language::ENGLISH);

            foreach ($old['texts'] as $lang => $text) {
                $body['texts'][$lang] = $wrap($text, $lang);
            }

            $stored = get_option(MessageTemplates::OPTION_PREFIX . $id, []);
            $stored = is_array($stored) ? $stored : [];
            $stored[MessageTemplates::BODY] = $body;
            update_option(MessageTemplates::OPTION_PREFIX . $id, $stored, false);
        }

        update_option(self::MOVED_OPTION, 1);
    }

    /** Whether a text for $variant is saved on the Messages page. */
    public static function hasTemplateText(string $variant): bool
    {
        $id = self::TEMPLATES[$variant] ?? '';

        return '' !== $id && null !== MessageTemplates::stored($id)[MessageTemplates::BODY];
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
     * {date} still in it, whether it is the one-time text, and whether it is
     * the Messages page text (template: the whole email text, with the
     * greeting, the sign-off and {first_name} in it).
     *
     * @return array{text: string, next: bool, template: bool}
     */
    public function text(string $variant, string $lang): array
    {
        $next = $this->next($variant);

        if (null !== $next) {
            $text = Language::FINNISH === $lang ? $next['fi'] : $next['en'];

            return ['text' => $text, 'next' => true, 'template' => false];
        }

        if (self::hasTemplateText($variant)) {
            $id = self::TEMPLATES[$variant];
            $text = (string) Language::run(
                $lang,
                static fn (): string => MessageTemplates::raw($id, MessageTemplates::BODY)
            );

            return ['text' => $text, 'next' => false, 'template' => true];
        }

        $text = (string) Language::run($lang, static fn (): string => self::defaultText($variant));

        return ['text' => $text, 'next' => false, 'template' => false];
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

    /** $text with {event} and {date} replaced. */
    public static function fill(string $text, string $event, string $date): string
    {
        return strtr($text, [self::EVENT_TAG => $event, self::DATE_TAG => $date]);
    }
}
