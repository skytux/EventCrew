<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * Turns the plain-text body a notifier writes into the HTML fragment that drops
 * into the email template's {{content}} slot.
 *
 * Notifiers keep writing plain text - one translated string per message, the
 * same one the Telegram DM is built from - and this is the single place that
 * knows how text becomes email HTML. Blank lines separate paragraphs, runs of
 * "- " lines become a list, a paragraph of one "## " line becomes a section
 * heading, and each named action becomes a button. A list whose every line
 * starts with an emoji ("- 🎟️ ...") draws the emoji as its bullet, with more
 * room between the lines; only the welcome message and the organizer guide
 * write those. "[text](#anchor)" links to a heading in the same message (the
 * organizer guide's contents list). "**bold**" and "*italic*" mark words
 * inside a line. A first paragraph that is only a greeting ("Hi Aino,") is
 * drawn on its own line in italics, like the opening of a letter. Every style
 * is inline: an email client is not a browser, and a stylesheet in the head is
 * the first thing Gmail throws away. The one exception is the gold shimmer on
 * headings (the ec-shimmer class), which EmailTemplate adds in the head for
 * the mail apps that can draw it; elsewhere the inline gold is what shows.
 */
final class EmailBody
{
    /** The font stack, repeated inline because email clients inherit nothing. */
    public const FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

    /** The serif for the greeting, headings and closing. No web font is linked. */
    public const HEAD_FONT = "Georgia,'Times New Roman',serif";

    /** The text colour on the cream card. */
    public const TEXT_COLOR = '#2a2233';

    public const MUTED_COLOR = '#6b5f73';

    /** The gold of headings, and of the crew name in the closing. */
    public const GOLD = '#a8641f';

    /**
     * The colour of a warning button (the newsletter preview's stop button),
     * which stays filled red instead of white with a glow.
     */
    public const DANGER_COLOR = '#c62828';

    /** "**bold**": two stars on each side, no space just inside them. */
    private const BOLD = '/\*\*(?=\S)(.+?)(?<=\S)\*\*/u';

    /** "*italic*": one star on each side, not inside a word, no space just inside them. */
    private const ITALIC = '/(?<![\w*])\*(?=[^\s*])([^*\n]*?[^\s*])\*(?![\w*])/u';

    /** An emoji at the start of a line: the symbol and pictograph blocks. */
    private const EMOJI_START = '/^[\x{2190}-\x{2BFF}\x{1F000}-\x{1FAFF}]/u';

    /** "[text](#anchor)": a link to a heading in the same message. */
    private const ANCHOR_LINK = '/\[([^\]\n]+)\]\(#([a-z0-9-]+)\)/';

    /**
     * An action with this 'style' is an "Open in Telegram" deep link
     * (MessageActions::email()): drawn as one line of small links under the
     * buttons instead of a big button of its own.
     */
    public const STYLE_TELEGRAM = 'telegram';

    /**
     * An action with this 'style' is a plain web link: drawn as one line of
     * small links under the buttons (and the Telegram line), for the links
     * that come after at most three buttons (Owner 2026-11-07).
     */
    public const STYLE_LINK = 'link';

    /** The colour of the FI / EN language tag on a light background: a muted gold. */
    public const TAG_COLOR = '#9a7440';

    /**
     * @param array<int, array{label: string, url: string}> $actions Buttons to
     *        show under the text, most important first - the first is drawn
     *        filled, the rest outlined.
     * @param string $tag 'FI' or 'EN': the language tag (langTag()) at the
     *        start of the first paragraph; '' for none.
     * @param string $afterFirst HTML that goes right under the first button
     *        (the event's picture under the ticket button,
     *        EventPicture::block()), or under the text when there is none.
     */
    public static function toHtml(
        string $text,
        array $actions = [],
        string $accent = '#4a6cf7',
        string $tag = '',
        string $afterFirst = ''
    ): string {
        $html = self::blocks($text, $tag);
        $buttons = self::buttons($actions, $accent);

        if ([] === $buttons) {
            $html .= $afterFirst;
        }

        foreach ($buttons as $i => $button) {
            $html .= $button;

            if (0 === $i && '' !== $afterFirst) {
                // The next button keeps a gap from the text above it.
                $html .= count($buttons) > 1 ? '<div style="margin:0 0 24px">' . $afterFirst . '</div>' : $afterFirst;
            }
        }

        return $html . self::telegramLinks($actions) . self::plainLinks($actions);
    }

    /**
     * The STYLE_LINK actions as one centred line of small links, '' when
     * there are none.
     *
     * @param array<int, array{label: string, url: string, style?: string}> $actions
     */
    private static function plainLinks(array $actions): string
    {
        $links = [];

        foreach ($actions as $action) {
            if (self::STYLE_LINK !== ($action['style'] ?? '') || '' === trim($action['url'])) {
                continue;
            }

            $links[] = sprintf(
                '<a href="%s" style="color:%s;text-decoration:underline">%s</a>',
                esc_url($action['url']),
                self::MUTED_COLOR,
                esc_html($action['label'])
            );
        }

        return [] === $links ? '' : sprintf(
            '<p style="margin:4px 0 16px;text-align:center;font-family:%s;font-size:14px;line-height:1.7;'
                . 'color:%s">%s</p>',
            self::FONT,
            self::MUTED_COLOR,
            implode(' · ', $links)
        );
    }

    /**
     * The "Open in Telegram" line: the STYLE_TELEGRAM actions as small links,
     * centred under the buttons. '' when there are none.
     *
     * @param array<int, array{label: string, url: string, style?: string}> $actions
     */
    private static function telegramLinks(array $actions): string
    {
        $links = [];

        foreach (self::telegramActions($actions) as $action) {
            $links[] = sprintf(
                '<a href="%s" style="color:%s;text-decoration:underline">%s</a>',
                esc_url($action['url']),
                self::MUTED_COLOR,
                esc_html($action['label'])
            );
        }

        if ([] === $links) {
            return '';
        }

        return sprintf(
            '<p style="margin:4px 0 16px;text-align:center;font-family:%s;font-size:14px;line-height:1.7;'
                . 'color:%s">%s %s</p>',
            self::FONT,
            self::MUTED_COLOR,
            esc_html__('Open in Telegram:', 'eventcrew'),
            implode(' · ', $links)
        );
    }

    /**
     * @param array<int, array{label: string, url: string, style?: string}> $actions
     * @return list<array{label: string, url: string, style?: string}>
     */
    private static function telegramActions(array $actions): array
    {
        return array_values(array_filter(
            $actions,
            static fn (array $action): bool => self::STYLE_TELEGRAM === ($action['style'] ?? '')
                && '' !== trim($action['url'])
        ));
    }

    /**
     * $buttonsHtml (from toHtml() with no text) with every button centred.
     * Every button is centred already since the v4 look, so this returns
     * $buttonsHtml as it is; it stays for the callers that ask for it.
     */
    public static function centred(string $buttonsHtml): string
    {
        return $buttonsHtml;
    }

    /**
     * The plain-text counterpart, kept in step with the HTML so the multipart
     * alternative says the same thing: each button becomes a "Label: url" line,
     * which is what these bodies looked like before buttons existed.
     *
     * $afterFirst (plain text) goes right under the first button's line, as
     * in toHtml(), set apart by empty lines.
     *
     * @param array<int, array{label: string, url: string}> $actions
     */
    public static function toText(string $text, array $actions = [], string $afterFirst = ''): string
    {
        $text = self::plainHeadings($text);
        $lines = [];
        $afterFirst = trim($afterFirst);
        $placed = '' === $afterFirst;

        foreach ($actions as $action) {
            if ('' === $action['url']) {
                continue;
            }

            $telegram = self::STYLE_TELEGRAM === ($action['style'] ?? '');
            $lines[] = $telegram
                /* translators: %s: an action, such as "Show your ticket". The line is followed by a t.me link. */
                ? sprintf(__('%s in Telegram', 'eventcrew'), $action['label']) . ': ' . $action['url']
                : $action['label'] . ': ' . $action['url'];

            if (! $placed && ! $telegram && self::STYLE_LINK !== ($action['style'] ?? '')) {
                $lines[] = "\n" . $afterFirst . "\n";
                $placed = true;
            }
        }

        if (! $placed) {
            $text .= "\n\n" . $afterFirst;
        }

        return [] === $lines ? $text : rtrim($text . "\n\n" . implode("\n", $lines));
    }

    /**
     * The text with each "## " heading marker removed, for the places that
     * cannot draw a heading: the plain-text email part and a Telegram DM. The
     * heading stays as its own paragraph, which still reads as one.
     */
    public static function plainHeadings(string $text): string
    {
        $text = (string) preg_replace('/^## /m', '', $text);

        // Bold and italic cannot be drawn in plain text: keep the words.
        $text = (string) preg_replace(self::BOLD, '$1', $text);
        $text = (string) preg_replace(self::ITALIC, '$1', $text);

        // A contents link cannot jump anywhere in plain text: keep its words.
        return (string) preg_replace(self::ANCHOR_LINK, '$1', $text);
    }

    /**
     * The text for a Telegram DM. Each emoji list line loses its "- ".
     *
     * Spacing (Owner 2026-11-07): a section is a run of lines with no empty
     * line between them. When one of its lines is likely to wrap on a phone
     * (longer than WRAP characters), every line of that section gets an
     * empty line under it; otherwise the lines stay together.
     *
     * Plain ($html false): headings, bold and links as plainHeadings() makes
     * them. Telegram HTML ($html true, for a message sent with parse_mode
     * HTML): the text escaped, "## " headings and **bold** as <b>, *italic*
     * as <i>, so the headings show as headings in Telegram (Owner 2026-11-07).
     */
    public static function forDm(string $text, bool $html = false): string
    {
        $out = [];

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
            if (self::isEmojiLine($line)) {
                $line = substr(trim($line), 2);
            }

            if (! $html) {
                $out[] = self::plainHeadings($line);

                continue;
            }

            $heading = str_starts_with($line, '## ');
            $line = htmlspecialchars($heading ? substr($line, 3) : $line, ENT_NOQUOTES, 'UTF-8');
            $line = (string) preg_replace(self::ANCHOR_LINK, '$1', $line);

            if ($heading) {
                $out[] = '<b>' . (string) preg_replace(self::BOLD, '$1', $line) . '</b>';

                continue;
            }

            $line = (string) preg_replace(self::BOLD, '<b>$1</b>', $line);
            $out[] = (string) preg_replace(self::ITALIC, '<i>$1</i>', $line);
        }

        return self::spaceSections($out, $html);
    }

    /** Characters a DM line holds before it likely wraps on a phone. */
    public const WRAP = 50;

    /**
     * Joins the lines; a section with a line likely to wrap gets an empty
     * line between all its lines (see forDm()).
     *
     * @param list<string> $lines
     */
    private static function spaceSections(array $lines, bool $html): string
    {
        $sections = [[]];

        foreach ($lines as $line) {
            if ('' === trim($line)) {
                $sections[] = [];

                continue;
            }

            $sections[count($sections) - 1][] = $line;
        }

        $out = [];

        foreach ($sections as $section) {
            $wraps = false;

            foreach ($section as $line) {
                $seen = $html ? html_entity_decode(strip_tags($line), ENT_QUOTES, 'UTF-8') : $line;

                if (mb_strlen(trim($seen), 'UTF-8') > self::WRAP) {
                    $wraps = true;

                    break;
                }
            }

            $out[] = implode($wraps ? "\n\n" : "\n", $section);
        }

        return implode("\n\n", array_values(array_filter($out, static fn (string $s): bool => '' !== $s)));
    }

    /**
     * The id a "## " heading gets, from its words: lower case, ASCII letters
     * and digits, joined by "-". The emoji in front drops out. A heading with
     * no ASCII letters at all (a translation) gets a short hash instead.
     */
    public static function anchor(string $heading): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($heading)), '-');

        return 'ec-' . ('' === $slug ? substr(md5($heading), 0, 8) : $slug);
    }

    /** A "- " line whose text starts with an emoji. */
    public static function isEmojiLine(string $line): bool
    {
        $line = trim($line);

        return str_starts_with($line, '- ') && 1 === preg_match(self::EMOJI_START, substr($line, 2));
    }

    /**
     * Paragraphs, and lists for runs of "- " lines (the open-task roll-up and
     * the recent-task recap both write those).
     */
    private static function blocks(string $text, string $tag = ''): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $blocks = preg_split('/\n{2,}/', trim($text)) ?: [];
        $html = '';
        $prefix = '' === $tag ? '' : self::langTag($tag);
        $first = true;

        foreach ($blocks as $block) {
            $block = trim($block, "\n");

            if ('' === trim($block)) {
                continue;
            }

            // A greeting as the first paragraph gets its own italic line,
            // with the language tag on a small line of its own above it.
            if ($first && self::isGreeting($block)) {
                $html .= ('' === $prefix ? '' : '<p style="margin:0 0 6px;line-height:17px">' . $prefix . '</p>')
                    . self::greeting(self::inline(trim($block)));
                $prefix = '';
                $first = false;

                continue;
            }

            $first = false;

            if (self::isHeading($block)) {
                $html .= self::heading(substr(trim($block), 3));

                continue;
            }

            if (self::isList($block)) {
                $html .= self::list($block);

                continue;
            }

            // A standing bar ({standing_bar}) on a paragraph of its own is
            // drawn as a coloured bar; the plain-text part keeps the text one.
            $barPercent = StandingBar::parse($block);

            if (null !== $barPercent) {
                $html .= StandingBar::html($barPercent);

                continue;
            }

            // The language tag goes in front of the first paragraph only.
            $html .= self::paragraph($block, $prefix);
            $prefix = '';
        }

        return $html;
    }

    /**
     * The small "FI" or "EN" tag at the start of a text in that language, so
     * a reader of a two-language email finds their part at a glance: bold,
     * letter-spaced, with no frame, in $color (a muted gold by
     * default; a lighter one on a dark card). The space after it is written
     * out, as Outlook drops the margin of an inline element.
     */
    public static function langTag(string $label, string $color = self::TAG_COLOR): string
    {
        return sprintf(
            '<span style="display:inline-block;font-family:%2$s;font-size:11px;font-weight:700;line-height:17px;'
                . 'letter-spacing:.12em;color:%1$s;vertical-align:1px">%3$s</span>&nbsp; ',
            esc_attr($color),
            self::FONT,
            esc_html($label)
        );
    }

    /**
     * $body with the greeting in front of it as its own paragraph: "Hi Aino,"
     * with the first word of $name, or "Hi," when $name is blank. For the
     * emails whose text starts without one. In the current language.
     */
    public static function withGreeting(string $name, string $body): string
    {
        $first = DoorDesk::firstName($name);

        return ('' === $first
                ? __('Hi,', 'eventcrew')
                /* translators: %s: the person's first name */
                : sprintf(__('Hi %s,', 'eventcrew'), $first))
            . "\n\n" . $body;
    }

    /**
     * A block that is only a greeting: one short line ending in a comma, like
     * "Hi Aino," or "Hei,".
     */
    public static function isGreeting(string $block): bool
    {
        $block = trim($block);

        return '' !== $block
            && ! str_contains($block, "\n")
            && str_ends_with($block, ',')
            && mb_strlen($block) <= 60
            && ! str_starts_with($block, '- ')
            && ! str_starts_with($block, '## ');
    }

    /**
     * The greeting paragraph: the serif, in italics, a little larger than the
     * text. $html is already escaped.
     */
    public static function greeting(string $html): string
    {
        return sprintf(
            '<p style="margin:0 0 18px;font-family:%s;font-size:18px;font-style:italic;line-height:1.5;color:%s">'
                . '%s</p>',
            self::HEAD_FONT,
            self::TEXT_COLOR,
            $html
        );
    }

    /**
     * A centred title for the top of a card: a small star, the title in gold
     * (with the shimmer where the mail app draws it) and a short gold rule.
     * $text is plain text and is escaped here.
     */
    public static function title(string $text): string
    {
        return '<p style="margin:0 0 14px;font-family:' . self::FONT . ';font-size:16px;line-height:1;'
            . 'color:#c9802f;text-align:center">&#10022;</p>'
            . sprintf(
                '<h1 class="ec-shimmer" style="margin:0;font-family:%s;font-size:28px;font-weight:700;'
                    . 'line-height:1.25;color:%s;text-align:center">%s</h1>',
                self::HEAD_FONT,
                self::GOLD,
                // A new line in $text starts a row of its own.
                str_replace("\n", '<br>', esc_html($text))
            )
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"'
            . ' style="margin:18px auto 26px"><tr><td style="width:48px;height:2px;line-height:2px;'
            . 'font-size:0;background:#d9b27a">&nbsp;</td></tr></table>';
    }

    private static function isHeading(string $block): bool
    {
        return str_starts_with(trim($block), '## ') && ! str_contains(trim($block), "\n");
    }

    private static function heading(string $text): string
    {
        return sprintf(
            '<p id="%s" class="ec-shimmer" style="margin:32px 0 12px;font-family:%s;font-size:21px;'
                . 'font-weight:700;line-height:1.3;color:%s">%s</p>',
            esc_attr(self::anchor($text)),
            self::HEAD_FONT,
            self::GOLD,
            esc_html($text)
        );
    }

    private static function isList(string $block): bool
    {
        foreach (explode("\n", $block) as $line) {
            if (! str_starts_with(trim($line), '- ')) {
                return false;
            }
        }

        return true;
    }

    private static function list(string $block): string
    {
        $lines = explode("\n", $block);
        $emoji = [] === array_filter($lines, static fn (string $line): bool => ! self::isEmojiLine($line));
        $items = '';

        foreach ($lines as $line) {
            $items .= sprintf(
                '<li style="margin:0 0 %s">%s</li>',
                $emoji ? '12px' : '6px',
                self::inline(substr(trim($line), 2))
            );
        }

        // An emoji list: the emoji is the bullet, so no dot and no indent.
        return sprintf(
            '<ul style="margin:0 0 20px;padding:%s;font-family:%s;font-size:16px;'
                . 'line-height:1.7;color:%s%s">%s</ul>',
            $emoji ? '0' : '0 0 0 20px',
            self::FONT,
            self::TEXT_COLOR,
            $emoji ? ';list-style:none' : '',
            $items
        );
    }

    private static function paragraph(string $block, string $prefix = ''): string
    {
        return sprintf(
            '<p style="margin:0 0 16px;font-family:%s;font-size:16px;line-height:1.7;color:%s">%s%s</p>',
            self::FONT,
            self::TEXT_COLOR,
            $prefix,
            str_replace("\n", '<br>', self::inline($block))
        );
    }

    /**
     * Escapes a run of text and links any bare URL left in it. Escaping first
     * means the href carries the same &amp; entities as the visible text, which
     * is what HTML wants in an attribute anyway - no second pass can double-
     * encode it. "**bold**" and "*italic*" are applied last, and only to the
     * text outside code and links, so a star in a URL or a command is left
     * alone. The stars are matched in the escaped text, so the words between
     * them can never carry markup of their own.
     */
    public static function inline(string $text): string
    {
        $escaped = esc_html($text);

        // Commands as code that never breaks across lines: anything in
        // `backticks`, and a bare bot command like /door or /roster 14.11
        // (a slash at the start of a word, so a/b and URLs are left alone).
        $code = '<code class="eventcrew-cmd" style="white-space:nowrap;font-family:monospace">%s</code>';
        $escaped = (string) preg_replace_callback(
            '~`([^`]+)`|(?<![\w/.:&;-])(/[a-z][a-z_]*)(?![\w/.])~',
            static fn (array $m): string => sprintf($code, '' !== $m[1] ? $m[1] : $m[2]),
            $escaped
        );

        $escaped = (string) preg_replace(
            self::ANCHOR_LINK,
            '<a href="#$2" style="color:inherit;text-decoration:underline">$1</a>',
            $escaped
        );

        $escaped = (string) preg_replace_callback(
            '~https?://[^\s<>"\']+~',
            static function (array $match): string {
                // Sentence punctuation that trailed the URL in the source text
                // is not part of it; hand it back outside the link.
                $url = rtrim($match[0], '.,;:!?)');
                $tail = substr($match[0], strlen($url));

                return sprintf(
                    '<a href="%s" style="color:inherit;text-decoration:underline">%s</a>%s',
                    $url,
                    $url,
                    $tail
                );
            },
            $escaped
        );

        return self::emphasis($escaped);
    }

    /**
     * "**bold**" as <strong> and "*italic*" as <em> in $html, outside its
     * <code> and <a> elements.
     */
    private static function emphasis(string $html): string
    {
        if (! str_contains($html, '*')) {
            return $html;
        }

        $parts = preg_split('~(<code\b[^>]*>.*?</code>|<a\b[^>]*>.*?</a>)~s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (false === $parts) {
            return $html;
        }

        foreach ($parts as $index => $part) {
            // The odd parts are the code and link elements themselves.
            if (1 === $index % 2) {
                continue;
            }

            $part = (string) preg_replace(self::BOLD, '<strong>$1</strong>', $part);
            $parts[$index] = (string) preg_replace(self::ITALIC, '<em>$1</em>', $part);
        }

        return implode('', $parts);
    }

    /**
     * One table per button. A table (rather than a styled anchor alone) is what
     * keeps Outlook's Word renderer from dropping the background colour, and
     * stacking them one per row is what keeps them readable on a phone.
     *
     * Every button is centred and white with a rounded gold edge; the first
     * also glows gold (box-shadow: an app that cannot draw it shows the edge
     * only). A warning button ($accent DANGER_COLOR) is filled red instead.
     *
     * @param array<int, array{label: string, url: string}> $actions
     *
     * @return array<int, string>
     */
    private static function buttons(array $actions, string $accent): array
    {
        $buttons = [];
        $primary = true;

        foreach ($actions as $action) {
            $url = trim($action['url']);

            if ('' === $url || in_array($action['style'] ?? '', [self::STYLE_TELEGRAM, self::STYLE_LINK], true)) {
                continue;
            }

            $danger = 0 === strcasecmp(trim($accent), self::DANGER_COLOR);
            $buttons[] = sprintf(
                '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0"'
                    . ' style="margin:0 auto 12px">'
                    . '<tr><td bgcolor="%1$s" style="border-radius:28px;background:%1$s;border:1px solid %2$s%3$s">'
                    . '<a href="%4$s" style="display:inline-block;padding:14px 34px;font-family:%5$s;font-size:16px;'
                    . 'font-weight:%6$s;line-height:1.2;color:%7$s;text-decoration:none;border-radius:28px">%8$s</a>'
                    . '</td></tr></table>',
                $danger && $primary ? self::DANGER_COLOR : '#ffffff',
                $danger ? self::DANGER_COLOR : ($primary ? '#ffc98f' : '#e3c79f'),
                $primary && ! $danger
                    ? ';box-shadow:0 0 18px rgba(255,190,120,.55),0 0 46px rgba(255,190,120,.25)'
                    : '',
                esc_url($url),
                self::FONT,
                $primary ? '700' : '600',
                $danger ? ($primary ? '#ffffff' : self::DANGER_COLOR) : '#241238',
                // A label in several languages is one language per row.
                str_contains($action['label'], "\n")
                    ? '<span style="display:block;text-align:center">'
                        . str_replace("\n", '<br>', esc_html($action['label'])) . '</span>'
                    : esc_html($action['label'])
            );

            $primary = false;
        }

        if ([] !== $buttons) {
            // The buttons sit closer to the text than a paragraph gap, and the
            // first one carries the top margin for the whole group.
            $buttons[0] = str_replace('style="margin:0 auto 12px"', 'style="margin:8px auto 12px"', $buttons[0]);
        }

        return $buttons;
    }

    /**
     * Black or white text, whichever is readable on $background.
     *
     * The accent is whatever the site's palette says, and a site is as likely
     * to brand itself beige as navy. White label text baked in would vanish on
     * the light half of that range, so the choice is made from the colour's
     * luminance rather than assumed. The sRGB coefficients and the 0.55 split
     * are the usual WCAG ones, nudged to favour dark text on the midtones where
     * both are legible and dark reads better.
     */
    public static function readableOn(string $background): string
    {
        $hex = ltrim(trim($background), '#');

        if (3 === strlen($hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (6 !== strlen($hex) || 1 !== preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return '#ffffff';
        }

        $channel = static function (string $pair): float {
            $value = hexdec($pair) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        };

        $luminance = 0.2126 * $channel(substr($hex, 0, 2))
            + 0.7152 * $channel(substr($hex, 2, 2))
            + 0.0722 * $channel(substr($hex, 4, 2));

        return $luminance > 0.55 ? self::TEXT_COLOR : '#ffffff';
    }

    /**
     * The small print under the rule: the manage/unsubscribe line, as a link
     * rather than a button - it is the way out, not the thing to do.
     */
    public static function footerHtml(string $text, string $url): string
    {
        return sprintf(
            '<p style="margin:0;font-family:%s;font-size:13px;line-height:1.6;color:%s;text-align:center">%s</p>',
            self::FONT,
            self::MUTED_COLOR,
            '' === $url
                ? esc_html($text)
                : sprintf(
                    '<a href="%s" style="color:%s;text-decoration:underline">%s</a>',
                    esc_url($url),
                    self::MUTED_COLOR,
                    esc_html($text)
                )
        );
    }
}
