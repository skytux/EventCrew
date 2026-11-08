<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Telegram\BoardDjText;
use EventCrew\Web\SignupBoardView;

/**
 * An event's picture in an email: its address (the one the event's cards
 * show, EventSource::card(), else the post's featured image), the alt text
 * that says what is in it, the picture as email HTML, and the short DJ or
 * host text that goes under it (EventDescription::short()).
 *
 * The alt text: the DJs, the event, the day, the date and the start time,
 * and the venue, joined with " · ", like "Esimerkki · Ecstatic Dance ·
 * la 14.11.2026 18:30 · Example Hall Helsinki". A part that is not known
 * is left out.
 */
final class EventPicture
{
    public const SEPARATOR = ' · ';

    /** The picture of event $postId, '' when it has none (or there is no event). */
    public static function url(?int $postId): string
    {
        if (null === $postId || $postId <= 0) {
            return '';
        }

        $image = (string) (EventSource::card($postId)['image'] ?? '');

        return '' !== $image ? $image : SignupBoardView::eventImage($postId);
    }

    /**
     * The alt text of event $postId's picture in $lang. $name is the event's
     * name to use when EventMesh gives none.
     */
    public static function alt(?int $postId, string $lang, string $name = ''): string
    {
        if (null === $postId || $postId <= 0) {
            return trim($name);
        }

        return Language::run($lang, static function () use ($postId, $lang, $name): string {
            $event = EventSource::describe($postId);
            $date = null === $event ? '' : (string) $event['date'];
            $when = '';

            if ('' !== $date) {
                $timestamp = strtotime($date . ' 12:00:00 UTC');
                $day = false === $timestamp ? '' : Dates::format('D', $timestamp);
                $when = trim($day . ' ' . TicketMail::shortDate($date) . ' ' . EventTimes::start($postId));
            }

            return implode(self::SEPARATOR, array_filter([
                implode(', ', self::djNames($postId)),
                trim(EventName::name($postId, $lang, $name)),
                $when,
                EventVenue::forEvent($postId),
            ], static fn (string $part): bool => '' !== $part));
        });
    }

    /** The picture as email HTML, the full width of the email, rounded corners. */
    public static function html(string $url, string $alt, string $margin = '16px 0 0'): string
    {
        return sprintf(
            '<div style="margin:%s"><img src="%s" width="560" alt="%s" '
                . 'style="display:block;width:100%%;max-width:560px;height:auto;border:0;border-radius:12px"></div>',
            esc_attr($margin),
            esc_url($url),
            esc_attr($alt)
        );
    }

    /**
     * The short DJ or host text of event $postId for each of $langs, in that
     * language, else in English while its translation is missing; a language
     * with neither is left out. The same text twice is kept once, under the
     * first language. When a text or a translation is missing, it is asked
     * for in the background (BoardDjText::schedule()); the model is never
     * asked here.
     *
     * @param list<string> $langs
     * @return array<string, string> language => text
     */
    public static function djTexts(?int $postId, array $langs): array
    {
        if (null === $postId || $postId <= 0) {
            return [];
        }

        $texts = [];
        $missing = false;
        $english = null;

        foreach ($langs as $lang) {
            $text = EventDescription::short($postId, $lang);

            if ('' === $text) {
                $missing = true;
                $english ??= EventDescription::short($postId, Language::ENGLISH);
                $text = $english;
            }

            if ('' !== $text && ! in_array($text, $texts, true)) {
                $texts[$lang] = $text;
            }
        }

        if ($missing) {
            BoardDjText::schedule($postId);
        }

        return $texts;
    }

    /**
     * The names of event $postId's DJs (EventMesh, NewsletterWriter::DJ_FILTER).
     *
     * @return list<string>
     */
    private static function djNames(int $postId): array
    {
        $listed = apply_filters(NewsletterWriter::DJ_FILTER, [], $postId);
        $names = [];

        foreach (is_array($listed) ? $listed : [] as $dj) {
            $name = is_array($dj) && is_string($dj['name'] ?? null) ? trim($dj['name']) : '';

            if ('' !== $name && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
