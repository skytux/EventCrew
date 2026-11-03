<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use WP_Translation_Controller;

/**
 * The language crew-facing text is written in: English or Finnish, chosen per
 * person and stored on their record (NULL until they choose, which reads as
 * English).
 *
 * Everything a person reads - a bot DM, a popup, an email, the crew page - is
 * composed inside run() or forPerson(), so the plugin's own strings come out in
 * that person's language. wp-admin is never wrapped and keeps WordPress's own
 * language, and the Telegram group board is wrapped in English on purpose,
 * because the whole group reads the same message.
 *
 * Why not WordPress's switch_to_locale(): it refuses any locale that has no
 * core language pack installed in wp-content/languages, so on an English site
 * switch_to_locale('fi') returns false and changes nothing. This class switches
 * the eventcrew text domain only, which needs nothing but the plugin's own
 * languages/eventcrew-fi.mo. It does that through the same public pieces the
 * core switcher uses: a determine_locale filter and a reloadable unload, after
 * which WordPress's just-in-time loader reads the domain again in the new
 * locale on the next __() call.
 */
final class Language
{
    public const ENGLISH = 'en';
    public const FINNISH = 'fi';

    /** WordPress locale codes. Finnish is plain 'fi' in WordPress, not fi_FI. */
    private const LOCALES = [
        self::ENGLISH => 'en_US',
        self::FINNISH => 'fi',
    ];

    private const DOMAIN = 'eventcrew';

    /** Runs after the core switcher's filter (priority 10), so this one wins. */
    private const FILTER_PRIORITY = 99;

    /**
     * The languages entered and not yet left, innermost last, each with the
     * translation controller's locale from just before it was entered.
     *
     * @var list<array{language: string, controller_locale: ?string}>
     */
    private static array $stack = [];

    /**
     * 'en' or 'fi' for a stored or posted value, null for anything else - which
     * includes NULL, the "not chosen yet" state.
     */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return isset(self::LOCALES[$value]) ? $value : null;
    }

    /**
     * The language a browser asks for in its Accept-Language header: 'fi' when
     * it ranks Finnish above English, null (English) in every other case -
     * no header, neither language listed, or English first.
     */
    public static function fromBrowser(?string $header): ?string
    {
        $rank = self::browserRanks($header);
        $finnish = $rank[self::FINNISH] ?? null;
        $english = $rank[self::ENGLISH] ?? null;

        if (null === $finnish || $finnish['q'] <= 0.0) {
            return null;
        }

        if (
            null === $english
            || $finnish['q'] > $english['q']
            || ($finnish['q'] === $english['q'] && $finnish['at'] < $english['at'])
        ) {
            return self::FINNISH;
        }

        return null;
    }

    /**
     * Which of Finnish and English the browser lists first in its
     * Accept-Language preference order (highest q value, then the earlier
     * one): 'fi', 'en', or '' when it lists neither, or has no header.
     * Unlike fromBrowser(), "no answer" stays distinct from English, so a
     * stored value can tell "not known" apart.
     */
    public static function preferredInBrowser(?string $header): string
    {
        $best = '';
        $bestQuality = 0.0;
        $bestAt = PHP_INT_MAX;

        foreach (self::browserRanks($header) as $language => $rank) {
            if ($rank['q'] <= 0.0) {
                continue;
            }

            if ($rank['q'] > $bestQuality || ($rank['q'] === $bestQuality && $rank['at'] < $bestAt)) {
                $best = $language;
                $bestQuality = $rank['q'];
                $bestAt = $rank['at'];
            }
        }

        return $best;
    }

    /**
     * Per supported language in an Accept-Language header: its best q value,
     * and the position where it was first listed.
     *
     * @return array<string, array{q: float, at: int}>
     */
    private static function browserRanks(?string $header): array
    {
        $rank = [];

        foreach (explode(',', (string) $header) as $position => $part) {
            $pieces = explode(';', $part);
            $primary = strtolower(trim(explode('-', $pieces[0])[0]));

            if (! isset(self::LOCALES[$primary])) {
                continue;
            }

            $quality = 1.0;

            foreach (array_slice($pieces, 1) as $parameter) {
                if (1 === preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $parameter, $match)) {
                    $quality = (float) $match[1];
                }
            }

            if (! isset($rank[$primary]) || $quality > $rank[$primary]['q']) {
                $rank[$primary] = ['q' => $quality, 'at' => $rank[$primary]['at'] ?? $position];
            }
        }

        return $rank;
    }

    /**
     * preferredInBrowser() for the current request's Accept-Language header.
     */
    public static function preferredInRequest(): string
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- isset checked inline.
        $header = isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])
            ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_ACCEPT_LANGUAGE']))
            : null;

        return self::preferredInBrowser($header);
    }

    /**
     * The language text is being composed in right now, or null outside any
     * run() - where WordPress's own language applies.
     */
    public static function current(): ?string
    {
        $top = end(self::$stack);

        return false === $top ? null : $top['language'];
    }

    /**
     * Runs $callback with the plugin's strings in $language, English when it is
     * null or unknown, and returns what it returns. Nests: an inner run (the
     * board, inside a Finnish tap) restores the outer language when it ends.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function run(?string $language, callable $callback): mixed
    {
        self::enter($language);

        try {
            return $callback();
        } finally {
            self::leave();
        }
    }

    /**
     * run() in the language $person chose; English for a person who has not
     * chosen and for no person at all.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function forPerson(?Person $person, callable $callback): mixed
    {
        return self::run($person?->language, $callback);
    }

    /**
     * Switches to $language until the matching leave(). For a request that ends
     * in exit - a redirect, a JSON answer - where the rest of the request is one
     * person's and there is no callback to wrap; everything else uses run().
     */
    public static function enter(?string $language): void
    {
        $language = self::normalize($language) ?? self::ENGLISH;

        if ([] === self::$stack && function_exists('add_filter')) {
            add_filter('determine_locale', [self::class, 'filterLocale'], self::FILTER_PRIORITY);
        }

        self::$stack[] = ['language' => $language, 'controller_locale' => self::controllerLocale()];
        self::reloadDomain();
    }

    /** Leaves the innermost language entered with enter(). */
    public static function leave(): void
    {
        $left = array_pop(self::$stack);

        if (null === $left) {
            return;
        }

        if ([] === self::$stack && function_exists('remove_filter')) {
            remove_filter('determine_locale', [self::class, 'filterLocale'], self::FILTER_PRIORITY);
        }

        self::reloadDomain();

        // Loading the domain in a locale also makes that locale the translation
        // controller's current one, which every other domain is then looked up
        // in. Put it back, or core strings on a non-English site would stay
        // looked up in Finnish after a Finnish DM.
        if (null !== $left['controller_locale'] && class_exists(WP_Translation_Controller::class)) {
            WP_Translation_Controller::get_instance()->set_locale($left['controller_locale']);
        }
    }

    /**
     * The determine_locale filter: the innermost language's locale while one is
     * entered. Public only because WordPress calls it.
     */
    public static function filterLocale(mixed $locale): mixed
    {
        $current = self::current();

        return null === $current ? $locale : self::LOCALES[$current];
    }

    /**
     * Drops the loaded eventcrew translations without marking the domain as
     * unloaded, so the next __() loads it again for the locale now in force.
     */
    private static function reloadDomain(): void
    {
        if (function_exists('unload_textdomain')) {
            unload_textdomain(self::DOMAIN, true);
        }
    }

    private static function controllerLocale(): ?string
    {
        if (! class_exists(WP_Translation_Controller::class)) {
            return null;
        }

        return WP_Translation_Controller::get_instance()->get_locale();
    }
}
