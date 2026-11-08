<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * Cloudflare Turnstile: a privacy-friendly CAPTCHA guarding the public
 * email sign-in form against spam and abuse.
 *
 * The form mails a sign-in link to whatever address is typed, so without a
 * gate it is an open relay for sending mail to arbitrary addresses. Turnstile
 * makes each submission prove it came from a browser, not a script.
 *
 * The whole feature is opt-in: with no keys configured it is disabled and
 * verify() waves everything through, so an install that never sets it up is
 * never locked out of its own sign-in form.
 */
final class Turnstile
{
    public const SITE_KEY_OPTION = 'eventcrew_turnstile_site_key';
    public const SECRET_KEY_OPTION = 'eventcrew_turnstile_secret';

    /** The widget field the browser posts back, named by Cloudflare. */
    public const RESPONSE_FIELD = 'cf-turnstile-response';

    /** Cloudflare's widget script; loaderScript() adds it to the page when a form is first used. */
    public const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    /** The global function api.js calls once it has loaded (its onload argument). */
    public const READY_CALLBACK = 'eventcrewTsReady';

    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * Loads api.js only when a visitor starts using a form with a widget in
     * it, not with the page: the script is large, and most visitors never use
     * a form. The first focusin, pointerdown, touchstart or keydown inside a
     * form that holds a .cf-turnstile box adds the script once (explicit
     * render), and when it is ready every .cf-turnstile box that is still
     * empty is drawn from its data- attributes. Any number of forms on one
     * page share the one script.
     *
     * A form sent before its token is there is held: the submit is stopped
     * before the page's own handlers see it, the button is disabled and says
     * "Checking…", and the form is sent again (requestSubmit, so the page's
     * own handlers run as usual) as soon as the token arrives. A token that
     * Cloudflare has handed to getResponse() but not yet written into the
     * form's cf-turnstile-response field is written there first. When the
     * script cannot load, or no token comes in 60 seconds, the form goes on
     * without one and the server (or the page's own script) says why, so the
     * button is never dead.
     *
     * window.eventcrewTs.load(callback) starts the script from other code
     * and calls back once it is ready.
     *
     * The script can be printed inside a block's HTML, where WordPress turns
     * "&" into "&#038;", so it has no "&" and no "<"; the two strings come
     * in JSON-escaped (%URL%, %LABEL%).
     */
    private const LOADER_JS = '(function(){if(window.eventcrewTs){return;}'
        . 'var U=%URL%,L=%LABEL%,on=0,ready=0,fail=0,cbs=[],ev=["focusin","pointerdown","touchstart","keydown"];'
        . 'function draw(){document.querySelectorAll(".cf-turnstile").forEach(function(c){if(!c.firstChild){'
        . 'try{c.eventcrewTsId=turnstile.render(c);}catch(x){}}});}'
        . 'window.eventcrewTsReady=function(){ready=1;draw();var l=cbs;cbs=[];'
        . 'l.forEach(function(f){try{f();}catch(x){}});};'
        . 'function load(cb){if(cb){if(ready){cb();}else{cbs.push(cb);}}if(on){return;}on=1;'
        . 'ev.forEach(function(n){document.removeEventListener(n,wake,true);});'
        . 'var s=document.createElement("script");s.src=U;s.async=true;s.onerror=function(){fail=1;};'
        . 'document.head.appendChild(s);}'
        . 'function box(f){return f.querySelector?f.querySelector(".cf-turnstile"):null;}'
        . 'function wake(e){var f=e.target.closest?e.target.closest("form"):null;if(f){if(box(f)){load();}}}'
        . 'ev.forEach(function(n){document.addEventListener(n,wake,{capture:true,passive:true});});'
        . 'function tok(f){var i=f.querySelector("[name=cf-turnstile-response]"),v=i?i.value:"",c=box(f);'
        . 'if(!v){if(window.turnstile){if(c.firstChild){'
        . 'try{v=turnstile.getResponse(c.eventcrewTsId||c)||"";}catch(x){}if(v){if(i){i.value=v;}}}}}return v;}'
        . 'document.addEventListener("submit",function(e){var f=e.target;'
        . 'if(!box(f)){return;}if(f.eventcrewTsGo){return;}if(tok(f)){return;}'
        . 'e.preventDefault();e.stopImmediatePropagation();if(f.hasAttribute("data-ts-wait")){return;}'
        . 'f.setAttribute("data-ts-wait","1");'
        . 'var b=e.submitter||f.querySelector("[type=submit]"),t=b?b.textContent:"",n=0;'
        . 'if(b){b.disabled=true;b.textContent=L;}load();'
        . '(function poll(){if(!tok(f)){if(!fail){if(300>n++){setTimeout(poll,200);return;}}}'
        . 'f.removeAttribute("data-ts-wait");if(b){b.disabled=false;b.textContent=t;}f.eventcrewTsGo=1;'
        . 'try{if(f.requestSubmit){if(b){f.requestSubmit(b);}else{f.requestSubmit();}}else{f.submit();}}'
        . 'finally{f.eventcrewTsGo=0;}})();},true);'
        . 'window.eventcrewTs={load:load};})();';

    public function __construct(private readonly Logger $logger)
    {
    }

    /**
     * The inline script tag that loads the widget on demand (see LOADER_JS).
     * The same for every visitor, so a cached page is right for all of them.
     * Safe to print more than once: only the first copy runs.
     */
    public static function loaderScript(): string
    {
        $js = strtr(self::LOADER_JS, [
            '%URL%' => self::jsString(self::SCRIPT_URL . '?render=explicit&onload=' . self::READY_CALLBACK),
            '%LABEL%' => self::jsString(__('Checking…', 'eventcrew')),
        ]);

        return '<script>' . $js . '</script>';
    }

    /** A JavaScript string literal with no "&", "<" or ">" in it. */
    private static function jsString(string $text): string
    {
        $slash = chr(92);

        return strtr(
            (string) wp_json_encode($text),
            ['&' => $slash . 'u0026', '<' => $slash . 'u003C', '>' => $slash . 'u003E']
        );
    }

    public function siteKey(): string
    {
        return trim((string) get_option(self::SITE_KEY_OPTION, ''));
    }

    private function secret(): string
    {
        return trim((string) get_option(self::SECRET_KEY_OPTION, ''));
    }

    /**
     * Both keys are needed to gate anything: the site key renders the widget,
     * the secret verifies its token. With either missing the gate is off, so a
     * half-finished setup fails open rather than blocking every sign-in.
     */
    public function isEnabled(): bool
    {
        return '' !== $this->siteKey() && '' !== $this->secret();
    }

    /**
     * True when the submission may proceed. Disabled → always true. Enabled →
     * the token must be present and Cloudflare must confirm it. A network
     * failure talking to Cloudflare fails open (returns true) and is logged:
     * a transient outage on their side should not lock people out of signing
     * in, and the token itself is already single-use and short-lived.
     */
    public function verify(string $token, string $remoteIp = ''): bool
    {
        if (! $this->isEnabled()) {
            return true;
        }

        if ('' === $token) {
            /*
             * Logged, not silent. This is the likeliest refusal of the lot -
             * the form posted before the widget had written anything into its
             * hidden field - and it was the one path that returned false
             * without a word, so an install hitting it showed nothing in
             * Diagnostics at all and looked like the check was never reached.
             * An empty Diagnostics was itself the evidence, and unreadable.
             */
            $this->logger->warning(
                // phpcs:ignore Generic.Files.LineLength.TooLong -- one log line; wrapping it just splits the message.
                'Turnstile refused a submission: the form arrived with no token, so the widget had not solved when it was sent.'
            );

            return false;
        }

        $body = [
            'secret' => $this->secret(),
            'response' => $token,
        ];

        if ('' !== $remoteIp) {
            $body['remoteip'] = $remoteIp;
        }

        $response = wp_remote_post(self::VERIFY_URL, [
            'timeout' => 10,
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            $this->logger->warning('Turnstile verification could not reach Cloudflare: ' . $response->get_error_message());

            return true;
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        if (is_array($decoded) && true === ($decoded['success'] ?? false)) {
            return true;
        }

        /*
         * Cloudflare says why it refused, and until now we threw that away -
         * which left "couldn't verify you're human" as the only evidence of a
         * refusal whose cause was in the response all along. The codes that
         * matter here are operational, not adversarial:
         *
         *   timeout-or-duplicate  the token is stale or already spent. A widget
         *                         solves on page load and its token lasts about
         *                         five minutes, so a page left open while
         *                         somebody reads it produces exactly this - the
         *                         widget still shows a tick, and the token
         *                         behind it is no longer good.
         *   invalid-input-secret  the secret key is wrong; every solve fails.
         *   invalid-input-response  malformed or foreign token.
         *
         * Logged rather than shown: the visitor can do nothing with a code, and
         * the organizer reading Diagnostics can.
         */
        $codes = is_array($decoded) && is_array($decoded['error-codes'] ?? null)
            ? implode(', ', array_map('strval', $decoded['error-codes']))
            : 'no reason given';

        $this->logger->warning('Turnstile refused a submission: ' . $codes);

        return false;
    }
}
