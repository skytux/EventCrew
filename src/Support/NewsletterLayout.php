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
 * 3. One card per newly announced event:
 *    - The picture, which opens the ticket shop, or the event's own page
 *      when there is no ticket to buy. The picture (the event's poster)
 *      already shows the date, the title and the DJ, so they are not
 *      written under it. They are the picture's alt text instead, on one
 *      line ("14.11.2026 · 19:00 — Ecstatic Dance — DJ Kai"), and the
 *      picture is styled (font, size, colour, centred, a grey background)
 *      so that this text reads as a heading when the email client does not
 *      load pictures. A card with no picture writes the date and time, the
 *      title, the Finnish title when it is different and the DJ line as
 *      centred text instead.
 *    - A card given text_fi and text_en (the post-event email's first
 *      event): that text, Finnish then English, each with its FI / EN
 *      label, aligned left. Other cards show no description and no text
 *      written for the event (Lou, 2026-11-03).
 *    - Aligned left, the practical information the language model found in
 *      the description (NewsletterWriter), each line only when known: the venue
 *      and the street address; an empty line; then the door times as one
 *      block of Finnish lines with a small FI label and one block of English
 *      lines with a small EN label.
 *    - A "Buy a ticket" button with the price that opens the ticket shop, or
 *      "Sold out" for a sold-out event.
 *    - A "Listen to the DJ" button when the event has the DJ's music page
 *      (an email cannot play music itself, so it opens the page), on three
 *      lines: the ▶ icon, the Finnish words, the English words.
 * 4. "Later": every other coming event as one line, with the date, the
 *    title and a link to its tickets, and the event's picture small on its
 *    left, linked like the line, when it has one.
 *
 * "The title" is the event's clean name with the DJ line under it when
 * EventMesh has them (EventName), else the title as written. A "Later"
 * picture's alt text is the clean name too.
 *
 * The plain text has no pictures, so each card there starts with the date,
 * the title and the DJ line, then the venue and the address, an empty line,
 * and the door times: the Finnish lines, then the English lines, with "FI: "
 * and "EN: " in front of the first line of each.
 *
 * Every fixed word is written in both languages ("Osta lippu · Buy a
 * ticket"), so each picture appears once and a reader of either language
 * finds their part in the same card. All styles are inline, because email
 * clients drop stylesheets.
 *
 * That is the every-language email, for a subscriber whose language is not
 * known. Finnish and English are the example above; the languages are the
 * site's languages (HybridPolicy::siteLanguages()), main language first, so
 * with 3 languages each pair above has 3 parts and each labelled block comes
 * 3 times. When hybrid is not allowed (4 or more site languages), the
 * every-language email is the main language only. Given a site language,
 * html(), text() and greeting() make a one-language email instead (Lou,
 * 2026-11-03): the heading, every label pair, button and door block in that
 * language only, and the organizers' text in that language with no label.
 *
 * Texts in several languages ($text, a card's text_fi, text_en, …) are keyed
 * by the language code.
 */
final class NewsletterLayout
{
    private const TEXT_COLOR = '#1f2328';
    private const MUTED_COLOR = '#5b6470';
    private const LABEL_COLOR = '#8a919c';
    private const BORDER_COLOR = '#e6e8eb';

    /** Behind a card picture's alt text, when the email client does not load the picture. */
    private const ALT_BACKGROUND = '#f1f2f4';

    /** The picture's width: the template's 600px column less its 32px padding each side. */
    private const IMAGE_WIDTH = 536;

    /** The width of a picture in the "Later" list. */
    private const THUMB_WIDTH = 80;

    /** Between the cards and the "Later" list in the plain text. */
    private const TEXT_RULE = '————————';

    /** The longest Finnish-and-English pair kept on one line; see joinPair(). */
    private const PAIR_MAX = 32;

    /**
     * @param array<int, array<string, mixed>> $cards Newly announced events,
     *        each with date, time, title, title_fi, sold_out, url (the event
     *        page) and EventSource::card()'s fields.
     * @param array<int, array<string, mixed>> $later The other coming events,
     *        with the same keys.
     * @param array<string, string>|null $text The organizers' text, keyed by language.
     * @param string $lang A site language for a one-language email; '' for every language.
     * @param bool $band True for the heading in the confirmation card's look
     *        (NewsletterConfirmMail::headingBand()), as the thank-you email
     *        shows it; every-language only.
     * @param string $kind The message kind (HybridPolicy) whose Hybrid tick
     *        decides the every-language version: the newsletter's, or the
     *        post-event email's when it shows the coming events.
     */
    public static function html(
        array $cards,
        array $later,
        ?array $text,
        string $accent,
        string $lang = '',
        bool $band = false,
        string $kind = HybridPolicy::NEWSLETTER
    ): string {
        $langs = self::languages($lang, $kind);
        $words = self::words($langs);
        $headings = array_column($words, 'heading');
        $font = EmailBody::FONT;

        if (count($langs) > 1 && $band) {
            // The main language big, the others on one line under it.
            $others = array_values(array_unique(array_diff(array_slice($headings, 1), [$headings[0]])));
            $html = NewsletterConfirmMail::headingBand(
                $headings[0],
                [] === $others ? $headings[0] : implode(' · ', $others)
            );
        } elseif (count($langs) > 1) {
            $html = sprintf(
                '<h1 style="margin:0;font-family:%s;font-size:26px;line-height:1.25;color:%s">%s</h1>'
                    . '<p style="margin:2px 0 24px;font-family:%s;font-size:16px;line-height:1.4;color:%s">%s</p>',
                $font,
                self::TEXT_COLOR,
                esc_html($headings[0]),
                $font,
                self::MUTED_COLOR,
                implode('<br>', array_map('esc_html', array_unique(array_slice($headings, 1))))
            );
        } else {
            $html = sprintf(
                '<h1 style="margin:0 0 24px;font-family:%s;font-size:26px;line-height:1.25;color:%s">%s</h1>',
                $font,
                self::TEXT_COLOR,
                esc_html($headings[0])
            );
        }

        $html .= self::bilingual(self::inLanguage(self::byLanguage($text ?? [], ''), $langs), '0 0 24px');

        foreach ($cards as $card) {
            $html .= self::cardHtml($card, $words, $accent, $langs);
        }

        if ([] !== $later) {
            $html .= sprintf(
                '<h2 style="margin:8px 0 12px;font-family:%s;font-size:18px;line-height:1.3;color:%s">%s</h2>',
                $font,
                self::TEXT_COLOR,
                self::pairHtml(self::pair($words, 'later'))
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
     * The greeting line at the top of one recipient's announcement, HTML and
     * plain text: "Hei Anna! · Hi Anna!", or "Hei! · Hi!" without a name. In
     * a one-language email ($lang a site language) only that language: "Hei
     * Anna!" or "Hi Anna!". The name is escaped in the HTML. The HTML line is
     * EmailBody::greeting(): its own line, in italics.
     *
     * @return array{html: string, text: string}
     */
    public static function greeting(?string $name, string $lang = ''): array
    {
        $name = null === $name ? '' : trim($name);
        $words = static fn (): string => '' === $name
            ? __('Hi!', 'eventcrew')
            /* translators: %s: the name the subscriber wants to be greeted with */
            : sprintf(__('Hi %s!', 'eventcrew'), $name);
        $line = self::joinAll(array_map('strval', HybridPolicy::inEach(self::languages($lang), $words)));

        return [
            'html' => EmailBody::greeting(self::pairHtml($line)),
            'text' => $line,
        ];
    }

    /**
     * The same content as plain text, for the multipart alternative.
     *
     * @param array<int, array<string, mixed>> $cards
     * @param array<int, array<string, mixed>> $later
     * @param array<string, string>|null $text Keyed by language.
     * @param string $lang A site language for a one-language email; '' for every language.
     * @param string $kind The message kind whose Hybrid tick applies; see html().
     */
    public static function text(
        array $cards,
        array $later,
        ?array $text,
        string $lang = '',
        string $kind = HybridPolicy::NEWSLETTER
    ): string {
        $langs = self::languages($lang, $kind);
        $words = self::words($langs);
        $parts = [mb_strtoupper(self::pair($words, 'heading'))];

        $intro = self::bilingualText(self::inLanguage(self::byLanguage($text ?? [], ''), $langs));

        if ('' !== $intro) {
            $parts[] = $intro;
        }

        foreach ($cards as $card) {
            $parts[] = self::cardText($card, $words, $langs);
        }

        if ([] !== $later) {
            $lines = [self::TEXT_RULE, self::pair($words, 'later')];

            foreach ($later as $event) {
                $link = self::laterLink($event);
                $dj = self::dj($event);
                $lines[] = '- ' . self::when($event) . ' · ' . self::name($event)
                    . (! empty($event['sold_out']) ? ' · ' . self::pair($words, 'sold_out') : '')
                    . ('' === $link ? '' : ': ' . $link)
                    . ('' === $dj ? '' : "\n  " . $dj);
            }

            $parts[] = implode("\n", $lines);
        }

        return implode("\n\n", $parts);
    }

    /**
     * One card as plain text: the date, the title, the DJ line, the card's own text when
     * it has one, the practical information, then the ticket and music links.
     *
     * @param array<string, mixed> $card
     * @param array<string, array<string, string>> $words
     * @param list<string> $langs
     */
    private static function cardText(array $card, array $words, array $langs): string
    {
        $text = self::bilingualText(self::inLanguage(self::byLanguage($card, 'text_'), $langs));
        $practical = self::practicalLines($card, $words);
        $times = self::bilingualText(self::inLanguage(
            array_map(static fn (array $lines): string => implode("\n", $lines), $practical['times']),
            $langs
        ));
        $lines = array_merge(
            [self::when($card), self::name($card)],
            '' === self::dj($card) ? [] : [self::dj($card)],
            '' === $text ? [] : [$text],
            $practical['plain'],
            '' === $times ? [] : [([] === $practical['plain'] ? '' : "\n") . $times]
        );

        $ticket = (string) ($card['ticket_url'] ?? '');

        if (! empty($card['sold_out'])) {
            $lines[] = self::pair($words, 'sold_out');
        } elseif ('' !== $ticket) {
            $lines[] = self::buyLabel($card, $words) . ': ' . $ticket;
        }

        if ('' !== (string) ($card['listen'] ?? '')) {
            $lines[] = self::pair($words, 'listen') . ': ' . $card['listen'];
        }

        return implode("\n", $lines);
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
     * @param list<string> $langs
     */
    private static function cardHtml(array $card, array $words, string $accent, array $langs): string
    {
        $font = EmailBody::FONT;
        $ticket = (string) ($card['ticket_url'] ?? '');
        $listen = (string) ($card['listen'] ?? '');
        $page = (string) ($card['url'] ?? '');
        $image = (string) ($card['image'] ?? '');
        $soldOut = ! empty($card['sold_out']);
        $name = self::name($card);
        $nameFi = self::nameFi($card);
        // The lines under the name: the Finnish name when it is different,
        // then the DJ line. The last one keeps the space below the title.
        $under = array_values(array_filter(
            [$nameFi !== $name ? $nameFi : '', self::dj($card)],
            static fn (string $line): bool => '' !== $line
        ));

        $html = '';
        $body = '';

        if ('' !== $image) {
            // The picture already shows the date, the name and the DJ, so
            // they are not written under it; they are its alt text instead,
            // styled to read as a heading when the email client does not
            // load pictures (Lou, 2026-11-03).
            $alt = implode(' — ', array_filter(
                [self::when($card), $name, ...$under],
                static fn (string $part): bool => '' !== $part
            ));
            $picture = sprintf(
                '<img src="%s" width="%d" alt="%s" style="display:block;width:100%%;max-width:%dpx;height:auto;'
                    . 'border:0;border-radius:12px 12px 0 0;background:%s;font-family:%s;font-size:20px;'
                    . 'font-weight:700;line-height:1.4;color:%s;text-align:center">',
                esc_url($image),
                self::IMAGE_WIDTH,
                esc_attr($alt),
                self::IMAGE_WIDTH,
                self::ALT_BACKGROUND,
                $font,
                self::TEXT_COLOR
            );
            $link = '' !== $ticket && ! $soldOut ? $ticket : $page;
            $html .= '<tr><td style="padding:0;line-height:0">'
                . ('' === $link ? $picture : self::pictureLink($link, $picture))
                . '</td></tr>';
        } else {
            $body .= sprintf(
                '<p style="margin:0 0 6px;font-family:%s;font-size:13px;font-weight:700;letter-spacing:.04em;'
                    . 'color:%s;text-align:center">%s</p>',
                $font,
                esc_attr($accent),
                esc_html(self::when($card))
            );
            $body .= sprintf(
                '<h2 style="margin:0 0 %s;font-family:%s;font-size:20px;line-height:1.3;color:%s;text-align:center">'
                    . '%s</h2>',
                [] !== $under ? '2px' : '14px',
                $font,
                self::TEXT_COLOR,
                esc_html($name)
            );

            foreach ($under as $index => $line) {
                $body .= sprintf(
                    '<p style="margin:0 0 %s;font-family:%s;font-size:15px;line-height:1.4;color:%s;'
                        . 'text-align:center">%s</p>',
                    count($under) - 1 === $index ? '14px' : '2px',
                    $font,
                    self::MUTED_COLOR,
                    esc_html($line)
                );
            }
        }

        // A text written for this card alone (the post-event email's first
        // event); the newsletter's cards have none.
        $body .= self::bilingual(self::inLanguage(self::byLanguage($card, 'text_'), $langs), '0 0 14px');

        $practical = self::practicalLines($card, $words);

        // A one-language email has one block of door times, with no label.
        $timeBlocks = [];

        foreach ($practical['times'] as $language => $lines) {
            $timeBlocks[count($langs) > 1 ? HybridPolicy::tag($language) : ''] = $lines;
        }

        // The venue and the address, then, after an empty line, the door
        // times as one block per language, each with its FI / EN label,
        // aligned left (Lou, 2026-11-03).
        $blocks = [];

        if ([] !== $practical['plain']) {
            $blocks[] = implode('<br>', array_map('esc_html', $practical['plain']));
        }

        foreach ($timeBlocks as $label => $lines) {
            if ([] !== $lines) {
                $blocks[] = ('' === $label ? '' : self::labelHtml($label))
                    . implode('<br>', array_map('esc_html', $lines));
            }
        }

        $last = count($blocks) - 1;

        foreach ($blocks as $i => $block) {
            if ($i === $last) {
                $margin = '0 0 16px';
            } elseif (0 === $i && [] !== $practical['plain']) {
                $margin = '0 0 21px'; // one empty line: 14px text at line height 1.5
            } else {
                $margin = '0 0 8px';
            }

            $body .= sprintf(
                '<p style="margin:%s;font-family:%s;font-size:14px;line-height:1.5;color:%s;'
                    . 'text-align:left">%s</p>',
                $margin,
                $font,
                self::MUTED_COLOR,
                $block
            );
        }

        if ($soldOut) {
            $body .= sprintf(
                '<p style="margin:4px 0 16px;text-align:center"><span style="display:inline-block;padding:8px 16px;'
                    . 'border-radius:999px;'
                    . 'background:#f1f2f4;font-family:%s;font-size:14px;font-weight:600;color:%s">%s</span></p>',
                $font,
                self::MUTED_COLOR,
                self::pairHtml(self::pair($words, 'sold_out'))
            );
        } elseif ('' !== $ticket) {
            $body .= sprintf(
                '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0"'
                    . ' style="margin:4px auto 16px">'
                    . '<tr><td align="center" style="border-radius:999px;background:%s">'
                    . '<a href="%s" style="display:inline-block;text-align:center;padding:13px 24px;'
                    . 'font-family:%s;font-size:15px;'
                    . 'font-weight:700;line-height:1.2;color:%s;text-decoration:none;border-radius:999px">%s</a>'
                    . '</td></tr></table>',
                esc_attr($accent),
                esc_url($ticket),
                $font,
                EmailBody::readableOn($accent),
                self::pairHtml(self::buyLabel($card, $words))
            );
        }

        if ('' !== $listen) {
            $body .= sprintf(
                '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0"'
                    . ' style="margin:0 auto 16px">'
                    . '<tr><td align="center" style="border-radius:999px;border:1px solid %s;background:#ffffff">'
                    . '<a href="%s" style="display:inline-block;text-align:center;padding:11px 22px;'
                    . 'font-family:%s;font-size:15px;'
                    . 'font-weight:600;line-height:1.2;color:%s;text-decoration:none;border-radius:999px">▶<br>%s</a>'
                    . '</td></tr></table>',
                esc_attr($accent),
                esc_url($listen),
                $font,
                esc_attr($accent),
                implode('<br>', array_map('esc_html', array_unique(array_column($words, 'listen'))))
            );
        }

        if ('' !== $body) {
            $html .= '<tr><td align="center" style="padding:18px 20px 20px;text-align:center">' . $body . '</td></tr>';
        }

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
            $right = self::pairHtml(self::pair($words, 'sold_out'));
        } elseif ('' !== $link) {
            $right = sprintf(
                '<a href="%s" style="color:%s;font-weight:600;text-decoration:none;white-space:nowrap">%s →</a>',
                esc_url($link),
                esc_attr($accent),
                self::pairHtml(self::pair($words, 'tickets') . ('' === $price ? '' : ' · ' . $price))
            );
        } else {
            $right = '';
        }

        $dj = self::dj($event);
        $text = sprintf(
            '<strong>%s</strong><br>%s%s',
            esc_html(self::when($event)),
            esc_html(self::name($event)) . ('' === $dj ? '' : '<br>' . esc_html($dj)),
            '' === $right
                ? ''
                : '<br><span style="font-size:14px;color:' . self::MUTED_COLOR . '">' . $right . '</span>'
        );
        $cell = sprintf(
            'font-family:%s;font-size:15px;line-height:1.45;color:%s',
            $font,
            self::TEXT_COLOR
        );
        $image = (string) ($event['image'] ?? '');

        if ('' === $image) {
            return sprintf(
                '<tr><td style="padding:10px 0;border-top:1px solid %s;%s">%s</td></tr>',
                self::BORDER_COLOR,
                $cell,
                $text
            );
        }

        // The picture on the left and the text on its right, in a table of
        // their own: email clients do not lay out floats or flex boxes.
        $picture = sprintf(
            '<img src="%s" width="%d" alt="%s" '
                . 'style="display:block;width:%dpx;height:auto;border:0;border-radius:6px">',
            esc_url($image),
            self::THUMB_WIDTH,
            esc_attr(self::name($event)),
            self::THUMB_WIDTH
        );

        return sprintf(
            '<tr><td style="padding:10px 0;border-top:1px solid %s">'
                . '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0"><tr>'
                . '<td width="%d" valign="middle" style="width:%dpx;padding:0 14px 0 0;vertical-align:middle;'
                . 'line-height:0">%s</td>'
                . '<td valign="middle" style="vertical-align:middle;%s">%s</td>'
                . '</tr></table></td></tr>',
            self::BORDER_COLOR,
            self::THUMB_WIDTH,
            self::THUMB_WIDTH,
            '' === $link ? $picture : self::pictureLink($link, $picture),
            $cell,
            $text
        );
    }

    /** Where a "Later" line links: the ticket shop, or the event page when there is no shop link. */
    private static function laterLink(array $event): string
    {
        $ticket = (string) ($event['ticket_url'] ?? '');

        return '' !== $ticket ? $ticket : (string) ($event['url'] ?? '');
    }

    /**
     * Each language's text with its label ("FI", "EN"), or one text with no
     * label (the '' key) in a one-language email; see inLanguage(). A blank
     * line in a text starts a new paragraph, and the label goes on its first
     * paragraph only.
     *
     * @param array<string, string> $texts label => text
     */
    private static function bilingual(array $texts, string $margin): string
    {
        $html = '';

        foreach ($texts as $label => $text) {
            $label = (string) $label;
            $text = trim($text);

            if ('' === $text) {
                continue;
            }

            $paragraphs = preg_split('/\n[^\S\n]*\n\s*/', $text) ?: [$text];

            foreach ($paragraphs as $i => $paragraph) {
                $html .= sprintf(
                    '<p style="margin:0 0 10px;font-family:%s;font-size:15px;line-height:1.6;color:%s;'
                        . 'text-align:left">%s%s</p>',
                    EmailBody::FONT,
                    self::TEXT_COLOR,
                    '' === $label || 0 !== $i ? '' : self::labelHtml($label),
                    str_replace("\n", '<br>', EmailBody::inline($paragraph))
                );
            }
        }

        return '' === $html ? '' : sprintf('<div style="margin:%s">%s</div>', $margin, $html);
    }

    /** The small "FI" or "EN" tag in front of a text in that language (EmailBody::langTag()). */
    private static function labelHtml(string $label): string
    {
        return EmailBody::langTag($label);
    }

    /**
     * "FI: …" then "EN: …", the label on the first line of each, or one text
     * with no label (the '' key). When a text has more than one line, a blank
     * line goes between the languages so the English does not run on from
     * the last Finnish line.
     *
     * @param array<string, string> $texts label => text
     */
    private static function bilingualText(array $texts): string
    {
        $lines = [];
        $multiline = false;

        foreach ($texts as $label => $text) {
            $label = (string) $label;
            $text = trim($text);

            if ('' !== $text) {
                $lines[] = ('' === $label ? '' : $label . ': ') . $text;
                $multiline = $multiline || str_contains($text, "\n");
            }
        }

        return implode($multiline ? "\n\n" : "\n", $lines);
    }

    /**
     * A card's practical information (NewsletterWriter::apply()) as short
     * lines: 'plain' holds the venue and the street address, which need no
     * translation; 'times' holds the door times in each language of $words
     * ("Ovet aukeavat 18:30", "Doors open 18:30"). A line that is not known
     * is left out.
     *
     * @param array<string, mixed> $card
     * @param array<string, array<string, string>> $words
     * @return array{plain: list<string>, times: array<string, list<string>>}
     */
    private static function practicalLines(array $card, array $words): array
    {
        $info = NewsletterWriter::practicalOf($card['practical'] ?? null);

        $lines = ['plain' => [], 'times' => array_fill_keys(array_keys($words), [])];
        $first = reset($words);

        if (null === $info) {
            return $lines;
        }

        foreach (NewsletterWriter::PRACTICAL_KEYS as $key) {
            if ('' === $info[$key]) {
                continue;
            }

            // The venue and the address need no label; the times do.
            if (isset($first[$key])) {
                foreach ($words as $language => $each) {
                    $lines['times'][$language][] = sprintf($each[$key], $info[$key]);
                }
            } else {
                $lines['plain'][] = $info[$key];
            }
        }

        return $lines;
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

    /** The event's clean name (EventName), or its title when EventMesh has none. */
    private static function name(array $event): string
    {
        return self::plain(
            EventName::name((int) ($event['id'] ?? 0), Language::ENGLISH, (string) ($event['title'] ?? ''))
        );
    }

    /** The clean Finnish name, or the Finnish title as before; '' when there is neither. */
    private static function nameFi(array $event): string
    {
        return self::plain(
            EventName::name((int) ($event['id'] ?? 0), Language::FINNISH, (string) ($event['title_fi'] ?? ''))
        );
    }

    /** The DJ line (EventName::djLine()), '' when there is none. */
    private static function dj(array $event): string
    {
        return self::plain(EventName::djLine((int) ($event['id'] ?? 0)));
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
        return self::joinAll(array_column($words, $key));
    }

    /**
     * The words under $key with $value in place of their %s, in each
     * language: "Ovet aukeavat 18:30", new line, "Doors open 18:30".
     *
     * @param array<string, array<string, string>> $words
     */
    private static function pairWith(array $words, string $key, string $value): string
    {
        return self::joinAll(array_map(
            static fn (array $each): string => sprintf($each[$key], $value),
            array_values($words)
        ));
    }

    /**
     * The rule for putting a Finnish text and its English translation
     * together. When both fit on one line of a narrow phone screen
     * (PAIR_MAX characters with the " · " between them) they share one
     * line: "Osta lippu · Buy a ticket". When they are longer, the English
     * goes on a new line under the Finnish, as "\n" (pairHtml() makes it a
     * line break in the email). The same text in both languages is written
     * once.
     */
    public static function joinPair(string $fi, string $en): string
    {
        return self::joinAll([$fi, $en]);
    }

    /**
     * joinPair() for any number of languages, main language first: one line
     * with " · " between the texts when it fits in PAIR_MAX characters, else
     * one line per text. A text the same as an earlier one is left out.
     *
     * @param array<string> $texts
     */
    public static function joinAll(array $texts): string
    {
        $texts = array_values(array_unique(array_map('strval', $texts)));
        $line = implode(' · ', $texts);

        return mb_strlen($line) <= self::PAIR_MAX ? $line : implode("\n", $texts);
    }

    /** A text from joinPair() for the email's HTML: escaped, its new line a line break. */
    private static function pairHtml(string $text): string
    {
        return str_replace("\n", '<br>', esc_html($text));
    }

    /**
     * The small muted "Automatically translated" note, in $lang, for a
     * one-language email whose text was translated by the language model,
     * HTML and plain text.
     *
     * @return array{html: string, text: string}
     */
    public static function translatedNote(string $lang = Language::ENGLISH): array
    {
        $note = (string) Language::run(
            $lang,
            static fn (): string => __('Automatically translated', 'eventcrew')
        );

        return [
            'html' => sprintf(
                '<p style="margin:8px 0 0;font-family:%s;font-size:12px;line-height:1.4;color:%s">%s</p>',
                EmailBody::FONT,
                self::LABEL_COLOR,
                esc_html($note)
            ),
            'text' => $note,
        ];
    }

    /**
     * The languages the email is written in, main language first: $lang
     * alone when it is a site language; for any other $lang ('' = every
     * language) the every-language version of $kind, which is every site
     * language while hybrid is allowed and $kind's Hybrid tick is on
     * (HybridPolicy), else the main language alone.
     *
     * @return list<string>
     */
    private static function languages(string $lang, string $kind = HybridPolicy::NEWSLETTER): array
    {
        return in_array($lang, HybridPolicy::siteLanguages(), true)
            ? [$lang]
            : HybridPolicy::languagesFor($kind);
    }

    /**
     * The texts in $source, keyed by language: $source[$prefix . $language]
     * for every key that has the form, like a card's text_fi and text_en.
     *
     * @param array<string, mixed> $source
     * @return array<string, string>
     */
    private static function byLanguage(array $source, string $prefix): array
    {
        $texts = [];

        foreach ($source as $key => $value) {
            $key = (string) $key;

            if (
                ('' === $prefix || str_starts_with($key, $prefix))
                && 1 === preg_match('/^[a-z]{2,3}$/', substr($key, strlen($prefix)))
                && is_scalar($value)
            ) {
                $texts[substr($key, strlen($prefix))] = trim((string) $value);
            }
        }

        return $texts;
    }

    /**
     * The label => text argument for bilingual() and bilingualText(): each
     * language's text with its label ("FI", "EN") in an email in several
     * languages, or the one language's text with no label ('') in a
     * one-language email. When that language's text is empty, the first
     * other site language's text that is not empty is shown instead of
     * nothing.
     *
     * @param array<string, string> $texts language => text
     * @param list<string> $langs
     * @return array<string, string>
     */
    private static function inLanguage(array $texts, array $langs): array
    {
        if (1 === count($langs)) {
            foreach (array_unique([$langs[0], ...HybridPolicy::siteLanguages()]) as $language) {
                if ('' !== trim($texts[$language] ?? '')) {
                    return ['' => trim($texts[$language])];
                }
            }

            return ['' => ''];
        }

        $labelled = [];

        foreach ($langs as $language) {
            $labelled[HybridPolicy::tag($language)] = trim($texts[$language] ?? '');
        }

        return $labelled;
    }

    /**
     * Every fixed word, in each of $langs, keyed by language.
     *
     * @param list<string> $langs
     * @return array<string, array<string, string>>
     */
    private static function words(array $langs): array
    {
        $words = static fn (): array => [
            'heading' => __('Coming events', 'eventcrew'),
            'later' => __('Later', 'eventcrew'),
            'buy' => __('Buy a ticket', 'eventcrew'),
            'tickets' => __('Tickets', 'eventcrew'),
            'listen' => __('Listen to the DJ', 'eventcrew'),
            'sold_out' => __('Sold out', 'eventcrew'),
            /* translators: %s: the time the doors of the event open, like 18:30 */
            'doors_open' => __('Doors open %s', 'eventcrew'),
            /* translators: %s: the time the doors of the event close, like 19:00 */
            'doors_close' => __('Doors close %s', 'eventcrew'),
        ];

        return HybridPolicy::inEach($langs, $words);
    }

    /**
     * A card picture wrapped in its link, with no underline under it: mail
     * apps underline every link by default, and under a picture that shows
     * as a line (Lou, Q208).
     */
    private static function pictureLink(string $link, string $picture): string
    {
        return sprintf(
            '<a href="%s" style="display:block;text-decoration:none;border:0">%s</a>',
            esc_url($link),
            $picture
        );
    }
}
