<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\EmailBody;
use EventCrew\Support\Mailer;
use EventCrew\Support\NotificationPreferences;
use EventCrew\Support\SignupService;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\Turnstile;
use EventCrew\Support\WebSession;
use EventCrew\Telegram\TicketRedemptionService;

/**
 * The public web front door: a shortcode/block showing the open-task board, with
 * email magic-link sign-in and claim/drop, for people who are not in the
 * Telegram group.
 *
 * Identity stays a verified email - clicking the magic link both signs the
 * person in (a stateless WebSession cookie) and verifies their address, so the
 * same person can move between the web and the bot. Claiming goes through the
 * shared SignupService, so the reputation gate, overlap and capacity rules are
 * exactly the bot's.
 *
 * The actions run over admin-ajax rather than the REST API, because the target
 * host serves a JS challenge on /wp-json that a plain form post cannot pass -
 * the same reason the bot webhook has an admin-ajax fallback door.
 */
final class SignupController
{
    public const LOGIN_ACTION = 'eventcrew_web_login';
    public const CLAIM_ACTION = 'eventcrew_web_claim';
    public const DROP_ACTION = 'eventcrew_web_drop';
    public const LOGOUT_ACTION = 'eventcrew_web_logout';
    public const REDEEM_ACTION = 'eventcrew_web_redeem_ticket';
    public const PREFS_ACTION = 'eventcrew_web_prefs';

    private const LOGIN_PURPOSE = 'web_login';
    private const LOGIN_TTL = 30 * MINUTE_IN_SECONDS;

    /** Composes the board's view model; the presentation half of this class. */
    private readonly SignupBoardView $boardView;

    public function __construct(
        private readonly PersonRepository $people,
        private readonly AuthTokenRepository $tokens,
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly SignupService $signup,
        private readonly StandingCalculator $standing,
        private readonly Mailer $mailer,
        private readonly ClaimNotifier $notifier,
        private readonly Turnstile $turnstile,
        private readonly TicketRedemptionService $tickets
    ) {
        $this->boardView = new SignupBoardView($tasks, $assignments, $standing, $tickets, $turnstile);
    }

    /** The public stylesheet's handle, shared by the block and the shortcode. */
    public const STYLE_HANDLE = 'eventcrew-signup';

    public function boot(): void
    {
        add_shortcode('eventcrew_signup', [$this, 'renderShortcode']);
        add_action('init', [$this, 'registerBlock']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueStyle']);
        add_action('template_redirect', [$this, 'maybeSignInFromEmailLink']);

        foreach ([self::LOGIN_ACTION, self::CLAIM_ACTION, self::DROP_ACTION, self::LOGOUT_ACTION, self::REDEEM_ACTION, self::PREFS_ACTION] as $action) {
            add_action('wp_ajax_' . $action, [$this, 'dispatch']);
            add_action('wp_ajax_nopriv_' . $action, [$this, 'dispatch']);
        }
    }

    public function registerBlock(): void
    {
        if (! function_exists('register_block_type')) {
            return;
        }

        wp_register_script(
            'eventcrew-signup-editor',
            plugins_url('blocks/signup/editor.js', EVENTCREW_PLUGIN_FILE),
            ['wp-blocks', 'wp-element', 'wp-server-side-render'],
            EVENTCREW_VERSION,
            true
        );

        register_block_type('eventcrew/signup', [
            'api_version' => 2,
            'editor_script' => 'eventcrew-signup-editor',
            // WordPress enqueues this itself whenever the block renders, which
            // covers the block path without any page sniffing.
            'style' => self::STYLE_HANDLE,
            'render_callback' => [$this, 'renderShortcode'],
        ]);
    }

    /**
     * Registers the public stylesheet, and enqueues it on a page that visibly
     * uses the widget.
     *
     * Registration is unconditional so the block's 'style' handle above and the
     * late enqueue in renderShortcode() both have something to point at. The
     * eager enqueue is the only path that gets the sheet into <head> rather
     * than the footer, so it is worth the content sniff.
     */
    public function enqueueStyle(): void
    {
        $this->registerStyle();

        $post = get_post();

        if (! $post instanceof \WP_Post) {
            return;
        }

        if (has_shortcode((string) $post->post_content, 'eventcrew_signup') || has_block('eventcrew/signup', $post)) {
            wp_enqueue_style(self::STYLE_HANDLE);
        }
    }

    /**
     * Registers the stylesheet, once, wherever the need is first noticed.
     *
     * Separate from the enqueue because registration is the part that must not
     * be missed: wp_enqueue_style() on an unregistered handle is a silent no-op,
     * so a render reached by a path that never fired wp_enqueue_scripts - an
     * FSE template part, a page builder, the block's REST preview - would ask
     * for a stylesheet that does not exist and get no stylesheet and no error.
     * That is precisely how a page ends up serving the new markup with none of
     * its CSS.
     */
    private function registerStyle(): void
    {
        if (wp_style_is(self::STYLE_HANDLE, 'registered')) {
            return;
        }

        wp_register_style(
            self::STYLE_HANDLE,
            plugins_url('assets/eventcrew.css', EVENTCREW_PLUGIN_FILE),
            [],
            EVENTCREW_VERSION
        );

        $accent = $this->accentStyle();

        if ('' !== $accent) {
            wp_add_inline_style(self::STYLE_HANDLE, $accent);
        }
    }

    /**
     * The crew's accent colour, handed to the stylesheet as a custom property.
     *
     * The same option the installed app and the notification emails read, so
     * all three finally look like one thing. Scoped to the widget rather than
     * :root - a plugin has no business claiming the document's variables - and
     * only emitted when it is actually set, so an install that has never chosen
     * a colour falls through to the theme's own primary in the stylesheet.
     */
    private function accentStyle(): string
    {
        // Mirrors Web\PwaController::COLOR_OPTION; kept literal here for the
        // same reason the Support layer does it.
        $color = sanitize_hex_color((string) get_option('eventcrew_app_theme_color', ''));

        if (! is_string($color) || '' === $color) {
            return '';
        }

        return sprintf(
            '.eventcrew-signup{--eventcrew-accent:%s;--eventcrew-on-accent:%s}',
            $color,
            EmailBody::readableOn($color)
        );
    }

    /**
     * The shortcode/block render: buffers the public template and returns it,
     * since a shortcode must return its markup, never echo it.
     */
    public function renderShortcode(): string
    {
        // Belt and braces for the placements enqueueStyle() cannot see: a
        // widget, a template part, a shortcode nested inside another block.
        // Registering here too is the point - see registerStyle() - because an
        // enqueue of an unregistered handle fails silently. Enqueuing after
        // wp_head has run puts the sheet in the footer rather than losing it,
        // which is worse than <head> but far better than an unstyled widget.
        $this->registerStyle();
        wp_enqueue_style(self::STYLE_HANDLE);

        $view = $this->viewModel();

        ob_start();
        include EVENTCREW_PLUGIN_DIR . 'templates/public/signup.php';

        return (string) ob_get_clean();
    }

    /**
     * Everything the template needs: the board composition (delegated to
     * SignupBoardView, resolved for the session's person) plus the admin-ajax
     * action names this controller owns.
     *
     * @return array<string, mixed>
     */
    public function viewModel(): array
    {
        return $this->boardView->build($this->currentPerson()) + [
            'login_action' => self::LOGIN_ACTION,
            'claim_action' => self::CLAIM_ACTION,
            'drop_action' => self::DROP_ACTION,
            'logout_action' => self::LOGOUT_ACTION,
            'redeem_action' => self::REDEEM_ACTION,
            'prefs_action' => self::PREFS_ACTION,
        ];
    }

    /**
     * POST email → issue a magic link and mail it. Returns a notice code for
     * the redirect. Creating the person if new mirrors the bot's onboarding: an
     * unverified row that the magic link will verify.
     */
    public function loginByEmail(string $email): string
    {
        $email = sanitize_email($email);

        if ('' === $email || ! is_email($email)) {
            return 'bad_email';
        }

        $person = $this->people->findByEmail($email);
        $personId = null !== $person ? $person->id : $this->people->create(['email' => $email]);

        $raw = $this->tokens->issue($personId, self::LOGIN_PURPOSE, self::LOGIN_TTL);

        $this->mailer->toPerson(
            $personId,
            $email,
            __('Your EventCrew sign-in link', 'eventcrew'),
            __(
                "Use the button below to sign in and manage your tasks.\n\nThe link is good for 30 minutes, and works once.",
                'eventcrew'
            ),
            [['label' => __('Sign in', 'eventcrew'), 'url' => $this->loginUrl($raw)]]
        );

        return 'check_email';
    }

    /**
     * The magic-link target: consume the token, verify the email, and return
     * the person id to start a session for (or null when the link is bad).
     */
    public function consumeMagicLink(string $token): ?int
    {
        $personId = $this->tokens->consume($token, self::LOGIN_PURPOSE);

        if (null === $personId) {
            return null;
        }

        $this->people->markEmailVerified($personId);

        return $personId;
    }

    /**
     * Claim for the signed-in person, returning a notice code. The rules are
     * SignupService's, shared with the bot.
     */
    public function claimFor(int $personId, int $taskId): string
    {
        return match ($this->signup->claim($personId, $taskId)) {
            SignupService::GATED => 'gated',
            SignupService::LEADER_ONLY => 'leader_only',
            SignupService::OVERLAP => 'overlap',
            AssignmentRepository::JOIN_OK, AssignmentRepository::JOIN_REJOINED => 'claimed',
            AssignmentRepository::JOIN_DUPLICATE => 'already',
            AssignmentRepository::JOIN_FULL => 'full',
            default => 'unavailable',
        };
    }

    public function dropFor(int $personId, int $taskId): string
    {
        return '' === $this->signup->drop($personId, $taskId) ? 'not_on' : 'dropped';
    }

    /**
     * The account link in every email points at this public page carrying a
     * single-use `web_login` token (see Mailer::manageUrl). On the page load,
     * consume it - exactly the magic-link sign-in the login form uses, one-time
     * and good for 30 minutes - and land the person on their own signed-in
     * profile, where pausing email or deleting their data now lives, instead of
     * a bare endpoint. A stale or reused link just drops them on the sign-in
     * form with a notice to request a fresh one.
     */
    public function maybeSignInFromEmailLink(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification -- a single-use signed token authenticates this, not a nonce.
        if (! isset($_GET['eventcrew_login'])) {
            return;
        }

        $token = sanitize_text_field(wp_unslash($_GET['eventcrew_login']));
        // phpcs:enable WordPress.Security.NonceVerification

        $personId = $this->consumeMagicLink($token);

        if (null !== $personId) {
            $this->setSessionCookie(WebSession::mint($personId));
        }

        // Strip the token and reload so it never lingers in history or a shared
        // link; the reload renders signed in, or shows the sign-in form on a bad
        // or expired link.
        $this->redirect(remove_query_arg('eventcrew_login'), null !== $personId ? 'signed_in' : 'bad_link');
    }

    /**
     * The single admin-ajax entry point, branching on the action. This is the
     * untested-by-design boundary: it reads the request, calls the testable
     * methods above, and redirects.
     */
    public function dispatch(): void
    {
        // A logged-out visitor has no WordPress nonce to send; the admin-ajax
        // actions are guarded by the signed session cookie and the CSRF token
        // instead, so the nonce sniff does not apply across this handler.
        // phpcs:disable WordPress.Security.NonceVerification
        // The action arrives in the POST body for the forms and in the query
        // string for the magic link, so read it from either - the same
        // $_REQUEST admin-ajax itself routed on to reach this handler.
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        $redirect = $this->safeRedirect();

        // Set by the board's fetch() call; when present, claim/drop answer with
        // the re-rendered board as JSON instead of a redirect, so the page can
        // update in place. Login/logout never carry it and stay full-page.
        $isAjax = isset($_POST['eventcrew_ajax']);

        if (self::LOGIN_ACTION === $action && isset($_GET['token'])) {
            $personId = $this->consumeMagicLink(sanitize_text_field(wp_unslash($_GET['token'])));

            if (null !== $personId) {
                $this->setSessionCookie(WebSession::mint($personId));
            }

            $this->redirect($redirect, null !== $personId ? 'signed_in' : 'bad_link');
        }

        if (self::LOGIN_ACTION === $action) {
            // The CAPTCHA is checked before a link is issued or mailed, so a
            // script that never solved it cannot make the form send mail at
            // all. Disabled Turnstile verifies as always-passing.
            $token = isset($_POST[Turnstile::RESPONSE_FIELD])
                ? sanitize_text_field(wp_unslash($_POST[Turnstile::RESPONSE_FIELD]))
                : '';

            if (! $this->turnstile->verify($token, $this->clientIp())) {
                $this->finish($redirect, 'captcha_failed', $isAjax);
            }

            $email = isset($_POST['email']) ? sanitize_text_field(wp_unslash($_POST['email'])) : '';
            $this->finish($redirect, $this->loginByEmail($email), $isAjax);
        }

        if (self::LOGOUT_ACTION === $action) {
            $this->setSessionCookie('', time() - DAY_IN_SECONDS);
            $this->redirect($redirect, 'signed_out');
        }

        // Claim and drop need an authenticated, CSRF-checked person.
        $person = $this->currentPerson();
        $csrf = isset($_POST['csrf']) ? sanitize_text_field(wp_unslash($_POST['csrf'])) : '';
        $taskId = isset($_POST['task_id']) ? (int) $_POST['task_id'] : 0;

        if (null === $person || ! WebSession::verifyCsrf($person->id, $csrf)) {
            $this->finish($redirect, 'please_sign_in', $isAjax);
        }

        if (self::CLAIM_ACTION === $action) {
            $notice = $this->claimFor($person->id, $taskId);

            // The same confirmation the bot sends on a join, so a slot claimed
            // from the web arrives with its door ticket too.
            if ('claimed' === $notice) {
                $this->notifier->confirmSignup($person, $taskId);
            }

            $this->finish($redirect, $notice, $isAjax);
        }

        if (self::PREFS_ACTION === $action) {
            $posted = isset($_POST['prefs']) && is_array($_POST['prefs']) ? wp_unslash($_POST['prefs']) : [];
            $map = [];

            foreach (array_keys(NotificationPreferences::labels()) as $slug) {
                $map[$slug] = [
                    'dm' => isset($posted[$slug]['dm']),
                    'email' => isset($posted[$slug]['email']),
                ];
            }

            $this->people->setNotifyPrefs($person->id, $map);
            $this->finish($redirect, 'prefs_saved', $isAjax);
        }

        if (self::REDEEM_ACTION === $action) {
            $date = isset($_POST['ticket_date']) ? sanitize_text_field(wp_unslash($_POST['ticket_date'])) : '';
            $result = $this->tickets->redeem($person->id, $date, 'web');

            // On an AJAX call the page opens the returned ticket link itself; a
            // no-JS fallback lands straight on the ticket, or back with a notice.
            if ($isAjax) {
                wp_send_json([
                    'notice' => $this->redeemNotice($result['code']),
                    'board' => $this->renderBoard($redirect),
                    'ticket_url' => $result['url'],
                ]);
            }

            if (TicketRedemptionService::TICKET_READY === $result['code']) {
                wp_safe_redirect($result['url']);

                exit;
            }

            $this->redirect($redirect, 'ticket_' . $result['code']);
        }

        $notice = $this->dropFor($person->id, $taskId);

        if ('dropped' === $notice) {
            // The drop has resolved the row to late_cancel or cancelled; read it
            // back so the cancellation email carries the right standing note.
            $assignment = $this->assignments->findFor($taskId, $person->id);

            if (null !== $assignment) {
                $this->notifier->confirmCancellation($person, $taskId, $assignment->status);
            }
        }

        $this->finish($redirect, $notice, $isAjax);
        // phpcs:enable WordPress.Security.NonceVerification
    }

    /**
     * The human text for a redirect/AJAX notice code, or '' if unknown. Static
     * and public so the template can render the initial toast and the AJAX
     * response can return the same wording - one source for both.
     */
    public static function noticeText(string $code): string
    {
        $map = [
            'check_email' => __('Check your email for a sign-in link.', 'eventcrew'),
            'bad_email' => __('That doesn’t look like a valid email address.', 'eventcrew'),
            'signed_in' => __('You’re signed in.', 'eventcrew'),
            'bad_link' => __('That sign-in link is invalid or has expired.', 'eventcrew'),
            'captcha_failed' => __('Couldn’t verify you’re human — please try again.', 'eventcrew'),
            'signed_out' => __('You’re signed out.', 'eventcrew'),
            'claimed' => __('You’re signed up — thanks!', 'eventcrew'),
            'dropped' => __('You’ve cancelled that task.', 'eventcrew'),
            'already' => __('You were already signed up for that.', 'eventcrew'),
            'full' => __('That slot just filled up.', 'eventcrew'),
            'overlap' => __('That clashes with another slot you hold.', 'eventcrew'),
            'gated' => __('Sign-ups are paused on your account — please contact the organizer.', 'eventcrew'),
            'leader_only' => __('The leader slot is for crew the organizers have cleared to lead.', 'eventcrew'),
            'prefs_saved' => __('Your notification preferences are saved.', 'eventcrew'),
            'unavailable' => __('That task is no longer available.', 'eventcrew'),
            'not_on' => __('You weren’t signed up for that one.', 'eventcrew'),
            'please_sign_in' => __('Please sign in first.', 'eventcrew'),
            'ticket_ticket_ready' => __('Your free-entry ticket is ready.', 'eventcrew'),
            'ticket_no_credit' => __('You have no free-entry credits to spend.', 'eventcrew'),
            'ticket_entry_closed' => __('Free entry is closed for that date.', 'eventcrew'),
            'ticket_already_redeemed' => __('You already have a free-entry ticket for that night.', 'eventcrew'),
            'ticket_not_eligible' => __('That event is no longer available.', 'eventcrew'),
        ];

        return $map[$code] ?? '';
    }

    /**
     * The toast text for a redeem outcome on an AJAX call: a success line, or
     * the shared refusal wording from the ticket service.
     */
    private function redeemNotice(string $code): string
    {
        if (TicketRedemptionService::TICKET_READY === $code) {
            return __('Your free-entry ticket is ready.', 'eventcrew');
        }

        return $this->tickets->noticeText($code);
    }

    /**
     * Ends a claim/drop request: on an AJAX call, the fresh board list plus the
     * notice text as JSON (the page shows it as a toast); otherwise the usual
     * redirect back to the page.
     */
    private function finish(string $url, string $notice, bool $ajax): never
    {
        if ($ajax) {
            wp_send_json([
                'notice' => self::noticeText($notice),
                'board' => $this->renderBoard($url),
            ]);
        }

        $this->redirect($url, $notice);
    }

    /**
     * Renders just the board partial - the grouped task list - against a fresh
     * view model, for the AJAX in-place refresh. $here is the page URL the
     * request came from, since get_permalink() has no queried page to read
     * during admin-ajax.
     */
    public function renderBoard(string $here): string
    {
        $view = $this->viewModel();
        $eventcrew_here = $here;

        ob_start();
        require EVENTCREW_PLUGIN_DIR . 'templates/public/signup-board.php';

        return (string) ob_get_clean();
    }

    /**
     * The person the session cookie identifies, or null.
     */
    private function currentPerson(): ?Person
    {
        $cookie = isset($_COOKIE[WebSession::COOKIE])
            ? (string) $_COOKIE[WebSession::COOKIE]
            : '';

        $personId = WebSession::read($cookie);

        return null === $personId ? null : $this->people->find($personId);
    }

    private function loginUrl(string $rawToken): string
    {
        return add_query_arg(
            [
                'action' => self::LOGIN_ACTION,
                'token' => $rawToken,
                'redirect_to' => rawurlencode($this->safeRedirect()),
            ],
            admin_url('admin-ajax.php')
        );
    }

    /**
     * A same-site URL to return to after an action, from the form's redirect_to
     * or the referer, defaulting to home. wp_validate_redirect keeps it on-site.
     */
    private function safeRedirect(): string
    {
        // phpcs:disable WordPress.Security.NonceVerification
        $requested = '';

        if (isset($_REQUEST['redirect_to'])) {
            $requested = rawurldecode((string) wp_unslash($_REQUEST['redirect_to']));
        } elseif (isset($_SERVER['HTTP_REFERER'])) {
            $requested = (string) wp_unslash($_SERVER['HTTP_REFERER']);
        }
        // phpcs:enable WordPress.Security.NonceVerification

        return wp_validate_redirect($requested, home_url('/'));
    }

    /**
     * The visitor's IP for Turnstile's optional remoteip check. REMOTE_ADDR
     * only - the forwarded headers a proxy adds are spoofable, and Turnstile
     * treats remoteip as advisory, so a wrong or empty value never blocks a
     * genuine solve.
     */
    private function clientIp(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification -- read-only, not a state change.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) wp_unslash($_SERVER['REMOTE_ADDR']) : '';

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    private function redirect(string $url, string $notice): never
    {
        wp_safe_redirect(add_query_arg('eventcrew_notice', $notice, $url));

        exit;
    }

    private function setSessionCookie(string $value, ?int $expires = null): void
    {
        setcookie(
            WebSession::COOKIE,
            $value,
            [
                'expires' => $expires ?? (time() + WebSession::TTL),
                'path' => '/',
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }
}
