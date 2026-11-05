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
 * uses the Finnish text; a two-language email (Finnish, then English) shows
 * both.
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
     * The plain-text sign-off of a two-language email: the Finnish one, a
     * blank line and the English one, or just one when they are the same.
     */
    public static function bothText(): string
    {
        $finnish = self::text(Language::FINNISH);
        $english = self::text(Language::ENGLISH);

        return $finnish === $english ? $english : trim($finnish . "\n\n" . $english);
    }

    /**
     * The email rendering: the closing of the letter, centred, above the
     * manage link - the sign-off closes the message, the unsubscribe closes
     * the envelope. The first line ("With love,") is in italics, the rest
     * (the crew's name) in gold. A one-line sign-off is split after its first
     * comma, so "With love, the crew" is drawn on two lines as well.
     *
     * $bothLanguages is for a two-language email: when the Finnish sign-off
     * differs from the English one, each line shows the Finnish words, " · "
     * and the English words, or the two sign-offs follow each other when
     * their lines do not pair up.
     */
    public static function html(bool $bothLanguages = false): string
    {
        if (! $bothLanguages) {
            return self::closing(self::lines(self::text()));
        }

        $finnish = self::lines(self::text(Language::FINNISH));
        $english = self::lines(self::text(Language::ENGLISH));

        if ($finnish === $english || [] === $finnish || [] === $english) {
            return self::closing([] === $english ? $finnish : $english);
        }

        if (count($finnish) !== count($english)) {
            return self::closing($finnish) . self::closing($english);
        }

        $paired = [];

        foreach ($finnish as $index => $line) {
            $paired[] = $line === $english[$index] ? $line : $line . ' · ' . $english[$index];
        }

        return self::closing($paired);
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

    /** @param array<int, string> $lines */
    private static function closing(array $lines): string
    {
        if ([] === $lines) {
            return '';
        }

        $html = sprintf(
            '<p style="margin:0 0 2px;font-family:%s;font-size:17px;font-style:italic;line-height:1.5;'
                . 'color:%s;text-align:center">%s</p>',
            EmailBody::HEAD_FONT,
            EmailBody::TEXT_COLOR,
            esc_html(array_shift($lines))
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
