<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\SignedLink;
use WP_REST_Request;

/**
 * The door ticket: a public page a person shows at the event.
 *
 * It carries no state of its own - the signed link names an assignment, and the
 * page reads that assignment's current status live, so it says VALID while the
 * slot is held and DISABLED the moment it is cancelled. The organizer's own
 * door list is the Roster; this is the attendee's copy of it.
 */
final class TicketController
{
    public function __construct(
        private readonly AssignmentRepository $assignments,
        private readonly TaskRepository $tasks,
        private readonly PersonRepository $people
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
            '/ticket',
            [
                'methods' => 'GET',
                'callback' => [$this, 'handle'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function handle(WP_REST_Request $request): void
    {
        $ticket = $this->ticketFor((string) $request->get_param('token'));

        status_header(null === $ticket ? 404 : 200);
        header('Content-Type: text/html; charset=utf-8');
        // Built from literal markup and esc_html()'d values in renderPage().
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $this->renderPage($ticket);
        exit;
    }

    /**
     * The ticket's details, or null when the link is bad or its assignment,
     * task or person is gone.
     *
     * @return array{valid: bool, name: string, event: string, date: string, role: string, time: string}|null
     */
    public function ticketFor(string $token): ?array
    {
        $assignmentId = SignedLink::verify('ticket', $token);

        if (null === $assignmentId) {
            return null;
        }

        $assignment = $this->assignments->find($assignmentId);

        if (null === $assignment) {
            return null;
        }

        $task = $this->tasks->find($assignment->taskId);
        $person = $this->people->find($assignment->personId);

        if (null === $task || null === $person) {
            return null;
        }

        return [
            'valid' => $assignment->isOccupying(),
            'name' => $person->name(),
            'event' => $task->eventName(),
            'date' => $task->taskDate,
            'role' => $task->roleDisplay(),
            'time' => $task->timeRange(),
        ];
    }

    /**
     * @param array{valid: bool, name: string, event: string, date: string, role: string, time: string}|null $ticket
     */
    public function renderPage(?array $ticket): string
    {
        if (null === $ticket) {
            return $this->document(
                __('Ticket not found', 'eventcrew'),
                '<p>' . esc_html__('This ticket link is not valid.', 'eventcrew') . '</p>'
            );
        }

        $badge = $ticket['valid']
            ? '<strong style="color:#1a7f37">' . esc_html__('VALID', 'eventcrew') . '</strong>'
            : '<strong style="color:#b32d2e">' . esc_html__('DISABLED', 'eventcrew') . '</strong>';

        $rows = sprintf(
            '<p style="font-size:1.5em;margin:.2em 0">%s</p><p>%s</p><p>%s%s</p><p>%s</p>',
            esc_html($ticket['name']),
            esc_html($ticket['event']),
            esc_html($ticket['role']),
            '' === $ticket['time'] ? '' : ' · ' . esc_html($ticket['time']),
            esc_html($ticket['date'])
        );

        return $this->document(__('Your EventCrew ticket', 'eventcrew'), $rows . '<p>' . $badge . '</p>');
    }

    private function document(string $title, string $inner): string
    {
        return sprintf(
            // phpcs:ignore Generic.Files.LineLength.TooLong -- one literal HTML document; wrapping it just adds stray whitespace.
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>%1$s</title></head><body style="font-family:sans-serif;max-width:24em;margin:3em auto;padding:1.5em;border:1px solid #ccc;border-radius:12px;text-align:center"><h1 style="font-size:1.1em;color:#666">%1$s</h1>%2$s</body></html>',
            esc_html($title),
            $inner
        );
    }
}
