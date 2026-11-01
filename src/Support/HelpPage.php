<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The Help tab of the crew web page: the welcome text for every member, and
 * the organizer guide below it for organizers.
 *
 * The text is the same plain text the welcome message and the organizer guide
 * send ("## " headings, "- " lists, "[text](#anchor)" links), drawn here as
 * page HTML with classes instead of the email's inline styles, so the theme's
 * colours and fonts apply. Each heading gets the same id as in the email,
 * so the organizer contents list links work on the page too.
 */
final class HelpPage
{
    /** The Help tab's panel: a link to it opens the tab. */
    public const PANEL_ANCHOR = 'eventcrew-panel-help';

    /** The organizer part inside the Help tab. */
    public const ORGANIZER_ANCHOR = 'eventcrew-help-organizer';

    /**
     * Free text about how this install is run (hosting, mail, outside
     * services), shown to organizers only, in the Help tab's Infrastructure
     * card. '' leaves the card out. Set in Settings > Web page.
     */
    public const INFRASTRUCTURE_OPTION = 'eventcrew_infrastructure_note';

    /** The infrastructure text, or '' when none is set. */
    public static function infrastructure(): string
    {
        return trim((string) get_option(self::INFRASTRUCTURE_OPTION, ''));
    }

    /** The crew page's address with $anchor, or '' when no crew page is set. */
    public static function url(string $anchor = self::PANEL_ANCHOR): string
    {
        $pageId = (int) get_option('eventcrew_signup_page_id', 0);
        $url = $pageId > 0 ? get_permalink($pageId) : false;

        return is_string($url) && '' !== $url ? $url . '#' . $anchor : '';
    }

    public static function html(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $html = '';

        foreach (preg_split('/\n{2,}/', trim($text)) ?: [] as $block) {
            $block = trim($block, "\n");

            if ('' === trim($block)) {
                continue;
            }

            $lines = explode("\n", $block);

            if (1 === count($lines) && str_starts_with(trim($block), '## ')) {
                $heading = substr(trim($block), 3);
                $html .= sprintf(
                    '<h3 class="eventcrew-help-heading" id="%s">%s</h3>',
                    esc_attr(EmailBody::anchor($heading)),
                    esc_html($heading)
                );

                continue;
            }

            if ([] === array_filter($lines, static fn (string $line): bool => ! str_starts_with(trim($line), '- '))) {
                $emoji = [] === array_filter($lines, static fn (string $line): bool => ! EmailBody::isEmojiLine($line));
                $items = '';

                foreach ($lines as $line) {
                    $items .= '<li>' . EmailBody::inline(substr(trim($line), 2)) . '</li>';
                }

                $html .= sprintf('<ul class="eventcrew-help-list%s">%s</ul>', $emoji ? ' is-emoji' : '', $items);

                continue;
            }

            $html .= '<p>' . str_replace("\n", '<br>', EmailBody::inline($block)) . '</p>';
        }

        return $html;
    }

    /**
     * $text as cards, one per "## " section: the heading in the card's header
     * bar (with the same id as html() gives it, so contents links still land),
     * the section's text in the card's body. Text before the first heading
     * becomes a card with no header.
     */
    public static function cards(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $sections = preg_split('/^(?=## )/m', trim($text)) ?: [];
        $html = '';

        foreach ($sections as $section) {
            $section = trim($section, "\n");

            if ('' === trim($section)) {
                continue;
            }

            $head = '';

            if (str_starts_with($section, '## ')) {
                $parts = explode("\n", $section, 2);
                $heading = trim(substr($parts[0], 3));
                $section = $parts[1] ?? '';
                $head = sprintf(
                    '<header class="eventcrew-event-head">'
                    . '<h3 class="eventcrew-event-title eventcrew-help-heading" id="%s">%s</h3></header>',
                    esc_attr(EmailBody::anchor($heading)),
                    esc_html($heading)
                );
            }

            $body = self::html($section);
            $html .= sprintf(
                '<section class="eventcrew-event eventcrew-help-card">%s%s</section>',
                $head,
                '' === $body ? '' : '<div class="eventcrew-card-body">' . $body . '</div>'
            );
        }

        return $html;
    }
}
