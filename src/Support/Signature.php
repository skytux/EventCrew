<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The crew's sign-off, shared by the emails and the Telegram DMs so both sound
 * like the same people wrote them.
 *
 * A setting rather than a translated string, because it names a particular crew
 * - "Barefoot and with ♥ — the Ecstatic Dance Helsinki crew" belongs to one
 * install, not to the plugin. Empty by default, and every caller drops the line
 * entirely when it is, so an install that never sets one is not left with a
 * stray blank line or a half-written sentence.
 *
 * The sign-off is a written-once text (SiteTexts, TranslatedText): written in
 * any language and kept in every site language. A message composed in Finnish
 * uses the Finnish text; an email in several languages shows each of them,
 * one language per row.
 */
final class Signature
{
    public const OPTION = 'eventcrew_signature';

    /**
     * The Finnish sign-off of the time before the sign-off was a
     * TranslatedText value. Only read: SiteTexts::value() takes it as the
     * Finnish text until the signature is saved again.
     */
    public const FINNISH_OPTION = 'eventcrew_signature_fi';

    /**
     * The raw sign-off, for the plain-text bodies and the DMs: the text in
     * $language (or, when null, the language being composed in, else the
     * main language), as SiteTexts::text() gives it.
     */
    public static function text(?string $language = null): string
    {
        return SiteTexts::text('signature', $language);
    }

    public static function isSet(): bool
    {
        return '' !== self::text();
    }

    /**
     * The plain-text sign-off of an email in $languages (every site language
     * when empty): each language's sign-off, a blank line between them, each
     * different text once.
     *
     * @param list<string> $languages
     */
    public static function bothText(array $languages = []): string
    {
        $texts = array_map(static fn (string $language): string => trim(self::text($language)), self::languages($languages));

        return implode("\n\n", array_values(array_unique(array_filter($texts, static fn (string $text): bool => '' !== $text))));
    }

    /**
     * The email rendering: the closing of the letter, centred, above the
     * manage link - the sign-off closes the message, the unsubscribe closes
     * the envelope. The first line ("With love,") is in italics, the rest
     * (the crew's name) in gold. A one-line sign-off is split after its first
     * comma, so "With love, the crew" is drawn on two lines as well.
     *
     * $languages is for an email in several languages: true for every site
     * language, or the list of languages. When the sign-offs differ, each
     * line comes once per language, one language per row ("Rakkaudella,",
     * then "With love,"; a line the same in every language once), or the
     * sign-offs follow each other when their lines do not pair up. A single
     * language shows that language's sign-off.
     *
     * @param bool|list<string> $languages
     */
    public static function html(bool|array $languages = false): string
    {
        if (false === $languages) {
            return self::closing(self::lines(self::text()));
        }

        $sets = [];

        foreach (self::languages(true === $languages ? [] : $languages) as $language) {
            $lines = self::lines(self::text($language));

            if ([] !== $lines && ! in_array($lines, $sets, true)) {
                $sets[] = $lines;
            }
        }

        if (count($sets) <= 1) {
            return self::closing($sets[0] ?? []);
        }

        if (1 !== count(array_unique(array_map('count', $sets)))) {
            return implode('', array_map(static fn (array $lines): string => self::closing($lines), $sets));
        }

        $rows = [];
        $first = 0;

        foreach (array_keys($sets[0]) as $index) {
            $each = array_values(array_unique(array_column($sets, $index)));
            $first = 0 === $index ? count($each) : $first;
            array_push($rows, ...$each);
        }

        return self::closing($rows, $first);
    }

    /**
     * $languages, or the shown site languages (HybridPolicy::shown()) when
     * it is empty.
     *
     * @param list<string> $languages
     * @return list<string>
     */
    private static function languages(array $languages): array
    {
        return [] === $languages ? HybridPolicy::shown() : array_values($languages);
    }

    /**
     * $text as the closing's lines: its own lines, or a single line split
     * after its first comma.
     *
     * @return array<int, string>
     */
    private static function lines(string $text): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        if ('' === $text) {
            return [];
        }

        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $text)),
            static fn (string $line): bool => '' !== $line
        ));

        if (1 === count($lines) && 1 === preg_match('/^(.+?,)\s+(\S.*)$/u', $lines[0], $match)) {
            return [$match[1], $match[2]];
        }

        return $lines;
    }

    /**
     * $lines as the closing: the first $first lines in italics, one per
     * row, the rest in gold.
     *
     * @param array<int, string> $lines
     */
    private static function closing(array $lines, int $first = 1): string
    {
        if ([] === $lines) {
            return '';
        }

        $html = sprintf(
            '<p style="margin:0 0 2px;font-family:%s;font-size:17px;font-style:italic;line-height:1.5;'
                . 'color:%s;text-align:center">%s</p>',
            EmailBody::HEAD_FONT,
            EmailBody::TEXT_COLOR,
            implode('<br>', array_map('esc_html', array_splice($lines, 0, max(1, $first))))
        );

        if ([] !== $lines) {
            $html .= sprintf(
                '<p style="margin:0;font-family:%s;font-size:15px;line-height:1.5;letter-spacing:.05em;'
                    . 'color:%s;text-align:center">%s</p>',
                EmailBody::HEAD_FONT,
                EmailBody::GOLD,
                implode('<br>', array_map('esc_html', $lines))
            );
        }

        return '<div style="margin:0 0 16px">' . $html . '</div>';
    }
}
