<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Support\Dates;
use EventCrew\Support\DemoTicket;
use EventCrew\Support\Language;
use EventCrew\Telegram\TicketController;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The demo ticket and its one-row sales desk (DemoTicket), as REST routes
 * like the real ticket page, so both open on a phone with no login.
 *
 * The ticket is the real ticket page (TicketController::renderPage()) with
 * a made-up holder, and it waits on state() the same way the real one waits
 * on the check-ins table. The desk is drawn in the door app's look and
 * posts its tick to mark(). Neither reads or writes anything but the demo's
 * own option.
 */
final class DemoController
{
    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    public function __construct(
        private readonly TicketController $tickets,
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
        $routes = [
            [DemoTicket::TICKET_ROUTE, 'GET', 'ticketPage'],
            [DemoTicket::STATE_ROUTE, 'GET', 'state'],
            [DemoTicket::DESK_ROUTE, 'GET', 'deskPage'],
            [DemoTicket::DESK_ROUTE, 'POST', 'mark'],
        ];

        foreach ($routes as [$route, $method, $callback]) {
            register_rest_route('eventcrew/v1', $route, [
                'methods' => $method,
                'callback' => [$this, $callback],
                // The signed demo link is the only key (DemoTicket).
                'permission_callback' => '__return_true',
            ]);
        }
    }

    public function ticketPage(WP_REST_Request $request): void
    {
        $token = (string) $request->get_param('token');

        $this->send(
            Language::run($this->language(), fn (): string => $this->ticketHtml($token)),
            null === DemoTicket::forToken(DemoTicket::TICKET_PURPOSE, $token) ? 404 : 200
        );
    }

    /** The demo ticket page for $token: the real ticket's look, or its "not found" page. */
    public function ticketHtml(string $token): string
    {
        $demo = DemoTicket::forToken(DemoTicket::TICKET_PURPOSE, $token);

        if (null === $demo) {
            return $this->tickets->renderPage(null);
        }

        return $this->tickets->renderPage([
            'valid' => true,
            'demo' => true,
            'name' => DemoTicket::NAME,
            'event' => __('Demo event', 'eventcrew'),
            'event_id' => null,
            'date' => (string) current_time('Y-m-d'),
            'role' => __('Demo ticket', 'eventcrew'),
            'time' => '',
            'issued' => '',
            'language' => Language::current(),
            'image' => '',
            'short' => DemoTicket::shortCode($demo['id']),
            'used' => '' === $demo['in'] ? null : $demo['in'],
            'poll' => add_query_arg(['token' => $token], rest_url('eventcrew/v1' . DemoTicket::STATE_ROUTE)),
        ]);
    }

    /**
     * The open demo ticket's (or desk's) wait, like TicketController::state():
     * held up to WAIT_SECONDS while the demo's state is still what the page
     * shows ('in', 0 or 1), answering the moment the desk changes it. Answers
     * {in, at, next}, next always 0 (ask again). Either demo link reads it;
     * any other answers 404.
     */
    public function state(WP_REST_Request $request): WP_REST_Response
    {
        $token = (string) $request->get_param('token');
        $demo = DemoTicket::forToken(DemoTicket::TICKET_PURPOSE, $token)
            ?? DemoTicket::forToken(DemoTicket::DESK_PURPOSE, $token);

        if (null === $demo) {
            return self::noStore(new WP_REST_Response(['code' => 'not_found'], 404));
        }

        $shown = $request->get_param('in');
        $limit = (int) ini_get('max_execution_time');
        $wait = null === $shown ? 0 : TicketController::WAIT_SECONDS;

        if ($limit > 0) {
            $wait = max(0, min($wait, $limit - 10));
        }

        $shownIn = '1' === (string) $shown;

        for ($waited = 0; null !== $demo && ('' !== $demo['in']) === $shownIn && $waited < $wait; $waited++) {
            ($this->sleep)(1);
            $demo = DemoTicket::current();
        }

        if (null === $demo) {
            return self::noStore(new WP_REST_Response(['code' => 'not_found'], 404));
        }

        return self::noStore(new WP_REST_Response(self::answer($demo), 200));
    }

    /** The desk's tick: in=1 checks the demo ticket in, in=0 out. Answers {ok, in, at}. */
    public function mark(WP_REST_Request $request): WP_REST_Response
    {
        $token = (string) $request->get_param('token');

        if (! DemoTicket::mark($token, '1' === (string) $request->get_param('in'))) {
            return self::noStore(new WP_REST_Response(['ok' => false, 'code' => 'not_found'], 404));
        }

        $demo = DemoTicket::current();

        return self::noStore(new WP_REST_Response(
            ['ok' => true] + (null === $demo ? ['in' => false, 'at' => '', 'next' => 0] : self::answer($demo)),
            200
        ));
    }

    public function deskPage(WP_REST_Request $request): void
    {
        $token = (string) $request->get_param('token');

        $this->send(
            Language::run($this->language(), fn (): string => $this->deskHtml($token)),
            null === DemoTicket::forToken(DemoTicket::DESK_PURPOSE, $token) ? 404 : 200
        );
    }

    /**
     * The demo desk: the door app's look, one row (name, short code, tick),
     * and a line saying it is a demo. The tick posts to mark(); the row also
     * follows a tick from another device through state().
     */
    public function deskHtml(string $token): string
    {
        $demo = DemoTicket::forToken(DemoTicket::DESK_PURPOSE, $token);
        $title = __('Demo sales desk', 'eventcrew');

        if (null === $demo) {
            return $this->deskDocument($title, '<main><p>' . esc_html__('This demo has ended.', 'eventcrew') . '</p></main>');
        }

        $endpoint = add_query_arg(['token' => $token], rest_url('eventcrew/v1' . DemoTicket::DESK_ROUTE));
        $config = [
            'mark' => $endpoint,
            'poll' => add_query_arg(['token' => $token], rest_url('eventcrew/v1' . DemoTicket::STATE_ROUTE)),
            /* translators: %s: the time the door checked the ticket in, like 21:05 */
            'inAt' => __('Checked in at %s', 'eventcrew'),
            'notIn' => __('Not in yet', 'eventcrew'),
        ];
        $answer = self::answer($demo);
        $in = $answer['in'];
        $tick = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"'
            . ' stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7"/></svg>';

        $inner = '<header><h1>' . esc_html($title) . '</h1>'
            . '<div class="ec-day">' . esc_html(Dates::longDayLabel((string) current_time('Y-m-d'))) . '</div></header>'
            . '<main><p class="ec-msg">'
            . esc_html__('Tick the row and the demo ticket turns gold by itself. Nothing here is in the real door list.', 'eventcrew')
            . '</p><ul><li id="ec-row" class="' . ($in ? 'is-in' : '') . '"><div class="ec-who"><div class="ec-name">'
            . esc_html(DemoTicket::NAME) . '<span class="ec-short">' . esc_html(DemoTicket::shortCode($demo['id']))
            . '</span></div><div class="ec-detail" id="ec-detail">'
            . esc_html($in ? sprintf($config['inAt'], $answer['at']) : $config['notIn']) . '</div></div>'
            . '<button type="button" id="ec-toggle" class="ec-toggle' . ($in ? ' is-in' : '') . '" aria-pressed="'
            . ($in ? 'true' : 'false') . '" aria-label="' . esc_attr__('Check in', 'eventcrew') . '">' . $tick
            . '</button></li></ul></main>';

        $json = (string) wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
        // phpcs:ignore Generic.Files.LineLength.TooLong -- one self-contained script; wrapping adds stray whitespace.
        $script = '<script>(function(){var c=' . $json . ';var row=document.getElementById("ec-row"),btn=document.getElementById("ec-toggle"),detail=document.getElementById("ec-detail");var isIn=-1!==row.className.indexOf("is-in"),busy=false,timer=null;function show(d){isIn=!!d.in;row.className=isIn?"is-in":"";btn.className="ec-toggle"+(isIn?" is-in":"");btn.setAttribute("aria-pressed",isIn?"true":"false");detail.textContent=isIn?c.inAt.replace("%s",d.at||""):c.notIn;}btn.addEventListener("click",function(){btn.disabled=true;btn.classList.add("is-busy");var f=new FormData();f.append("in",isIn?"0":"1");fetch(c.mark,{method:"POST",body:f,cache:"no-store",credentials:"same-origin"}).then(function(r){return r.json();}).then(function(d){if(d&&d.ok){show(d);}}).catch(function(){}).then(function(){btn.disabled=false;btn.classList.remove("is-busy");});});function later(ms){if(timer){clearTimeout(timer);}timer=setTimeout(ask,ms);}function ask(){timer=null;if(busy||document.hidden){return;}busy=true;fetch(c.poll+"&in="+(isIn?1:0)+"&_="+Date.now(),{cache:"no-store",credentials:"same-origin"}).then(function(r){if(!r.ok){throw new Error(String(r.status));}return r.json();}).then(function(d){busy=false;if(!btn.disabled){show(d);}later(1000);},function(){busy=false;later(5000);});}document.addEventListener("visibilitychange",function(){if(!document.hidden&&!busy){later(0);}});ask();})();</script>';

        return $this->deskDocument($title, $inner . $script);
    }

    /**
     * {in, at, next} of $demo, as state() answers it.
     *
     * @param array{id: int, expires: int, in: string} $demo
     * @return array{in: bool, at: string, next: int}
     */
    private static function answer(array $demo): array
    {
        return [
            'in' => '' !== $demo['in'],
            'at' => '' === $demo['in'] ? '' : Dates::wallClock($demo['in'], 'H:i'),
            'next' => 0,
        ];
    }

    /** The desk page around $inner, in the door app's colours and row style. */
    private function deskDocument(string $title, string $inner): string
    {
        $css = ':root{--fg:#1d1d1f;--muted:#6e6e73;--bg:#fff;--card:#f5f5f7;--line:#d2d2d7;--in:#1a7f37}'
            . '@media (prefers-color-scheme:dark){:root{--fg:#f5f5f7;--muted:#a1a1a6;--bg:#111;--card:#1c1c1e;'
            . '--line:#38383a;--in:#4ac26b}}'
            . '*{box-sizing:border-box}'
            . 'body{margin:0;font:16px/1.4 system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--fg);background:var(--bg)}'
            . 'header{border-bottom:1px solid var(--line);padding:6px 16px 8px}'
            . 'h1{font-size:14px;font-weight:600;color:var(--muted);margin:0}'
            . '.ec-day{font-size:20px;font-weight:700}'
            . 'main{padding:8px 16px 40px;max-width:40em}'
            . 'ul{list-style:none;margin:0;padding:0}'
            . 'li{display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid var(--line)}'
            . 'li.is-in .ec-name{color:var(--in)}'
            . '.ec-who{flex:1;min-width:0}.ec-name{font-weight:600;overflow-wrap:anywhere}'
            . '.ec-detail{font-size:13px;color:var(--muted)}'
            . '.ec-short{display:inline-block;margin-left:6px;padding:0 6px;border:1px solid var(--line);'
            . 'border-radius:6px;font:600 13px/1.5 ui-monospace,Menlo,Consolas,monospace;letter-spacing:.12em;'
            . 'color:var(--fg);vertical-align:1px}'
            . 'button{font:inherit;border:1px solid var(--line);background:var(--card);color:var(--fg);'
            . 'border-radius:10px;min-height:44px;min-width:44px;padding:0 12px;cursor:pointer}'
            . '.ec-toggle{width:48px;padding:0;color:var(--muted)}'
            . '.ec-toggle svg{display:block;width:24px;height:24px;margin:auto}'
            . '.ec-toggle.is-in{background:var(--in);border-color:var(--in);color:var(--bg)}'
            . 'button.is-busy{opacity:.5}'
            . '.ec-msg{margin:8px 0;padding:8px 12px;border-radius:10px;background:var(--card);font-size:14px}';

        return sprintf(
            // phpcs:ignore Generic.Files.LineLength.TooLong -- one literal HTML document; wrapping it just adds stray whitespace.
            '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>%1$s</title><style>%3$s</style></head><body>%2$s</body></html>',
            esc_html($title),
            $inner,
            $css
        );
    }

    /** The language the browser asks for, as on an emailed ticket. */
    private function language(): ?string
    {
        $accept = isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])
            ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_ACCEPT_LANGUAGE']))
            : null;

        return Language::fromBrowser($accept);
    }

    private function send(string $html, int $status): never
    {
        status_header($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow', true);
        // Built from literal markup and escaped values in ticketHtml() and deskHtml().
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $html;
        exit;
    }

    private static function noStore(WP_REST_Response $response): WP_REST_Response
    {
        $response->header('Cache-Control', 'no-store, max-age=0');
        $response->header('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
