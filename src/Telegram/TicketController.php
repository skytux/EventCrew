<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\Dates;
use EventCrew\Support\SignedLink;
use WP_REST_Request;

/**
 * The door ticket: a public page a person shows at the event.
 *
 * It carries no state of its own - the signed link names either an assignment
 * (a rostered slot) or a redemption (a spent free-entry credit), and the page
 * reads that record's live state on each load, so it says VALID while the slot
 * is held and DISABLED the moment it is cancelled. A ticket for a redemption
 * that has been handed back simply no longer resolves. The organizer's own door
 * list is the Roster; this is the attendee's copy of it.
 *
 * Because a web page can always be screenshotted, the page is built to make a
 * stale or shared copy *detectable* rather than impossible: it prints when it
 * was issued and runs a live ticking clock a screenshot cannot fake, for door
 * staff to glance at.
 */
final class TicketController
{
    public function __construct(
        private readonly AssignmentRepository $assignments,
        private readonly TaskRepository $tasks,
        private readonly PersonRepository $people,
        private readonly RedemptionRepository $redemptions
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
        // Not cacheable and not saveable as a shared file: a ticket is a live,
        // per-load view, never a static document to pass around.
        header('Cache-Control: no-store, max-age=0');
        // Built from literal markup and esc_html()'d values in renderPage().
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $this->renderPage($ticket);
        exit;
    }

    /**
     * The ticket's details, or null when the link is bad or the record it names
     * (assignment or redemption), or its task or person, is gone. A token is
     * tried as a rostered-slot ticket first, then as a free-entry-credit one.
     *
     * @return array{valid: bool, name: string, event: string, date: string, role: string, time: string, issued: string}|null
     */
    public function ticketFor(string $token): ?array
    {
        return $this->assignmentTicket($token) ?? $this->creditTicket($token);
    }

    /**
     * A ticket for a rostered slot - the assignment's own signed link.
     *
     * @return array{valid: bool, name: string, event: string, date: string, role: string, time: string, issued: string}|null
     */
    private function assignmentTicket(string $token): ?array
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
            'issued' => $assignment->signedUpAt,
        ];
    }

    /**
     * A ticket for a spent free-entry credit - a redemption's signed link. Valid
     * while the redemption exists; once handed back the row is gone and the link
     * no longer resolves.
     *
     * @return array{valid: bool, name: string, event: string, date: string, role: string, time: string, issued: string}|null
     */
    private function creditTicket(string $token): ?array
    {
        $redemptionId = SignedLink::verify('credit_ticket', $token);

        if (null === $redemptionId) {
            return null;
        }

        $redemption = $this->redemptions->find($redemptionId);

        if (null === $redemption || null === $redemption->redeemedFor) {
            return null;
        }

        $person = $this->people->find($redemption->personId);

        if (null === $person) {
            return null;
        }

        return [
            'valid' => true,
            'name' => $person->name(),
            'event' => $this->redemptionEvent($redemption),
            'date' => $redemption->redeemedFor,
            'role' => __('Free entry', 'eventcrew'),
            'time' => '',
            'issued' => $redemption->redeemedAt,
        ];
    }

    private function redemptionEvent(\EventCrew\Models\Redemption $redemption): string
    {
        if (null !== $redemption->eventPostId) {
            $title = (string) get_the_title($redemption->eventPostId);

            if ('' !== $title) {
                return $title;
            }
        }

        return $redemption->eventLabel;
    }

    /**
     * @param array{valid: bool, name: string, event: string, date: string, role: string, time: string, issued: string}|null $ticket
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

        return $this->document(
            __('Your EventCrew ticket', 'eventcrew'),
            $rows . '<p>' . $badge . '</p>' . $this->antiFraudBlock($ticket['issued'])
        );
    }

    /**
     * The issued time (static) and a live ticking clock (script) that a
     * screenshot cannot fake, so door staff can tell a real, freshly-loaded
     * ticket from a saved image or an old copy.
     */
    private function antiFraudBlock(string $issued): string
    {
        $issuedLine = '' === $issued
            ? ''
            : sprintf(
                '<p style="opacity:.7;font-size:.85em">%s</p>',
                esc_html(sprintf(
                    /* translators: %s: the date and time the ticket was issued */
                    __('Issued %s', 'eventcrew'),
                    $this->formatIssued($issued)
                ))
            );

        // The clock is written and ticked entirely client-side (no network), so
        // it moves every second on a live page and freezes in a screenshot.
        return $issuedLine
            . '<p style="font-size:2em;font-variant-numeric:tabular-nums;margin:.3em 0" id="ec-clock">—</p>'
            . '<p style="opacity:.7;font-size:.8em">'
            . esc_html__('The clock above must match the time now. A frozen clock is a screenshot.', 'eventcrew')
            . '</p>'
            // phpcs:ignore Generic.Files.LineLength.TooLong -- one self-contained clock script; wrapping adds stray whitespace.
            . '<script>(function(){var e=document.getElementById("ec-clock");function t(){var d=new Date();e.textContent=d.toLocaleTimeString();}t();setInterval(t,1000);})();</script>';
    }

    private function formatIssued(string $issued): string
    {
        // The stored value is already local wall-clock time (current_time('mysql')
        // when the ticket was made), so Dates::wallClock reformats its own digits
        // without running it back through a timezone - the door only cares what
        // the clock read when it was issued, not which zone that was.
        return Dates::wallClock($issued, 'H:i, D j M');
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
