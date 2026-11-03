<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The email that asks a new address to confirm the next events announcement
 * (and the one to an address that is already subscribed), in the site's look:
 * one dark card with a gold edge, in this order:
 *
 * 1. The sign-up bar's picture (Newsletter::barImageId()), when one is set.
 * 2. A gold sparkle, the heading "Enää yksi askel" with "One step left"
 *    under it, and the promise line in both languages (left out when empty).
 * 3. The Finnish text, the English text, then the gold Confirm button
 *    (both texts say "the button below").
 *
 * The card's colours are fixed, so it looks the same in a light and a dark
 * mail app. The plain-text part says the same, with the link written out.
 */
final class NewsletterConfirmMail
{
    private const CARD_BACKGROUND = '#1d1422';
    private const CARD_EDGE = '#e9c58f';
    private const HEADING_COLOR = '#ffe2bf';
    private const TEXT_COLOR = '#fff1e2';
    private const MUTED_COLOR = '#d9c3a8';
    private const BUTTON_BACKGROUND = '#f0c987';
    private const BUTTON_TEXT = '#2a1606';
    private const IMAGE_WIDTH = 536;

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
            'button' => __('Write to us', 'eventcrew'),
        ];
    }

    /** A Finnish and an English text as one line, or the text once when both are the same. */
    public static function both(string $fi, string $en): string
    {
        return $fi === $en ? $en : $fi . ' / ' . $en;
    }

    /**
     * The HTML for the email template's content slot.
     *
     * @param array{subject: string, heading: string, promise: string, body: string, button: string} $fi
     * @param array{subject: string, heading: string, promise: string, body: string, button: string} $en
     */
    public static function html(array $fi, array $en, string $confirmUrl, string $imageUrl = ''): string
    {
        $font = EmailBody::FONT;
        $image = '' === $imageUrl ? '' : sprintf(
            '<tr><td style="padding:0"><img src="%s" width="%d" alt="" style="display:block;width:100%%;'
                . 'max-width:%dpx;height:auto;border:0;border-radius:15px 15px 0 0"></td></tr>',
            esc_url($imageUrl),
            self::IMAGE_WIDTH,
            self::IMAGE_WIDTH
        );
        $heading = sprintf(
            '<p style="margin:0 0 6px;font-family:%1$s;font-size:22px;line-height:1;color:%2$s">✦</p>'
                . '<h1 style="margin:0;font-family:%1$s;font-size:28px;line-height:1.2;font-weight:700;color:%2$s">'
                . '%3$s</h1>',
            $font,
            self::HEADING_COLOR,
            esc_html($fi['heading'])
        );

        if ($fi['heading'] !== $en['heading']) {
            $heading .= sprintf(
                '<p style="margin:4px 0 0;font-family:%s;font-size:17px;line-height:1.3;color:%s">%s</p>',
                $font,
                self::MUTED_COLOR,
                esc_html($en['heading'])
            );
        }

        $promise = '' === $fi['promise'] ? '' : sprintf(
            '<p style="margin:14px 0 0;font-family:%s;font-size:16px;line-height:1.5;font-style:italic;color:%s">'
                . '%s</p>',
            $font,
            self::TEXT_COLOR,
            esc_html(self::both($fi['promise'], $en['promise']))
        );
        $button = sprintf(
            '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0"'
                . ' style="margin:22px auto">'
                . '<tr><td style="border-radius:999px;background:%1$s;'
                . 'background-image:linear-gradient(135deg,#f6dcaa,%1$s);box-shadow:0 0 18px rgba(240,201,135,.45)">'
                . '<a href="%2$s" style="display:inline-block;padding:14px 34px;font-family:%3$s;font-size:16px;'
                . 'font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:%4$s;text-decoration:none;'
                . 'border-radius:999px">%5$s</a></td></tr></table>',
            self::BUTTON_BACKGROUND,
            esc_url($confirmUrl),
            $font,
            self::BUTTON_TEXT,
            esc_html(self::both($fi['button'], $en['button']))
        );
        $paragraph = static fn (string $text, string $color, string $margin): string => sprintf(
            '<p style="margin:%s;font-family:%s;font-size:15px;line-height:1.6;color:%s;text-align:left">%s</p>',
            $margin,
            $font,
            $color,
            esc_html($text)
        );
        // Both texts say "press the button below", so both stand above the button.
        $texts = $paragraph($fi['body'], self::TEXT_COLOR, '0')
            . ($fi['body'] === $en['body'] ? '' : $paragraph($en['body'], self::MUTED_COLOR, '14px 0 0'))
            . $button;

        return sprintf(
            '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0"'
                . ' style="max-width:%dpx;margin:0 auto;border-collapse:separate;background:%s;'
                . 'border:1px solid %s;border-radius:16px">'
                . '%s<tr><td align="center" style="padding:28px 26px 26px;text-align:center">%s%s</td></tr>'
                . '<tr><td style="padding:0 26px 28px">%s</td></tr></table>',
            self::IMAGE_WIDTH,
            self::CARD_BACKGROUND,
            self::CARD_EDGE,
            $image,
            $heading,
            $promise,
            $texts
        );
    }

    /**
     * The plain-text part.
     *
     * @param array{subject: string, heading: string, promise: string, body: string, button: string} $fi
     * @param array{subject: string, heading: string, promise: string, body: string, button: string} $en
     */
    public static function text(array $fi, array $en, string $confirmUrl): string
    {
        $lines = [
            '✦ ' . $fi['heading'] . ($fi['heading'] === $en['heading'] ? '' : ' / ' . $en['heading']),
            self::both($fi['promise'], $en['promise']),
            '',
            $fi['body'],
            '',
            self::both($fi['button'], $en['button']) . ': ' . $confirmUrl,
        ];

        if ($fi['body'] !== $en['body']) {
            $lines[] = '';
            $lines[] = $en['body'];
        }

        if ('' === $fi['promise']) {
            unset($lines[1]);
        }

        return implode("\n", $lines);
    }
}
