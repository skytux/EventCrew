<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;

/**
 * Decides whether a message goes out "hybrid" - one message in every site
 * language, one part per language, each part starting with its short
 * language code (FI, EN) - or in one language only.
 *
 * - The site languages are SiteLanguages::codes(), main language first.
 * - isAllowed(): hybrid works with up to 3 site languages (Q284). With 4 or more it
 *   is switched off for every message kind (Q289), and wp-admin says why.
 * - isOn($kind): the "Hybrid" tick of that message kind (on by default for
 *   every kind that was hybrid before the tick existed), and isAllowed().
 * - languagesFor($kind, $recipient): every site language when the kind is
 *   hybrid; otherwise the one language: a person's stored language, or
 *   English for a person who has not chosen one (personLanguage(), as
 *   Language::forPerson() does); the main language (the first site
 *   language) for ticket buyers, guests, subscribers and anyone else who is
 *   not a person, whose language is not known (Q290).
 *
 * Every sender that used to write Finnish then English asks this class which
 * languages to write in, so the rule is in one place.
 */
final class HybridPolicy
{
    /** The stored ticks: array<kind, bool>. A kind not in it is on. */
    public const OPTION = 'eventcrew_hybrid_messages';

    /** Hybrid works up to this many site languages; one more switches it off. */
    public const MAX_LANGUAGES = 3;

    /** The ticket email (TicketMail::compose()). */
    public const TICKET = 'ticket';

    /** The "Your ticket is cancelled" and "Your ticket moved" emails. */
    public const TICKET_CANCELLED = 'ticket_cancelled';

    /** The post-event email (FollowupMail). */
    public const FOLLOWUP = 'followup';

    /** The "Task cancelled" email (TaskCancelledNotice). */
    public const TASK_CANCELLED = 'task_cancelled';

    /** The newsletter's "confirm your subscription" email (NewsletterConfirmMail). */
    public const NEWSLETTER_CONFIRM = 'newsletter_confirm';

    /** The next events announcement, to subscribers whose language is not known. */
    public const NEWSLETTER = 'newsletter';

    /** The Telegram group's "X is covering for Y" message (TakeoverService). */
    public const TAKEOVER_GROUP = 'takeover_group';

    /** Every message kind with a Hybrid tick, in the order the settings show them. */
    public const KINDS = [
        self::TICKET,
        self::TICKET_CANCELLED,
        self::FOLLOWUP,
        self::TASK_CANCELLED,
        self::NEWSLETTER_CONFIRM,
        self::NEWSLETTER,
        self::TAKEOVER_GROUP,
    ];

    /**
     * The site languages, main language first (SiteLanguages, set on the
     * Translations page).
     *
     * @return list<string>
     */
    public static function siteLanguages(): array
    {
        return SiteLanguages::codes();
    }

    /** The main language: the first site language. */
    public static function mainLanguage(): string
    {
        return SiteLanguages::main();
    }

    /** True when hybrid messages can be used: 3 site languages or fewer. */
    public static function isAllowed(): bool
    {
        return count(self::siteLanguages()) <= self::MAX_LANGUAGES;
    }

    /** The stored Hybrid tick of $kind, whether or not hybrid is allowed now. */
    public static function ticked(string $kind): bool
    {
        $stored = function_exists('get_option') ? get_option(self::OPTION, []) : [];

        if (! is_array($stored) || ! array_key_exists($kind, $stored)) {
            return in_array($kind, self::KINDS, true);
        }

        return (bool) $stored[$kind];
    }

    /** True when $kind goes out hybrid: its tick is on and hybrid is allowed. */
    public static function isOn(string $kind): bool
    {
        return self::isAllowed() && self::ticked($kind);
    }

    /**
     * The languages a $kind message to $recipient is written in, main
     * language first: every site language when it is hybrid, else one.
     * $recipient is a Person (personLanguage()), a language code, or null
     * when the language is not known (a ticket buyer, a guest, a subscriber,
     * a Telegram group): then the main language. A language that is not a
     * site language also gets the main language.
     *
     * @return list<string>
     */
    public static function languagesFor(string $kind, Person|string|null $recipient = null): array
    {
        $site = self::siteLanguages();

        if (self::isOn($kind)) {
            return $site;
        }

        $language = $recipient instanceof Person ? self::personLanguage($recipient) : $recipient;
        $language = is_string($language) ? strtolower(trim($language)) : '';

        return [in_array($language, $site, true) ? $language : $site[0]];
    }

    /**
     * The language a person reads: their stored language, or English when
     * they have not chosen one (the same rule as Language::forPerson()).
     */
    public static function personLanguage(Person $person): string
    {
        return $person->language ?? Language::ENGLISH;
    }

    /**
     * $words run once in each of $languages (Language::run()).
     *
     * @template T
     * @param list<string> $languages
     * @param callable(): T $words
     * @return array<string, T> language => what $words returned
     */
    public static function inEach(array $languages, callable $words): array
    {
        $parts = [];

        foreach ($languages as $language) {
            $parts[$language] = Language::run($language, $words);
        }

        return $parts;
    }

    /**
     * One field of each part, keyed by language.
     *
     * @param array<string, array<string, mixed>> $parts
     * @return array<string, string>
     */
    public static function field(array $parts, string $key): array
    {
        return array_map(static fn (array $part): string => (string) ($part[$key] ?? ''), $parts);
    }

    /**
     * The texts on one line, "fi / en / sv", each different text once. One
     * language, or all the same, gives that one text.
     *
     * @param array<string, string> $texts
     */
    public static function join(array $texts, string $glue = ' / '): string
    {
        return implode($glue, array_unique(array_values(array_map('strval', $texts))));
    }

    /**
     * True when the texts differ, so each needs its language code in front.
     *
     * @param array<string, string> $texts
     */
    public static function differ(array $texts): bool
    {
        return count(array_unique(array_values($texts))) > 1;
    }

    /** The short language code shown in front of a part: "FI", "EN". */
    public static function tag(string $language): string
    {
        return strtoupper($language);
    }

    /**
     * The language code in front of each text when the texts differ, ''
     * for each when they are the same or there is only one.
     *
     * @param array<string, string> $texts
     * @return array<string, string>
     */
    public static function tags(array $texts): array
    {
        $differ = self::differ($texts);
        $tags = [];

        foreach (array_keys($texts) as $language) {
            $tags[$language] = $differ ? self::tag((string) $language) : '';
        }

        return $tags;
    }

    /**
     * The email texts as HTML (EmailBody::toHtml()), one per language, each
     * starting with its language code when they differ, with a thin line
     * between them.
     *
     * @param array<string, string> $bodies language => text
     */
    public static function bodiesHtml(array $bodies, string $accent): string
    {
        $tags = self::tags($bodies);
        $html = [];

        foreach ($bodies as $language => $body) {
            $html[] = EmailBody::toHtml($body, [], $accent, $tags[$language]);
        }

        return implode('<hr style="border:0;border-top:1px solid #e3e3e8;margin:20px 0">', $html);
    }

    /**
     * The message kinds with their names, for the settings table.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::TICKET => __('Ticket email', 'eventcrew'),
            self::TICKET_CANCELLED => __('Ticket cancelled or moved email', 'eventcrew'),
            self::FOLLOWUP => __('Post-event email', 'eventcrew'),
            self::TASK_CANCELLED => __('Task cancelled email', 'eventcrew'),
            self::NEWSLETTER_CONFIRM => __('Announcement confirmation email', 'eventcrew'),
            self::NEWSLETTER => __('Next events announcement (subscribers with no language)', 'eventcrew'),
            self::TAKEOVER_GROUP => __('Telegram group message about a takeover', 'eventcrew'),
        ];
    }

    /**
     * The ticks to store from a posted array of ticked kinds: every kind,
     * true when it was ticked.
     *
     * @return array<string, bool>
     */
    public static function fromPost(mixed $posted): array
    {
        $posted = is_array($posted) ? array_map('strval', array_keys($posted)) : [];
        $ticks = [];

        foreach (self::KINDS as $kind) {
            $ticks[$kind] = in_array($kind, $posted, true);
        }

        return $ticks;
    }

    /** The admin warning while hybrid is off because of the number of languages. */
    public static function offWarning(): string
    {
        return sprintf(
            /* translators: 1: number of site languages, 2: the most languages hybrid messages work with */
            __('EventCrew: the site has %1$d languages. Hybrid messages work with %2$d languages or fewer, so they are switched off: every message goes out in the reader’s own language, or in the main language when it is not known.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            count(self::siteLanguages()),
            self::MAX_LANGUAGES
        );
    }
}
