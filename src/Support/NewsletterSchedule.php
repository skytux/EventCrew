<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * When the newsletter's preview and the newsletter itself go out: the
 * settings, and the time arithmetic Newsletter plans with.
 *
 * Every time here is the site's own wall-clock time, as a "Y-m-d H:i:s"
 * string (the form current_time('mysql') and EventSource use), so times
 * compare as strings. DateTimeImmutable in UTC only carries the arithmetic;
 * it makes no claim about the zone.
 *
 * - The newsletter goes Newsletter::days() days before the event, at the
 *   send time (SEND_TIME_OPTION, HH:MM; by default the hour the send window
 *   opens, SendWindow::hour(), which is when it went before this setting).
 * - The preview goes either PREVIEW_HOURS hours after the previous event
 *   ends (PREVIEW_AFTER_PREVIOUS, the default, with 0 hours), or
 *   PREVIEW_DAYS days before the event at the preview time
 *   (PREVIEW_BEFORE_EVENT). The first falls back to the second when there is
 *   no previous event or it has no end time.
 */
final class NewsletterSchedule
{
    public const PREVIEW_MODE_OPTION = 'eventcrew_newsletter_preview_mode';

    public const PREVIEW_HOURS_OPTION = 'eventcrew_newsletter_preview_hours';

    public const PREVIEW_DAYS_OPTION = 'eventcrew_newsletter_preview_days';

    public const PREVIEW_TIME_OPTION = 'eventcrew_newsletter_preview_time';

    public const SEND_TIME_OPTION = 'eventcrew_newsletter_send_time';

    /** The preview goes PREVIEW_HOURS after the previous event ends. */
    public const PREVIEW_AFTER_PREVIOUS = 'after_previous';

    /** The preview goes PREVIEW_DAYS days before the event, at the preview time. */
    public const PREVIEW_BEFORE_EVENT = 'before_event';

    public const DEFAULT_PREVIEW_HOURS = 0;

    public const MAX_PREVIEW_HOURS = 168;

    public const MIN_PREVIEW_DAYS = 1;

    public const MAX_PREVIEW_DAYS = Newsletter::MAX_DAYS + 1;

    public static function previewMode(): string
    {
        $mode = (string) get_option(self::PREVIEW_MODE_OPTION, self::PREVIEW_AFTER_PREVIOUS);

        return self::PREVIEW_BEFORE_EVENT === $mode ? self::PREVIEW_BEFORE_EVENT : self::PREVIEW_AFTER_PREVIOUS;
    }

    /** Hours after the previous event ends, within 0..MAX_PREVIEW_HOURS. */
    public static function previewHours(): int
    {
        return self::clampPreviewHours((int) get_option(self::PREVIEW_HOURS_OPTION, self::DEFAULT_PREVIEW_HOURS));
    }

    /**
     * Days before the event, within MIN_PREVIEW_DAYS..MAX_PREVIEW_DAYS. By
     * default the day before the send day, as before this setting.
     */
    public static function previewDays(): int
    {
        $stored = get_option(self::PREVIEW_DAYS_OPTION, null);

        return null === $stored || '' === $stored
            ? Newsletter::days() + 1
            : self::clampPreviewDays((int) $stored);
    }

    /** The preview time, HH:MM. By default the send time. */
    public static function previewTime(): string
    {
        return self::parseTime((string) get_option(self::PREVIEW_TIME_OPTION, '')) ?? self::sendTime();
    }

    /** The send time, HH:MM. By default the hour the send window opens. */
    public static function sendTime(): string
    {
        return self::parseTime((string) get_option(self::SEND_TIME_OPTION, ''))
            ?? sprintf('%02d:00', SendWindow::hour());
    }

    public static function clampPreviewHours(int $hours): int
    {
        return max(0, min(self::MAX_PREVIEW_HOURS, $hours));
    }

    public static function clampPreviewDays(int $days): int
    {
        return max(self::MIN_PREVIEW_DAYS, min(self::MAX_PREVIEW_DAYS, $days));
    }

    /** "9:05" or "09:05" as "09:05"; null when it is not a time of day. */
    public static function parseTime(string $time): ?string
    {
        if (1 !== preg_match('/^\s*([01]?\d|2[0-3]):([0-5]\d)\s*$/', $time, $matches)) {
            return null;
        }

        return sprintf('%02d:%s', (int) $matches[1], $matches[2]);
    }

    /** The moment the newsletter for an event on $eventDate (Y-m-d) is due. */
    public static function sendAt(string $eventDate): string
    {
        return self::at(self::addDays($eventDate, -Newsletter::days()), self::sendTime());
    }

    /**
     * The moment the preview for $event is due.
     *
     * @param array{date: string, starts_at: string} $event
     * @param array{starts_at: string, ends_at: string}|null $previous The
     *     latest event that starts before $event, or null.
     */
    public static function previewAt(array $event, ?array $previous): string
    {
        if (
            self::PREVIEW_AFTER_PREVIOUS === self::previewMode()
            && null !== $previous
            && '' !== $previous['ends_at']
        ) {
            return self::addHours($previous['ends_at'], self::previewHours());
        }

        return self::at(self::addDays($event['date'], -self::previewDays()), self::previewTime());
    }

    /**
     * When the plan is made: at the preview time, but no later than one day
     * before the send time, so a preview set after the send time still gives
     * the organizers a day (it then goes with the plan).
     */
    public static function planAt(string $previewAt, string $sendAt): string
    {
        return min($previewAt, self::addHours($sendAt, -24));
    }

    /** "Y-m-d" and "HH:MM" as "Y-m-d HH:MM:00". */
    public static function at(string $ymd, string $time): string
    {
        return $ymd . ' ' . $time . ':00';
    }

    public static function addDays(string $ymd, int $days): string
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new \DateTimeZone('UTC'));

        return false === $day ? $ymd : $day->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    /** "Y-m-d H:i:s" plus $hours wall-clock hours. */
    public static function addHours(string $moment, int $hours): string
    {
        $at = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $moment, new \DateTimeZone('UTC'));

        return false === $at ? $moment : $at->modify(sprintf('%+d hours', $hours))->format('Y-m-d H:i:s');
    }
}
