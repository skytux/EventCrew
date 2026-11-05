<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Repositories\NewsletterSubscriberRepository;
use EventCrew\Support\HybridPolicy;
use EventCrew\Support\Language;
use EventCrew\Support\Mailer;
use EventCrew\Support\Newsletter;
use EventCrew\Support\NewsletterConfirmMail;
use EventCrew\Support\PrivacyPolicy;
use EventCrew\Support\SignedLink;
use EventCrew\Support\Turnstile;
use WP_REST_Request;

/**
 * The public side of the newsletter.
 *
 * - The [eventcrew_newsletter] shortcode: a form with one email field. It
 *   posts to admin-ajax.php (JOIN_ACTION). A hidden field that people do not
 *   see (HONEYPOT) catches simple bots, and Cloudflare Turnstile is used when
 *   it is set up in Settings. There is no nonce: the form is on a public page
 *   that a page cache may serve for days, and a stale nonce would refuse
 *   every real sign-up.
 * - A sign-up is a pending row and a confirmation email. Only the signed link
 *   in that email makes the address a subscriber (double opt-in). The answer
 *   on the page is the same whether or not the address was already known, so
 *   the form cannot be used to find out who subscribes.
 * - After the address is saved, the form asks, optionally, what to call the
 *   person (the second step, NAME_ACTION). The first step hands back a signed
 *   token that expires in NAME_TOKEN_SECONDS and names the one row that this
 *   submission wrote, so the second step can set the name of that row and of
 *   no other. When the submission wrote nothing (the address was already
 *   confirmed, or its sign-up is less than an hour old, or a bot filled the
 *   honeypot), the token names no row: the page looks the same, and the name
 *   goes nowhere. The language the browser prefers (Accept-Language) is
 *   stored with the sign-up.
 * - The REST route Newsletter::ROUTE answers the signed links: confirm (a
 *   GET shows a button, the POST confirms), unsubscribe (a GET shows a
 *   button, the POST unsubscribes; a POST straight to the link is also the
 *   one-click unsubscribe of the List-Unsubscribe-Post header, RFC 8058), so
 *   a mail scanner opening links changes nothing, and stop (an
 *   organizer's link from the preview email: a GET shows a button, the POST
 *   stops the newsletter, so a mail client opening links in advance cannot
 *   stop it), and manage (the footer link of every announcement: a GET shows
 *   the subscriber's page, where a POST changes their name and language, or
 *   leaves the announcement).
 */
final class NewsletterController
{
    public const SHORTCODE = 'eventcrew_newsletter';

    /** The block with the same form as the shortcode. */
    public const BLOCK = 'eventcrew/newsletter';

    public const JOIN_ACTION = 'eventcrew_newsletter_join';

    /** The field people do not see; a bot that fills it in is ignored. */
    public const HONEYPOT = 'website';

    /**
     * The address field's name. Not plain "email": Firefox matched that name to
     * a saved street address and filled it in. autocomplete="email" still
     * offers the reader's saved email addresses.
     */
    public const EMAIL_FIELD = 'eventcrew_email';

    /** The query argument that carries the form's result back to the page. */
    public const RESULT_ARG = 'eventcrew_newsletter';

    public const RESULT_SENT = 'sent';
    public const RESULT_INVALID = 'invalid';
    public const RESULT_CAPTCHA = 'captcha';

    /** After the optional second step (the name) was answered. */
    public const RESULT_NAMED = 'named';

    /** After the subscribe button in a thank-you email (FollowupMail::joinClick()). */
    public const RESULT_JOINED = 'joined';

    /** The second step's form action, field, and the query argument of its token. */
    public const NAME_ACTION = 'eventcrew_newsletter_name';
    public const NAME_FIELD = 'eventcrew_name';
    public const NAME_TOKEN_ARG = 'eventcrew_nl_name';

    /** The signed-token purpose of the second step. */
    public const PURPOSE_NAME = 'newsletter_name';

    /** How long the second step's token is good for. */
    public const NAME_TOKEN_SECONDS = 30 * MINUTE_IN_SECONDS;

    /** The id of the full form, and of the bar; the answer comes back to one of them. */
    public const FORM_ANCHOR = 'eventcrew-newsletter';
    public const BAR_ANCHOR = 'eventcrew-nl-bar';

    /** Posted by the page's script: answer with JSON instead of a redirect. */
    public const AJAX_FIELD = 'eventcrew_ajax';

    /**
     * Sends the forms without a new page. The script posts the form to the
     * same address with AJAX_FIELD and the page's lang, and puts the answer's
     * html in place of the box's content (or the bar's, for a form whose
     * anchor is the bar). A new Cloudflare check in that html is started with
     * turnstile.render(). The answer's text gets the focus (without scrolling)
     * so a screen reader reads it, and the box scrolls into view only when
     * its top is off screen. The box (or bar) then gets the event
     * eventcrew-newsletter-answer, detail.result the answer.
     *
     * "Skip" in the second step removes that form and keeps the answer above it.
     * Without fetch the forms post as before. When the request fails, the form
     * posts as before; when the answer cannot be placed, the page goes to the
     * address the plain form would have gone to.
     *
     * The script is printed inside the block's HTML, and WordPress changes "&"
     * there into "&#038;" (after a "<" it reads as a tag). So the script has
     * no "&" and no "<" in it.
     */
    private const AJAX_JS = '(function(){if(window.eventcrewNlAjax){return;}window.eventcrewNlAjax=1;'
        . 'if(!window.fetch||!window.FormData||!window.DOMParser){return;}'
        . 'var A=["eventcrew_newsletter_join","eventcrew_newsletter_name"],'
        . 'still=window.matchMedia?matchMedia("(prefers-reduced-motion: reduce)").matches:false;'
        . 'function widgets(el){if(!window.turnstile){return;}'
        . 'el.querySelectorAll(".cf-turnstile").forEach(function(c){if(!c.firstChild){'
        . 'try{turnstile.render(c);}catch(e){}}});}'
        . 'function settle(to,result){widgets(to);'
        . 'var m=to.querySelector(".eventcrew-newsletter-message,.eventcrew-nl-bar-message");'
        . 'if(m){m.setAttribute("tabindex","-1");m.focus({preventScroll:true});}'
        . 'if(to.id!=="eventcrew-nl-bar"){var r=to.getBoundingClientRect();'
        . 'if(0>r.top||r.top>window.innerHeight){to.scrollIntoView({behavior:still?"auto":"smooth",block:"start"});}}'
        . 'to.dispatchEvent(new CustomEvent("eventcrew-newsletter-answer",{bubbles:true,detail:{result:result}}));}'
        . 'function place(where,d){var doc=new DOMParser().parseFromString(String(d.html||""),"text/html"),to,from;'
        . 'if(where==="eventcrew-nl-bar"){to=document.getElementById("eventcrew-nl-bar");from=doc.body;}'
        . 'else{to=document.querySelector(".eventcrew-newsletter");from=doc.querySelector(".eventcrew-newsletter");}'
        . 'if(!to||!from){return false;}to.innerHTML=from.innerHTML;settle(to,d.result);return true;}'
        . 'document.addEventListener("submit",function(e){var f=e.target;'
        . 'if(e.defaultPrevented||!f.closest||!f.closest(".eventcrew-newsletter,#eventcrew-nl-bar")){return;}'
        . 'var a=f.querySelector("input[name=action]");if(!a||-1===A.indexOf(a.value)){return;}'
        . 'e.preventDefault();if(f.getAttribute("aria-busy")){return;}f.setAttribute("aria-busy","true");'
        . 'var bt=f.querySelector("button[type=submit]");if(bt){bt.disabled=true;}'
        . 'var fd=new FormData(f);fd.append("eventcrew_ajax","1");'
        . 'fd.append("lang",document.documentElement.lang||"");'
        . 'var an=f.querySelector("input[name=anchor]"),where=an?an.value:"eventcrew-newsletter";'
        . 'fetch(f.getAttribute("action"),{method:"POST",body:fd,credentials:"same-origin"})'
        . '.then(function(r){if(!r.ok){throw new Error("http");}return r.json();})'
        . '.then(function(j){if(!j||!j.success||!j.data){throw new Error("json");}'
        . 'if(!place(where,j.data)){if(j.data.url){location.href=j.data.url;}}})'
        . '.catch(function(){f.removeAttribute("aria-busy");if(bt){bt.disabled=false;}'
        . 'HTMLFormElement.prototype.submit.call(f);});});'
        . 'document.addEventListener("click",function(e){'
        . 'var s=e.target.closest?e.target.closest(".eventcrew-nl-skip"):null,f=s?s.closest("form"):null;'
        . 'if(!f||!f.closest(".eventcrew-newsletter,#eventcrew-nl-bar")){return;}e.preventDefault();'
        . 'var to=f.closest(".eventcrew-newsletter,#eventcrew-nl-bar");f.remove();settle(to,"sent");});})();';

    /** Days the bar stays away after the visitor closes it. */
    public const CLOSE_DAYS = 30;

    /** Marks an address that got the already-subscribed email within the hour. */
    private const ALREADY_KEY_PREFIX = 'eventcrew_nl_already_';

    /** A pending sign-up gets a new confirmation email only after this long. */
    private const RESEND_AFTER_SECONDS = HOUR_IN_SECONDS;

    private const BAR_CSS = '.eventcrew-nl-bar{position:fixed;left:0;right:0;bottom:0;z-index:9999;'
        . 'background:var(--wp--preset--color--contrast,#111);color:var(--wp--preset--color--base,#fff);'
        . 'padding:8px 52px 8px 12px;box-shadow:0 -2px 12px rgba(0,0,0,.25);font-size:14px;line-height:1.3;'
        . 'transition:opacity .25s ease,transform .25s ease}'
        . '.eventcrew-nl-bar-check{flex-basis:100%;display:flex;justify-content:center}'
        . '.eventcrew-nl-bar-check:empty{display:none}'
        . '.eventcrew-nl-bar.has-image{background-color:#111;background-size:cover;background-position:center;'
        . 'color:#fff}'
        . '.eventcrew-nl-bar.is-out{opacity:0;transform:translateY(10px);pointer-events:none}'
        . '.eventcrew-nl-bar[hidden]{display:none}'
        . '@media (prefers-reduced-motion:reduce){.eventcrew-nl-bar{transition:none}}'
        . '.eventcrew-nl-bar form{display:flex;flex-wrap:nowrap;align-items:center;justify-content:center;gap:8px;'
        . 'margin:0}'
        . '.eventcrew-nl-bar-label{white-space:nowrap}'
        . '.eventcrew-nl-bar input[type=email],.eventcrew-nl-bar input[type=text]'
        . '{flex:1 1 200px;max-width:320px;min-width:0;min-height:40px;'
        . 'box-sizing:border-box;padding:6px 14px;font-size:16px;border-radius:999px}'
        . '.eventcrew-nl-bar-info{flex:none;display:inline-flex;align-items:center;justify-content:center;'
        . 'width:40px;height:40px;color:inherit;text-decoration:none}'
        . '.eventcrew-nl-bar-info span{display:inline-flex;align-items:center;justify-content:center;width:24px;'
        . 'height:24px;border:1.5px solid currentColor;border-radius:50%;font:italic 600 15px/1 Georgia,serif}'
        . '.eventcrew-nl-bar .cf-turnstile{position:absolute;bottom:100%;left:50%;transform:translateX(-50%)}'
        . '.eventcrew-nl-bar-message{margin:0 0 4px;text-align:center}'
        . '.eventcrew-nl-top{margin:0 0 8px;text-align:center;font-size:14px}'
        . '.eventcrew-nl-bar-close{position:absolute;top:50%;right:4px;transform:translateY(-50%);background:none;'
        . 'border:0;color:inherit;font-size:22px;line-height:1;cursor:pointer;width:44px;height:44px;padding:0}'
        . '@media (max-width:600px){.eventcrew-nl-bar-label{display:none}}';

    /**
     * The secondary (outline) button both forms use, and the full form's
     * layout: centred, a wide field, and a small faint note.
     */
    private const FORM_CSS = '.eventcrew-nl-button{display:inline-flex;align-items:center;justify-content:center;'
        . 'min-height:40px;padding:6px 22px;background:transparent;color:inherit;border:1.5px solid currentColor;'
        . 'border-radius:999px;font:inherit;font-size:14px;letter-spacing:.1em;text-transform:uppercase;'
        . 'white-space:nowrap;cursor:pointer;transition:background-color .15s}'
        . '.eventcrew-nl-button:hover,.eventcrew-nl-button:focus-visible{background:rgba(255,255,255,.15)}'
        . '.eventcrew-newsletter{text-align:center}'
        . '.eventcrew-newsletter label{display:block;margin-bottom:6px}'
        . '.eventcrew-nl-skip{display:inline-block;margin-left:12px;color:inherit;font-size:14px}'
        . '.eventcrew-newsletter input[type=email],.eventcrew-newsletter input[type=text]'
        . '{display:block;width:100%;max-width:22em;min-height:40px;'
        . 'margin:0 auto;box-sizing:border-box;padding:6px 14px;font-size:16px;border-radius:999px}'
        . '.eventcrew-newsletter .cf-turnstile{display:flex;justify-content:center}'
        . '.eventcrew-newsletter .eventcrew-muted{font-size:13px;line-height:1.4;opacity:.7}';

    /** Set when FORM_CSS was printed on this page. */
    private bool $formCssAdded = false;

    /**
     * Shows the bar after the visitor's first interaction with the page (a
     * scroll, wheel, touch, click or key press), unless it was closed in the
     * last CLOSE_DAYS days. An answer to a sign-up from the bar shows at once
     * and in any case. It hides the bar while a full
     * form is on screen, and keeps room for it at the bottom of the page.
     * It fades in and out (the class is-out), and gets `hidden` when the fade
     * out ends; with reduced motion it switches at once. While focus is in the
     * bar it stays shown.
     *
     * The on-screen keyboard is handled by the viewport meta (see
     * resizingViewport()): the keyboard shrinks the layout viewport, so
     * bottom:0 is above it. lift() is the fallback for a browser that ignores
     * that key and draws the keyboard over the page (Safari on iOS): it moves
     * the bar up only when the visual viewport ends more than 100px above the
     * layout viewport, so a browser toolbar that slides in or out is ignored.
     *
     * When the page also has the full form, the bar has no Cloudflare check of
     * its own. Subscribe in the bar takes the full form's check token, starts
     * that check again for later, and sends the bar's own form with the bar as
     * the place for the answer: the page does not scroll, and the answer and
     * the second step show in the bar. When the full form has no token yet,
     * the bar starts a check of its own in the bar (it shows only when
     * Cloudflare wants a click, and then where the visitor already is) and
     * sends with whichever token comes first, or after 2 minutes, so the
     * server answers a check that never finished. Only when the page cannot
     * send without a reload does the bar do as before: it copies the address
     * into the full form, scrolls to it, and submits it when its check has a
     * token.
     */
    private const BAR_JS = '(function(){var b=document.getElementById("eventcrew-nl-bar");if(!b){return;}'
        . 'var K="eventcrew_nl_bar_closed",d=+b.getAttribute("data-close-days")||30,closed=false;'
        . 'try{var t=+localStorage.getItem(K);closed=t>0&&Date.now()-t<d*864e5;}catch(e){}'
        . 'if(closed&&!b.hasAttribute("data-message")){return;}'
        . 'var forms=document.querySelectorAll(".eventcrew-newsletter"),inView=[],off=false,tm=0,'
        . 'wait=!b.hasAttribute("data-message"),ev=["scroll","wheel","touchstart","pointerdown","keydown"],'
        . 'still=!!(window.matchMedia&&matchMedia("(prefers-reduced-motion: reduce)").matches);'
        . 'function go(){if(!wait){return;}wait=false;'
        . 'ev.forEach(function(n){window.removeEventListener(n,go,true);});show();}'
        . 'if(wait){ev.forEach(function(n){window.addEventListener(n,go,{capture:true,passive:true});});}'
        . 'function show(){var hide=off||wait||(inView.length>0&&!b.contains(document.activeElement));'
        . 'clearTimeout(tm);'
        . 'if(hide){b.classList.add("is-out");if(still){b.hidden=true;}'
        . 'else if(!b.hidden){tm=setTimeout(function(){b.hidden=true;},250);}}'
        . 'else{if(b.hidden){b.classList.add("is-out");b.hidden=false;void b.offsetWidth;}'
        . 'b.classList.remove("is-out");}'
        . 'document.body.style.paddingBottom=hide?"":b.offsetHeight+"px";}'
        . 'b.addEventListener("click",function(e){if(!e.target.closest(".eventcrew-nl-bar-close")){return;}off=true;'
        . 'try{localStorage.setItem(K,String(Date.now()));}catch(e){}show();});'
        . 'document.addEventListener("eventcrew-newsletter-answer",function(e){'
        . 'if(e.target!==b&&!e.target.querySelector("input[type=email]")){off=true;show();}});'
        . 'if(forms.length&&"IntersectionObserver" in window){var io=new IntersectionObserver(function(es){'
        . 'es.forEach(function(e){var i=inView.indexOf(e.target);'
        . 'if(e.isIntersecting&&i<0){inView.push(e.target);}if(!e.isIntersecting&&i>=0){inView.splice(i,1);}});'
        . 'show();});forms.forEach(function(f){io.observe(f);});}'
        . 'var vv=window.visualViewport,raf=0;function lift(){raf=0;var c=vv&&b.contains(document.activeElement)'
        . '?document.documentElement.clientHeight-vv.height-vv.offsetTop:0;'
        . 'b.style.bottom=c>100?Math.round(c)+"px":"";}'
        . 'function queue(){if(!raf){raf=requestAnimationFrame(lift);}}'
        . 'if(vv){vv.addEventListener("resize",queue);vv.addEventListener("scroll",queue);}'
        . 'b.addEventListener("focusin",function(){queue();setTimeout(queue,350);});'
        . 'b.addEventListener("focusout",function(e){if(!b.contains(e.relatedTarget)){b.style.bottom="";show();}});'
        . 'var bf=b.querySelector("form");'
        . 'if(bf&&!bf.querySelector(".cf-turnstile")'
        . '&&document.querySelector(".eventcrew-newsletter form .cf-turnstile")){'
        . 'bf.addEventListener("submit",function(e){'
        . 'if(bf.hasAttribute("data-go")){bf.removeAttribute("data-go");return;}'
        . 'var ff=document.querySelector(".eventcrew-newsletter form");'
        . 'if(!ff||!ff.querySelector("input[type=email]")){return;}e.preventDefault();'
        . 'if(b.contains(document.activeElement)){document.activeElement.blur();}'
        . 'var bt=bf.querySelector("button[type=submit]"),n=0,done=false,own=null,'
        . 'ajax=!!(window.eventcrewNlAjax&&window.fetch&&window.FormData&&window.DOMParser&&bf.requestSubmit),'
        . 'fc=ff.querySelector(".cf-turnstile");'
        . 'if(bt){bt.disabled=true;}'
        . 'function token(){var t=ff.querySelector("[name=cf-turnstile-response]");return t?t.value:"";}'
        . 'function here(v,mine){if(done){return;}done=true;'
        . 'var h=bf.querySelector("input[name=cf-turnstile-response]");'
        . 'if(!h){h=document.createElement("input");h.type="hidden";h.name="cf-turnstile-response";bf.appendChild(h);}'
        . 'h.value=v;var a=bf.querySelector("input[name=anchor]");if(a){a.value="eventcrew-nl-bar";}'
        . 'if(!mine&&fc&&window.turnstile){try{turnstile.reset(fc);}catch(x){}}'
        // A form ignores requestSubmit() while its own submit event is still running (the token from the
        // footer arrives inside it), so the second submit starts one tick later.
        . 'if(bt){bt.disabled=false;}setTimeout(function(){bf.setAttribute("data-go","1");bf.requestSubmit();},0);}'
        . 'function there(){if(bt){bt.disabled=false;}'
        . 'ff.querySelector("input[type=email]").value=bf.querySelector("input[type=email]").value;'
        . 'ff.scrollIntoView({behavior:still?"auto":"smooth",block:"center"});var m=0;'
        . '(function send(){if(token()||m++>600){if(ff.requestSubmit){ff.requestSubmit();}else{ff.submit();}}'
        . 'else{setTimeout(send,200);}})();}'
        . 'if(!ajax||!fc){there();return;}'
        . 'if(token()){here(token(),false);return;}'
        // No token yet: the bar starts its own check (shown in the bar only if Cloudflare wants a click),
        // and takes whichever token comes first; after 2 minutes it sends, and the server answers.
        . 'if(window.turnstile&&fc.getAttribute("data-sitekey")){own=document.createElement("div");'
        . 'own.className="eventcrew-nl-bar-check";bf.appendChild(own);'
        . 'try{turnstile.render(own,{sitekey:fc.getAttribute("data-sitekey"),appearance:"interaction-only",'
        . 'response_field:false,callback:function(v){here(v,true);}});}catch(x){}}'
        . '(function wait(){if(done){return;}if(token()){here(token(),false);}'
        . 'else if(n++>600){here("",true);}else{setTimeout(wait,200);}})();});}'
        . 'window.addEventListener("resize",show);show();})();';

    /** Set when the full form was rendered on this page. */
    private bool $formShown = false;

    /** Set when the Turnstile script tag was printed on this page. */
    private bool $captchaScriptAdded = false;

    /** Set when the full form printed its email field (and so its own check). */
    private bool $formAsksEmail = false;

    /**
     * The answer answerHtml() is rendering, which takes the place of the
     * page's query arguments and address; null while a page renders.
     *
     * @var array{result: string, token: string, page: string}|null
     */
    private ?array $answer = null;

    /** The second step's token from the last join(): see nameToken(). */
    private string $nameToken = '';

    public function __construct(
        private readonly NewsletterSubscriberRepository $subscribers,
        private readonly Mailer $mailer,
        private readonly Newsletter $newsletter,
        private readonly Turnstile $turnstile
    ) {
    }

    public function boot(): void
    {
        add_shortcode(self::SHORTCODE, [$this, 'renderShortcode']);
        add_action('wp_ajax_' . self::JOIN_ACTION, [$this, 'handleJoin']);
        add_action('wp_ajax_nopriv_' . self::JOIN_ACTION, [$this, 'handleJoin']);
        add_action('wp_ajax_' . self::NAME_ACTION, [$this, 'handleName']);
        add_action('wp_ajax_nopriv_' . self::NAME_ACTION, [$this, 'handleName']);
        add_action('rest_api_init', [$this, 'registerRoutes']);
        add_action('wp_footer', [$this, 'renderBar']);
        add_filter('template_include', [$this, 'resizingViewport'], 100);
        add_action('init', [$this, 'registerBlock']);
    }

    /**
     * The same form as a block, for places where a shortcode is not expanded:
     * a shortcode inside a synced pattern in a block theme's footer shows as
     * raw text, a block renders.
     */
    public function registerBlock(): void
    {
        if (! function_exists('register_block_type')) {
            return;
        }

        wp_register_script(
            'eventcrew-newsletter-editor',
            plugins_url('blocks/newsletter/editor.js', EVENTCREW_PLUGIN_FILE),
            ['wp-blocks', 'wp-element', 'wp-server-side-render'],
            EVENTCREW_VERSION,
            true
        );

        register_block_type(self::BLOCK, [
            'api_version' => 2,
            'editor_script' => 'eventcrew-newsletter-editor',
            'render_callback' => fn (): string => $this->renderShortcode(),
        ]);
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            'eventcrew/v1',
            '/newsletter',
            [
                'methods' => ['GET', 'POST'],
                'callback' => [$this, 'handle'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /** The sign-up form. */
    public function renderShortcode(): string
    {
        $message = $this->resultMessage();
        $html = $this->formCss()
            . '<div class="eventcrew-newsletter" id="' . esc_attr(self::FORM_ANCHOR) . '">';

        if ('' !== $message) {
            $html .= '<p class="eventcrew-newsletter-message" role="status">' . esc_html($message) . '</p>'
                . self::backToTop();
        }

        $nameToken = $this->pendingNameToken();

        if ('' !== $nameToken) {
            $this->formShown = true;

            return $html . $this->nameForm($nameToken, self::FORM_ANCHOR, false) . '</div>';
        }

        // After a finished sign-up only the thank-you stays: no form, no footnote.
        // A wrong address or a failed bot check still shows the form to try again.
        if ($this->signupFinished()) {
            $this->formShown = true;

            return $html . '</div>';
        }

        $this->formAsksEmail = true;
        $html .= $this->formStart(self::FORM_ANCHOR)
            . '<p><label for="eventcrew-newsletter-email" class="screen-reader-text">'
            . esc_html__('Your email address', 'eventcrew') . '</label>'
            . '<input type="email" id="eventcrew-newsletter-email" name="' . self::EMAIL_FIELD . '" required'
            . ' autocomplete="email"'
            . ' placeholder="' . esc_attr__('Your email address', 'eventcrew') . '"'
            . ' class="wp-element-input"></p>'
            . self::honeypot()
            . $this->captcha(false)
            . '<p><button type="submit" class="eventcrew-nl-button">'
            . esc_html__('Subscribe', 'eventcrew') . '</button></p>'
            . '<p class="eventcrew-muted">'
            . esc_html__('We send you an email to confirm. You can leave the announcement at any time with the link in every announcement.', 'eventcrew') // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            . '</p></form></div>';

        $this->formShown = true;

        return $html;
    }

    /**
     * The bar fixed to the bottom of the screen, printed by wp_footer: the
     * same sign-up in one line, with a close button.
     *
     * It is printed hidden; its script shows it unless the visitor closed it
     * in the last CLOSE_DAYS days (kept in the visitor's browser), and hides
     * it while a full form (the shortcode, e.g. in the site footer) is on
     * screen. Not printed when the newsletter is off, nor on the crew app and
     * sign-in page (a page with the eventcrew_signup shortcode or block).
     */
    public function renderBar(): void
    {
        if (! $this->barBelongsHere()) {
            return;
        }

        // The answer to a sign-up from the bar shows in the full form when the
        // page has one, and in the bar when it has not.
        $message = $this->formShown ? '' : $this->resultMessage();
        $anchor = $this->formShown ? self::FORM_ANCHOR : self::BAR_ANCHOR;

        $imageUrl = Newsletter::barImageId() > 0
            ? (string) wp_get_attachment_image_url(Newsletter::barImageId(), 'large')
            : '';
        // A dark layer over the image keeps the white text readable.
        $look = '' !== $imageUrl
            ? ' class="eventcrew-nl-bar has-image" style="background-image:'
                . 'linear-gradient(rgba(0,0,0,.55),rgba(0,0,0,.55)),url(' . esc_url($imageUrl) . ')"'
            : ' class="eventcrew-nl-bar"';

        $html = '<div' . $look . ' id="' . esc_attr(self::BAR_ANCHOR) . '" hidden'
            . ' data-close-days="' . esc_attr((string) self::CLOSE_DAYS) . '"'
            . ('' !== $message ? ' data-message="1"' : '') . '>'
            . $this->formCss()
            . '<style>' . self::BAR_CSS . '</style>'
            . $this->barContent($message, $anchor)
            . '</div>'
            . '<script>' . self::BAR_JS . '</script>';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
        echo $html;
    }

    /** What is inside the bar: the answer, then the form (or the second step), then the close button. */
    private function barContent(string $message, string $anchor): string
    {
        $close = '<button type="button" class="eventcrew-nl-bar-close" aria-label="'
            . esc_attr__('Close', 'eventcrew') . '">×</button>';
        $html = '';

        if ('' !== $message) {
            $html .= '<p class="eventcrew-nl-bar-message" role="status">' . esc_html($message) . '</p>'
                . self::backToTop();
        }

        // The second step shows in the bar when the sign-up came from the bar.
        $nameToken = $this->formShown ? '' : $this->pendingNameToken();

        if ('' !== $nameToken) {
            return $html . $this->nameForm($nameToken, self::BAR_ANCHOR, true) . $close;
        }

        // The "i" leads to the full form (with its note) when the page has one.
        $info = $this->formShown
            ? '<a class="eventcrew-nl-bar-info" href="#' . esc_attr(self::FORM_ANCHOR) . '" aria-label="'
                . esc_attr__('About the next events announcement', 'eventcrew')
                . '"><span aria-hidden="true">i</span></a>'
            : '';

        return $html . $this->formStart($anchor)
            . $info
            . '<label for="eventcrew-nl-bar-email" class="eventcrew-nl-bar-label">'
            . esc_html__('Get the coming events by email', 'eventcrew') . '</label>'
            . '<input type="email" id="eventcrew-nl-bar-email" name="' . self::EMAIL_FIELD . '" required'
            . ' autocomplete="email"'
            . ' placeholder="' . esc_attr__('Your email address', 'eventcrew') . '" class="wp-element-input">'
            . self::honeypot()
            . '<button type="submit" class="eventcrew-nl-button">' . esc_html__('Subscribe', 'eventcrew') . '</button>'
            // With the full form's email field on the page, the Cloudflare
            // check is only there: the bar's script hands its address to that
            // form. (While the full form asks for the name, the bar has its own.)
            . ($this->formAsksEmail ? '' : $this->captcha(true))
            . '</form>'
            . $close;
    }

    /** The link under the answer to a sign-up: back to the top of the page. */
    private static function backToTop(): string
    {
        return '<p class="eventcrew-nl-top"><a href="#top">' . esc_html__('Back to top', 'eventcrew') . ' ↑</a></p>';
    }

    /**
     * The template_include filter: on a page with the bar, swaps the viewport
     * meta of a block theme for one with interactive-widget=resizes-content
     * (Chrome for Android 108+, Firefox for Android 132+). The on-screen
     * keyboard then shrinks the layout viewport and the bar at bottom:0 sits
     * above it, with no script. Core adds that meta from locate_block_template(),
     * which has run by now; a classic theme prints its own meta and is left as
     * it is.
     */
    public function resizingViewport(mixed $template): mixed
    {
        if (
            false !== has_action('wp_head', '_block_template_viewport_meta_tag')
            && $this->barBelongsHere()
        ) {
            remove_action('wp_head', '_block_template_viewport_meta_tag', 0);
            add_action('wp_head', [$this, 'printViewportMeta'], 0);
        }

        return $template;
    }

    /** Core's block theme viewport meta, plus the keyboard key. */
    public function printViewportMeta(): void
    {
        echo '<meta name="viewport" content="width=device-width, initial-scale=1,'
            . ' interactive-widget=resizes-content" />' . "\n";
    }

    /** FORM_CSS and AJAX_JS the first time a form is printed on the page, then ''. */
    private function formCss(): string
    {
        if ($this->formCssAdded) {
            return '';
        }

        $this->formCssAdded = true;

        return '<style>' . self::FORM_CSS . '</style><script>' . self::AJAX_JS . '</script>';
    }

    /** Whether the bar is printed on this request. */
    public function barBelongsHere(): bool
    {
        if (! Newsletter::enabled() || is_admin() || is_feed() || is_embed()) {
            return false;
        }

        $post = is_singular() ? get_queried_object() : null;

        if (
            $post instanceof \WP_Post
            && (has_shortcode((string) $post->post_content, 'eventcrew_signup') || has_block('eventcrew/signup', $post))
        ) {
            return false;
        }

        return true;
    }

    /**
     * The answer shown: the one answerHtml() is building, else the page's
     * ?eventcrew_newsletter= value, else ''.
     */
    private function shownResult(): string
    {
        if (null !== $this->answer) {
            return $this->answer['result'];
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks what to show.
        return isset($_GET[self::RESULT_ARG]) ? sanitize_key(wp_unslash($_GET[self::RESULT_ARG])) : '';
    }

    /** True when this page is the answer to a sign-up that went through. */
    private function signupFinished(): bool
    {
        return in_array($this->shownResult(), [self::RESULT_SENT, self::RESULT_NAMED, self::RESULT_JOINED], true);
    }

    /** The message for the ?eventcrew_newsletter= answer, or ''. */
    private function resultMessage(): string
    {
        return match ($this->shownResult()) {
            self::RESULT_SENT => __('Thank you. Check your inbox: the next events announcement starts only after you press the link in the email we sent.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            self::RESULT_INVALID => __('That does not look like an email address. Please check it.', 'eventcrew'),
            self::RESULT_CAPTCHA => __('The check that you are not a bot failed. Please try again.', 'eventcrew'),
            self::RESULT_NAMED => __('Thank you. Now check your inbox: the announcement starts only after you press the link in the email we sent.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            self::RESULT_JOINED => __(
                'You are now subscribed to the next events announcement. Thank you!',
                'eventcrew'
            ),
            default => '',
        };
    }

    /**
     * The second step's token from this page's address, when the page is the
     * answer to a sign-up and the token is one this site signed and is still
     * valid; else ''. Only the signature and the time are checked here: a
     * token that names no row shows the same form.
     */
    private function pendingNameToken(): string
    {
        if (null !== $this->answer) {
            $token = $this->answer['token'];
        } else {
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the signed token is the check.
            $token = isset($_GET[self::NAME_TOKEN_ARG])
                ? (string) preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) wp_unslash($_GET[self::NAME_TOKEN_ARG]))
                : '';
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
        }

        if (self::RESULT_SENT !== $this->shownResult() || '' === $token) {
            return '';
        }

        return null === self::nameTokenRow($token) ? '' : $token;
    }

    /**
     * The optional second step: what to call the person. $inBar is the
     * one-line version in the bar. "Skip" goes back to the plain answer.
     */
    private function nameForm(string $token, string $anchor, bool $inBar): string
    {
        $skip = add_query_arg([self::RESULT_ARG => self::RESULT_SENT], $this->pageUrl()) . '#' . $anchor;
        $question = esc_html__('What should we call you?', 'eventcrew');
        $id = $inBar ? 'eventcrew-nl-bar-name' : 'eventcrew-newsletter-name';
        $input = '<input type="text" id="' . $id . '" name="' . self::NAME_FIELD . '"'
            . ' maxlength="' . NewsletterSubscriberRepository::MAX_NAME_LENGTH . '" autocomplete="given-name"'
            . ' placeholder="' . esc_attr__('Your first name (optional)', 'eventcrew') . '" class="wp-element-input">';
        $button = '<button type="submit" class="eventcrew-nl-button">' . esc_html__('Save', 'eventcrew') . '</button>';
        $skipLink = '<a class="eventcrew-nl-skip" href="' . esc_url($skip) . '">'
            . esc_html__('Skip', 'eventcrew') . '</a>';

        $html = '<form method="post" action="' . esc_url(admin_url('admin-ajax.php')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::NAME_ACTION) . '">'
            . '<input type="hidden" name="redirect_to" value="' . esc_attr($this->pageUrl()) . '">'
            . '<input type="hidden" name="anchor" value="' . esc_attr($anchor) . '">'
            . '<input type="hidden" name="token" value="' . esc_attr($token) . '">';

        if ($inBar) {
            return $html . '<label for="' . $id . '" class="eventcrew-nl-bar-label">' . $question . '</label>'
                . $input . $button . $skipLink . '</form>';
        }

        return $html . '<p><label for="' . $id . '">' . $question . '</label>' . $input . '</p>'
            . '<p>' . $button . $skipLink . '</p>'
            . '<p class="eventcrew-muted">'
            . esc_html__('We use it only to greet you in the announcement. You can change it with the link at the end of every announcement.', 'eventcrew') // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            . '</p></form>';
    }

    /**
     * The second step's token for $rowId: the row this submission wrote, or
     * 0 for none. Signed, and good for NAME_TOKEN_SECONDS.
     */
    public static function signNameToken(int $rowId, ?int $now = null): string
    {
        $expires = ($now ?? time()) + self::NAME_TOKEN_SECONDS;

        return SignedLink::signPayload(self::PURPOSE_NAME, max(0, $rowId) . "\n" . $expires);
    }

    /**
     * The row a second-step token names (0 for none), or null when the token
     * is not one this site signed, or has expired.
     */
    public static function nameTokenRow(string $token, ?int $now = null): ?int
    {
        $payload = SignedLink::verifyPayload(self::PURPOSE_NAME, $token);

        if (null === $payload) {
            return null;
        }

        $parts = explode("\n", $payload);

        if (2 !== count($parts) || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            return null;
        }

        if ((int) $parts[1] < ($now ?? time())) {
            return null;
        }

        return (int) $parts[0];
    }

    /** The second step's token from the last join(), or '' when it gave none. */
    public function nameToken(): string
    {
        return $this->nameToken;
    }

    /** The second step's POST: saves the name and goes back to the page. */
    public function handleName(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- the signed token is the check; see the class comment.
        $token = isset($_POST['token'])
            ? (string) preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) wp_unslash($_POST['token']))
            : '';
        $name = isset($_POST[self::NAME_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::NAME_FIELD])) : '';
        $requested = isset($_POST['redirect_to']) ? (string) wp_unslash($_POST['redirect_to']) : '';
        $anchor = isset($_POST['anchor']) && self::BAR_ANCHOR === wp_unslash($_POST['anchor'])
            ? self::BAR_ANCHOR
            : self::FORM_ANCHOR;
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $result = $this->saveName($token, $name);
        $back = wp_validate_redirect($requested, home_url('/'));
        $url = add_query_arg(self::RESULT_ARG, $result, $back) . '#' . $anchor;

        if (self::wantsJson()) {
            $this->sendAnswer($result, '', $back, $anchor, $url);
        }

        wp_safe_redirect($url);
        exit;
    }

    /**
     * The second step: sets the name of the row the token names, while that
     * row is still the pending sign-up the token was given for. Answers
     * RESULT_NAMED whatever happened (also for a token that names no row),
     * so the answer tells nothing about the address; RESULT_SENT only for a
     * token that is not valid at all, which shows the plain answer again.
     */
    public function saveName(string $token, string $name): string
    {
        $rowId = self::nameTokenRow($token);

        if (null === $rowId) {
            return self::RESULT_SENT;
        }

        if ($rowId > 0 && null !== NewsletterSubscriberRepository::cleanName($name)) {
            $row = $this->subscribers->find($rowId);

            if (null !== $row && NewsletterSubscriberRepository::STATUS_PENDING === $row['status']) {
                $this->subscribers->setName($rowId, $name);
            }
        }

        return self::RESULT_NAMED;
    }

    /** The form tag and its hidden fields; the answer comes back to $anchor on this page. */
    private function formStart(string $anchor): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-ajax.php')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::JOIN_ACTION) . '">'
            . '<input type="hidden" name="redirect_to" value="' . esc_attr($this->pageUrl()) . '">'
            . '<input type="hidden" name="anchor" value="' . esc_attr($anchor) . '">';
    }

    /**
     * Hidden from people (off screen, skipped by the tab key and by screen
     * readers); a bot that fills every field fills this one too.
     */
    private static function honeypot(): string
    {
        return '<p style="position:absolute;left:-9999px" aria-hidden="true"><label>'
            . esc_html__('Leave this field empty', 'eventcrew')
            . ' <input type="text" name="' . esc_attr(self::HONEYPOT) . '" value="" tabindex="-1" autocomplete="off">'
            . '</label></p>';
    }

    /**
     * The Turnstile widget when it is set up; its script is added once per
     * page. It shows only when Cloudflare asks the visitor to act.
     */
    private function captcha(bool $inBar): string
    {
        $siteKey = $this->turnstile->isEnabled() ? $this->turnstile->siteKey() : '';

        if ('' === $siteKey) {
            return '';
        }

        $html = '<div class="cf-turnstile" data-sitekey="' . esc_attr($siteKey) . '" data-theme="auto"'
            . ' data-appearance="interaction-only"></div>';

        if (! $this->captchaScriptAdded) {
            $this->captchaScriptAdded = true;
            // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- the widget script loads only where the form is.
            $html .= '<script src="' . esc_url(Turnstile::SCRIPT_URL) . '" async defer></script>';
        }

        return $html;
    }

    /**
     * The address of this page without an earlier answer on it: where the
     * form sends the visitor back to. get_permalink() would be wrong in a
     * site footer on a list of posts.
     */
    private function pageUrl(): string
    {
        return null !== $this->answer ? $this->answer['page'] : self::currentUrl();
    }

    /** The address of the page being shown, without an earlier answer on it. */
    private static function currentUrl(): string
    {
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '/';

        if ('' === $host) {
            return home_url('/');
        }

        return remove_query_arg([self::RESULT_ARG, self::NAME_TOKEN_ARG], set_url_scheme('//' . $host . $uri));
    }

    /** The form's POST: records the sign-up and goes back to the page. */
    public function handleJoin(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- public form without a nonce; see the class comment.
        $honeypot = isset($_POST[self::HONEYPOT]) ? trim((string) wp_unslash($_POST[self::HONEYPOT])) : '';
        // "email" is the field's old name, still sent by a page cached before the rename.
        $field = isset($_POST[self::EMAIL_FIELD]) ? self::EMAIL_FIELD : 'email';
        $email = isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : '';
        $token = isset($_POST[Turnstile::RESPONSE_FIELD])
            ? sanitize_text_field(wp_unslash($_POST[Turnstile::RESPONSE_FIELD]))
            : '';
        $requested = isset($_POST['redirect_to']) ? (string) wp_unslash($_POST['redirect_to']) : '';
        $anchor = isset($_POST['anchor']) && self::BAR_ANCHOR === wp_unslash($_POST['anchor'])
            ? self::BAR_ANCHOR
            : self::FORM_ANCHOR;
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $result = $this->join($email, $honeypot, $token, $this->clientIp(), Language::preferredInRequest());
        $back = wp_validate_redirect($requested, home_url('/'));
        $args = [self::RESULT_ARG => $result];

        if ('' !== $this->nameToken) {
            $args[self::NAME_TOKEN_ARG] = $this->nameToken;
        }

        $url = add_query_arg($args, $back) . '#' . $anchor;

        if (self::wantsJson()) {
            $this->sendAnswer($result, $this->nameToken, $back, $anchor, $url);
        }

        wp_safe_redirect($url);
        exit;
    }

    /** True when the page's script sent the form and wants the answer as JSON instead of a new page. */
    private static function wantsJson(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- only picks the answer's format.
        return isset($_POST[self::AJAX_FIELD]) && '1' === wp_unslash($_POST[self::AJAX_FIELD]);
    }

    /**
     * The JSON answer for the page's script: the result, the new content of
     * the box or bar, and the address the plain form would have gone to.
     * Rendered in the page's language (the script sends the page's lang).
     */
    private function sendAnswer(string $result, string $token, string $page, string $anchor, string $url): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- only picks the language.
        $lang = isset($_POST['lang']) ? substr(sanitize_key(wp_unslash($_POST['lang'])), 0, 2) : '';
        $html = Language::run(
            Language::normalize($lang),
            fn (): string => $this->answerHtml($result, $token, $page, $anchor)
        );

        wp_send_json_success(['result' => $result, 'html' => $html, 'url' => $url]);
    }

    /**
     * The box (FORM_ANCHOR) or the bar's content (BAR_ANCHOR) as the page at
     * $page would show it with this answer, without the style and script tags
     * the page already has.
     */
    public function answerHtml(string $result, string $token, string $page, string $anchor): string
    {
        $this->answer = ['result' => $result, 'token' => $token, 'page' => $page];
        $this->formCssAdded = true;
        $this->captchaScriptAdded = true;

        try {
            if (self::BAR_ANCHOR === $anchor) {
                $this->formShown = false;
                $this->formAsksEmail = false;

                return $this->barContent($this->resultMessage(), self::BAR_ANCHOR);
            }

            return $this->renderShortcode();
        } finally {
            $this->answer = null;
        }
    }

    /**
     * A sign-up. Returns one of the RESULT_* answers.
     *
     * A filled-in honeypot answers RESULT_SENT and does nothing, so a bot
     * learns nothing. A confirmed address gets no email. A pending address
     * gets a new confirmation email only when the last one is more than an
     * hour old, so the form cannot be used to flood a mailbox.
     *
     * Every RESULT_SENT also leaves a second-step token (nameToken()). It
     * names the row only when this call wrote it; in every other case it
     * names no row, so the next page is the same either way. $language
     * ('fi', 'en' or '') is the browser's preference, stored with a sign-up.
     */
    public function join(
        string $email,
        string $honeypot,
        string $captchaToken,
        string $ip = '',
        string $language = ''
    ): string {
        $this->nameToken = '';

        if ('' !== $honeypot) {
            $this->nameToken = self::signNameToken(0);

            return self::RESULT_SENT;
        }

        if (! $this->turnstile->verify($captchaToken, $ip)) {
            return self::RESULT_CAPTCHA;
        }

        $email = NewsletterSubscriberRepository::normalize($email);

        if ('' === $email || ! is_email($email) || strlen($email) > NewsletterSubscriberRepository::MAX_EMAIL_LENGTH) {
            return self::RESULT_INVALID;
        }

        $existing = $this->subscribers->findByEmail($email);
        $this->nameToken = self::signNameToken(0);

        if (null !== $existing) {
            if (NewsletterSubscriberRepository::STATUS_CONFIRMED === $existing['status']) {
                // The page answers as for a new address; the note goes only to the address itself.
                $this->sendAlreadySubscribed($email, (string) ($existing['language'] ?? ''));

                return self::RESULT_SENT;
            }

            if (
                NewsletterSubscriberRepository::STATUS_PENDING === $existing['status']
                && self::secondsSince($existing['created_at']) < self::RESEND_AFTER_SECONDS
            ) {
                return self::RESULT_SENT;
            }
        }

        $id = $this->subscribers->addPending($email, $language);

        if ($id > 0) {
            $this->nameToken = self::signNameToken($id);
            $this->sendConfirmation($email, $id, $language);
        }

        return self::RESULT_SENT;
    }

    public function handle(WP_REST_Request $request): void
    {
        $page = $this->respond(
            (string) $request->get_param('do'),
            $request->get_method(),
            (string) $request->get_param('token'),
            [
                'act' => (string) $request->get_param('act'),
                'name' => sanitize_text_field((string) $request->get_param('name')),
                'language' => (string) $request->get_param('language'),
            ]
        );

        status_header($page['status']);
        header('Content-Type: text/html; charset=utf-8');
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        // Built from literal markup and esc_html()'d values in document().
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $page['html'];
        exit;
    }

    /**
     * What a signed link does and the page it answers with. $fields are the
     * manage page's posted fields: act ('save' or 'leave'), name, language.
     *
     * @param array{act?: string, name?: string, language?: string} $fields
     * @return array{status: int, html: string}
     */
    public function respond(string $do, string $method, string $token, array $fields = []): array
    {
        $post = 'POST' === strtoupper($method);

        if ('manage' === $do) {
            return $this->manage($post, $token, $fields);
        }

        if ('confirm' === $do) {
            $id = SignedLink::verify(Newsletter::PURPOSE_CONFIRM, $token);

            if (null === $id) {
                return $this->invalid();
            }

            // Opening the link only shows the button: a mail scanner that
            // opens links does not subscribe anybody.
            if (! $post) {
                return $this->page(
                    200,
                    static fn (): string => __(
                        'Press the button to subscribe to the next events announcement.',
                        'eventcrew'
                    ),
                    $this->buttonForm(
                        'confirm',
                        $token,
                        static fn (): string => __('Subscribe', 'eventcrew'),
                        false
                    )
                );
            }

            if ($this->subscribers->confirm($id)) {
                return $this->page(
                    200,
                    static fn (): string => __(
                        'You are now subscribed to the next events announcement. Thank you!',
                        'eventcrew'
                    )
                );
            }

            return $this->invalid();
        }

        if ('unsubscribe' === $do) {
            $id = SignedLink::verify(Newsletter::PURPOSE_UNSUBSCRIBE, $token);

            if (null === $id) {
                return $this->invalid();
            }

            // Opening the link only shows the button. The one-click
            // unsubscribe of RFC 8058 (List-Unsubscribe-Post) is a POST to
            // this same address, so it still works in one step.
            if (! $post) {
                return $this->page(
                    200,
                    static fn (): string => __(
                        'Press the button to leave the announcement.',
                        'eventcrew'
                    ),
                    $this->buttonForm(
                        'unsubscribe',
                        $token,
                        static fn (): string => __('Leave the announcement', 'eventcrew'),
                        true
                    )
                );
            }

            if ($this->subscribers->unsubscribe($id)) {
                return $this->page(
                    200,
                    static fn (): string => __(
                        'You have left the announcement. You will not get it any more.',
                        'eventcrew'
                    )
                );
            }

            return $this->invalid();
        }

        if ('stop' === $do) {
            $planId = SignedLink::verify(Newsletter::PURPOSE_STOP, $token);

            if (null === $planId) {
                return $this->invalid();
            }

            if (! $post) {
                return $this->page(
                    200,
                    static fn (): string => __(
                        'Press the button to stop this announcement. It will not be sent.',
                        'eventcrew'
                    ),
                    $this->stopForm($token)
                );
            }

            $answer = $this->newsletter->stop($planId);

            return $this->page(200, static fn (): string => match ($answer) {
                Newsletter::STOPPED => __('The announcement is stopped. It will not be sent.', 'eventcrew'),
                Newsletter::TOO_LATE => __('Too late: the announcement is already being sent.', 'eventcrew'),
                default => __('This announcement was already stopped or sent.', 'eventcrew'),
            });
        }

        return $this->invalid();
    }

    /**
     * The subscriber's own page, from the footer link of every announcement.
     * A GET only shows it (a mail client that opens links in advance changes
     * nothing); a POST with act=save sets the name and the language, a POST
     * with act=leave unsubscribes.
     *
     * @param array{act?: string, name?: string, language?: string} $fields
     * @return array{status: int, html: string}
     */
    private function manage(bool $post, string $token, array $fields): array
    {
        $id = SignedLink::verify(Newsletter::PURPOSE_MANAGE, $token);
        $row = null === $id ? null : $this->subscribers->find($id);

        if (null === $id || null === $row || NewsletterSubscriberRepository::STATUS_PENDING === $row['status']) {
            return $this->invalid();
        }

        $left = static fn (): string => __(
            'You have left the announcement. You will not get it any more.',
            'eventcrew'
        );

        if (NewsletterSubscriberRepository::STATUS_UNSUBSCRIBED === $row['status']) {
            return $this->page(200, $left);
        }

        $act = $post ? (string) ($fields['act'] ?? '') : '';

        if ('leave' === $act) {
            return $this->subscribers->unsubscribe($id) ? $this->page(200, $left) : $this->invalid();
        }

        $message = static fn (): string => __(
            'Here you can change the name we greet you with and your language, or leave the announcement.',
            'eventcrew'
        );

        if ('save' === $act) {
            $this->subscribers->setName($id, (string) ($fields['name'] ?? ''));
            $language = Language::normalize($fields['language'] ?? '');

            if (null !== $language) {
                $this->subscribers->setLanguage($id, $language);
            }

            $row = $this->subscribers->find($id) ?? $row;
            $message = static fn (): string => __('Saved.', 'eventcrew');
        }

        return $this->page(200, $message, $this->manageForm($token, $row));
    }

    /**
     * The manage page's two forms: the name and language with Save, and the
     * Leave button on its own.
     *
     * @param array{name: ?string, language: string} $row
     */
    private function manageForm(string $token, array $row): string
    {
        $both = static function (callable $words): string {
            $fi = (string) Language::run(Language::FINNISH, $words);
            $en = (string) Language::run(Language::ENGLISH, $words);

            return $fi === $en ? $en : $fi . ' / ' . $en;
        };
        $hidden = static fn (string $act): string => '<input type="hidden" name="do" value="manage">'
            . '<input type="hidden" name="token" value="' . esc_attr($token) . '">'
            . '<input type="hidden" name="act" value="' . esc_attr($act) . '">';
        $field = 'display:block;width:100%;box-sizing:border-box;margin-top:.3em;padding:.5em;font-size:1em';
        $radio = static fn (string $value, string $label): string => '<label style="margin-right:1.2em">'
            . '<input type="radio" name="language" value="' . esc_attr($value) . '"'
            . ($value === $row['language'] ? ' checked' : '') . '> ' . esc_html($label) . '</label>';

        return '<form method="post" style="margin:1.5em 0">' . $hidden('save')
            . '<p><label for="eventcrew-manage-name">'
            . esc_html($both(static fn (): string => __('What should we call you?', 'eventcrew')))
            . '</label><input type="text" id="eventcrew-manage-name" name="name" maxlength="'
            . NewsletterSubscriberRepository::MAX_NAME_LENGTH . '" autocomplete="given-name" value="'
            . esc_attr((string) ($row['name'] ?? '')) . '" style="' . $field . '"></p>'
            . '<fieldset style="border:0;margin:0 0 1em;padding:0"><legend style="padding:0;margin-bottom:.3em">'
            . esc_html($both(static fn (): string => __('Language', 'eventcrew')))
            . '</legend>' . $radio(Language::FINNISH, 'Suomi') . $radio(Language::ENGLISH, 'English')
            . '<p style="margin:.4em 0 0;font-size:.85em;color:#5b6470">'
            . esc_html($both(static fn (): string => __('The announcement comes in the language you choose.', 'eventcrew'))) // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            . '</p></fieldset>'
            . '<button type="submit" style="padding:.6em 1em">'
            . esc_html($both(static fn (): string => __('Save', 'eventcrew'))) . '</button></form>'
            . '<form method="post" style="margin:2em 0 1em;padding-top:1em;border-top:1px solid #ddd">'
            . $hidden('leave')
            . '<button type="submit" style="padding:.6em 1em;color:#b32d2e">'
            . esc_html($both(static fn (): string => __('Leave the announcement', 'eventcrew')))
            . '</button></form>';
    }

    /**
     * Tells an address that is already subscribed so, and whom to write to when
     * the announcements do not arrive: the contact of the privacy settings, or
     * the site's admin email. At most once an hour per address.
     */
    private function sendAlreadySubscribed(string $email, string $language = ''): void
    {
        $key = self::ALREADY_KEY_PREFIX . substr(hash('sha256', $email), 0, 32);

        if (false !== get_transient($key)) {
            return;
        }

        set_transient($key, 1, self::RESEND_AFTER_SECONDS);
        $contact = PrivacyPolicy::contact();
        $contact = '' !== $contact ? $contact : (string) get_option('admin_email', '');
        $site = (string) get_bloginfo('name');
        $this->sendCard(
            $email,
            static fn (): array => NewsletterConfirmMail::alreadyWords($site, $contact),
            'mailto:' . $contact,
            $language
        );
    }

    private function sendConfirmation(string $email, int $id, string $language = ''): void
    {
        $site = (string) get_bloginfo('name');
        $this->sendCard(
            $email,
            static fn (): array => NewsletterConfirmMail::words($site),
            Newsletter::confirmUrl($id),
            $language
        );
    }

    /**
     * One card email in Finnish and English, or in $language alone (the
     * language the browser asked for; the main language when it is not
     * known) when its Hybrid tick is off (HybridPolicy).
     *
     * @param callable(): array{subject: string, heading: string, promise: string, body: string, button: string} $words
     */
    private function sendCard(string $email, callable $words, string $url, string $language = ''): void
    {
        $site = (string) get_bloginfo('name');
        $why = static fn (): string => sprintf(
            /* translators: %s: the site's name */
            __('You get this email because this address was entered in the announcement form on %s.', 'eventcrew'),
            $site
        );
        $languages = HybridPolicy::languagesFor(HybridPolicy::NEWSLETTER_CONFIRM, $language);
        $parts = HybridPolicy::inEach($languages, $words);
        $imageUrl = Newsletter::barImageId() > 0
            ? (string) wp_get_attachment_image_url(Newsletter::barImageId(), 'large')
            : '';

        $this->mailer->toSubscriber(
            $email,
            HybridPolicy::join(HybridPolicy::field($parts, 'subject')),
            NewsletterConfirmMail::html($parts, $url, $imageUrl),
            NewsletterConfirmMail::text($parts, $url),
            HybridPolicy::join(HybridPolicy::inEach($languages, $why)),
            home_url('/')
        );
    }

    /** @return array{status: int, html: string} */
    private function invalid(): array
    {
        return $this->page(404, static fn (): string => __('This link is no longer valid.', 'eventcrew'));
    }

    /**
     * A page with $message in Finnish and in English, and $extra under it.
     *
     * @return array{status: int, html: string}
     */
    private function page(int $status, callable $message, string $extra = ''): array
    {
        $title = (string) Language::run(
            Language::FINNISH,
            static fn (): string => __('Next events announcement', 'eventcrew')
        );
        $titleEn = (string) Language::run(
            Language::ENGLISH,
            static fn (): string => __('Next events announcement', 'eventcrew')
        );
        $fi = (string) Language::run(Language::FINNISH, $message);
        $en = (string) Language::run(Language::ENGLISH, $message);

        $inner = '<p lang="fi">' . esc_html($fi) . '</p>'
            . ($fi === $en ? '' : '<p lang="en">' . esc_html($en) . '</p>')
            . $extra;

        return [
            'status' => $status,
            'html' => sprintf(
                // phpcs:ignore Generic.Files.LineLength.TooLong -- one literal HTML document; wrapping it just adds stray whitespace.
                '<!doctype html><html lang="fi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>%1$s</title></head><body style="font-family:sans-serif;max-width:28em;margin:3em auto;padding:0 1em"><h1 style="font-size:1.2em">%1$s</h1>%2$s</body></html>',
                esc_html($title === $titleEn ? $titleEn : $title . ' / ' . $titleEn),
                $inner
            ),
        ];
    }

    private function stopForm(string $token): string
    {
        return $this->buttonForm(
            'stop',
            $token,
            static fn (): string => __('Do not send this announcement', 'eventcrew'),
            true
        );
    }

    /**
     * One button that POSTs $do with the token back to this address. $words
     * is the label, shown in Finnish and English; $red marks a button that
     * ends something.
     *
     * @param callable(): string $words
     */
    private function buttonForm(string $do, string $token, callable $words, bool $red): string
    {
        $label = (string) Language::run(Language::FINNISH, $words);
        $labelEn = (string) Language::run(Language::ENGLISH, $words);

        return sprintf(
            // phpcs:ignore Generic.Files.LineLength.TooLong -- one literal HTML form; wrapping it just adds stray whitespace.
            '<form method="post" style="margin:1em 0"><input type="hidden" name="do" value="%s"><input type="hidden" name="token" value="%s"><button type="submit" style="padding:.6em 1em%s">%s</button></form>',
            esc_attr($do),
            esc_attr($token),
            $red ? ';color:#b32d2e' : '',
            esc_html($label === $labelEn ? $labelEn : $label . ' / ' . $labelEn)
        );
    }

    private static function secondsSince(string $mysqlDate): int
    {
        $then = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysqlDate);
        $now = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) current_time('mysql'));

        if (false === $then || false === $now) {
            return PHP_INT_MAX;
        }

        return $now->getTimestamp() - $then->getTimestamp();
    }

    /** REMOTE_ADDR only, as in SignupController: Turnstile treats it as advisory. */
    private function clientIp(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification -- read-only, not a state change.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) wp_unslash($_SERVER['REMOTE_ADDR']) : '';

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }
}
