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
 * "- " lines become a list, and each named action becomes a button. Every style
 * is inline: an email client is not a browser, and a stylesheet in the head is
 * the first thing Gmail throws away.
 */
final class EmailBody
{
    /** The font stack, repeated inline because email clients inherit nothing. */
    public const FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

    private const TEXT_COLOR = '#1f2328';
    private const MUTED_COLOR = '#5b6470';

    /**
     * @param array<int, array{label: string, url: string}> $actions Buttons to
     *        show under the text, most important first - the first is drawn
     *        filled, the rest outlined.
     */
    public static function toHtml(string $text, array $actions = [], string $accent = '#4a6cf7'): string
    {
        $html = self::blocks($text);

        foreach (self::buttons($actions, $accent) as $button) {
            $html .= $button;
        }

        return $html;
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
        $lines = [];

        foreach ($actions as $action) {
            if ('' !== $action['url']) {
                $lines[] = $action['label'] . ': ' . $action['url'];
            }
        }

        return [] === $lines ? $text : $text . "\n\n" . implode("\n", $lines);
    }

    /**
     * Paragraphs, and lists for runs of "- " lines (the open-task roll-up and
     * the recent-task recap both write those).
     */
    private static function blocks(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $blocks = preg_split('/\n{2,}/', trim($text)) ?: [];
        $html = '';

        foreach ($blocks as $block) {
            $block = trim($block, "\n");

            if ('' === trim($block)) {
                continue;
            }

            $html .= self::isList($block) ? self::list($block) : self::paragraph($block);
        }

        return $html;
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
        $items = '';

        foreach (explode("\n", $block) as $line) {
            $items .= sprintf(
                '<li style="margin:0 0 6px">%s</li>',
                self::inline(substr(trim($line), 2))
            );
        }

        return sprintf(
            '<ul style="margin:0 0 16px;padding:0 0 0 20px;font-family:%s;font-size:16px;'
                . 'line-height:1.6;color:%s">%s</ul>',
            self::FONT,
            self::TEXT_COLOR,
            $items
        );
    }

    private static function paragraph(string $block): string
    {
        return sprintf(
            '<p style="margin:0 0 16px;font-family:%s;font-size:16px;line-height:1.6;color:%s">%s</p>',
            self::FONT,
            self::TEXT_COLOR,
            str_replace("\n", '<br>', self::inline($block))
        );
    }

    /**
     * Escapes a run of text and links any bare URL left in it. Escaping first
     * means the href carries the same &amp; entities as the visible text, which
     * is what HTML wants in an attribute anyway - no second pass can double-
     * encode it.
     */
    private static function inline(string $text): string
    {
        $escaped = esc_html($text);

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
                $primary ? '#ffffff' : esc_attr($accent),
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
