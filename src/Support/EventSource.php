<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The soft link to EventMesh.
 *
 * EventCrew must run standalone, so nothing here may reference an EventMesh
 * class, `use` an EventMesh namespace, or fail when the plugin is absent.
 * Every method degrades to "no events available", at which point the task
 * form falls back to a typed event name and nothing else changes.
 *
 * That means duplicating one rule EventMesh already implements: each event
 * field is stored twice, `_eventmesh_{key}` written by the sync and
 * `_eventmesh_manual_{key}` written by hand on the event's edit screen, with
 * the manual value winning when it is non-empty. EventMesh resolves this in
 * EventMeta::resolve(). Reimplementing it is the price of the soft link, and
 * it is a stable contract - the meta keys are registered post meta, not
 * internals - but if EventMesh ever changes that rule, this is the file that
 * has to follow.
 */
final class EventSource
{
    /** The post type EventMesh registers. */
    public const POST_TYPE = 'eventmesh_event';

    private const MANUAL_PREFIX = '_eventmesh_manual_';
    private const SCRAPED_PREFIX = '_eventmesh_';

    /**
     * Whether there is anything to link to. False on a standalone install,
     * and also false when EventMesh is installed but not yet booted, since
     * the post type is registered on `init`.
     */
    public static function isAvailable(): bool
    {
        return post_type_exists(self::POST_TYPE);
    }

    /**
     * Events worth offering in the task form: upcoming first, then recent
     * past ones, because tasks are sometimes filled in the morning after.
     *
     * Hidden and disabled events are left in. Those flags govern what the
     * public site shows, which has nothing to do with whether an organizer
     * still needs to staff the thing.
     *
     * @return array<int, array{id: int, title: string, starts_at: string, ends_at: string, date: string}>
     */
    public static function upcoming(int $limit = 50): array
    {
        if (! self::isAvailable()) {
            return [];
        }

        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => ['publish', 'draft', 'private'],
            'numberposts' => $limit,
            'orderby' => 'meta_value',
            'meta_key' => self::SCRAPED_PREFIX . 'starts_at',
            'order' => 'ASC',
            'suppress_filters' => false,
        ]);

        $events = [];

        foreach ($posts as $post) {
            $event = self::describe((int) $post->ID);

            if (null !== $event) {
                $events[] = $event;
            }
        }

        // Sorted here rather than trusting the meta_value ordering: the
        // scraped value is a DATE_ATOM string while a manual override may
        // have been typed later, and only the resolved value is meaningful.
        usort(
            $events,
            static fn (array $a, array $b): int => $a['starts_at'] <=> $b['starts_at']
        );

        return $events;
    }

    /**
     * One event's resolved details, or null when the post is missing or is
     * not an event.
     *
     * @return array{id: int, title: string, starts_at: string, ends_at: string, date: string}|null
     */
    public static function describe(int $postId): ?array
    {
        if ($postId <= 0 || ! self::isAvailable()) {
            return null;
        }

        $post = get_post($postId);

        if (null === $post || self::POST_TYPE !== $post->post_type) {
            return null;
        }

        $startsAt = self::naiveDateTime(self::resolve($postId, 'starts_at'));
        $endsAt = self::naiveDateTime(self::resolve($postId, 'ends_at'));

        return [
            'id' => $postId,
            'title' => (string) $post->post_title,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            // The day the event's tasks get filed under. Taken from the start,
            // because an event running past midnight is still that evening's
            // event to everyone organizing it.
            'date' => '' === $startsAt ? '' : substr($startsAt, 0, 10),
        ];
    }

    public static function title(int $postId): string
    {
        return self::describe($postId)['title'] ?? '';
    }

    /**
     * EventMesh's translation of the event's title into $lang ('fi', 'en'),
     * or '' when there is none to use: the event is already in $lang, its
     * original language is unknown, no translation was stored, or the stored
     * one was made from an older title or text. This mirrors the rule
     * EventMesh itself uses before it shows a translation.
     */
    public static function translatedTitle(int $postId, string $lang): string
    {
        return self::translated($postId, $lang, 'title');
    }

    /**
     * What the announcement card shows of an event, beyond its title and date:
     *
     * - image: the event's picture (EventMesh's image address; on Holvi's own
     *   image server for a Holvi event), '' when there is none;
     * - price: as the shop wrote it ("22.00 EUR"), '' when there is none;
     * - ticket_url: the shop page that sells the ticket, '' when there is none;
     * - listen: the DJ's music page that EventMesh's player plays (SoundCloud,
     *   Mixcloud), '' when there is none;
     * - excerpt_fi, excerpt_en: the short description in Finnish and in
     *   English: the event's own excerpt in its own language, EventMesh's
     *   current translation in the other; '' where there is none;
     * - excerpt: the excerpt when its language is unknown, so it is shown
     *   once without a language label; '' otherwise.
     *
     * Every field is '' when the post is missing or is not an event.
     *
     * @return array{
     *     image: string,
     *     price: string,
     *     ticket_url: string,
     *     listen: string,
     *     excerpt_fi: string,
     *     excerpt_en: string,
     *     excerpt: string
     * }
     */
    public static function card(int $postId): array
    {
        $card = [
            'image' => '',
            'price' => '',
            'ticket_url' => '',
            'listen' => '',
            'excerpt_fi' => '',
            'excerpt_en' => '',
            'excerpt' => '',
        ];

        if ($postId <= 0 || ! self::isAvailable()) {
            return $card;
        }

        $post = get_post($postId);

        if (null === $post || self::POST_TYPE !== $post->post_type) {
            return $card;
        }

        $card['image'] = self::resolve($postId, 'image_url');
        $card['price'] = self::resolve($postId, 'price');
        $card['ticket_url'] = self::resolve($postId, 'url');
        $card['listen'] = self::resolve($postId, 'embed_source_url');

        $excerpt = self::plain((string) ($post->post_excerpt ?? ''));

        if ('' === $excerpt) {
            $excerpt = self::shorten(self::plain((string) ($post->post_content ?? '')), 360);
        }

        $original = (string) get_post_meta($postId, self::SCRAPED_PREFIX . 'original_lang', true);

        if (Language::FINNISH === $original || Language::ENGLISH === $original) {
            $other = Language::FINNISH === $original ? Language::ENGLISH : Language::FINNISH;
            $card['excerpt_' . $original] = $excerpt;
            $card['excerpt_' . $other] = self::plain(self::translated($postId, $other, 'excerpt'));
        } else {
            $card['excerpt'] = $excerpt;
        }

        return $card;
    }

    /** Text with tags removed, entities decoded and runs of spaces squeezed. */
    private static function plain(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** $text cut at a word boundary to at most $max characters, with "…" when cut. */
    private static function shorten(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max);
        $space = mb_strrpos($cut, ' ');

        return rtrim(false === $space ? $cut : mb_substr($cut, 0, $space), " ,.;:-") . '…';
    }

    /**
     * One field ('title', 'excerpt') of EventMesh's translation into $lang,
     * under translatedTitle()'s rule.
     */
    private static function translated(int $postId, string $lang, string $field): string
    {
        if ($postId <= 0 || '' === $lang || ! self::isAvailable()) {
            return '';
        }

        $post = get_post($postId);

        if (null === $post || self::POST_TYPE !== $post->post_type) {
            return '';
        }

        $original = (string) get_post_meta($postId, self::SCRAPED_PREFIX . 'original_lang', true);

        if ('' === $original || $original === $lang) {
            return '';
        }

        $prefix = self::SCRAPED_PREFIX . 'tr_' . $lang . '_';
        $hash = (string) get_post_meta($postId, $prefix . 'hash', true);

        if ('' === $hash || md5((string) $post->post_title . "\0" . (string) $post->post_content) !== $hash) {
            return '';
        }

        return trim((string) get_post_meta($postId, $prefix . $field, true));
    }

    /**
     * Why the linked event no longer takes free-entry credits, or '' while it
     * still does: 'unpublished' (the sync sets an event removed from the shop
     * back to draft), 'disabled', 'hidden' or 'sold_out'. '' too when nothing
     * is linked or EventMesh is absent, so a standalone install is unaffected.
     *
     * The flags mirror EventMesh's own reads: hidden/disabled are manual
     * checkboxes stored as '1', and a manual sold-out of '1' or '0' beats the
     * synced value (EventMeta::isSoldOut()).
     */
    public static function closedReason(int $postId): string
    {
        if ($postId <= 0 || ! self::isAvailable()) {
            return '';
        }

        $post = get_post($postId);

        if (null === $post || self::POST_TYPE !== $post->post_type) {
            return '';
        }

        if ('publish' !== $post->post_status) {
            return 'unpublished';
        }

        foreach (['disabled', 'hidden'] as $flag) {
            if ('1' === (string) get_post_meta($postId, self::MANUAL_PREFIX . $flag, true)) {
                return $flag;
            }
        }

        $soldOut = (string) get_post_meta($postId, self::MANUAL_PREFIX . 'sold_out', true);

        if ('' === $soldOut) {
            $soldOut = (string) get_post_meta($postId, self::SCRAPED_PREFIX . 'sold_out', true);
        }

        return '1' === $soldOut ? 'sold_out' : '';
    }

    /**
     * A manual override beats the synced value, and a blank override falls
     * back rather than blanking the field - mirroring EventMesh's own rule so
     * an organizer's correction on the event is what EventCrew schedules
     * against.
     */
    private static function resolve(int $postId, string $baseKey): string
    {
        $manual = trim((string) get_post_meta($postId, self::MANUAL_PREFIX . $baseKey, true));

        if ('' !== $manual) {
            return $manual;
        }

        return trim((string) get_post_meta($postId, self::SCRAPED_PREFIX . $baseKey, true));
    }

    /**
     * EventMesh now stores its event dates as naive local wall-clock strings
     * with no timezone (a source publishes "the doors open at nine," never a
     * UTC instant), which is exactly the shape EventCrew's own DATETIME
     * columns hold. So this takes the value as-is and only normalises the
     * separator - no timezone conversion, because converting a value that was
     * never in UTC is precisely what used to displace every event by the
     * offset.
     */
    private static function naiveDateTime(string $stored): string
    {
        if ('' === $stored) {
            return '';
        }

        try {
            // Parsed only to normalise "…T18:00:00" / "…T18:00" to the
            // "Y-m-d H:i:s" MySQL form and to reject anything unparseable;
            // UTC is a neutral carrier here, never a claim about the zone.
            $moment = new \DateTimeImmutable($stored, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return '';
        }

        return $moment->format('Y-m-d H:i:s');
    }
}
