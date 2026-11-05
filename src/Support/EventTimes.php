<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * An event's times as members read them on the ticket and in messages, like
 * 19:00: its start ({start_time}).
 */
final class EventTimes
{
    public const START_TAG = '{start_time}';

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
}
