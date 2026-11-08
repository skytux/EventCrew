<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
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
     * The block that goes right under an email's ticket button: the picture
     * of event $eventId, its alt text (alt()) in the first of $langs, and
     * under it the short DJ or host text in each of $langs (djTexts()), each
     * with its language tag when they differ. No picture: only the text. No
     * event: nothing. The plain-text part gets the text alone, followed by an
     * empty line. $event is the event's name to use when EventMesh gives none.
     *
     * @param list<string> $langs
     * @return array{html: string, text: string}
     */
    public static function block(?int $eventId, array $langs, string $event = ''): array
    {
        if (null === $eventId || $eventId <= 0) {
            return ['html' => '', 'text' => ''];
        }

        $url = self::url($eventId);
        $html = '' === $url
            ? ''
            : self::html($url, self::alt($eventId, $langs[0] ?? Language::ENGLISH, $event), '26px 0 0');
        $texts = self::djTexts($eventId, $langs);
        $tags = HybridPolicy::tags($texts);

        foreach ($texts as $lang => $text) {
            $html .= sprintf(
                '<p style="margin:14px 0 0;font-family:%1$s;font-size:15px;line-height:1.6;color:%2$s;'
                    . 'text-align:left">%3$s%4$s</p>',
                EmailBody::FONT,
                EmailBody::TEXT_COLOR,
                '' === $tags[$lang] ? '' : EmailBody::langTag($tags[$lang]),
                esc_html($text)
            );
        }

        return [
            'html' => $html,
            'text' => [] === $texts ? '' : implode("\n\n", $texts) . "\n\n",
        ];
    }

    /**
     * block() for an email about $task to $person, whose ticket button it
     * goes under (the sign-up email, the reminder): in the ticket email's
     * languages for them (HybridPolicy::TICKET). Nothing when the task's
     * event has no picture.
     *
     * @return array{html: string, text: string}
     */
    public static function forTask(Task $task, Person $person): array
    {
        if ('' === self::url($task->eventPostId)) {
            return ['html' => '', 'text' => ''];
        }

        return self::block(
            $task->eventPostId,
            HybridPolicy::languagesFor(HybridPolicy::TICKET, HybridPolicy::personLanguage($person)),
            $task->eventName()
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
