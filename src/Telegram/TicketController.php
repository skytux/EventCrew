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
use EventCrew\Support\Language;
use EventCrew\Support\SignedLink;
use EventCrew\Support\SiteTexts;
use EventCrew\Support\TicketList;
use EventCrew\Support\TicketMail;
use EventCrew\Web\SignupBoardView;
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
    /** The image URL drawn behind the ticket card; '' or unset draws none. */
    public const BACKGROUND_OPTION = 'eventcrew_ticket_background';

    /** Free text shown on every ticket (door code, address, ...); '' shows nothing. */
    public const NOTE_OPTION = 'eventcrew_ticket_note';

    public function __construct(
        private readonly AssignmentRepository $assignments,
        private readonly TaskRepository $tasks,
        private readonly PersonRepository $people,
        private readonly RedemptionRepository $redemptions,
        private readonly TicketBuyerRepository $buyers = new TicketBuyerRepository(),
        private readonly GuestRepository $guests = new GuestRepository(),
        private readonly CheckinRepository $checkins = new CheckinRepository()
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

        $title = __('Your EventCrew ticket', 'eventcrew');

        $band = $ticket['valid']
            ? $this->validBand($ticket['used'] ?? null)
            : '<p class="t-band is-disabled">' . esc_html__('DISABLED', 'eventcrew') . '</p>';

        $body = sprintf(
            '<p class="t-name">%s</p><p class="t-task">%s%s</p>',
            esc_html($ticket['name']),
            esc_html($ticket['role']),
            '' === $ticket['time'] ? '' : ' · ' . esc_html($ticket['time'])
        );

        $watermark = ltrim($this->watermarkStyle(), ';');
        // The event's clean name in the page's language (EventName).
        $event = null === ($ticket['event_id'] ?? null)
            ? $ticket['event']
            : EventName::name((int) $ticket['event_id'], EventName::lang(), $ticket['event']);

        return $this->document(
            $title,
            $this->headBlock($title, $event, $ticket['date'], (string) ($ticket['image'] ?? ''))
            . '<div class="t-body"' . ('' === $watermark ? '' : ' style="' . $watermark . '"') . '>'
            . $body . $band . $this->qrBlock($ticket['qr'] ?? '') . $this->codeBlock($ticket['code'] ?? '')
            . $this->noteBlock() . $this->antiFraudBlock($ticket['issued'])
            . '</div>'
        );
    }

    /**
     * The band of a ticket that is not cancelled: green VALID, or orange with
     * the time once the door has marked it in.
     */
    private function validBand(?string $used): string
    {
        if (null === $used) {
            return '<p class="t-band is-valid">' . esc_html__('VALID', 'eventcrew') . '</p>';
        }

        return '<p class="t-band is-used">' . esc_html(TicketList::usedText($used)) . '</p>';
    }

    /**
     * The card's header, like an event card on the crew board: the event's
     * picture behind it under a dark layer (faces sit about a third of the way
     * down the posters, hence centre 25%), the event name large, and the date
     * written out. Without a picture the header is plain dark.
     *
     * The picture URL goes through esc_url() inside &quot; quotes, as in
     * watermarkStyle().
     */
    private function headBlock(string $title, string $event, string $date, string $image): string
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
            esc_html(Dates::longDayLabel($date))
        );
    }

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

        return '<div class="t-qr" id="ec-qr">' . $img . '</div>'
            . '<script src="' . esc_url($lib) . '"></script>'
            // phpcs:ignore Generic.Files.LineLength.TooLong -- one self-contained script; wrapping adds stray whitespace.
            . '<script>(function(){var h=document.getElementById("ec-qr");if(!h){return;}if("function"!==typeof window.qrcode){h.parentNode.removeChild(h);return;}var q=window.qrcode(0,"H");q.addData(' . $content . ');q.make();h.insertAdjacentHTML("afterbegin",q.createSvgTag({cellSize:4,margin:8,scalable:true}));})();</script>';
    }

    /** The ticket code written out under the QR code, or '' when there is none. */
    private function codeBlock(string $code): string
    {
        return '' === $code ? '' : '<p class="t-code">' . esc_html($code) . '</p>';
    }

    /**
     * The free text set in Settings > Web page > Ticket note, in the
     * ticket's language (SiteTexts), as plain text with its line breaks
     * kept, or '' when none is set.
     */
    private function noteBlock(): string
    {
        $note = SiteTexts::text('ticket_note');

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
            . '.t{max-width:26em;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;'
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
            // Bright orange with dark text: 7:1 contrast, where white text on
            // an orange this light would not be readable.
            . '.t-band.is-used{background:#ff8c1a;color:#1d1d1f}'
            . '.t-qr{position:relative;width:15em;max-width:100%;margin:0 auto 1em}'
            . '.t-qr svg{display:block;width:100%;height:auto}'
            . '.t-qr-logo{display:none;position:absolute;left:50%;top:50%;width:20%;height:20%;'
            . 'transform:translate(-50%,-50%);object-fit:contain;background:#fff;padding:3%;border-radius:8px}'
            . '.t-qr svg+.t-qr-logo{display:block}'
            . '.t-code{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:1.4em;font-weight:700;'
            . 'letter-spacing:.08em;margin:-.4em 0 1em}'
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
