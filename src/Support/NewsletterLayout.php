<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The look of the next events announcement: the HTML for the email
 * template's content slot, and the same as plain text.
 *
 * In order:
 *
 * 1. The heading, in Finnish with the English under it.
 * 2. The organizers' text, if any: the Finnish paragraph, then the English
 *    one, each with a small FI / EN label.
 * 3. One card per newly announced event: the picture, the date and time, the
 *    title, the whole description in Finnish and then in English (labelled
 *    FI / EN, with its line breaks), a "Buy a ticket" button with the price
 *    that opens the ticket shop, and a "Listen to the DJ" button, Finnish on
 *    the first line and English on the second, when the event has the DJ's
 *    music page (an email cannot play music itself, so it opens the page). A
 *    sold-out event shows "Sold out" instead of the ticket button. The
 *    picture opens the ticket shop, or the event's own page when there is no
 *    ticket to buy.
 * 4. "Later": every other coming event as one line, with the date, the
 *    title and a link to its tickets.
 *
 * Every fixed word is written in both languages ("Osta lippu · Buy a
 * ticket"), so each picture appears once and a reader of either language
 * finds their part in the same card. All styles are inline, because email
 * clients drop stylesheets.
 */
final class NewsletterLayout
{
    private const TEXT_COLOR = '#1f2328';
    private const MUTED_COLOR = '#5b6470';
    private const LABEL_COLOR = '#8a919c';
    private const BORDER_COLOR = '#e6e8eb';

    /** The picture's width: the template's 600px column less its 32px padding each side. */
    private const IMAGE_WIDTH = 536;

    /** Between the cards and the "Later" list in the plain text. */
    private const TEXT_RULE = '————————';

    /**
     * @param array<int, array<string, mixed>> $cards Newly announced events,
     *        each with date, time, title, title_fi, sold_out, url (the event
     *        page) and EventSource::card()'s fields.
     * @param array<int, array<string, mixed>> $later The other coming events,
     *        with the same keys.
     * @param array{fi?: string, en?: string}|null $text The organizers' text.
     */
    public static function html(array $cards, array $later, ?array $text, string $accent): string
    {
        $words = self::words();
        $font = EmailBody::FONT;

        $html = sprintf(
            '<h1 style="margin:0;font-family:%s;font-size:26px;line-height:1.25;color:%s">%s</h1>'
                . '<p style="margin:2px 0 24px;font-family:%s;font-size:16px;line-height:1.4;color:%s">%s</p>',
            $font,
            self::TEXT_COLOR,
            esc_html($words['fi']['heading']),
            $font,
            self::MUTED_COLOR,
            esc_html($words['en']['heading'])
        );

        $html .= self::bilingual(
            trim((string) ($text['fi'] ?? '')),
            trim((string) ($text['en'] ?? '')),
            '',
            '0 0 24px'
        );

        foreach ($cards as $card) {
            $html .= self::cardHtml($card, $words, $accent);
        }

        if ([] !== $later) {
            $html .= sprintf(
                '<h2 style="margin:8px 0 12px;font-family:%s;font-size:18px;line-height:1.3;color:%s">%s</h2>',
                $font,
                self::TEXT_COLOR,
                esc_html(self::pair($words, 'later'))
            );

            $items = '';

            foreach ($later as $event) {
                $items .= self::laterHtml($event, $words, $accent);
            }

            $html .= sprintf(
                '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0"'
                    . ' style="margin:0 0 8px">%s</table>',
                $items
            );
        }

        return $html;
    }

    /**
     * The same content as plain text, for the multipart alternative.
     *
     * @param array<int, array<string, mixed>> $cards
     * @param array<int, array<string, mixed>> $later
     * @param array{fi?: string, en?: string}|null $text
     */
    public static function text(array $cards, array $later, ?array $text): string
    {
        $words = self::words();
        $parts = [mb_strtoupper(self::pair($words, 'heading'))];

        $intro = self::bilingualText(trim((string) ($text['fi'] ?? '')), trim((string) ($text['en'] ?? '')), '');

        if ('' !== $intro) {
            $parts[] = $intro;
        }

        foreach ($cards as $card) {
            $lines = [self::when($card), self::title($card)];
            $about = self::bilingualText(
                (string) ($card['excerpt_fi'] ?? ''),
                (string) ($card['excerpt_en'] ?? ''),
                (string) ($card['excerpt'] ?? '')
            );

            if ('' !== $about) {
                $lines[] = $about;
            }

            $ticket = (string) ($card['ticket_url'] ?? '');

            if (! empty($card['sold_out'])) {
                $lines[] = self::pair($words, 'sold_out');
            } elseif ('' !== $ticket) {
                $lines[] = self::buyLabel($card, $words) . ': ' . $ticket;
            }

            if ('' !== (string) ($card['listen'] ?? '')) {
                $lines[] = self::pair($words, 'listen') . ': ' . $card['listen'];
            }

            $parts[] = implode("\n", $lines);
        }

        if ([] !== $later) {
            $lines = [self::TEXT_RULE, self::pair($words, 'later')];

            foreach ($later as $event) {
                $link = self::laterLink($event);
                $lines[] = '- ' . self::when($event) . ' · ' . self::title($event)
                    . (! empty($event['sold_out']) ? ' · ' . self::pair($words, 'sold_out') : '')
                    . ('' === $link ? '' : ': ' . $link);
            }

            $parts[] = implode("\n", $lines);
        }

        return implode("\n\n", $parts);
    }

    /**
     * "22.00 EUR" as "22 €", "27.50 EUR" as "27,50 €". Anything else is shown
     * as the shop wrote it.
     */
    public static function price(string $price): string
    {
        $price = trim($price);

        if (1 !== preg_match('/^(\d+)(?:[.,](\d{1,2}))?\s*(?:EUR|€)$/iu', $price, $m)) {
            return $price;
        }

        $cents = str_pad($m[2] ?? '', 2, '0');

        return $m[1] . ('' === $cents || '00' === $cents ? '' : ',' . $cents) . ' €';
    }

    /**
     * @param array<string, mixed> $card
     * @param array<string, array<string, string>> $words
     */
    private static function cardHtml(array $card, array $words, string $accent): string
    {
        $font = EmailBody::FONT;
        $ticket = (string) ($card['ticket_url'] ?? '');
        $listen = (string) ($card['listen'] ?? '');
        $page = (string) ($card['url'] ?? '');
        $image = (string) ($card['image'] ?? '');
        $soldOut = ! empty($card['sold_out']);
        $title = self::title($card);
        $titleFi = self::plain((string) ($card['title_fi'] ?? ''));

        $html = '';

        if ('' !== $image) {
            $picture = sprintf(
                '<img src="%s" width="%d" alt="%s" style="display:block;width:100%%;max-width:%dpx;height:auto;'
                    . 'border:0;border-radius:12px 12px 0 0">',
                esc_url($image),
                self::IMAGE_WIDTH,
                esc_attr($title),
                self::IMAGE_WIDTH
            );
            $link = '' !== $ticket && ! $soldOut ? $ticket : $page;
            $html .= '<tr><td style="padding:0;line-height:0">'
                . ('' === $link ? $picture : sprintf('<a href="%s">%s</a>', esc_url($link), $picture))
                . '</td></tr>';
        }

        $body = sprintf(
            '<p style="margin:0 0 6px;font-family:%s;font-size:13px;font-weight:700;letter-spacing:.04em;color:%s">'
                . '%s</p>',
            $font,
            esc_attr($accent),
            esc_html(self::when($card))
        );
        $body .= sprintf(
            '<h2 style="margin:0 0 %s;font-family:%s;font-size:20px;line-height:1.3;color:%s">%s</h2>',
            '' !== $titleFi && $titleFi !== $title ? '2px' : '14px',
            $font,
            self::TEXT_COLOR,
            esc_html($title)
        );

        if ('' !== $titleFi && $titleFi !== $title) {
            $body .= sprintf(
                '<p style="margin:0 0 14px;font-family:%s;font-size:15px;line-height:1.4;color:%s">%s</p>',
                $font,
                self::MUTED_COLOR,
                esc_html($titleFi)
            );
        }

        $body .= self::bilingual(
            (string) ($card['excerpt_fi'] ?? ''),
            (string) ($card['excerpt_en'] ?? ''),
            (string) ($card['excerpt'] ?? ''),
            '0 0 16px'
        );

        if ($soldOut) {
            $body .= sprintf(
                '<p style="margin:4px 0 16px"><span style="display:inline-block;padding:8px 16px;border-radius:999px;'
                    . 'background:#f1f2f4;font-family:%s;font-size:14px;font-weight:600;color:%s">%s</span></p>',
                $font,
                self::MUTED_COLOR,
                esc_html(self::pair($words, 'sold_out'))
            );
        } elseif ('' !== $ticket) {
            $body .= sprintf(
                '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 16px">'
                    . '<tr><td style="border-radius:999px;background:%s">'
                    . '<a href="%s" style="display:inline-block;padding:13px 24px;font-family:%s;font-size:15px;'
                    . 'font-weight:700;line-height:1.2;color:%s;text-decoration:none;border-radius:999px">%s</a>'
                    . '</td></tr></table>',
                esc_attr($accent),
                esc_url($ticket),
                $font,
                EmailBody::readableOn($accent),
                esc_html(self::buyLabel($card, $words))
            );
        }

        if ('' !== $listen) {
            $body .= sprintf(
                '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px">'
                    . '<tr><td style="border-radius:999px;border:1px solid %s;background:#ffffff">'
                    . '<a href="%s" style="display:inline-block;padding:11px 22px;font-family:%s;font-size:15px;'
                    . 'font-weight:600;line-height:1.2;color:%s;text-decoration:none;border-radius:999px">▶ %s</a>'
                    . '</td></tr></table>',
                esc_attr($accent),
                esc_url($listen),
                $font,
                esc_attr($accent),
                $words['fi']['listen'] === $words['en']['listen']
                    ? esc_html($words['en']['listen'])
                    : esc_html($words['fi']['listen']) . '<br>' . esc_html($words['en']['listen'])
            );
        }

        $html .= '<tr><td style="padding:18px 20px 20px">' . $body . '</td></tr>';

        return sprintf(
            '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0"'
                . ' style="margin:0 0 24px;border:1px solid %s;border-radius:12px;border-collapse:separate">%s</table>',
            self::BORDER_COLOR,
            $html
        );
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, array<string, string>> $words
     */
    private static function laterHtml(array $event, array $words, string $accent): string
    {
        $font = EmailBody::FONT;
        $link = self::laterLink($event);
        $soldOut = ! empty($event['sold_out']);
        $price = self::price((string) ($event['price'] ?? ''));

        if ($soldOut) {
            $right = esc_html(self::pair($words, 'sold_out'));
        } elseif ('' !== $link) {
            $right = sprintf(
                '<a href="%s" style="color:%s;font-weight:600;text-decoration:none;white-space:nowrap">%s →</a>',
                esc_url($link),
                esc_attr($accent),
                esc_html(self::pair($words, 'tickets') . ('' === $price ? '' : ' · ' . $price))
            );
        } else {
            $right = '';
        }

        return sprintf(
            '<tr><td style="padding:10px 0;border-top:1px solid %s;font-family:%s;font-size:15px;line-height:1.45;'
                . 'color:%s"><strong>%s</strong><br>%s%s</td></tr>',
            self::BORDER_COLOR,
            $font,
            self::TEXT_COLOR,
            esc_html(self::when($event)),
            esc_html(self::title($event)),
            '' === $right
                ? ''
                : '<br><span style="font-size:14px;color:' . self::MUTED_COLOR . '">' . $right . '</span>'
        );
    }

    /** Where a "Later" line links: the ticket shop, or the event page when there is no shop link. */
    private static function laterLink(array $event): string
    {
        $ticket = (string) ($event['ticket_url'] ?? '');

        return '' !== $ticket ? $ticket : (string) ($event['url'] ?? '');
    }

    /**
     * The Finnish and the English text, each with its label, or $plain alone
     * with no label when the language is not known. A blank line in a text
     * starts a new paragraph, and the label goes on its first paragraph only.
     */
    private static function bilingual(string $fi, string $en, string $plain, string $margin): string
    {
        $html = '';

        foreach (['FI' => trim($fi), 'EN' => trim($en), '' => trim($plain)] as $label => $text) {
            if ('' === $text) {
                continue;
            }

            $paragraphs = preg_split('/\n[^\S\n]*\n\s*/', $text) ?: [$text];

            foreach ($paragraphs as $i => $paragraph) {
                $html .= sprintf(
                    '<p style="margin:0 0 10px;font-family:%s;font-size:15px;line-height:1.6;color:%s">%s%s</p>',
                    EmailBody::FONT,
                    self::TEXT_COLOR,
                    '' === $label || 0 !== $i ? '' : sprintf(
                        '<span style="font-size:11px;font-weight:700;letter-spacing:.06em;color:%s">%s</span>&nbsp; ',
                        self::LABEL_COLOR,
                        $label
                    ),
                    str_replace("\n", '<br>', EmailBody::inline($paragraph))
                );
            }
        }

        return '' === $html ? '' : sprintf('<div style="margin:%s">%s</div>', $margin, $html);
    }

    /**
     * "FI: …" then "EN: …", the label on the first line of each. When a text
     * has more than one line, a blank line goes between the languages so
     * the English does not run on from the last Finnish line.
     */
    private static function bilingualText(string $fi, string $en, string $plain): string
    {
        $lines = [];
        $multiline = false;

        foreach (['FI: ' => trim($fi), 'EN: ' => trim($en), '' => trim($plain)] as $label => $text) {
            if ('' !== $text) {
                $lines[] = $label . $text;
                $multiline = $multiline || str_contains($text, "\n");
            }
        }

        return implode($multiline ? "\n\n" : "\n", $lines);
    }

    /** "14.11.2026 · 19:00", or the date alone when the time is not known. */
    private static function when(array $event): string
    {
        $time = (string) ($event['time'] ?? '');

        return TicketMail::shortDate((string) ($event['date'] ?? '')) . ('' === $time ? '' : ' · ' . $time);
    }

    private static function title(array $event): string
    {
        return self::plain((string) ($event['title'] ?? ''));
    }

    private static function plain(string $text): string
    {
        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * "Osta lippu · Buy a ticket · 22 €".
     *
     * @param array<string, mixed> $card
     * @param array<string, array<string, string>> $words
     */
    private static function buyLabel(array $card, array $words): string
    {
        $price = self::price((string) ($card['price'] ?? ''));

        return self::pair($words, 'buy') . ('' === $price ? '' : ' · ' . $price);
    }

    /** @param array<string, array<string, string>> $words */
    private static function pair(array $words, string $key): string
    {
        return $words['fi'][$key] === $words['en'][$key]
            ? $words['en'][$key]
            : $words['fi'][$key] . ' · ' . $words['en'][$key];
    }

    /** @return array<string, array<string, string>> Every fixed word, in Finnish and in English. */
    private static function words(): array
    {
        $words = static fn (): array => [
            'heading' => __('Coming events', 'eventcrew'),
            'later' => __('Later', 'eventcrew'),
            'buy' => __('Buy a ticket', 'eventcrew'),
            'tickets' => __('Tickets', 'eventcrew'),
            'listen' => __('Listen to the DJ', 'eventcrew'),
            'sold_out' => __('Sold out', 'eventcrew'),
        ];

        return [
            'fi' => Language::run(Language::FINNISH, $words),
            'en' => Language::run(Language::ENGLISH, $words),
        ];
    }
}
