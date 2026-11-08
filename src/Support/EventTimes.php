<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * An event's times as members read them on the ticket and in messages, like
 * 19:00: its start ({start_time}) and when its doors close ({doors_close}).
 */
final class EventTimes
{
    public const START_TAG = '{start_time}';

    public const DOORS_TAG = '{doors_close}';

    /** '1' when the doors close DOORS_MINUTES_OPTION minutes after the start (Settings > Web page). */
    public const DOORS_ON_OPTION = 'eventcrew_doors_close_on';

    public const DOORS_MINUTES_OPTION = 'eventcrew_doors_close_minutes';

    public const DEFAULT_DOORS_MINUTES = 30;

    public const MAX_DOORS_MINUTES = 600;

    /** Post meta of one event: its own doors time (HH:MM), which wins over the setting. */
    public const DOORS_META = '_eventcrew_doors_close';

    /**
     * The event's start time, like 19:00, from EventMesh (EventSource).
     * '' when there is no event, EventMesh is off or the event has no start
     * time (a start at 00:00 is read as a date with no time).
     */
    public static function start(?int $postId): string
    {
        $event = null === $postId ? null : EventSource::describe($postId);
        $time = null === $event ? '' : substr($event['starts_at'], 11, 5);

        return '00:00' === $time ? '' : $time;
    }

    /**
     * The event's times, like 19:00–23:00, or only its start when it has no
     * end; an end on a later day than the start gets the day offset, like
     * "19:00–02:00 (+1)", as Task::timeRange() marks it. '' when there is no
     * event, EventMesh is off or the event has no start time.
     */
    public static function range(?int $postId): string
    {
        $start = self::start($postId);
        $event = '' === $start || null === $postId ? null : EventSource::describe($postId);

        if (null === $event) {
            return '';
        }

        $end = substr($event['ends_at'], 11, 5);

        if ('' === $end) {
            return $start;
        }

        $from = strtotime(substr($event['starts_at'], 0, 10) . ' 00:00:00');
        $to = strtotime(substr($event['ends_at'], 0, 10) . ' 00:00:00');
        $days = false === $from || false === $to ? 0 : (int) round(($to - $from) / 86400);

        return $start . '–' . (0 === $days ? $end : sprintf('%s (%+d)', $end, $days));
    }

    /**
     * When the event's doors close, like 19:30: the event's own time (Messages
     * page, Coming events tab) when set, else the start plus the minutes of
     * the setting when it is on. '' when neither gives a time.
     */
    public static function doorsClose(?int $postId): string
    {
        if (null === $postId || null === EventSource::describe($postId)) {
            return '';
        }

        $own = self::ownDoorsClose($postId);

        if ('' !== $own) {
            return $own;
        }

        $start = self::start($postId);

        if ('' === $start || ! self::doorsOn()) {
            return '';
        }

        $time = \DateTimeImmutable::createFromFormat('!H:i', $start);

        return false === $time ? '' : $time->modify('+' . self::doorsMinutes() . ' minutes')->format('H:i');
    }

    /**
     * When the doors close on the night of $date, as a local "Y-m-d H:i:s",
     * or '' when doorsClose() gives no time. A doors time earlier than the
     * start (start 23:30, doors 00:15) is on the next day.
     */
    public static function doorsCloseAt(?int $postId, string $date): string
    {
        $doors = self::doorsClose($postId);

        if ('' === $doors || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return '';
        }

        $at = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $doors);

        if (false === $at) {
            return '';
        }

        $start = self::start($postId);

        if ('' !== $start && $doors < $start) {
            $at = $at->modify('+1 day');
        }

        return $at->format('Y-m-d H:i:s');
    }

    /** The event's own doors time (HH:MM), or '' when it follows the setting or EventMesh is off. */
    public static function ownDoorsClose(int $postId): string
    {
        if (! post_type_exists(EventSource::POST_TYPE)) {
            return '';
        }

        return self::cleanTime((string) get_post_meta($postId, self::DOORS_META, true));
    }

    /**
     * Saves the event's own doors time; a blank or unreadable time removes
     * it, so the setting is used again. Returns the time saved ('' when
     * removed).
     */
    public static function saveOwnDoorsClose(int $postId, string $time): string
    {
        $time = self::cleanTime($time);

        if ('' === $time) {
            delete_post_meta($postId, self::DOORS_META);
        } else {
            update_post_meta($postId, self::DOORS_META, $time);
        }

        return $time;
    }

    public static function doorsOn(): bool
    {
        return '1' === (string) get_option(self::DOORS_ON_OPTION, '0');
    }

    public static function doorsMinutes(): int
    {
        $minutes = (int) get_option(self::DOORS_MINUTES_OPTION, self::DEFAULT_DOORS_MINUTES);

        return max(0, min(self::MAX_DOORS_MINUTES, $minutes));
    }

    /** Saves the setting "Doors close [30] minutes after the start" (Settings > Web page). */
    public static function saveDoorsSetting(bool $on, string $minutes): void
    {
        update_option(self::DOORS_ON_OPTION, $on ? '1' : '0');

        if ('' !== trim($minutes)) {
            update_option(self::DOORS_MINUTES_OPTION, max(0, min(self::MAX_DOORS_MINUTES, (int) $minutes)));
        }
    }

    /** $time as HH:MM ("7:5" is not read), or '' when it is not a time of day. */
    private static function cleanTime(string $time): string
    {
        $time = trim($time);

        return 1 === preg_match('/^([01]?\d|2[0-3])[:.]([0-5]\d)$/', $time, $m)
            ? sprintf('%02d:%s', (int) $m[1], $m[2])
            : '';
    }
}
