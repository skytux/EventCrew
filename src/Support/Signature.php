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
 */
final class Signature
{
    public const OPTION = 'eventcrew_signature';

    /** The raw line, for the plain-text bodies and the DMs. */
    public static function text(): string
    {
        return trim((string) get_option(self::OPTION, ''));
    }

    public static function isSet(): bool
    {
        return '' !== self::text();
    }

    /**
     * The email rendering: small, muted, and above the manage link rather than
     * below it - the sign-off closes the message, the unsubscribe closes the
     * envelope.
     */
    public static function html(): string
    {
        $text = self::text();

        if ('' === $text) {
            return '';
        }

        return sprintf(
            '<p style="margin:0 0 10px;font-family:%s;font-size:13px;line-height:1.6;color:#5b6470">%s</p>',
            EmailBody::FONT,
            nl2br(esc_html($text))
        );
    }
}
