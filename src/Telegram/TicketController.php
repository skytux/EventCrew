<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CheckinRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\Dates;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\EventName;
use EventCrew\Support\EventTimes;
use EventCrew\Support\Organization;
use EventCrew\Support\TicketNote;
use EventCrew\Support\Language;
use EventCrew\Support\ShortCode;
use EventCrew\Support\SignedLink;
use EventCrew\Support\SiteTexts;
use EventCrew\Support\TicketMail;
use EventCrew\Web\SignupBoardView;
use WP_REST_Request;
use WP_REST_Response;

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
    /** The image URL drawn behind the ticket card; '' or unset draws none. */
    public const BACKGROUND_OPTION = 'eventcrew_ticket_background';

    /** Free text shown on every ticket (door code, address, ...); '' shows nothing. */
    public const NOTE_OPTION = 'eventcrew_ticket_note';

    /** The route the open ticket page waits on for its check-in (state()). */
    public const STATE_ROUTE = '/ticket/state';

    /**
     * The longest state() holds one request, in seconds. Each waiting page
     * holds a PHP worker that long, so it stays well under the host's
     * max_execution_time and proxy timeouts.
     */
    public const WAIT_SECONDS = 20;

    /** Seconds a page waits before asking again when the night is still to come. */
    public const BEFORE_NIGHT_RETRY = 900;

    /** Hour of the next day (site time) until which a night's tickets are waited on. */
    private const NIGHT_ENDS_HOUR = 6;

    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    /** The sound of a ticket turning gold, under the plugin folder. */
    public const IN_SOUND = 'assets/sounds/finger-cymbals.aac';

    public function __construct(
        private readonly AssignmentRepository $assignments,
        private readonly TaskRepository $tasks,
        private readonly PersonRepository $people,
        private readonly RedemptionRepository $redemptions,
        private readonly TicketBuyerRepository $buyers = new TicketBuyerRepository(),
        private readonly GuestRepository $guests = new GuestRepository(),
        private readonly CheckinRepository $checkins = new CheckinRepository(),
        ?\Closure $sleep = null
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
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

        // The open ticket page's live wait for the door's check-in. The token
        // is the page's own signed link, so it shows only that ticket's state.
        register_rest_route(
            'eventcrew/v1',
            self::STATE_ROUTE,
            [
                'methods' => 'GET',
                'callback' => [$this, 'state'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /** The URL the ticket page of $token waits on (state()). */
    public static function stateUrl(string $token): string
    {
        return add_query_arg(['token' => $token], rest_url('eventcrew/v1' . self::STATE_ROUTE));
    }

    /**
     * The closest a shared host without websockets gets to a push: a long
     * poll. While the ticket is not checked in and its night is on, the
     * request is held up to WAIT_SECONDS, asking the check-ins table once a
     * second (one indexed query, CheckinRepository::anyInAt()), and answers
     * the moment the door marks the ticket in. The page asks again at once
     * after each answer.
     *
     * Answers {in, at, next}: 'at' is the check-in time ("21:05", '' when not
     * in); 'next' is how many seconds the page waits before asking again, 0
     * while the night is on, null once the night is over or the ticket is in
     * (stop asking). A bad link answers 404 and nothing else.
     */
    public function state(WP_REST_Request $request): WP_REST_Response
    {
        $entry = $this->doorEntry((string) $request->get_param('token'));

        if (null === $entry) {
            return self::noStore(new WP_REST_Response(['code' => 'not_found'], 404));
        }

        $now = (string) current_time('mysql');
        $nightStarts = $entry['date'] . ' 00:00:00';
        $nightEnds = gmdate(
            'Y-m-d H:i:s',
            (int) strtotime($entry['date'] . ' UTC') + DAY_IN_SECONDS + self::NIGHT_ENDS_HOUR * HOUR_IN_SECONDS
        );
        $on = $now >= $nightStarts && $now < $nightEnds;
        $limit = (int) ini_get('max_execution_time');
        $wait = $on ? self::WAIT_SECONDS : 0;

        if ($limit > 0) {
            $wait = max(0, min($wait, $limit - 10));
        }

        $at = $this->checkins->anyInAt($entry['date'], $entry['kinds'], $entry['ref']);

        for ($waited = 0; null === $at && $waited < $wait; $waited++) {
            ($this->sleep)(1);
            $at = $this->checkins->anyInAt($entry['date'], $entry['kinds'], $entry['ref']);
        }

        if (null !== $at) {
            $next = null;
        } elseif ($on) {
            $next = 0;
        } else {
            $next = $now < $nightStarts ? self::BEFORE_NIGHT_RETRY : null;
        }

        return self::noStore(new WP_REST_Response([
            'in' => null !== $at,
            'at' => null === $at ? '' : Dates::wallClock($at, 'H:i'),
            'next' => $next,
        ], 200));
    }

    private static function noStore(WP_REST_Response $response): WP_REST_Response
    {
        $response->header('Cache-Control', 'no-store, max-age=0');
        $response->header('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /**
     * The door row a ticket link stands for: the night, the check-in kinds
     * it is marked in as and the row's ref, or null when the link is bad or
     * its record is gone. The same checks as ticketFor(), without reading
     * anything the page shows.
     *
     * @return array{date: string, kinds: array<int, string>, ref: int}|null
     */
    public function doorEntry(string $token): ?array
    {
        $assignmentId = SignedLink::verify('ticket', $token);

        if (null !== $assignmentId) {
            $assignment = $this->assignments->find($assignmentId);
            $task = null === $assignment ? null : $this->tasks->find($assignment->taskId);

            return null === $task
                ? null
                : ['date' => $task->taskDate, 'kinds' => CheckinRepository::TASK_TICKET_KINDS, 'ref' => $assignment->personId];
        }

        $redemptionId = SignedLink::verify('credit_ticket', $token);

        if (null !== $redemptionId) {
            $redemption = $this->redemptions->find($redemptionId);

            return null === $redemption || null === $redemption->redeemedFor
                ? null
                : [
                    'date' => $redemption->redeemedFor,
                    'kinds' => CheckinRepository::CREDIT_TICKET_KINDS,
                    'ref' => $redemption->personId,
                ];
        }

        $parts = TicketMail::guestTokenParts($token);

        if (null !== $parts) {
            $guest = $this->guests->find($parts['id']);

            return null === $guest || '' === $guest->email || $parts['rev'] !== $guest->ticketRev
                ? null
                : ['date' => $guest->eventDate, 'kinds' => [CheckinRepository::KIND_GUEST], 'ref' => $guest->id];
        }

        $id = SignedLink::idOf($token);
        $buyer = null === $id ? null : $this->buyers->find($id);

        if (
            null === $buyer || '' === $buyer->ticketCode || '' === $buyer->email
            || $id !== SignedLink::verify('buyer_ticket|' . $buyer->ticketCode, $token)
        ) {
            return null;
        }

        return ['date' => $buyer->eventDate, 'kinds' => [CheckinRepository::KIND_BUYER], 'ref' => $buyer->id];
    }

    public function handle(WP_REST_Request $request): void
    {
        $token = (string) $request->get_param('token');
        $ticket = $this->ticketFor($token);

        if (null !== $ticket) {
            $ticket['poll'] = self::stateUrl($token);
        }

        status_header(null === $ticket ? 404 : 200);
        header('Content-Type: text/html; charset=utf-8');
        // Not cacheable and not saveable as a shared file: a ticket is a live,
        // per-load view, never a static document to pass around.
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow', true);
        // Built from literal markup and esc_html()'d values in renderPage().
        // The page is in the ticket holder's language; a bad link, which names
        // nobody, is English.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo Language::run($ticket['language'] ?? null, fn (): string => $this->renderPage($ticket));
        exit;
    }

    /**
     * The ticket's details, or null when the link is bad or the record it names
     * (assignment, redemption, ticket buyer or guest), or its task or person,
     * is gone. A token is tried as a rostered-slot ticket first, then as a
     * free-entry-credit one, then as an emailed ticket (TicketMail).
     *
     * 'qr' is what the QR code holds when it is not the page's own link: a
     * ticket buyer's ticket code. 'code' is written out under the QR code.
     * 'used' is when the door marked the ticket in (site time), null while
     * it is not in (CheckinRepository::inAt()). 'event_id' is the linked
     * event's post, so the page names it in the ticket's language.
     *
     * @return array{
     *     valid: bool, name: string, event: string, date: string, role: string, time: string,
     *     issued: string, language: ?string, image?: string, qr?: string, code?: string,
     *     used?: string|null, event_id?: int|null
     * }|null
     */
    public function ticketFor(string $token): ?array
    {
        return $this->assignmentTicket($token)
            ?? $this->creditTicket($token)
            ?? $this->guestTicket($token)
            ?? $this->buyerTicket($token);
    }

    /**
     * An imported ticket buyer's emailed ticket. The ticket code is part of
     * what the link signs, so once the code is deleted (60 days after the
     * event, or on an erasure request) the link no longer resolves.
     *
     * @return array{
     *     valid: bool, name: string, event: string, date: string, role: string, time: string,
     *     issued: string, language: ?string, image?: string, qr?: string, code?: string
     * }|null
     */
    private function buyerTicket(string $token): ?array
    {
        $id = SignedLink::idOf($token);
        $buyer = null === $id ? null : $this->buyers->find($id);

        if (null === $buyer || '' === $buyer->ticketCode || '' === $buyer->email) {
            return null;
        }

        if ($id !== SignedLink::verify('buyer_ticket|' . $buyer->ticketCode, $token)) {
            return null;
        }

        $role = static fn (): string => __('Ticket', 'eventcrew');

        // Holvi gives every ticket its own code, so this row is the ticket.
        return $this->emailedTicket($buyer->eventDate, $buyer->name, $role) + [
            'qr' => $buyer->ticketCode,
            'code' => $buyer->ticketCode,
            'short' => ShortCode::forBuyer($buyer->id),
            'used' => $this->checkins->inAt($buyer->eventDate, [CheckinRepository::KIND_BUYER], $buyer->id),
        ];
    }

    /**
     * A guest's emailed ticket. Works while the guest is on the list with an
     * email address; the address is deleted 60 days after the event.
     *
     * @return array{
     *     valid: bool, name: string, event: string, date: string, role: string, time: string,
     *     issued: string, language: ?string, image?: string
     * }|null
     */
    private function guestTicket(string $token): ?array
    {
        // The token carries the guest's ticketRev. A link from before an
        // organizer changed the guest's address has an older rev: no ticket.
        $parts = TicketMail::guestTokenParts($token);
        $guest = null === $parts ? null : $this->guests->find($parts['id']);

        if (null === $guest || '' === $guest->email || $parts['rev'] !== $guest->ticketRev) {
            return null;
        }

        $plusOnes = $guest->plusOnes;
        $role = static fn (): string => $plusOnes > 0
            ? sprintf(
                /* translators: %d: how many people the guest may bring */
                _n('Guest list, +%d person', 'Guest list, +%d people', $plusOnes, 'eventcrew'),
                $plusOnes
            )
            : __('Guest list', 'eventcrew');

        return $this->emailedTicket($guest->eventDate, $guest->name, $role) + [
            'short' => ShortCode::forGuest($guest->id),
            'used' => $this->checkins->inAt($guest->eventDate, [CheckinRepository::KIND_GUEST], $guest->id),
        ];
    }

    /**
     * The common part of an emailed ticket: the night's event (its first
     * task's), and the page in the language the browser asks for. $role
     * returns the line under the name, in the current language.
     *
     * @param callable(): string $role
     * @return array{
     *     valid: bool, name: string, event: string, date: string, role: string, time: string,
     *     issued: string, language: ?string, image: string
     * }
     */
    private function emailedTicket(string $date, string $name, callable $role): array
    {
        $task = $this->tasks->forDate($date)[0] ?? null;
        $accept = isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])
            ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_ACCEPT_LANGUAGE']))
            : null;
        $language = Language::fromBrowser($accept);

        return [
            'valid' => true,
            'name' => $name,
            'event' => null === $task ? (string) get_bloginfo('name') : $task->eventName(),
            'event_id' => $task?->eventPostId,
            'date' => $date,
            'role' => Language::run($language, $role),
            'time' => '',
            'issued' => '',
            'language' => $language,
            'image' => null === $task ? '' : SignupBoardView::eventImage($task->eventPostId),
        ];
    }

    /**
     * A ticket for a rostered slot - the assignment's own signed link.
     *
     * @return array{valid: bool, name: string, event: string, date: string, role: string, time: string, issued: string, language: ?string, image?: string}|null
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
            // One ticket per person per night: valid while they hold any
            // task that night, as at the door (DoorDesk::scanAssignment()).
            'valid' => $assignment->isOccupying()
                || $this->assignments->holdsTaskOn($assignment->personId, $task->taskDate),
            'name' => $person->name(),
            'event' => $task->eventName(),
            'event_id' => $task->eventPostId,
            'date' => $task->taskDate,
            'role' => Language::forPerson($person, static fn (): string => $task->roleDisplay()),
            'time' => $task->timeRange(),
            'issued' => $assignment->signedUpAt,
            'language' => $person->language,
            'image' => SignupBoardView::eventImage($task->eventPostId),
            'used' => $this->checkins->inAt($task->taskDate, CheckinRepository::TASK_TICKET_KINDS, $person->id),
            'short' => ShortCode::forPerson($person->id, $task->taskDate),
        ];
    }

    /**
     * A ticket for a spent free-entry credit - a redemption's signed link. Valid
     * while the redemption exists; once handed back the row is gone and the link
     * no longer resolves.
     *
     * @return array{valid: bool, name: string, event: string, date: string, role: string, time: string, issued: string, language: ?string, image?: string}|null
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
            'valid' => ! $redemption->isCancelled(),
            'name' => $person->name(),
            'event' => $this->redemptionEvent($redemption),
            'event_id' => $redemption->eventPostId,
            'date' => $redemption->redeemedFor,
            'role' => Language::forPerson($person, static fn (): string => __('Free entry', 'eventcrew')),
            'time' => '',
            'issued' => $redemption->redeemedAt,
            'language' => $person->language,
            'image' => SignupBoardView::eventImage($redemption->eventPostId),
            'used' => $this->checkins->inAt(
                $redemption->redeemedFor,
                CheckinRepository::CREDIT_TICKET_KINDS,
                $person->id
            ),
            'short' => ShortCode::forCredit($person->id, $redemption->redeemedFor),
        ];
    }

    private function redemptionEvent(\EventCrew\Models\Redemption $redemption): string
    {
        if (null !== $redemption->eventPostId) {
            $title = EventName::name(
                $redemption->eventPostId,
                EventName::lang(),
                (string) get_the_title($redemption->eventPostId)
            );

            if ('' !== $title) {
                return $title;
            }
        }

        return $redemption->eventLabel;
    }

    /**
     * @param array{valid: bool, name: string, event: string, date: string, role: string, time: string, issued: string, language: ?string, image?: string, used?: string|null, event_id?: int|null}|null $ticket
     */
    public function renderPage(?array $ticket): string
    {
        if (null === $ticket) {
            $title = __('Ticket not found', 'eventcrew');

            return $this->document(
                $title,
                '<header class="t-head"><h1 class="t-event">' . esc_html($title) . '</h1></header>'
                . '<div class="t-body"><p>' . esc_html__('This ticket link is not valid.', 'eventcrew') . '</p></div>'
            );
        }

        /* translators: %s: the organization's name, like Ecstatic Dance Helsinki */
        $title = sprintf(__('Your %s ticket', 'eventcrew'), Organization::name());

        $eventId = null === ($ticket['event_id'] ?? null) ? null : (int) $ticket['event_id'];
        $used = $ticket['used'] ?? null;
        // The demo ticket (DemoTicket) keeps waiting after it is in, since
        // its desk can tick it out again.
        $demo = ! empty($ticket['demo']);
        $closeAt = EventTimes::doorsCloseAt($eventId, $ticket['date']);
        // Seconds until the doors close, by the site's clock; null when the
        // event has no doors time. Counted from the site's own "now", so the
        // site's time zone never needs converting.
        $closesIn = '' === $closeAt
            ? null
            : (int) strtotime($closeAt . ' UTC') - (int) strtotime((string) current_time('mysql') . ' UTC');

        if (! $ticket['valid']) {
            $band = '<p class="t-band is-disabled">' . esc_html__('DISABLED', 'eventcrew') . '</p>';
        } elseif (null !== $used) {
            $band = $this->inBand(Dates::wallClock($used, 'H:i'));
        } else {
            $band = $this->openBand(null !== $closesIn && $closesIn <= 0);
        }

        $body = sprintf(
            '<p class="t-name">%s</p><p class="t-task">%s</p>',
            esc_html($ticket['name']),
            // The role and the time, each on its own row (Owner, 2026-11-06).
            implode('<br>', array_map('esc_html', array_filter([$ticket['role'], $ticket['time']], 'strlen')))
        );

        $watermark = ltrim($this->watermarkStyle(), ';');
        // The event's clean name in the page's language (EventName).
        $event = null === $eventId ? $ticket['event'] : EventName::name($eventId, EventName::lang(), $ticket['event']);

        return $this->document(
            $title,
            $this->headBlock(
                $title,
                $event,
                $ticket['date'],
                EventTimes::start($eventId),
                (string) ($ticket['image'] ?? '')
            )
            . '<div class="t-body"' . ('' === $watermark ? '' : ' style="' . $watermark . '"') . '>'
            . $body . $band . $this->qrBlock($ticket['qr'] ?? '')
            . $this->shortBlock((string) ($ticket['short'] ?? '')) . $this->codeBlock($ticket['code'] ?? '')
            . $this->noteBlock($eventId) . $this->antiFraudBlock($ticket['issued'])
            . ($ticket['valid'] && (null === $used || $demo)
                ? $this->liveScript((string) ($ticket['poll'] ?? ''), $closesIn, $demo)
                : '')
            . '</div>'
        );
    }

    /**
     * The band of a ticket that is valid and not in yet: green VALID, or,
     * once the doors have closed, black "Sorry, this ticket has expired".
     * An expired ticket can still be scanned and counted in: the band only
     * says it is late. liveScript() turns VALID into expired on an open page
     * when the doors close, and either into inBand() when the door marks it in.
     */
    private function openBand(bool $expired): string
    {
        return $expired
            ? '<p class="t-band is-expired" id="ec-band" aria-live="polite">'
                . esc_html__('Sorry, this ticket has expired', 'eventcrew') . '</p>'
            : '<p class="t-band is-valid" id="ec-band" aria-live="polite">' . esc_html__('VALID', 'eventcrew') . '</p>';
    }

    /**
     * The band of a ticket the door has marked in: gold with twinkling stars,
     * "You're in, welcome!" and the time ($at, like 21:05). On an open page
     * liveScript() draws the same band with a burst ("is-new").
     */
    private function inBand(string $at): string
    {
        return '<p class="t-band is-in" id="ec-band" aria-live="polite">' . $this->inBandInner($at) . '</p>';
    }

    private function inBandInner(string $at): string
    {
        return '<span class="t-stars" aria-hidden="true">' . str_repeat('<i>★</i><i>✦</i>', 5) . '</span>'
            . '<span class="t-in-text">' . esc_html__('You’re in, welcome!', 'eventcrew') . '</span>'
            . '<span class="t-at">' . esc_html(sprintf(
                /* translators: %s: the time the door checked the ticket in, like 21:05 */
                __('Checked in at %s', 'eventcrew'),
                $at
            )) . '</span>';
    }

    /**
     * The open page's live part, on a valid ticket not in yet. The clock
     * check turns the band black when the doors close ($closesIn seconds from
     * now by the site's clock, null for no doors time), measured from the
     * phone's own clock at load, so a wrong phone clock does not matter. The
     * wait loop asks state() ($poll) and turns the band gold the moment the
     * door marks the ticket in. It asks again after each answer, stops while
     * the tab is hidden and starts again when it is shown, and stops for good
     * once the ticket is in or the night is over. Errors back off up to a
     * minute.
     *
     * $again (the demo ticket) keeps asking after the ticket is in, sends
     * what the band shows (in=0 or 1) so the wait answers on any change, and
     * turns the band back to VALID when the desk ticks the ticket out.
     *
     * Turning gold plays finger cymbals at 30% volume (Owner 2026-11-07).
     * Phones only play sound on a page the person has touched, so the first
     * touch on the page unlocks it silently.
     */
    private function liveScript(string $poll, ?int $closesIn, bool $again = false): string
    {
        $config = [
            'poll' => $poll,
            'closesIn' => $closesIn,
            'again' => $again,
            'valid' => __('VALID', 'eventcrew'),
            'expired' => __('Sorry, this ticket has expired', 'eventcrew'),
            'inHtml' => $this->inBandInner('%s'),
            'sound' => defined('EVENTCREW_PLUGIN_URL') ? EVENTCREW_PLUGIN_URL . self::IN_SOUND . '?ver=' . EVENTCREW_VERSION : '',
        ];
        $json = (string) wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);

        // phpcs:ignore Generic.Files.LineLength.TooLong -- one self-contained script; wrapping adds stray whitespace.
        return '<script>(function(){var c=' . $json . ';var b=document.getElementById("ec-band");if(!b){return;}var done=false,busy=false,timer=null,fails=0,isIn=-1!==b.className.indexOf("is-in");var snd=null;if(c.sound&&window.Audio){snd=new Audio(c.sound);snd.preload="auto";snd.volume=0.3;var unlock=function(){document.removeEventListener("pointerdown",unlock,true);snd.muted=true;var p=snd.play();if(p&&p.then){p.then(function(){snd.pause();snd.currentTime=0;snd.muted=false;},function(){snd.muted=false;});}else{snd.pause();snd.muted=false;}};document.addEventListener("pointerdown",unlock,true);}function ding(){if(!snd){return;}try{snd.muted=false;snd.currentTime=0;var p=snd.play();if(p&&p.catch){p.catch(function(){});}}catch(e){}}var closeAt=null===c.closesIn?null:Date.now()+c.closesIn*1000;function expire(){if(done||isIn||null===closeAt||Date.now()<closeAt||"t-band is-expired"===b.className){return;}b.className="t-band is-expired";b.textContent=c.expired;}function burst(){var k=b.closest?b.closest(".t"):null;if(!k){return;}var o=k.querySelector(".t-fx");if(o){o.parentNode.removeChild(o);}var f=document.createElement("span"),w=k.clientWidth,h=k.clientHeight,y=b.offsetTop+b.offsetHeight/2,g="✦★•✦★✦";f.className="t-fx";f.setAttribute("aria-hidden","true");for(var i=0;i<48;i++){var e=document.createElement("i"),a=Math.random()*6.2832,r=.3+Math.random()*.7;e.textContent=g.charAt(i%6);e.style.cssText="left:"+(6+Math.random()*88).toFixed(1)+"%;top:"+Math.round(y)+"px;font-size:"+(.7+Math.random()*1.1).toFixed(2)+"em;--dx:"+Math.round(Math.cos(a)*r*w*.5)+"px;--dy:"+Math.round(Math.sin(a)*r*h*.45)+"px;--r:"+Math.round(Math.random()*360-180)+"deg;--s:"+(.5+Math.random()*.5).toFixed(2)+";--d:"+(Math.random()*.6).toFixed(2)+"s;--t:"+(2.2+Math.random()*1.8).toFixed(2)+"s";f.appendChild(e);}k.appendChild(f);setTimeout(function(){if(f.parentNode){f.parentNode.removeChild(f);}},5500);}function welcome(at){isIn=true;if(!c.again){done=true;if(timer){clearTimeout(timer);}}b.className="t-band is-in is-new";b.innerHTML=c.inHtml;ding();burst();var t=b.querySelector(".t-at");if(t){t.textContent=t.textContent.replace("%s",at);}}function out(){isIn=false;var x=document.querySelector(".t-fx");if(x){x.parentNode.removeChild(x);}b.className="t-band is-valid";b.textContent=c.valid;expire();}function later(ms){if(timer){clearTimeout(timer);}timer=setTimeout(ask,ms);}function ask(){timer=null;if(done||busy||!c.poll||document.hidden){return;}busy=true;fetch(c.poll+(-1===c.poll.indexOf("?")?"?":"&")+(c.again?"in="+(isIn?1:0)+"&":"")+"_="+Date.now(),{cache:"no-store",credentials:"same-origin"}).then(function(r){if(404===r.status){c.poll="";}if(!r.ok){throw new Error(String(r.status));}return r.json();}).then(function(d){busy=false;fails=0;if(d&&d.in){if(!isIn){welcome(d.at||"");}if(!c.again){return;}}else if(d&&isIn&&c.again){out();}if(!d||null===d.next){c.poll="";return;}later(1000*d.next);},function(){busy=false;fails++;later(Math.min(60000,2000*fails));});}document.addEventListener("visibilitychange",function(){if(!document.hidden){expire();if(!busy){later(0);}}});setInterval(expire,1000);expire();ask();})();</script>';
    }

    /**
     * The card's header, like an event card on the crew board: the event's
     * picture behind it under a dark layer (faces sit about a third of the way
     * down the posters, hence centre 25%), the event name large, and the date
     * written out with the event's start time ($start, '' when not known).
     * Without a picture the header is plain dark.
     *
     * The picture URL goes through esc_url() inside &quot; quotes, as in
     * watermarkStyle().
     */
    private function headBlock(string $title, string $event, string $date, string $start, string $image): string
    {
        $style = '' === $image
            ? ''
            : ' style="background-image:linear-gradient(rgba(0,0,0,.2),rgba(0,0,0,.72)),url(&quot;'
                . esc_url($image) . '&quot;)"';

        return sprintf(
            '<header class="t-head%1$s"%2$s><p class="t-kicker">%3$s</p>'
                . '<h1 class="t-event">%4$s</h1><p class="t-date">%5$s</p></header>',
            '' === $image ? '' : ' has-image',
            $style,
            esc_html($title),
            esc_html($event),
            // The date and the start time, each on its own row (Owner, 2026-11-06).
            esc_html(Dates::longDayLabel($date)) . ('' === $start ? '' : '<br>' . esc_html($start))
        );
    }

    /** A small white QR code drawn on the Show QR code button. */
    private const QR_ICON = '<svg class="t-qr-icon" viewBox="0 0 24 24" fill="#fff" aria-hidden="true"'
        . ' focusable="false"><path d="M3 3h8v8H3zm2 2v4h4V5zm1 1h2v2H6zm7-3h8v8h-8zm2 2v4h4V5zm1 1h2v2h-2z'
        . 'M3 13h8v8H3zm2 2v4h4v-4zm1 1h2v2H6zm7-3h3v3h-3zm5 0h3v3h-3zm-5 5h3v3h-3zm5 0h3v3h-3z"/></svg>';

    /**
     * A QR code of this ticket's own link, so door staff can open it on their
     * phone, with the site's icon (or logo) in the middle. The code is drawn in the
     * browser by the bundled qrcode-generator, at error correction level H:
     * up to 30% of the code can be covered and it still scans, and the logo
     * covers about 5%. No library, no code: the box is removed.
     *
     * $data, when given, is what the code holds instead of the page's link: a
     * ticket buyer's ticket code, which is what the desk looks up.
     */
    private function qrBlock(string $data = ''): string
    {
        if (! defined('EVENTCREW_PLUGIN_URL')) {
            return '';
        }

        $logo = EmailTemplate::qrMarkUrl();
        $img = '' === $logo ? '' : '<img class="t-qr-logo" src="' . esc_url($logo) . '" alt="">';
        $lib = EVENTCREW_PLUGIN_URL . 'assets/vendor/qrcode-generator/qrcode.js?ver=' . EVENTCREW_VERSION;
        $content = '' === $data ? 'location.href' : (string) wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP);

        // The code stays hidden behind a button (Owner 2026-11-07): the tap
        // that shows it also lets the browser play the "you're in" sound.
        // The button covers the code's own square (t-qr-wrap), which is
        // always laid out, so showing the code moves nothing on the page.
        return '<div class="t-qr-wrap" id="ec-qr-wrap">'
            . '<button type="button" class="t-qr-show" id="ec-qr-show">' . self::QR_ICON
            . esc_html__('Show QR code', 'eventcrew') . '</button>'
            . '<div class="t-qr" id="ec-qr" hidden>' . $img . '</div></div>'
            . '<script src="' . esc_url($lib) . '"></script>'
            // phpcs:ignore Generic.Files.LineLength.TooLong -- one self-contained script; wrapping adds stray whitespace.
            . '<script>(function(){var h=document.getElementById("ec-qr"),s=document.getElementById("ec-qr-show"),w=document.getElementById("ec-qr-wrap");if(!h){return;}if("function"!==typeof window.qrcode){if(w){w.parentNode.removeChild(w);}return;}var q=window.qrcode(0,"H");q.addData(' . $content . ');q.make();h.insertAdjacentHTML("afterbegin",q.createSvgTag({cellSize:4,margin:8,scalable:true}));if(s){s.addEventListener("click",function(){h.hidden=false;s.parentNode.removeChild(s);});}else{h.hidden=false;}})();</script>';
    }

    /**
     * The short code (ShortCode) written large right under the QR code, the
     * same as on the ticket's row in the door app, so the door crew can check
     * a row they tick by hand. '' when there is none.
     */
    private function shortBlock(string $short): string
    {
        return '' === $short ? '' : '<p class="t-short">' . esc_html($short) . '</p>';
    }

    /** The ticket code written out under the short code, or '' when there is none. */
    private function codeBlock(string $code): string
    {
        return '' === $code ? '' : '<p class="t-code">' . esc_html($code) . '</p>';
    }

    /**
     * The free text set in Settings > Web page > Ticket note, in the
     * ticket's language (SiteTexts), as plain text with its line breaks
     * kept. When none is set, the built-in note (the doors line); '' when
     * that is empty too.
     */
    private function noteBlock(?int $eventId): string
    {
        $note = TicketNote::fill(
            TicketNote::orBuiltIn(SiteTexts::text('ticket_note')),
            TicketNote::values($eventId)
        );

        if ('' === $note) {
            return '';
        }

        return '<p style="white-space:pre-line">' . esc_html($note) . '</p>';
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
                '<p class="t-small">%s</p>',
                esc_html(sprintf(
                    /* translators: %s: the date and time the ticket was issued */
                    __('Issued %s', 'eventcrew'),
                    $this->formatIssued($issued)
                ))
            );

        // The clock is written and ticked entirely client-side (no network), so
        // it moves every second on a live page and freezes in a screenshot.
        return $issuedLine
            . '<p class="t-clock" id="ec-clock">—</p>'
            . '<p class="t-small">'
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
        /* translators: PHP date() format of when a ticket was issued, like "21:05, Sat 1 Aug". */
        return Dates::wallClock($issued, _x('H:i, D j M', 'date format', 'eventcrew'));
    }

    /**
     * The page around the ticket: a grey page with one white card in the
     * middle, built like an event card on the crew board (picture header,
     * then the body). All styling is in this one style block; the page loads
     * nothing from the theme.
     */
    private function document(string $title, string $inner): string
    {
        $css = 'html{background:#e8e6e3}'
            . 'body{margin:0;padding:1.5em 1em;font-family:system-ui,sans-serif;color:#1d1d1f;text-align:center}'
            . '.t{position:relative;isolation:isolate;max-width:26em;margin:0 auto;background:#fff;border-radius:16px;'
            . 'overflow:hidden;'
            . 'box-shadow:0 2px 12px rgba(0,0,0,.18)}'
            . '.t-head{background:#2b2b30 center 25%/cover no-repeat;color:#fff;padding:1.2em;'
            . 'display:flex;flex-direction:column;justify-content:flex-end}'
            . '.t-head.has-image{min-height:9em}'
            . '.t-head p,.t-head h1{margin:0;text-shadow:0 1px 3px rgba(0,0,0,.6)}'
            . '.t-kicker{font-size:.75em;text-transform:uppercase;letter-spacing:.08em;opacity:.85}'
            . '.t-event{font-size:1.5em;line-height:1.2;margin:.2em 0}'
            . '.t-date{font-size:.95em}'
            . '.t-body{padding:1.2em}'
            . '.t-name{font-size:1.9em;font-weight:700;line-height:1.15;margin:0 0 .2em}'
            . '.t-task{margin:0 0 1em;opacity:.8}'
            . '.t-band{margin:0 -1.2em 1em;padding:.45em;color:#fff;font-weight:700;letter-spacing:.15em}'
            . '.t-band.is-valid{background:#1a7f37}.t-band.is-disabled{background:#b32d2e}'
            . '.t-band.is-expired{background:#000;letter-spacing:.04em}'
            // Gold with dark brown text (over 6:1 on the darkest gold). It all
            // stays inside the card (Owner 2026-11-07: it went out of the
            // frame): every glow is inset, nothing full-width scales above 1,
            // and the card (overflow hidden, radius 16px) clips the rest.
            //
            // The calm loop, also on a page opened already in: the band is
            // a deep metallic gold (a light top, a darker foot, a shimmer
            // drifting by exactly one 200% tile, t-gold), its inner glow
            // breathes (t-glow), a soft sheen crosses it (t-sheen) and ten
            // stars twinkle (t-twinkle). The card's edge catches gold light
            // that travels along it (t-rim) and the card breathes a warm
            // inset glow (t-halo). Every loop starts where it ends.
            //
            // The moment itself (is-new, drawn live): the band opens from
            // the middle (t-open) and blooms (t-bloom), the whole card
            // flares gold from its edges (t-flare), a golden light sweeps
            // across the whole card (t-sweep), the stars burst in one by
            // one (t-burst), the words rise in (t-rise), and liveScript()
            // throws about 40 sparkles (.t-fx) from the band across the card
            // that fly out, drift down and fade (t-fly). Each ends on the
            // state its loop starts from. Less motion: plain gold and a gold
            // edge, nothing moves.
            . '.t-band.is-in{position:relative;overflow:hidden;isolation:isolate;padding:.7em .45em;color:#3b2500;'
            . 'letter-spacing:.04em;'
            . 'background:linear-gradient(180deg,rgba(255,255,255,.5),rgba(255,255,255,0) 45%,rgba(150,85,0,.18)),'
            . 'linear-gradient(90deg,#d9a21f,#f7cf55 16%,#fff6cf 30%,#f9d462 44%,#e2ab2a 58%,#ffeaa0 74%,#efbf3c 88%,'
            . '#d9a21f);'
            . 'background-size:100% 100%,200% 100%;'
            . 'box-shadow:inset 0 1px 0 rgba(255,255,255,.85),inset 0 -2px 0 rgba(120,70,0,.3),'
            . 'inset 0 0 1em rgba(255,250,220,.5);'
            . 'animation:t-gold 10s linear infinite,t-glow 3s ease-in-out infinite alternate}'
            . '.t-band.is-in::before{content:"";position:absolute;top:0;bottom:0;left:0;width:35%;pointer-events:none;'
            . 'background:linear-gradient(90deg,rgba(255,255,255,0),rgba(255,255,255,.6) 50%,rgba(255,255,255,0));'
            . 'transform:translateX(-150%) skewX(-20deg);animation:t-sheen 6s ease-in-out infinite}'
            . '.t-in-text,.t-at{display:block;position:relative;z-index:1}'
            . '.t-in-text{font-size:1.25em;text-shadow:0 0 .3em rgba(255,250,225,.95),0 0 .8em rgba(255,230,140,.8)}'
            . '.t-at{font-size:.8em;font-weight:600;letter-spacing:normal;margin-top:.15em}'
            . '.t-stars i{position:absolute;z-index:1;font-style:normal;color:#fff;line-height:1;opacity:.3;'
            . 'transform:scale(.5);text-shadow:0 0 3px #b07000,0 0 8px #fff;'
            . 'animation:t-twinkle 2.6s ease-in-out calc(var(--d,0s)*-6) infinite}'
            . '.t-stars i:nth-child(1){left:4%;top:14%;font-size:1.17em}'
            . '.t-stars i:nth-child(2){left:13%;top:58%;font-size:.78em;--d:.2s}'
            . '.t-stars i:nth-child(3){left:23%;top:10%;font-size:.91em;--d:.05s}'
            . '.t-stars i:nth-child(4){left:33%;top:68%;font-size:.65em;--d:.3s}'
            . '.t-stars i:nth-child(5){left:45%;top:6%;font-size:.715em;--d:.4s}'
            . '.t-stars i:nth-child(6){left:58%;top:70%;font-size:.78em;--d:.1s}'
            . '.t-stars i:nth-child(7){left:68%;top:12%;font-size:1.04em;--d:.25s}'
            . '.t-stars i:nth-child(8){left:78%;top:60%;font-size:.78em;--d:.15s}'
            . '.t-stars i:nth-child(9){left:87%;top:14%;font-size:.845em;--d:.45s}'
            . '.t-stars i:nth-child(10){left:93%;top:56%;font-size:1.17em;--d:.35s}'
            // The card around a gold band: its rim (::before, a 3px ring cut
            // out with a mask, its light turning round the card) and its inner
            // glow and sweep (::after).
            . '.t:has(.t-band.is-in)::before{content:"";position:absolute;inset:0;z-index:3;pointer-events:none;'
            . 'border-radius:inherit;padding:3px;'
            . 'background:conic-gradient(from var(--t-rim,0deg),#c88d14,#ffe9a0 10%,#d9a020 22%,#c88d14 35%,'
            . '#fff7d6 50%,#d9a020 62%,#c88d14 75%,#ffe9a0 88%,#c88d14);'
            . '-webkit-mask:linear-gradient(#000 0 0) content-box,linear-gradient(#000 0 0);'
            . '-webkit-mask-composite:xor;mask:linear-gradient(#000 0 0) content-box exclude,linear-gradient(#000 0 0);'
            . 'animation:t-rim 6s linear infinite}'
            . '.t:has(.t-band.is-in)::after{content:"";position:absolute;inset:0;z-index:2;pointer-events:none;'
            . 'border-radius:inherit;box-shadow:inset 0 0 1.2em rgba(235,175,30,.35);'
            . 'background:linear-gradient(110deg,rgba(255,220,110,0) 38%,rgba(255,215,100,.45) 45%,'
            . 'rgba(255,250,225,.85) 50%,rgba(255,215,100,.45) 55%,rgba(255,220,110,0) 62%) 130% 0/250% 100% no-repeat;'
            . 'animation:t-halo 3.5s ease-in-out infinite alternate}'
            . '.t-band.is-new{animation:t-gold 10s linear infinite,t-open .7s cubic-bezier(.2,.8,.2,1) both,'
            . 't-glow 3s ease-in-out .7s infinite alternate}'
            . '.t-band.is-new::after{content:"";position:absolute;inset:0;pointer-events:none;opacity:0;'
            . 'background:radial-gradient(ellipse at center,rgba(255,255,245,1),rgba(255,240,180,.6) 40%,'
            . 'rgba(255,220,120,0) 70%);animation:t-bloom 1.6s ease-out .15s}'
            . '.t-band.is-new .t-in-text{animation:t-rise .8s cubic-bezier(.2,.8,.2,1) .3s both}'
            . '.t-band.is-new .t-at{animation:t-rise .8s cubic-bezier(.2,.8,.2,1) .5s both}'
            . '.t-band.is-new .t-stars i{animation:t-burst 1.2s ease-out calc(.4s + var(--d,0s)) both,'
            . 't-twinkle 2.6s ease-in-out calc(1.6s + var(--d,0s)) infinite}'
            . '.t:has(.t-band.is-new)::after{animation:t-flare 2.2s ease-out both,'
            . 't-sweep 1.8s cubic-bezier(.45,.05,.35,1) .25s both,t-halo 3.5s ease-in-out 2.2s infinite alternate}'
            . '.t:has(.t-band.is-new)::before{animation:t-rim 1.5s linear 2 both,t-rim 6s linear 3s infinite}'
            . '.t-fx{position:absolute;inset:0;z-index:4;pointer-events:none;overflow:hidden}'
            . '.t-fx i{position:absolute;font-style:normal;line-height:1;color:#ffd24d;opacity:0;'
            . 'text-shadow:0 0 3px #fff6c8,0 0 9px rgba(255,185,30,.95);'
            . 'animation:t-fly var(--t,2.6s) linear var(--d,0s) both}'
            . '.t-fx i:nth-child(3n){color:#fff}.t-fx i:nth-child(4n){color:#f0a81a}'
            . '@keyframes t-gold{from{background-position:0 0,0 0}to{background-position:0 0,200% 0}}'
            . '@keyframes t-glow{from{box-shadow:inset 0 1px 0 rgba(255,255,255,.85),inset 0 -2px 0 rgba(120,70,0,.3),'
            . 'inset 0 0 1em rgba(255,250,220,.5)}'
            . 'to{box-shadow:inset 0 1px 0 rgba(255,255,255,1),inset 0 -2px 0 rgba(120,70,0,.3),'
            . 'inset 0 0 2em rgba(255,252,232,.95)}}'
            . '@keyframes t-sheen{0%{transform:translateX(-150%) skewX(-20deg)}'
            . '50%,100%{transform:translateX(400%) skewX(-20deg)}}'
            . '@keyframes t-twinkle{0%,100%{opacity:.3;transform:scale(.5) rotate(0deg)}'
            . '50%{opacity:1;transform:scale(1) rotate(45deg)}}'
            . '@property --t-rim{syntax:"<angle>";inherits:false;initial-value:0deg}'
            . '@keyframes t-rim{from{--t-rim:0deg}to{--t-rim:360deg}}'
            . '@keyframes t-halo{from{box-shadow:inset 0 0 1.2em rgba(235,175,30,.35)}'
            . 'to{box-shadow:inset 0 0 2.4em rgba(240,185,40,.55)}}'
            . '@keyframes t-flare{0%{box-shadow:inset 0 0 0 rgba(255,205,70,0)}'
            . '20%{box-shadow:inset 0 0 0 4px rgba(255,214,90,.95),inset 0 0 9em rgba(255,200,60,.8)}'
            . '100%{box-shadow:inset 0 0 0 0 rgba(255,214,90,0),inset 0 0 1.2em rgba(235,175,30,.35)}}'
            . '@keyframes t-sweep{from{background-position:130% 0}to{background-position:-30% 0}}'
            . '@keyframes t-open{from{clip-path:inset(0 50%)}to{clip-path:inset(0 0)}}'
            . '@keyframes t-bloom{0%{opacity:0;transform:scaleX(.2)}25%{opacity:1}100%{opacity:0;transform:scaleX(1)}}'
            . '@keyframes t-rise{from{opacity:0;transform:translateY(.4em)}to{opacity:1;transform:translateY(0)}}'
            . '@keyframes t-burst{0%{opacity:0;transform:translateY(.5em) scale(.2) rotate(-90deg)}'
            . '60%{opacity:1;transform:translateY(0) scale(1) rotate(30deg)}'
            . '100%{opacity:.3;transform:translateY(0) scale(.5) rotate(0deg)}}'
            . '@keyframes t-fly{0%{opacity:0;transform:translate(-50%,-50%) scale(.2) rotate(0deg);'
            . 'animation-timing-function:cubic-bezier(.1,.75,.3,1)}'
            . '8%{opacity:1}'
            . '40%{opacity:1;transform:translate(calc(var(--dx) - 50%),calc(var(--dy) - 50%)) scale(var(--s,.8))'
            . ' rotate(var(--r,90deg));animation-timing-function:ease-in-out}'
            . '100%{opacity:0;transform:translate(calc(var(--dx)*1.15 - 50%),calc(var(--dy) + 4em - 50%))'
            . ' scale(.3) rotate(calc(var(--r,90deg)*2))}}'
            . '@media (prefers-reduced-motion:reduce){.t-band.is-in,.t-band.is-new,.t-band.is-new .t-in-text,'
            . '.t-band.is-new .t-at,.t-stars i,.t-band.is-new .t-stars i,.t:has(.t-band.is-in)::before,'
            . '.t:has(.t-band.is-in)::after,.t:has(.t-band.is-new)::before,.t:has(.t-band.is-new)::after{animation:none}'
            . '.t-band.is-in::before,.t-band.is-new::after,.t-fx{display:none}.t-stars i{opacity:.8;transform:none}}'
            // The code's square is always laid out, hidden until the tap, and
            // the button covers it: showing the code moves nothing.
            . '.t-qr-wrap{position:relative;width:15em;max-width:100%;margin:0 auto 1em}'
            . '.t-qr{position:relative;aspect-ratio:1/1}'
            . '.t-qr[hidden]{display:block;visibility:hidden}'
            . '.t-qr-show{position:absolute;inset:0;z-index:1;display:flex;flex-direction:column;align-items:center;'
            . 'justify-content:center;gap:.6em;width:100%;min-height:48px;margin:0;padding:1em;font:inherit;'
            . 'font-size:1.15em;font-weight:700;color:#fff;background:#1d1d1f;border:0;border-radius:14px;'
            . 'box-shadow:0 3px 10px rgba(0,0,0,.25);cursor:pointer;-webkit-tap-highlight-color:transparent}'
            . '.t-qr-icon{display:block;width:3.4em;height:3.4em}'
            . '.t-qr-show:hover{background:#000}.t-qr-show:active{background:#3a3a3f}'
            . '.t-qr-show:focus-visible{outline:3px solid #0a66d8;outline-offset:3px}'
            . '.t-qr svg{display:block;width:100%;height:auto}'
            . '.t-qr-logo{display:none;position:absolute;left:50%;top:50%;width:20%;height:20%;'
            . 'transform:translate(-50%,-50%);object-fit:contain;background:#fff;padding:3%;border-radius:8px}'
            . '.t-qr svg+.t-qr-logo{display:block}'
            . '.t-short{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:1.8em;font-weight:700;'
            . 'letter-spacing:.25em;margin:-.4em 0 .6em;padding-left:.25em}'
            . '.t-code{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:1em;font-weight:600;'
            . 'letter-spacing:.06em;opacity:.75;margin:-.4em 0 1em}'
            . '.t-short+.t-code{margin-top:0}'
            . '.t-clock{font-size:2em;font-variant-numeric:tabular-nums;margin:.3em 0}'
            . '.t-small{opacity:.7;font-size:.82em}';

        return sprintf(
            // phpcs:ignore Generic.Files.LineLength.TooLong -- one literal HTML document; wrapping it just adds stray whitespace.
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>%1$s</title><style>%3$s</style></head><body><main class="t">%2$s</main></body></html>',
            esc_html($title),
            $inner,
            $css
        );
    }

    /**
     * The ticket background image set in Settings > Web page (the site's own
     * background picture, typically), covering the ticket card, or '' when
     * none is set.
     *
     * A white layer at 80% sits over the image so every line of text, and the
     * VALID / DISABLED colours, read exactly as on a plain card. It is put on
     * the card's body, under the picture header.
     *
     * The URL goes through esc_url() and sits in a double-quoted CSS string
     * written as &quot; inside the style attribute. esc_url() strips double
     * quotes, backslashes and whitespace, so nothing in the URL can end that
     * string, and the entities it emits (&amp;, &#039;) are decoded back by
     * the attribute parser to the real URL before CSS reads it.
     */
    private function watermarkStyle(): string
    {
        $image = trim((string) get_option(self::BACKGROUND_OPTION, ''));

        if ('' === $image) {
            return '';
        }

        return ';background:linear-gradient(rgba(255,255,255,.8),rgba(255,255,255,.8)),'
            . 'url(&quot;' . esc_url($image) . '&quot;) center/cover no-repeat #fff';
    }
}
