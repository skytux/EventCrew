<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * Where an event is ({venue}): the event's own venue (META, set on the
 * Messages page, Coming events tab), else the default venue (Settings > Web
 * page), else EventMesh's venue of the event. The messages and the ticket
 * show it on a row of its own, "📍 venue".
 */
final class EventVenue
{
    /** The default venue (Settings > Web page); '' when there is none. */
    public const DEFAULT_OPTION = 'eventcrew_default_venue';

    /** One event's own venue, on the EventMesh event post. */
    public const META = '_eventcrew_venue';

    public const MAX_LENGTH = 120;

    public const PIN = '📍';

    /** The venue of event $postId, or '' when none is known. */
    public static function forEvent(?int $postId): string
    {
        $own = null === $postId || $postId <= 0 ? '' : self::own($postId);

        if ('' !== $own) {
            return $own;
        }

        $default = self::defaultVenue();

        if ('' !== $default) {
            return $default;
        }

        return null === $postId || $postId <= 0 ? '' : self::clean(EventSource::venue($postId));
    }

    /** "📍 venue" for event $postId, or '' when none is known. */
    public static function line(?int $postId): string
    {
        $venue = self::forEvent($postId);

        return '' === $venue ? '' : self::PIN . ' ' . $venue;
    }

    public static function defaultVenue(): string
    {
        return self::clean((string) get_option(self::DEFAULT_OPTION, ''));
    }

    /** Saves the default venue; blank removes it. Returns what was saved. */
    public static function saveDefault(string $venue): string
    {
        $venue = self::clean($venue);
        update_option(self::DEFAULT_OPTION, $venue);

        return $venue;
    }

    /** The event's own venue, or '' when it has none or EventMesh is off. */
    public static function own(int $postId): string
    {
        if (! post_type_exists(EventSource::POST_TYPE)) {
            return '';
        }

        return self::clean((string) get_post_meta($postId, self::META, true));
    }

    /**
     * Saves the event's own venue; blank removes it, so the default venue or
     * EventMesh's is used again. Returns what was saved.
     */
    public static function saveOwn(int $postId, string $venue): string
    {
        $venue = self::clean($venue);

        if ('' === $venue) {
            delete_post_meta($postId, self::META);
        } else {
            update_post_meta($postId, self::META, $venue);
        }

        return $venue;
    }

    /** $venue on one line, without tags, at most MAX_LENGTH characters. */
    public static function clean(string $venue): string
    {
        $venue = trim((string) preg_replace('/\s+/u', ' ', strip_tags($venue)));

        return function_exists('mb_substr')
            ? mb_substr($venue, 0, self::MAX_LENGTH)
            : substr($venue, 0, self::MAX_LENGTH);
    }
}
