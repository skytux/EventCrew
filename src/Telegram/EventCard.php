<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Support\EventName;
use EventCrew\Support\EventSource;
use EventCrew\Support\Language;
use EventCrew\Support\NewsletterLayout;
use EventCrew\Support\TicketMail;

/**
 * One event as a Telegram photo message: the event's picture with a caption
 * and link buttons under it. /events shows these cards to an organizer and
 * posts them in the group (EventsCommand).
 *
 * - photo: the event's picture, the same address the announcement email uses
 *   (EventSource::card(); for a Holvi event it is on Holvi's own image
 *   server, which Telegram can always fetch). '' when there is none: the card
 *   is then sent as a text message.
 * - caption: Telegram HTML. The title in bold (and the Finnish title in
 *   italics when it is different), the DJ line (EventName: the title is then
 *   EventMesh's clean name, without date and DJs), the date and time, the price or a sold-out
 *   mark, then a short description: the Finnish one, then the English one,
 *   like the email. The whole caption fits Telegram's 1024 characters: each
 *   description gets at most 300 characters or its half of the room left,
 *   and is cut at the end of a sentence, or at the end of a word with "…".
 * - buttons: rows of link buttons. "🎟 Osta lippu · Buy a ticket · 22 €" to
 *   the ticket shop (not on a sold-out event), "▶ Kuuntele DJ:tä · Listen to
 *   the DJ" to the DJ's music page when there is one, and "📱 Crew app" to the
 *   crew page when one is set.
 */
final class EventCard
{
    /** Telegram's limit for a photo caption, in characters. */
    public const CAPTION_LIMIT = 1024;

    /** The most events /events shows. */
    public const MAX_EVENTS = 5;

    /** The longest description in one language, in characters. */
    private const SHORT = 300;

    /**
     * The longest title, in characters. With two titles of this length and
     * the date and price, more than 500 characters are left for the two
     * descriptions, so both languages always fit.
     */
    private const TITLE_MAX = 200;

    /**
     * The longest DJ line, in characters. With it the title lines still leave
     * more than 400 characters for the two descriptions.
     */
    private const DJ_MAX = 100;

    /** The closed reasons that keep an event out, as in the announcement email. */
    private const HIDDEN_REASONS = ['unpublished', 'disabled', 'hidden'];

    /**
     * The cards of the coming events, earliest first: the events after today
     * that the announcement email would list (not unpublished, disabled or
     * hidden), at most $limit.
     *
     * @return array<int, array{
     *     photo: string,
     *     caption: string,
     *     buttons: array<int, array<int, array{text: string, url: string}>>
     * }>
     */
    public static function coming(int $limit = self::MAX_EVENTS): array
    {
        $today = (string) current_time('Y-m-d');
        $cards = [];

        foreach (EventSource::upcoming(-1) as $event) {
            if (count($cards) >= $limit) {
                break;
            }

            if ('' === $event['date'] || $event['date'] <= $today) {
                continue;
            }

            if (in_array(EventSource::closedReason($event['id']), self::HIDDEN_REASONS, true)) {
                continue;
            }

            $card = self::forEvent($event['id']);

            if (null !== $card) {
                $cards[] = $card;
            }
        }

        return $cards;
    }

    /**
     * The card of one event, or null when the post is missing or is not an
     * event. Built whatever the event's state, so a probe can show any event.
     *
     * $about, when given, is plain text shown in place of the descriptions
     * (the group board's short text about the DJ or host); '' shows no text
     * under the name, date and price lines at all.
     *
     * @return array{
     *     photo: string,
     *     caption: string,
     *     buttons: array<int, array<int, array{text: string, url: string}>>
     * }|null
     */
    public static function forEvent(int $postId, ?string $about = null): ?array
    {
        $event = EventSource::describe($postId);

        if (null === $event) {
            return null;
        }

        $details = EventSource::card($postId) + [
            'date' => $event['date'],
            'time' => substr($event['starts_at'], 11, 5),
            'title' => EventName::name($postId, Language::ENGLISH, $event['title']),
            'title_fi' => EventName::name(
                $postId,
                Language::FINNISH,
                EventSource::translatedTitle($postId, Language::FINNISH)
            ),
            'dj' => EventName::djLine($postId),
            'sold_out' => 'sold_out' === EventSource::closedReason($postId),
            'app_url' => self::appUrl(),
        ];

        return [
            'photo' => self::isLink((string) $details['image']) ? (string) $details['image'] : '',
            'caption' => self::caption($details, $about),
            'buttons' => self::buttons($details),
        ];
    }

    /**
     * Sends one card to $chatId: a photo message, or a text message with the
     * same caption and buttons when the card has no picture or Telegram does
     * not accept the picture. Returns the sent message, or null when nothing
     * was sent.
     *
     * @param array{
     *     photo: string,
     *     caption: string,
     *     buttons: array<int, array<int, array{text: string, url: string}>>
     * } $card
     * @return array<string, mixed>|null
     */
    public static function send(TelegramClient $telegram, int|string $chatId, array $card): ?array
    {
        $markup = [] === $card['buttons'] ? null : ['inline_keyboard' => $card['buttons']];

        if ('' !== $card['photo']) {
            $sent = $telegram->sendPhoto($chatId, $card['photo'], $card['caption'], $markup);

            // Telegram asked to wait: a text message now would be refused too.
            if (null !== $sent || $telegram->lastRetryAfter() > 0) {
                return $sent;
            }
        }

        $params = [
            'chat_id' => $chatId,
            'text' => $card['caption'],
            'parse_mode' => 'HTML',
        ];

        if (null !== $markup) {
            $params['reply_markup'] = $markup;
        }

        return $telegram->call('sendMessage', $params);
    }

    /**
     * The caption, from the event's details: date, time, title, title_fi, dj,
     * sold_out and EventSource::card()'s price and descriptions. $about, when
     * not null, is plain text shown instead of the descriptions, cut to fit.
     *
     * @param array<string, mixed> $event
     */
    public static function caption(array $event, ?string $about = null): string
    {
        $words = self::words();
        $title = self::cut(self::plain((string) ($event['title'] ?? '')), self::TITLE_MAX);
        $titleFi = self::cut(self::plain((string) ($event['title_fi'] ?? '')), self::TITLE_MAX);
        $dj = self::cut(self::plain((string) ($event['dj'] ?? '')), self::DJ_MAX);
        $time = (string) ($event['time'] ?? '');
        $date = (string) ($event['date'] ?? '');
        $price = NewsletterLayout::price((string) ($event['price'] ?? ''));

        $head = ['<b>' . self::escape($title) . '</b>'];

        if ('' !== $titleFi && $titleFi !== $title) {
            $head[] = '<i>' . self::escape($titleFi) . '</i>';
        }

        if ('' !== $dj) {
            $head[] = self::escape($dj);
        }

        if ('' !== $date) {
            $head[] = '📅 ' . self::escape(TicketMail::shortDate($date) . ('' === $time ? '' : ' · ' . $time));
        }

        if (! empty($event['sold_out'])) {
            $head[] = '🚫 <b>' . self::escape(self::pair($words, 'sold_out')) . '</b>';
        } elseif ('' !== $price) {
            $head[] = '🎟 ' . self::escape($price);
        }

        $caption = implode("\n", $head);
        $room = self::CAPTION_LIMIT - self::length($caption) - 2;

        if (null === $about) {
            $about = self::about($event, $room);
        } else {
            $about = self::compact(self::plain($about));
            $about = '' === $about || $room < 1 ? '' : self::escape(self::cut($about, $room));
        }

        return '' === $about ? $caption : $caption . "\n\n" . $about;
    }

    /**
     * The link buttons, one per row.
     *
     * @param array<string, mixed> $event
     * @return array<int, array<int, array{text: string, url: string}>>
     */
    public static function buttons(array $event): array
    {
        $words = self::words();
        $ticket = (string) ($event['ticket_url'] ?? '');
        $listen = (string) ($event['listen'] ?? '');
        $app = (string) ($event['app_url'] ?? '');
        $price = NewsletterLayout::price((string) ($event['price'] ?? ''));
        $rows = [];

        if (empty($event['sold_out']) && self::isLink($ticket)) {
            $rows[] = [[
                'text' => '🎟 ' . self::pair($words, 'buy') . ('' === $price ? '' : ' · ' . $price),
                'url' => $ticket,
            ]];
        }

        if (self::isLink($listen)) {
            $rows[] = [['text' => '▶ ' . self::pair($words, 'listen'), 'url' => $listen]];
        }

        if (self::isLink($app)) {
            $rows[] = [['text' => '📱 Crew app', 'url' => $app]];
        }

        return $rows;
    }

    /**
     * The descriptions as Telegram HTML in at most $room characters: "FI: …"
     * then "EN: …", or the one of unknown language without a label.
     *
     * @param array<string, mixed> $event
     */
    private static function about(array $event, int $room): string
    {
        $fi = self::compact((string) ($event['excerpt_fi'] ?? ''));
        $en = self::compact((string) ($event['excerpt_en'] ?? ''));
        $plain = self::compact((string) ($event['excerpt'] ?? ''));

        if ('' === $fi && '' === $en) {
            return '' === $plain || $room < 1 ? '' : self::escape(self::cut($plain, min(self::SHORT, $room)));
        }

        $parts = array_filter(['FI' => $fi, 'EN' => $en], static fn (string $text): bool => '' !== $text);
        // Each part is "XX: " and its text; two parts have a blank line between.
        $share = intdiv($room - 2 * (count($parts) - 1), count($parts)) - 4;

        if ($share < 1) {
            return '';
        }

        $lines = [];

        foreach ($parts as $label => $text) {
            $lines[] = '<b>' . $label . ':</b> ' . self::escape(self::cut($text, min(self::SHORT, $share)));
        }

        return implode("\n\n", $lines);
    }

    /**
     * $text in at most $max characters (UTF-16 units, as Telegram counts).
     * Longer text is cut after the last sentence end that keeps at least half
     * of it, or else at the last space, with "…".
     */
    private static function cut(string $text, int $max): string
    {
        if (EntityHtml::units($text) <= $max) {
            return $text;
        }

        // The longest start of $text that leaves room for the "…".
        $kept = '';

        foreach (mb_str_split($text) as $char) {
            if (EntityHtml::units($kept . $char) > $max - 1) {
                break;
            }

            $kept .= $char;
        }

        $sentence = 0;

        if (preg_match_all('/[.!?…](?=\s)|\n/u', $kept . ' ', $ends, PREG_OFFSET_CAPTURE) > 0) {
            $last = end($ends[0]);
            $sentence = $last[1] + strlen(rtrim($last[0]));
        }

        if ($sentence > 0 && EntityHtml::units(substr($kept, 0, $sentence)) >= intdiv($max, 2)) {
            return rtrim(substr($kept, 0, $sentence));
        }

        $space = mb_strrpos($kept, ' ');

        if (false !== $space && $space > 0) {
            $kept = mb_substr($kept, 0, $space);
        }

        return rtrim($kept, " \n\t,;:-–—") . '…';
    }

    /** One line break at most between lines: a short description has no paragraphs. */
    private static function compact(string $text): string
    {
        return trim((string) preg_replace('/\n\s*\n+/u', "\n", trim($text)));
    }

    /** The caption's length as Telegram counts it: the text without its tags. */
    private static function length(string $html): int
    {
        return EntityHtml::units(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
    }

    private static function plain(string $text): string
    {
        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function isLink(string $url): bool
    {
        return 1 === preg_match('#^https?://\S+$#i', $url);
    }

    /**
     * The crew page's address, '' when none is set. The option name mirrors
     * Web\PwaController::PAGE_OPTION, kept literal so this Telegram class does
     * not reach into the Web layer (as ProfileService does).
     */
    private static function appUrl(): string
    {
        $pageId = (int) get_option('eventcrew_signup_page_id', 0);
        $url = $pageId > 0 ? get_permalink($pageId) : false;

        return is_string($url) ? $url : '';
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
            'buy' => __('Buy a ticket', 'eventcrew'),
            'listen' => __('Listen to the DJ', 'eventcrew'),
            'sold_out' => __('Sold out', 'eventcrew'),
        ];

        return [
            'fi' => Language::run(Language::FINNISH, $words),
            'en' => Language::run(Language::ENGLISH, $words),
        ];
    }
}
