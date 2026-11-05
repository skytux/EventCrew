<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The email that asks a new address to confirm the next events announcement
 * (and the one to an address that is already subscribed), in the v4 look of
 * the email template's cream card, in this order:
 *
 * 1. The sign-up bar's picture (Newsletter::barImageId()), when one is set.
 * 2. A gold sparkle, the gold heading "Enää yksi askel" with "One step left"
 *    under it, and the promise line in both languages (left out when empty).
 * 3. The Finnish text, the English text, then the white Confirm button with
 *    the gold glow in a row of its own, centred on the cell (mail apps such
 *    as Gmail ignore a centred table inside a left-aligned cell). A label of
 *    up to 22 characters stays on one line; it fits a phone-wide card.
 *
 * The plain-text part says the same, with the link written out.
 */
final class NewsletterConfirmMail
{
    private const HEADING_COLOR = EmailBody::GOLD;
    private const TEXT_COLOR = EmailBody::TEXT_COLOR;
    private const MUTED_COLOR = EmailBody::MUTED_COLOR;
    private const TAG_COLOR = EmailBody::TAG_COLOR;
    private const BUTTON_EDGE = '#ffc98f';
    private const BUTTON_TEXT = '#241238';
    private const IMAGE_WIDTH = 480;

    /**
     * The words of the email in one language.
     *
     * @return array{subject: string, heading: string, promise: string, body: string, button: string}
     */
    public static function words(string $site): array
    {
        return [
            'subject' => __('Confirm your subscription to the next events announcement', 'eventcrew'),
            'heading' => __('One step left', 'eventcrew'),
            'promise' => __('One email before each event. Nothing else.', 'eventcrew'),
            'body' => sprintf(
                /* translators: %s: the site's name */
                __('Someone, probably you, asked for the next events announcement of %s at this email address. To start getting it, press the button below. If it was not you, you can ignore this email: the address is deleted in 7 days.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                $site
            ),
            'button' => __('Confirm', 'eventcrew'),
        ];
    }

    /**
     * The words of the email to an address that is already subscribed and was
     * entered in the form again. The button writes to $contact.
     *
     * @return array{subject: string, heading: string, promise: string, body: string, button: string}
     */
    public static function alreadyWords(string $site, string $contact): array
    {
        return [
            'subject' => __('You are already subscribed to the next events announcement', 'eventcrew'),
            'heading' => __('You are already subscribed', 'eventcrew'),
            'promise' => '',
            'body' => sprintf(
                /* translators: 1: the site's name, 2: the site's email address */
                __('This address already gets the next events announcement of %1$s, so nothing more is needed. If the announcements do not reach you and you want them, write to %2$s with the button below, and someone will look into it.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                $site,
                $contact
            ),
            'button' => __('Message', 'eventcrew'),
        ];
    }

    /** A Finnish and an English text as one line, or the text once when both are the same. */
    public static function both(string $fi, string $en): string
    {
        return $fi === $en ? $en : $fi . ' / ' . $en;
    }

    /**
     * The HTML for the email template's content slot. $parts holds the words
     * in each language the email is in (HybridPolicy), main language first.
     *
     * @param array<string, array{
     *     subject: string, heading: string, promise: string, body: string, button: string
     * }> $parts
     */
    public static function html(array $parts, string $confirmUrl, string $imageUrl = ''): string
    {
        $font = EmailBody::FONT;
        $head = EmailBody::HEAD_FONT;
        $first = reset($parts);
        $image = '' === $imageUrl ? '' : sprintf(
            '<tr><td style="padding:0 0 24px"><img src="%s" width="%d" alt="" style="display:block;width:100%%;'
                . 'max-width:%dpx;height:auto;border:0;border-radius:18px"></td></tr>',
            esc_url($imageUrl),
            self::IMAGE_WIDTH,
            self::IMAGE_WIDTH
        );
        $heading = sprintf(
            '<p style="margin:0 0 14px;font-family:%1$s;font-size:16px;line-height:1;color:#c9802f">✦</p>'
                . '<h1 class="ec-shimmer" style="margin:0;font-family:%2$s;font-size:28px;line-height:1.25;'
                . 'font-weight:700;color:%3$s">%4$s</h1>',
            $font,
            $head,
            self::HEADING_COLOR,
            esc_html($first['heading'])
        );

        foreach (array_slice(array_unique(HybridPolicy::field($parts, 'heading')), 1) as $other) {
            $heading .= sprintf(
                '<p style="margin:6px 0 0;font-family:%s;font-size:17px;font-style:italic;line-height:1.3;color:%s">'
                    . '%s</p>',
                $head,
                self::MUTED_COLOR,
                esc_html($other)
            );
        }

        $promise = '' === $first['promise'] ? '' : sprintf(
            '<p style="margin:16px 0 0;font-family:%s;font-size:17px;line-height:1.5;font-style:italic;color:%s">'
                . '%s</p>',
            $head,
            self::TEXT_COLOR,
            esc_html(HybridPolicy::join(HybridPolicy::field($parts, 'promise')))
        );
        $label = HybridPolicy::join(HybridPolicy::field($parts, 'button'));
        $button = sprintf(
            '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0"'
                . ' style="margin:0 auto">'
                . '<tr><td bgcolor="#ffffff" style="border-radius:28px;background:#ffffff;border:1px solid %1$s;'
                . 'box-shadow:0 0 18px rgba(255,190,120,.55),0 0 46px rgba(255,190,120,.25)">'
                . '<a href="%2$s" style="display:inline-block;padding:14px 30px;font-family:%3$s;font-size:15px;'
                . 'font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:%4$s;text-decoration:none;'
                . 'text-align:center;border-radius:28px%6$s">%5$s</a></td></tr></table>',
            self::BUTTON_EDGE,
            esc_url($confirmUrl),
            $font,
            self::BUTTON_TEXT,
            esc_html($label),
            mb_strlen($label) <= 22 ? ';white-space:nowrap' : ''
        );
        $paragraph = static fn (string $text, string $color, string $margin, string $tag): string => sprintf(
            '<p style="margin:%s;font-family:%s;font-size:16px;line-height:1.7;color:%s;text-align:left">%s%s</p>',
            $margin,
            $font,
            $color,
            '' === $tag ? '' : EmailBody::langTag($tag, self::TAG_COLOR),
            esc_html($text)
        );
        // Every text says "press the button below", so all stand above the button.
        // Different texts each start with their language tag; one text needs none.
        $bodies = HybridPolicy::field($parts, 'body');
        $tags = HybridPolicy::tags($bodies);
        $texts = '';

        foreach (HybridPolicy::differ($bodies) ? $bodies : array_slice($bodies, 0, 1, true) as $lang => $body) {
            $texts .= $paragraph($body, self::TEXT_COLOR, '' === $texts ? '0' : '16px 0 0', $tags[$lang]);
        }

        return sprintf(
            '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0"'
                . ' style="max-width:%dpx;margin:0 auto;border-collapse:separate">'
                . '%s<tr><td align="center" style="padding:0 0 24px;text-align:center">%s%s'
                . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"'
                . ' style="margin:18px auto 0"><tr><td style="width:48px;height:2px;line-height:2px;font-size:0;'
                . 'background:#d9b27a">&nbsp;</td></tr></table></td></tr>'
                . '<tr><td style="padding:0">%s</td></tr>'
                . '<tr><td align="center" style="padding:26px 0 6px;text-align:center">%s</td></tr></table>',
            self::IMAGE_WIDTH,
            $image,
            $heading,
            $promise,
            $texts,
            $button
        );
    }

    /**
     * A section heading in this card's look, for the coming events in the
     * thank-you email: the gold sparkle, the Finnish heading big, gold and
     * centred, the English heading on a new line under it, smaller, muted and
     * in italics (left out when it is the same), and a short gold rule.
     */
    public static function headingBand(string $fi, string $en): string
    {
        $head = EmailBody::HEAD_FONT;
        $english = $fi === $en ? '' : sprintf(
            '<p style="margin:6px 0 0;font-family:%s;font-size:17px;font-style:italic;line-height:1.3;color:%s;'
                . 'text-align:center">%s</p>',
            $head,
            self::MUTED_COLOR,
            esc_html($en)
        );

        return sprintf(
            '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0"'
                . ' style="margin:0 0 18px;border-collapse:separate"><tr><td align="center"'
                . ' style="padding:0;text-align:center">'
                . '<p style="margin:0 0 14px;font-family:%1$s;font-size:16px;line-height:1;color:#c9802f;'
                . 'text-align:center">✦</p>'
                . '<h1 class="ec-shimmer" style="margin:0;font-family:%2$s;font-size:28px;line-height:1.25;'
                . 'font-weight:700;color:%3$s;text-align:center">%4$s</h1>%5$s'
                . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"'
                . ' style="margin:18px auto 8px"><tr><td style="width:48px;height:2px;line-height:2px;font-size:0;'
                . 'background:#d9b27a">&nbsp;</td></tr></table></td></tr></table>',
            EmailBody::FONT,
            $head,
            self::HEADING_COLOR,
            esc_html($fi),
            $english
        );
    }

    /**
     * The plain-text part, in the languages of $parts (see html()).
     *
     * @param array<string, array{
     *     subject: string, heading: string, promise: string, body: string, button: string
     * }> $parts
     */
    public static function text(array $parts, string $confirmUrl): string
    {
        $first = reset($parts);
        $bodies = array_values(array_unique(HybridPolicy::field($parts, 'body')));
        $lines = [
            '✦ ' . HybridPolicy::join(HybridPolicy::field($parts, 'heading')),
            HybridPolicy::join(HybridPolicy::field($parts, 'promise')),
            '',
            $bodies[0],
            '',
            HybridPolicy::join(HybridPolicy::field($parts, 'button')) . ': ' . $confirmUrl,
        ];

        foreach (array_slice($bodies, 1) as $body) {
            $lines[] = '';
            $lines[] = $body;
        }

        if ('' === $first['promise']) {
            unset($lines[1]);
        }

        return implode("\n", $lines);
    }
}
