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
 * organizer guide's contents list). Every style
 * is inline: an email client is not a browser, and a stylesheet in the head is
 * the first thing Gmail throws away.
 */
final class EmailBody
{
    /** The font stack, repeated inline because email clients inherit nothing. */
    public const FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

    private const TEXT_COLOR = '#1f2328';
    private const MUTED_COLOR = '#5b6470';

    /** An emoji at the start of a line: the symbol and pictograph blocks. */
    private const EMOJI_START = '/^[\x{2190}-\x{2BFF}\x{1F000}-\x{1FAFF}]/u';

    /** "[text](#anchor)": a link to a heading in the same message. */
    private const ANCHOR_LINK = '/\[([^\]\n]+)\]\(#([a-z0-9-]+)\)/';

    /** The colour of the FI / EN language tag on a light background: a muted gold. */
    public const TAG_COLOR = '#9a7440';

    /**
     * @param array<int, array{label: string, url: string}> $actions Buttons to
     *        show under the text, most important first - the first is drawn
     *        filled, the rest outlined.
     * @param string $tag 'FI' or 'EN': the language tag (langTag()) at the
     *        start of the first paragraph; '' for none.
     */
    public static function toHtml(
        string $text,
        array $actions = [],
        string $accent = '#4a6cf7',
        string $tag = ''
    ): string {
        $html = self::blocks($text, $tag);

        foreach (self::buttons($actions, $accent) as $button) {
            $html .= $button;
        }

        return $html;
    }

    /**
     * $buttonsHtml (from toHtml() with no text) with every button centred.
     */
    public static function centred(string $buttonsHtml): string
    {
        return str_replace(
            ['<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 12px">',
                '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 12px">'],
            ['<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0"'
                . ' style="margin:8px auto 12px">',
                '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0"'
                . ' style="margin:0 auto 12px">'],
            $buttonsHtml
        );
    }

    /**
     * The plain-text counterpart, kept in step with the HTML so the multipart
     * alternative says the same thing: each button becomes a "Label: url" line,
     * which is what these bodies looked like before buttons existed.
     *
     * @param array<int, array{label: string, url: string}> $actions
     */
    public static function toText(string $text, array $actions = []): string
    {
        $text = self::plainHeadings($text);
        $lines = [];

        foreach ($actions as $action) {
            if ('' !== $action['url']) {
                $lines[] = $action['label'] . ': ' . $action['url'];
            }
        }

        return [] === $lines ? $text : $text . "\n\n" . implode("\n", $lines);
    }

    /**
     * The text with each "## " heading marker removed, for the places that
     * cannot draw a heading: the plain-text email part and a Telegram DM. The
     * heading stays as its own paragraph, which still reads as one.
     */
    public static function plainHeadings(string $text): string
    {
        $text = (string) preg_replace('/^## /m', '', $text);

        // A contents link cannot jump anywhere in plain text: keep its words.
        return (string) preg_replace(self::ANCHOR_LINK, '$1', $text);
    }

    /**
     * The text for a Telegram DM: headings and links as plainHeadings() makes
     * them, and each emoji list line without its "- " and with a blank line
     * after it, so the lines do not run together on a phone screen.
     */
    public static function forDm(string $text): string
    {
        $lines = explode("\n", self::plainHeadings(str_replace(["\r\n", "\r"], "\n", $text)));
        $out = [];

        foreach ($lines as $index => $line) {
            if (! self::isEmojiLine($line)) {
                $out[] = $line;

                continue;
            }

            $out[] = substr(trim($line), 2);

            if (self::isEmojiLine($lines[$index + 1] ?? '')) {
                $out[] = '';
            }
        }

        return implode("\n", $out);
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

        foreach ($blocks as $block) {
            $block = trim($block, "\n");

            if ('' === trim($block)) {
                continue;
            }

            if (self::isHeading($block)) {
                $html .= self::heading(substr(trim($block), 3));

                continue;
            }

            if (self::isList($block)) {
                $html .= self::list($block);

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
     * letter-spaced, in a thin rounded frame, in $color (a muted gold by
     * default; a lighter one on a dark card). The space after it is written
     * out, as Outlook drops the margin of an inline element.
     */
    public static function langTag(string $label, string $color = self::TAG_COLOR): string
    {
        return sprintf(
            '<span style="display:inline-block;padding:0 6px;border:1px solid %1$s;'
                . 'border-radius:4px;font-family:%2$s;font-size:11px;font-weight:700;line-height:17px;'
                . 'letter-spacing:.1em;color:%1$s;vertical-align:1px">%3$s</span>&nbsp; ',
            esc_attr($color),
            self::FONT,
            esc_html($label)
        );
    }

    private static function isHeading(string $block): bool
    {
        return str_starts_with(trim($block), '## ') && ! str_contains(trim($block), "\n");
    }

    private static function heading(string $text): string
    {
        return sprintf(
            '<p id="%s" style="margin:32px 0 12px;font-family:%s;font-size:18px;font-weight:700;'
                . 'line-height:1.3;color:%s">%s</p>',
            esc_attr(self::anchor($text)),
            self::FONT,
            self::TEXT_COLOR,
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
                . 'line-height:1.6;color:%s%s">%s</ul>',
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
            '<p style="margin:0 0 16px;font-family:%s;font-size:16px;line-height:1.6;color:%s">%s%s</p>',
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
     * encode it.
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

        return (string) preg_replace_callback(
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
    }

    /**
     * One table per button. A table (rather than a styled anchor alone) is what
     * keeps Outlook's Word renderer from dropping the background colour, and
     * stacking them one per row is what keeps them readable on a phone.
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

            if ('' === $url) {
                continue;
            }

            $buttons[] = sprintf(
                '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 12px">'
                    . '<tr><td style="border-radius:8px;background:%s;border:1px solid %s">'
                    . '<a href="%s" style="display:inline-block;padding:12px 24px;font-family:%s;font-size:15px;'
                    . 'font-weight:600;line-height:1.2;color:%s;text-decoration:none;border-radius:8px">%s</a>'
                    . '</td></tr></table>',
                $primary ? esc_attr($accent) : '#ffffff',
                esc_attr($accent),
                esc_url($url),
                self::FONT,
                $primary ? self::readableOn($accent) : esc_attr($accent),
                esc_html($action['label'])
            );

            $primary = false;
        }

        if ([] !== $buttons) {
            // The buttons sit closer to the text than a paragraph gap, and the
            // first one carries the top margin for the whole group.
            $buttons[0] = str_replace('style="margin:0 0 12px"', 'style="margin:8px 0 12px"', $buttons[0]);
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
            '<p style="margin:0;font-family:%s;font-size:12px;line-height:1.6;color:%s">%s</p>',
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
