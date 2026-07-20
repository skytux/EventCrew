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
