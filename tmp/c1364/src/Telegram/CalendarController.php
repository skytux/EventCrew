<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Task;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\SignedLink;
use WP_REST_Request;

/**
 * An "add to calendar" hold for a task a person signed up for: a signed link
 * that returns an .ics file every calendar app (Google, Apple, Outlook) can
 * import. It carries no per-person state - the event details are the same for
 * everyone on the task - so the link is signed over the task id alone.
 *
 * Putting the task in the person's own calendar, with its own alarm, is the
 * single strongest nudge against a no-show, and complements the 24h reminder
 * rather than replacing it.
 */
final class CalendarController
{
    /** A sensible default length when a task records a start but no end. */
    private const DEFAULT_DURATION_HOURS = 2;

    public function __construct(
        private readonly TaskRepository $tasks
    ) {
    }

    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            'eventcrew/v1',
            '/calendar',
            [
                'methods' => 'GET',
                'callback' => [$this, 'handle'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * The signed link for a task's calendar hold, built the same way as the
     * door ticket's so callers do not have to know the route or the purpose.
     */
    public static function url(int $taskId): string
    {
        return add_query_arg(
            ['token' => SignedLink::sign('calendar', $taskId)],
            rest_url('eventcrew/v1/calendar')
        );
    }

    public function handle(WP_REST_Request $request): void
    {
        $taskId = SignedLink::verify('calendar', (string) $request->get_param('token'));
        $task = null === $taskId ? null : $this->tasks->find($taskId);

        if (null === $task) {
            status_header(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo esc_html__('This calendar link is not valid.', 'eventcrew');
            exit;
        }

        status_header(200);
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="eventcrew-' . $task->id . '.ics"');
        // Built from escaped values in ics(); it is a generated file, not markup.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $this->ics($task);
        exit;
    }

    /**
     * The task as an RFC 5545 VCALENDAR. A timed task becomes a UTC-anchored
     * event (converted from the site's timezone, which is how the columns are
     * stored); an untimed one becomes an all-day event on its date.
     */
    public function ics(Task $task): string
    {
        $summary = $task->roleLabel() . ' — ' . $task->eventName();
        $host = (string) wp_parse_url((string) home_url('/'), PHP_URL_HOST);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//EventCrew//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:eventcrew-task-' . $task->id . '@' . ('' === $host ? 'eventcrew' : $host),
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'SUMMARY:' . $this->escape($summary),
        ];

        foreach ($this->timing($task) as $line) {
            $lines[] = $line;
        }

        if ('' !== $task->notes) {
            $lines[] = 'DESCRIPTION:' . $this->escape($task->notes);
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        // CRLF line endings are what RFC 5545 mandates and what the strict
        // parsers (Outlook) insist on.
        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * The DTSTART/DTEND pair. Timed tasks are converted from the site timezone
     * to UTC; an untimed task is an all-day DATE value with DTEND on the next
     * day, as iCalendar's exclusive end requires.
     *
     * @return array<int, string>
     */
    private function timing(Task $task): array
    {
        $tz = wp_timezone();
        $utc = new \DateTimeZone('UTC');

        if (null === $task->startsAt) {
            $start = new \DateTimeImmutable($task->taskDate, $tz);
            $end = $start->modify('+1 day');

            return [
                'DTSTART;VALUE=DATE:' . $start->format('Ymd'),
                'DTEND;VALUE=DATE:' . $end->format('Ymd'),
            ];
        }

        $start = new \DateTimeImmutable($task->startsAt, $tz);
        $end = null !== $task->endsAt
            ? new \DateTimeImmutable($task->endsAt, $tz)
            : $start->modify('+' . self::DEFAULT_DURATION_HOURS . ' hours');

        return [
            'DTSTART:' . $start->setTimezone($utc)->format('Ymd\THis\Z'),
            'DTEND:' . $end->setTimezone($utc)->format('Ymd\THis\Z'),
        ];
    }

    /**
     * Escapes a text value for iCalendar: backslash, semicolon and comma are
     * literal-escaped and newlines become the literal \n the format expects.
     */
    private function escape(string $value): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n", "\r"],
            ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'],
            $value
        );
    }
}
