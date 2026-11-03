<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Repositories\NewsletterSubscriberRepository;
use EventCrew\Support\Language;
use EventCrew\Support\Mailer;
use EventCrew\Support\Newsletter;
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
 * - The REST route Newsletter::ROUTE answers the signed links: confirm (a
 *   GET confirms), unsubscribe (a GET or a POST unsubscribes; the POST is the
 *   one-click unsubscribe of the List-Unsubscribe-Post header), and stop (an
 *   organizer's link from the preview email: a GET shows a button, the POST
 *   stops the newsletter, so a mail client opening links in advance cannot
 *   stop it).
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

    /** The id of the full form, and of the bar; the answer comes back to one of them. */
    public const FORM_ANCHOR = 'eventcrew-newsletter';
    public const BAR_ANCHOR = 'eventcrew-nl-bar';

    /** Days the bar stays away after the visitor closes it. */
    public const CLOSE_DAYS = 30;

    /** A pending sign-up gets a new confirmation email only after this long. */
    private const RESEND_AFTER_SECONDS = HOUR_IN_SECONDS;

    private const BAR_CSS = '.eventcrew-nl-bar{position:fixed;left:0;right:0;bottom:0;z-index:9999;'
        . 'background:var(--wp--preset--color--contrast,#111);color:var(--wp--preset--color--base,#fff);'
        . 'padding:8px 52px 8px 12px;box-shadow:0 -2px 12px rgba(0,0,0,.25);font-size:14px;line-height:1.3;'
        . 'transition:opacity .25s ease,transform .25s ease}'
        . '.eventcrew-nl-bar.has-image{background-color:#111;background-size:cover;background-position:center;'
        . 'color:#fff}'
        . '.eventcrew-nl-bar.is-out{opacity:0;transform:translateY(10px);pointer-events:none}'
        . '.eventcrew-nl-bar[hidden]{display:none}'
        . '@media (prefers-reduced-motion:reduce){.eventcrew-nl-bar{transition:none}}'
        . '.eventcrew-nl-bar form{display:flex;flex-wrap:nowrap;align-items:center;justify-content:center;gap:8px;'
        . 'margin:0}'
        . '.eventcrew-nl-bar-label{white-space:nowrap}'
        . '.eventcrew-nl-bar input[type=email]{flex:1 1 200px;max-width:320px;min-width:0;min-height:40px;'
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
        . '.eventcrew-newsletter input[type=email]{display:block;width:100%;max-width:22em;min-height:40px;'
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
     * its own: Subscribe in the bar copies the address into the full form,
     * scrolls to it, and submits it as soon as its check has a token (or
     * after 2 minutes, so the server answers a check that never finished).
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
        . 'b.querySelector(".eventcrew-nl-bar-close").addEventListener("click",function(){off=true;'
        . 'try{localStorage.setItem(K,String(Date.now()));}catch(e){}show();});'
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
        . 'var bf=b.querySelector("form"),ff=document.querySelector(".eventcrew-newsletter form");'
        . 'if(bf&&ff&&!bf.querySelector(".cf-turnstile")&&ff.querySelector(".cf-turnstile")){'
        . 'bf.addEventListener("submit",function(e){e.preventDefault();'
        . 'ff.querySelector("input[type=email]").value=bf.querySelector("input[type=email]").value;'
        . 'if(b.contains(document.activeElement)){document.activeElement.blur();}'
        . 'ff.scrollIntoView({behavior:still?"auto":"smooth",block:"center"});var n=0;'
        . '(function send(){var t=ff.querySelector("[name=cf-turnstile-response]");'
        . 'if((t&&t.value)||n++>600){if(ff.requestSubmit){ff.requestSubmit();}else{ff.submit();}}'
        . 'else{setTimeout(send,200);}})();});}'
        . 'window.addEventListener("resize",show);show();})();';

    /** Set when the full form was rendered on this page. */
    private bool $formShown = false;

    /** Set when the Turnstile script tag was printed on this page. */
    private bool $captchaScriptAdded = false;

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
            . '<style>' . self::BAR_CSS . '</style>';

        if ('' !== $message) {
            $html .= '<p class="eventcrew-nl-bar-message" role="status">' . esc_html($message) . '</p>'
                . self::backToTop();
        }

        // The "i" leads to the full form (with its note) when the page has one.
        $info = $this->formShown
            ? '<a class="eventcrew-nl-bar-info" href="#' . esc_attr(self::FORM_ANCHOR) . '" aria-label="'
                . esc_attr__('About the next events announcement', 'eventcrew')
                . '"><span aria-hidden="true">i</span></a>'
            : '';

        $html .= $this->formStart($anchor)
            . $info
            . '<label for="eventcrew-nl-bar-email" class="eventcrew-nl-bar-label">'
            . esc_html__('Get the coming events by email', 'eventcrew') . '</label>'
            . '<input type="email" id="eventcrew-nl-bar-email" name="' . self::EMAIL_FIELD . '" required'
            . ' autocomplete="email"'
            . ' placeholder="' . esc_attr__('Your email address', 'eventcrew') . '" class="wp-element-input">'
            . self::honeypot()
            . '<button type="submit" class="eventcrew-nl-button">' . esc_html__('Subscribe', 'eventcrew') . '</button>'
            // With the full form on the page, the Cloudflare check is only
            // there: the bar's script hands its address to that form.
            . ($this->formShown ? '' : $this->captcha(true))
            . '</form>'
            . '<button type="button" class="eventcrew-nl-bar-close" aria-label="'
            . esc_attr__('Close', 'eventcrew') . '">×</button>'
            . '</div>'
            . '<script>' . self::BAR_JS . '</script>';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
        echo $html;
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

    /** FORM_CSS the first time a form is printed on the page, then ''. */
    private function formCss(): string
    {
        if ($this->formCssAdded) {
            return '';
        }

        $this->formCssAdded = true;

        return '<style>' . self::FORM_CSS . '</style>';
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

    /** The message for the ?eventcrew_newsletter= answer, or ''. */
    private function resultMessage(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which message to show.
        $result = isset($_GET[self::RESULT_ARG]) ? sanitize_key(wp_unslash($_GET[self::RESULT_ARG])) : '';

        return match ($result) {
            self::RESULT_SENT => __('Thank you. Check your inbox: the next events announcement starts only after you press the link in the email we sent.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            self::RESULT_INVALID => __('That does not look like an email address. Please check it.', 'eventcrew'),
            self::RESULT_CAPTCHA => __('The check that you are not a bot failed. Please try again.', 'eventcrew'),
            default => '',
        };
    }

    /** The form tag and its hidden fields; the answer comes back to $anchor on this page. */
    private function formStart(string $anchor): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-ajax.php')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::JOIN_ACTION) . '">'
            . '<input type="hidden" name="redirect_to" value="' . esc_attr(self::currentUrl()) . '">'
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
    private static function currentUrl(): string
    {
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '/';

        if ('' === $host) {
            return home_url('/');
        }

        return remove_query_arg(self::RESULT_ARG, set_url_scheme('//' . $host . $uri));
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

        $result = $this->join($email, $honeypot, $token, $this->clientIp());
        $back = wp_validate_redirect($requested, home_url('/'));

        wp_safe_redirect(add_query_arg(self::RESULT_ARG, $result, $back) . '#' . $anchor);
        exit;
    }

    /**
     * A sign-up. Returns one of the RESULT_* answers.
     *
     * A filled-in honeypot answers RESULT_SENT and does nothing, so a bot
     * learns nothing. A confirmed address gets no email. A pending address
     * gets a new confirmation email only when the last one is more than an
     * hour old, so the form cannot be used to flood a mailbox.
     */
    public function join(string $email, string $honeypot, string $captchaToken, string $ip = ''): string
    {
        if ('' !== $honeypot) {
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

        if (null !== $existing) {
            if (NewsletterSubscriberRepository::STATUS_CONFIRMED === $existing['status']) {
                return self::RESULT_SENT;
            }

            if (
                NewsletterSubscriberRepository::STATUS_PENDING === $existing['status']
                && self::secondsSince($existing['created_at']) < self::RESEND_AFTER_SECONDS
            ) {
                return self::RESULT_SENT;
            }
        }

        $id = $this->subscribers->addPending($email);

        if ($id > 0) {
            $this->sendConfirmation($email, $id);
        }

        return self::RESULT_SENT;
    }

    public function handle(WP_REST_Request $request): void
    {
        $page = $this->respond(
            (string) $request->get_param('do'),
            $request->get_method(),
            (string) $request->get_param('token')
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
     * What a signed link does and the page it answers with.
     *
     * @return array{status: int, html: string}
     */
    public function respond(string $do, string $method, string $token): array
    {
        $post = 'POST' === strtoupper($method);

        if ('confirm' === $do) {
            $id = SignedLink::verify(Newsletter::PURPOSE_CONFIRM, $token);

            if (null !== $id && $this->subscribers->confirm($id)) {
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

            if (null !== $id && $this->subscribers->unsubscribe($id)) {
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

    private function sendConfirmation(string $email, int $id): void
    {
        $site = (string) get_bloginfo('name');
        $words = static fn (): array => [
            'subject' => __('Confirm your subscription to the next events announcement', 'eventcrew'),
            'body' => sprintf(
                /* translators: %s: the site's name */
                __('Someone, probably you, asked for the next events announcement of %s at this email address. To start getting it, press the button below. If it was not you, you can ignore this email: the address is deleted in 7 days.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                $site
            ),
            'button' => __('Confirm', 'eventcrew'),
        ];
        $fi = Language::run(Language::FINNISH, $words);
        $en = Language::run(Language::ENGLISH, $words);
        $both = static fn (string $key): string => $fi[$key] === $en[$key] ? $en[$key] : $fi[$key] . ' / ' . $en[$key];

        $this->mailer->toUnconfirmedAddress(
            $email,
            $both('subject'),
            $fi['body'] . "\n\n" . $en['body'],
            [['label' => $both('button'), 'url' => Newsletter::confirmUrl($id)]]
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
        $words = static fn (): string => __('Do not send this announcement', 'eventcrew');
        $label = (string) Language::run(Language::FINNISH, $words);
        $labelEn = (string) Language::run(Language::ENGLISH, $words);

        return sprintf(
            // phpcs:ignore Generic.Files.LineLength.TooLong -- one literal HTML form; wrapping it just adds stray whitespace.
            '<form method="post" style="margin:1em 0"><input type="hidden" name="do" value="stop"><input type="hidden" name="token" value="%s"><button type="submit" style="padding:.6em 1em;color:#b32d2e">%s</button></form>',
            esc_attr($token),
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
