<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AppInstallPrompt;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\CancelNote;
use EventCrew\Support\ChannelChoice;
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\DmBody;
use EventCrew\Support\DoorLinkSender;
use EventCrew\Support\EmailBody;
use EventCrew\Support\JoinAnswers;
use EventCrew\Support\Language;
use EventCrew\Support\Mailer;
use EventCrew\Support\MessageActions;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\NewcomerApproval;
use EventCrew\Support\TakeoverService;
use EventCrew\Support\TicketTransferService;
use EventCrew\Support\NotificationPreferences;
use EventCrew\Support\LoginCode;
use EventCrew\Support\PageLanguage;
use EventCrew\Support\Paging;
use EventCrew\Support\PrivacyPolicy;
use EventCrew\Support\SalesDeskGate;
use EventCrew\Support\SignupService;
use EventCrew\Support\SiteLanguages;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\TelegramLogin;
use EventCrew\Support\Turnstile;
use EventCrew\Support\WebSession;
use EventCrew\Telegram\TelegramClient;
use EventCrew\Telegram\TicketRedemptionService;
use EventCrew\Telegram\WebLoginBot;

/**
 * The public web front door: a shortcode/block showing the open-task board, with
 * email magic-link sign-in and claim/drop, for people who are not in the
 * Telegram group.
 *
 * Identity stays a verified email - clicking the magic link both signs the
 * person in (a stateless WebSession cookie) and verifies their address, so the
 * same person can move between the web and the bot. Claiming goes through the
 * shared SignupService, so the reputation gate, role and capacity rules
 * are exactly the bot's.
 *
 * The actions run over admin-ajax rather than the REST API, because the target
 * host serves a JS challenge on /wp-json that a plain form post cannot pass -
 * the same reason the bot webhook has an admin-ajax fallback door.
 */
final class SignupController implements WebTaskSignup
{
    public const LOGIN_ACTION = 'eventcrew_web_login';
    public const CLAIM_ACTION = 'eventcrew_web_claim';
    public const DROP_ACTION = 'eventcrew_web_drop';
    public const LOGOUT_ACTION = 'eventcrew_web_logout';
    public const REDEEM_ACTION = 'eventcrew_web_redeem_ticket';
    public const PREFS_ACTION = 'eventcrew_web_prefs';
    public const REVOKE_ACTION = 'eventcrew_web_revoke_sessions';
    public const SALES_ACTION = 'eventcrew_web_sales';
    public const LANGUAGE_ACTION = 'eventcrew_web_language';
    public const PROFILE_ACTION = 'eventcrew_web_profile';

    /** Take someone's task on a task someone is on: asks one holder to hand theirs over. */
    public const TAKEOVER_ACTION = 'eventcrew_web_takeover';

    /** A free-entry ticket's Give away on the Tickets card: offers it to an email address. */
    public const GIVE_ACTION = 'eventcrew_web_give';

    /** Cancel offer on the Tickets card: the waiting offer ends, the ticket stays. */
    public const CANCEL_OFFER_ACTION = 'eventcrew_web_cancel_offer';
    /** The Telegram button asks for a login code here. */
    public const TG_START_ACTION = 'eventcrew_web_tg_start';

    /** The page asks here, every few seconds, whether the bot approved the code. */
    public const TG_STATUS_ACTION = 'eventcrew_web_tg_status';
    /** The sign-in email's code typed on the page (Support\LoginCode). */
    public const CODE_ACTION = 'eventcrew_web_login_code';
    /** The join questions' card, shown to a newcomer after their first sign-in (JoinAnswers). */
    public const JOIN_ACTION = 'eventcrew_web_join';
    /** The join card's Skip: a submit button with this name. */
    public const JOIN_SKIP_FIELD = 'join_skip';

    private const LOGIN_PURPOSE = 'web_login';
    private const LOGIN_TTL = 30 * MINUTE_IN_SECONDS;

    /** The link that confirms a new email address from the Settings tab. */
    private const EMAIL_PURPOSE = 'email_change';
    private const EMAIL_TTL = DAY_IN_SECONDS;

    /** Holds the new address, keyed by a hash of the link's token. */
    private const EMAIL_PENDING_PREFIX = 'eventcrew_email_change_';

    /**
     * The email sign-in form's limits, each counted for one hour: sign-in
     * links asked for from one IP address, and asked for one email address.
     * The IP limit is higher because a venue's Wi-Fi puts many people behind
     * one address.
     */
    private const LOGIN_RATE_PREFIX = 'eventcrew_login_rate_';
    private const LOGIN_RATE_WINDOW = HOUR_IN_SECONDS;
    private const LOGIN_LIMIT_PER_IP = 30;
    private const LOGIN_LIMIT_PER_EMAIL = 5;

    /** Composes the board's view model; the presentation half of this class. */
    private readonly SignupBoardView $boardView;

    /**
     * The session's person, read once per request: the page is drawn in their
     * language, so it is needed before the view model asks for it too.
     */
    private ?Person $person = null;
    private bool $personRead = false;

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
        private readonly TicketRedemptionService $tickets,
        // Optional so a board without the sales desk wired in (the older
        // tests) simply never shows the button.
        private readonly ?SalesDeskGate $salesDesk = null,
        private readonly ?DoorLinkSender $doorLinks = null,
        // Optional for the same reason: without it no Admin tab is drawn.
        private readonly ?CrewAdminController $admin = null,
        // Optional too: without it the page offers only the email sign-in.
        private readonly ?TelegramLogin $telegramLogin = null,
        // Optional too: without it the email-changed notice goes by email only.
        private readonly ?TelegramClient $telegram = null,
        // Optional too: without it a given-away ticket is not marked as such.
        private readonly ?TicketTransferService $transfers = null,
        // Optional too: without it a full task stays a disabled Full.
        private readonly ?TakeoverService $takeovers = null
    ) {
        $this->boardView = new SignupBoardView($tasks, $assignments, $standing, $tickets, $turnstile, $signup, $transfers, $takeovers);
    }

    /** The public stylesheet's handle, shared by the block and the shortcode. */
    public const STYLE_HANDLE = 'eventcrew-signup';

    public function boot(): void
    {
        add_shortcode('eventcrew_signup', [$this, 'renderShortcode']);
        add_action('init', [$this, 'registerBlock']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueStyle']);
        add_action('template_redirect', [$this, 'maybeSignInFromEmailLink']);
        add_action('template_redirect', [$this, 'preventCachingWhenSignedIn']);
        add_filter('w3tc_minify_css_do_tag_minification', [$this, 'keepStyleOutOfMinify'], 10, 2);

        $actions = [
            self::LOGIN_ACTION,
            self::CLAIM_ACTION,
            self::DROP_ACTION,
            self::LOGOUT_ACTION,
            self::REDEEM_ACTION,
            self::PREFS_ACTION,
            self::REVOKE_ACTION,
            self::SALES_ACTION,
            self::LANGUAGE_ACTION,
            self::PROFILE_ACTION,
            self::TAKEOVER_ACTION,
            self::GIVE_ACTION,
            self::CANCEL_OFFER_ACTION,
            self::TG_START_ACTION,
            self::TG_STATUS_ACTION,
            self::CODE_ACTION,
            self::JOIN_ACTION,
            TelegramLogin::RETURN_ACTION,
        ];

        foreach ($actions as $action) {
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
     * Tells page caches not to store this page while somebody is signed in.
     *
     * Crew are not WordPress users, so their session lives in a cookie a caching
     * plugin has never heard of - and a plugin that only skips caching for
     * `wordpress_logged_in_*` will happily cache a signed-in render and serve it
     * to the next visitor. That page carries the person's name and standing, the
     * signed links to their door tickets, their CSRF token, and the manage token
     * that deletes their account. None of it should ever reach a second reader.
     *
     * DONOTCACHEPAGE is the constant every major caching plugin honours;
     * nocache_headers() covers proxies and the browser's own store. Both are
     * skipped for a signed-out visitor, whose view is a public board worth
     * caching.
     *
     * Every page is covered while the cookie is there, not only a post with
     * the crew app block: the app can also reach a page another way (a
     * template, a page builder, a widget), and a page without it costs only a
     * cache miss for that one visitor.
     */
    public function preventCachingWhenSignedIn(): void
    {
        if (! isset($_COOKIE[WebSession::COOKIE])) {
            return;
        }

        if (! defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        nocache_headers();

        // Only here, on a response that is already personal and kept out of
        // every cache, may the session cookie be renewed: a Set-Cookie on a
        // cacheable page would be stored and handed to the next visitor.
        $this->renewSession();
    }

    /**
     * Sliding sign-in: a valid session cookie older than a day
     * (WebSession::RENEW_AFTER) is swapped for a fresh 30-day one, so
     * someone who opens the app now and then is never signed out. The
     * cookie alone decides whether to look further, so a fresh cookie costs
     * no database read; an old one is renewed only for a person who may
     * still sign in (currentPerson(): not blocked, the cookie not revoked by
     * "Sign out everywhere"). Returns the new cookie value, or null when
     * nothing was renewed.
     *
     * Called only from preventCachingWhenSignedIn(), whose response is never
     * cached.
     */
    public function renewSession(?int $now = null): ?string
    {
        $cookie = isset($_COOKIE[WebSession::COOKIE]) ? (string) $_COOKIE[WebSession::COOKIE] : '';
        $now ??= time();

        if ('' === $cookie || ! WebSession::shouldRenew($cookie, $now)) {
            return null;
        }

        $person = $this->currentPerson();

        if (null === $person) {
            return null;
        }

        $fresh = WebSession::mint($person->id, $now);

        // A "Get the app" pop-in this session closed stays closed until the
        // next real sign-in, not merely until this daily renewal.
        $carried = AppInstallPrompt::carryOver($person, (int) WebSession::issuedAt($cookie, $now), $now);

        if (null !== $carried) {
            $this->people->addRoleNotices($person->id, $carried);
        }

        if (! headers_sent()) {
            $this->setSessionCookie($fresh, $now + WebSession::TTL);
        }

        $_COOKIE[WebSession::COOKIE] = $fresh;

        return $fresh;
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
     *
     * Public because the privacy notice shares this sheet and is rendered by a
     * controller that does not own it - and asking for an unregistered handle
     * would fail there in exactly the silent way described above.
     */
    public function registerStyle(): void
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
     * Keeps this plugin's stylesheet out of W3 Total Cache's combined CSS file.
     *
     * The combined file is named after the list of stylesheets, not their
     * content, so it keeps the same address when the plugin is updated, and
     * W3TC tells browsers to keep it for 30 days. A browser that already has
     * it never asks for the new one, and a release's style changes do not
     * reach that person. Left out of the combined file, the stylesheet keeps
     * its own link with ?ver=<plugin version>, a new address on every release.
     *
     * @param mixed $minify    W3TC's decision so far.
     * @param mixed $styleTag  The <link> tag being considered.
     */
    public function keepStyleOutOfMinify(mixed $minify, mixed $styleTag = ''): mixed
    {
        if (is_string($styleTag) && str_contains($styleTag, self::STYLE_HANDLE . '-css')) {
            return false;
        }

        return $minify;
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
        // The accent option first; an install that never set one keeps the
        // theme colour, as before the accent had a setting of its own.
        $color = sanitize_hex_color((string) get_option(PwaController::ACCENT_OPTION, ''));

        if (! is_string($color) || '' === $color) {
            $color = sanitize_hex_color((string) get_option(PwaController::COLOR_OPTION, ''));
        }

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

        // In the signed-in person's language, else the website's or the browser's.
        return Language::run($this->pageLanguage(), function (): string {
            $view = $this->viewModel();
            $view['admin'] = $this->adminView($view['person'] ?? null);

            ob_start();
            include EVENTCREW_PLUGIN_DIR . 'templates/public/signup.php';

            return (string) ob_get_clean();
        });
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
        $person = $this->currentPerson();

        // Which page of Tasks and Past tasks the Tasks tab shows: plain GET
        // links (Previous / Next), so paging works without JavaScript.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only page numbers.
        $tasksPage = Paging::parse($_GET[SignupBoardView::TASKS_PAGE_ARG] ?? '');
        $pastPage = Paging::parse($_GET[SignupBoardView::PAST_PAGE_ARG] ?? '');
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return $this->boardView->build($person, $tasksPage, $pastPage) + [
            'login_action' => self::LOGIN_ACTION,
            'code_action' => self::CODE_ACTION,
            'claim_action' => self::CLAIM_ACTION,
            'drop_action' => self::DROP_ACTION,
            'logout_action' => self::LOGOUT_ACTION,
            'redeem_action' => self::REDEEM_ACTION,
            'prefs_action' => self::PREFS_ACTION,
            'revoke_action' => self::REVOKE_ACTION,
            'sales_action' => self::SALES_ACTION,
            'sales_desk' => null !== $person && $this->canOpenSalesDesk($person),
            'language_action' => self::LANGUAGE_ACTION,
            'profile_action' => self::PROFILE_ACTION,
            'takeover_action' => self::TAKEOVER_ACTION,
            'give_action' => self::GIVE_ACTION,
            'cancel_offer_action' => self::CANCEL_OFFER_ACTION,
            // The join questions' card: only for a newcomer the organizers
            // were not asked about yet (JoinAnswers::canAnswer()). Signed in
            // only, so never in the cached signed-out page.
            'join_action' => self::JOIN_ACTION,
            'join_questions' => null !== $person && JoinAnswers::canAnswer($person),
            'language' => null === $person ? null : ($person->language ?? Language::ENGLISH),
            'language_choices' => SiteLanguages::choices(),
            'telegram_login' => null === $person ? $this->telegramLoginView() : null,
            'telegram_link' => null === $person ? null : self::telegramLinkView($person),
            'app_install' => null === $person ? null : self::appInstallView($person),
            'channel_choice' => null === $person ? null : self::channelChoiceView($person),
            // The helper box under the tabs; null when the helper is off.
            'assistant' => null === $person ? null : AssistantWebController::view($person),
        ];
    }

    /**
     * The "Get the app" pop-in's inputs for the signed-in person: whether it
     * is due (AppInstallPrompt::state(), for this session's cookie), and the
     * app's name, icon and colour for its card, and its start address (what
     * "Open the app" opens).
     *
     * @return array{popin: bool, installed: bool, offer_notify: bool, action: string, name: string, icon: string, color: string, start: string}
     */
    public static function appInstallView(Person $person, ?int $now = null): array
    {
        $cookie = isset($_COOKIE[WebSession::COOKIE]) ? (string) $_COOKIE[WebSession::COOKIE] : '';
        $now ??= time();
        $pwa = new PwaController();

        return AppInstallPrompt::state($person, '' === $cookie ? null : WebSession::issuedAt($cookie, $now), $now) + [
            'action' => AppInstallController::ACTION,
            'name' => $pwa->appShortName(),
            'icon' => PwaController::iconUrl(192),
            'color' => $pwa->themeColor(),
            'start' => $pwa->startUrl(),
        ];
    }

    /**
     * The "Where do you want them?" inputs for the signed-in person
     * (ChannelChoice): whether the settings row shows (applies), the current
     * answer, whether the pop-in is due on this visit, whether the question
     * was ever asked (the page asks right after notifications go on only
     * then), and the action the pop-in posts to.
     *
     * @return array{applies: bool, choice: string, due: bool, asked: bool, telegram: bool, action: string}
     */
    public static function channelChoiceView(Person $person): array
    {
        $applies = ChannelChoice::applies($person);
        $asked = ChannelChoice::wasAsked($person);

        return [
            'applies' => $applies,
            'choice' => ChannelChoice::of($person),
            'due' => $applies && ! $asked,
            'asked' => $asked,
            'telegram' => $person->hasTelegram(),
            'action' => ChannelChoiceController::ACTION,
        ];
    }

    /**
     * Settings' Telegram card, the web's side of the bot's "Get a web
     * sign-in link" (Owner 2026-11-08): with Telegram linked, a link that
     * opens the bot on My info; without, the bot's sign-up link, where the
     * bot asks for the email and the link it mails connects the Telegram
     * account to this one (OnboardingService). Null when there is no bot.
     *
     * @return array{linked: bool, url: string}|null
     */
    public static function telegramLinkView(Person $person): ?array
    {
        $url = MessageActions::payloadUrl($person->hasTelegram() ? 'me' : 'onboard');

        return '' === $url ? null : ['linked' => $person->hasTelegram(), 'url' => $url];
    }

    /**
     * What the signed-out sign-in card needs to offer the Telegram button, or
     * null when the sign-in through the bot is not available (switch off, no
     * bot token, or no bot username) - then the card shows the email form
     * alone.
     *
     * @return array<string, string|int>|null
     */
    private function telegramLoginView(): ?array
    {
        if (null === $this->telegramLogin || ! TelegramLogin::isAvailable()) {
            return null;
        }

        return [
            'start_action' => self::TG_START_ACTION,
            'status_action' => self::TG_STATUS_ACTION,
        ];
    }

    /**
     * The Telegram button was pressed: a new login code for this browser.
     * 'notice' is 'tg_started' with the code, the bot link, the seconds the
     * code lives, the browser's secret (for the cookie, never for the page)
     * and the 4-digit match code the bot also shows; or 'tg_busy' (too many
     * codes from this address) or 'tg_failed'.
     *
     * @return array{notice: string, code?: string, url?: string, expires_in?: int, secret?: string, match?: string}
     */
    public function startTelegramSignIn(string $clientIp): array
    {
        if (null === $this->telegramLogin || ! TelegramLogin::isAvailable()) {
            return ['notice' => 'tg_failed'];
        }

        $created = $this->telegramLogin->create($clientIp);

        if (null === $created) {
            return ['notice' => 'tg_busy'];
        }

        return [
            'notice' => 'tg_started',
            'code' => $created['code'],
            'url' => TelegramLogin::startUrl($created['code']),
            'expires_in' => TelegramLogin::TTL,
            'secret' => $created['secret'],
            'match' => $created['match'],
        ];
    }

    /**
     * The page's poll for its code. $secret is this browser's cookie. Notices:
     * 'tg_pending' (keep waiting), 'tg_expired', and, once the bot approved
     * the code, 'signed_in' with the person id - or 'blocked' / 'tg_failed'
     * when the person can no longer sign in. The approved person must still be
     * a crew member who is not blocked and has a confirmed, real address: the
     * same rule the email link and the bot's button use.
     *
     * @return array{person_id: ?int, notice: string}
     */
    public function telegramStatus(string $code, string $secret): array
    {
        if (null === $this->telegramLogin) {
            return ['person_id' => null, 'notice' => 'tg_expired'];
        }

        $poll = $this->telegramLogin->poll($code, $secret);

        if (TelegramLogin::STATE_PENDING === $poll['state']) {
            return ['person_id' => null, 'notice' => 'tg_pending'];
        }

        if (TelegramLogin::STATE_APPROVED !== $poll['state']) {
            return ['person_id' => null, 'notice' => 'tg_expired'];
        }

        $person = $this->people->find($poll['person_id']);

        if (null !== $person && $person->isBanned()) {
            return ['person_id' => null, 'notice' => 'blocked'];
        }

        if (null === $person || ! WebLoginBot::canSignIn($person)) {
            return ['person_id' => null, 'notice' => 'tg_failed'];
        }

        return ['person_id' => $person->id, 'notice' => 'signed_in'];
    }

    /**
     * The Admin tab's content for the signed-in person, or null - and null
     * means the template draws neither the tab nor its panel. Only the full
     * page asks; the in-place board refresh does not draw tabs.
     *
     * @return array<string, mixed>|null
     */
    public function adminView(mixed $person): ?array
    {
        if (null === $this->admin || ! $person instanceof Person) {
            return null;
        }

        return $this->admin->viewFor($person);
    }

    /**
     * Whether the "Sales desk" button shows for $person: organizers always,
     * and the next night's sales-desk crew under SalesDeskGate's rule.
     */
    public function canOpenSalesDesk(Person $person): bool
    {
        if (null === $this->salesDesk) {
            return false;
        }

        return SalesDeskGate::OK === $this->salesDesk->check($person, $this->salesDesk->nextNight());
    }

    /**
     * The "Sales desk" button pressed: the rule is checked again, here on the
     * server, and the answer is a fresh one-time login link for the sales desk
     * to redirect to - or a notice code saying why not.
     *
     * @return array{url: string, notice: string}
     */
    public function salesDeskFor(Person $person): array
    {
        if (null === $this->salesDesk || null === $this->doorLinks) {
            return ['url' => '', 'notice' => 'sales_' . SalesDeskGate::NOT_CREW];
        }

        $date = $this->salesDesk->nextNight();
        $code = $this->salesDesk->check($person, $date);
        $url = SalesDeskGate::OK === $code ? $this->doorLinks->loginUrl($person, $date) : '';

        if ('' === $url) {
            return ['url' => '', 'notice' => 'sales_' . (SalesDeskGate::OK === $code ? SalesDeskGate::CLOSED : $code)];
        }

        return ['url' => $url, 'notice' => ''];
    }

    /**
     * POST email → issue a magic link and mail it. Returns a notice code for
     * the redirect. Creating the person if new mirrors the bot's onboarding: an
     * unverified row that the magic link will verify.
     *
     * 'login_busy' when $clientIp or this address asked for too many links in
     * the last hour (LOGIN_LIMIT_PER_IP, LOGIN_LIMIT_PER_EMAIL); then nothing
     * is looked up, made or mailed.
     *
     * A newcomer answers the join questions on the crew page after the link
     * signs them in (answerJoinQuestions()), not on this form.
     */
    public function loginByEmail(string $email, string $clientIp = ''): string
    {
        $email = sanitize_email($email);

        if ('' === $email || ! is_email($email)) {
            return 'bad_email';
        }

        if (! $this->allowLoginRequest($email, $clientIp)) {
            return 'login_busy';
        }

        $person = $this->people->findByEmail($email);

        // A blocked account gets no sign-in link at all. The answer is the same
        // "check your inbox" as for any other address, so the form does not
        // tell anyone which addresses belong to a blocked account.
        if (null !== $person && $person->isBanned()) {
            return 'check_email';
        }

        // A first sign-in creates the record, so it is the moment the notice is
        // owed - and the mail is the only thing an unverified stranger is
        // certain to see, since the form that sent them here may have been on
        // someone else's screen.
        $isNew = null === $person;
        // Someone who signs themselves up waits for the team's OK
        // (NewcomerApproval) before they can take a task.
        $personId = $isNew
            ? $this->people->create(['email' => $email, 'approval' => Person::APPROVAL_WAITING])
            : $person->id;

        // A new person gets the language the crew page was shown in (Q330):
        // signing up from a Finnish page means Finnish messages, until they
        // change it. English is left unset, as for anyone who has not chosen.
        $language = $isNew ? $this->pageLanguage() : $person->language;

        if ($isNew && null !== $language && Language::ENGLISH !== $language) {
            $this->people->setLanguage($personId, $language);
        }

        // No account yet: nobody has ever confirmed this address, by a link or
        // through the bot. The mail then says the link creates the account.
        $hasAccount = null !== $person && ($person->isEmailVerified() || null !== $person->telegramUserId);

        // The link mail is in the person's language.
        Language::run(
            $language,
            fn () => $this->sendLoginLink($personId, $email, $isNew, $hasAccount, $person?->displayName ?? '')
        );

        return 'check_email';
    }

    private function sendLoginLink(
        int $personId,
        string $email,
        bool $isNew,
        bool $hasAccount = true,
        string $name = ''
    ): void {
        // The text is the Messages page template (MessageTemplates); the
        // greeting is added here.
        $kind = $hasAccount ? 'sign_in_link' : 'account_create_link';
        $body = EmailBody::withGreeting($name, MessageTemplates::body($kind));

        $raw = $this->tokens->issue($personId, self::LOGIN_PURPOSE, self::LOGIN_TTL);
        // The same sign-in as a code to type, for an app on a phone's home
        // screen, which does not share the cookies of the browser the link
        // opens in. Spending either spends both.
        $code = LoginCode::issue($email, $raw, self::LOGIN_TTL);
        // Under the button, big and centred, in two groups ("123 456") to
        // read and type; LoginCode::normalize() drops the space again.
        $spaced = 6 === strlen($code) ? substr($code, 0, 3) . ' ' . substr($code, 3) : $code;
        /* translators: %s: the 6-digit sign-in code */
        $codeLine = __('Sign-in code for the app: %s', 'eventcrew');
        $picture = [
            'html' => '<p style="margin:24px 0 0;text-align:center;font-size:14px;color:#555">'
                . sprintf(
                    esc_html($codeLine),
                    '<br><span style="display:inline-block;margin-top:8px;font-size:36px;font-weight:bold;'
                        . 'letter-spacing:6px;color:#111;font-family:Menlo,Consolas,monospace;white-space:nowrap">'
                        . esc_html($spaced) . '</span>'
                )
                . '</p>',
            'text' => sprintf($codeLine, $spaced),
        ];
        $label = $hasAccount ? __('Sign in', 'eventcrew') : __('Create my account', 'eventcrew');
        $actions = [['label' => $label, 'url' => $this->loginUrl($raw)]];

        if ($isNew) {
            $body .= "\n\n" . PrivacyPolicy::noticeLine();
            $url = PrivacyPolicy::noticeUrl();

            if ('' !== $url) {
                $actions[] = ['label' => PrivacyPolicy::noticeLinkLabel(), 'url' => $url];
            }
        }

        $this->mailer->toPerson(
            $personId,
            $email,
            MessageTemplates::subject($kind),
            $body,
            $actions,
            null,
            null,
            $picture
        );
    }

    /**
     * The join card's Send or Skip ($answers from JoinAnswers::fromPost(), []
     * for a skip): keeps the answers while the organizers were not asked
     * yet, then asks them now (NewcomerApproval::ASK_HOOK, which sends the
     * request once, also when the card is posted twice or the 10-minute
     * WP-Cron event comes later). Returns the notice code.
     *
     * @param array<string, string> $answers
     */
    public function answerJoinQuestions(Person $person, array $answers): string
    {
        if (! JoinAnswers::canAnswer($person)) {
            return $person->isWaitingForOk() ? 'waiting_ok' : 'join_closed';
        }

        if ([] !== $answers) {
            $this->people->replaceRoleNotices($person->id, JoinAnswers::KEYS, $answers);
        }

        do_action(NewcomerApproval::ASK_HOOK, $person->id);

        return 'waiting_ok';
    }

    /**
     * The Settings tab's "Your details" form. A new name is saved at once. A
     * new email address is not: a link goes to the new address, and the
     * address changes only when that link is opened (confirmEmailChange). The
     * notice code says which of these happened.
     */
    public function updateProfile(Person $person, string $name, string $email): string
    {
        $name = trim($name);
        $email = sanitize_email($email);

        if ('' === $name) {
            return 'profile_no_name';
        }

        $current = Person::isPlaceholderEmail($person->email) ? '' : $person->email;
        $emailChanged = '' !== $email && 0 !== strcasecmp($email, $current);

        if ($emailChanged && ! is_email($email)) {
            return 'bad_email';
        }

        // An address that already belongs to another account is not refused
        // here: the answer would tell anyone which addresses are registered.
        // The link goes to that mailbox like any other, and opening it is
        // refused by confirmEmailChange(), which shows the reason only to
        // whoever reads that mailbox.

        $nameChanged = $name !== $person->displayName;

        if ($nameChanged) {
            $this->people->update($person->id, ['display_name' => mb_substr($name, 0, 191)]);
        }

        if (! $emailChanged) {
            return $nameChanged ? 'profile_saved' : 'profile_unchanged';
        }

        $raw = $this->tokens->issue($person->id, self::EMAIL_PURPOSE, self::EMAIL_TTL);
        set_transient(self::EMAIL_PENDING_PREFIX . $this->pendingKey($raw), $email, self::EMAIL_TTL);

        $url = add_query_arg(
            [
                'action' => self::PROFILE_ACTION,
                'token' => $raw,
                'redirect_to' => rawurlencode($this->safeRedirect()),
            ],
            admin_url('admin-ajax.php')
        );

        $this->mailer->toUnconfirmedAddress(
            $email,
            MessageTemplates::subject('email_change_confirm'),
            EmailBody::withGreeting($person->displayName, MessageTemplates::body('email_change_confirm')),
            [['label' => __('Confirm new email', 'eventcrew'), 'url' => $url]]
        );

        return $nameChanged ? 'profile_saved_email_sent' : 'email_sent';
    }

    /**
     * Opens the link from updateProfile(): moves the account to the new
     * address, counts it as confirmed, and tells the old address and the
     * linked Telegram chat. Returns the notice code.
     */
    public function confirmEmailChange(string $token): string
    {
        $key = self::EMAIL_PENDING_PREFIX . $this->pendingKey($token);
        $email = get_transient($key);
        // Spent by one conditional UPDATE, so the link opened twice at the same
        // moment moves the account only once.
        $personId = is_string($email) && '' !== $email ? $this->tokens->consumeOnce($token, self::EMAIL_PURPOSE) : null;
        $person = null === $personId ? null : $this->people->find($personId);

        if (null === $person || ! is_string($email)) {
            return 'bad_email_link';
        }

        delete_transient($key);

        // Someone else may have taken the address in the 24 hours since.
        $owner = $this->people->findByEmail($email);

        if (null !== $owner && $owner->id !== $person->id) {
            return 'email_taken';
        }

        $this->people->update($person->id, ['email' => $email]);
        $this->people->markEmailVerified($person->id);

        Language::forPerson($person, function () use ($person, $email): void {
            $context = ['person' => $person, 'email' => $email];

            $this->mailer->toPerson(
                $person->id,
                $person->email,
                MessageTemplates::subject('email_changed', $context),
                EmailBody::withGreeting($person->displayName, MessageTemplates::body('email_changed', $context))
            );

            // A security notice, so the Telegram twin goes out whatever the
            // person's notification settings say: someone who took over the
            // account cannot hide the change by muting the bot first.
            if (null !== $this->telegram && null !== $person->telegramChatId) {
                $this->telegram->sendMessage(
                    $person->telegramChatId,
                    DmBody::frame($person, MessageTemplates::body('email_changed_dm', $context))
                );
            }
        });

        return 'email_changed';
    }

    /** The token is only ever stored hashed; the transient key follows suit. */
    private function pendingKey(string $rawToken): string
    {
        return substr(hash('sha256', trim($rawToken)), 0, 32);
    }

    /**
     * The magic-link target: consume the token, verify the email, and return
     * the person id to start a session for (or null when the link is bad).
     */
    public function consumeMagicLink(string $token): ?int
    {
        return $this->magicLinkSignIn($token)['person_id'];
    }

    /**
     * consumeMagicLink() with the notice to show: 'signed_in', 'bad_link', or
     * 'blocked' for a link that was good but belongs to a blocked account -
     * which starts no session, even from a link mailed before the block.
     *
     * @return array{person_id: ?int, notice: string}
     */
    public function magicLinkSignIn(string $token): array
    {
        // One conditional UPDATE spends the link, so the same link opened twice
        // at the same moment (two tabs, or a mail scanner and the person) signs
        // in only one of them.
        $personId = $this->tokens->consumeOnce($token, self::LOGIN_PURPOSE);

        if (null === $personId) {
            return ['person_id' => null, 'notice' => 'bad_link'];
        }

        return $this->signInWithSpentToken($personId);
    }

    /**
     * The sign-in email's code typed on the page (Support\LoginCode), with
     * the address it was sent to. A right code spends the email's link token,
     * so it starts a session exactly as the link would, and only once.
     * Notices: 'signed_in', 'bad_code' (wrong, expired, used, or no code
     * waiting), 'code_locked' (the last allowed wrong try), 'code_busy' (too
     * many wrong codes from $clientIp) or 'blocked'.
     *
     * @return array{person_id: ?int, notice: string}
     */
    public function signInWithCode(string $email, string $code, string $clientIp = ''): array
    {
        $email = sanitize_email($email);

        if ('' === $email || ! is_email($email)) {
            return ['person_id' => null, 'notice' => 'bad_code'];
        }

        $check = LoginCode::check($email, $code, $clientIp);

        if (LoginCode::OK !== $check['state']) {
            return ['person_id' => null, 'notice' => match ($check['state']) {
                LoginCode::LOCKED => 'code_locked',
                LoginCode::BUSY => 'code_busy',
                default => 'bad_code',
            }];
        }

        $personId = $this->tokens->consumeHashOnce($check['token'] ?? '', self::LOGIN_PURPOSE);

        if (null === $personId) {
            return ['person_id' => null, 'notice' => 'bad_code'];
        }

        return $this->signInWithSpentToken($personId);
    }

    /**
     * After a sign-in token was spent (link or code): no session for a blocked account,
     * which also covers a link mailed before the block; else the address is
     * confirmed and the session can start.
     *
     * @return array{person_id: ?int, notice: string}
     */
    private function signInWithSpentToken(int $personId): array
    {
        $person = $this->people->find($personId);

        // The sign-in is used, so a code mailed with it is no longer needed
        // (the link's token is spent too, so the code could not work anyway).
        if (null !== $person) {
            LoginCode::forget($person->email);
        }

        if (null !== $person && $person->isBanned()) {
            return ['person_id' => null, 'notice' => 'blocked'];
        }

        // A newcomer's first confirmation: the organizers' request waits for
        // the join questions on the crew page (NewcomerApproval::putOff()).
        if (null !== $person && ! $person->isEmailVerified()) {
            NewcomerApproval::putOff($this->people, $person);
        }

        $this->people->markEmailVerified($personId);

        return ['person_id' => $personId, 'notice' => 'signed_in'];
    }

    /**
     * Claim for the signed-in person, returning a notice code. The rules are
     * SignupService's, shared with the bot.
     */
    public function claimFor(int $personId, int $taskId, ?bool $countsCredit = null): string
    {
        return match ($this->signup->claim($personId, $taskId, $countsCredit)) {
            SignupService::GATED => 'gated',
            SignupService::WAITING => 'waiting_ok',
            SignupService::TURNED_DOWN => 'not_ok',
            SignupService::CREDIT_QUESTION => 'credit_question',
            SignupService::CREW_ONLY => 'crew_only',
            AssignmentRepository::JOIN_OK, AssignmentRepository::JOIN_REJOINED => 'claimed',
            AssignmentRepository::JOIN_DUPLICATE => 'already',
            AssignmentRepository::JOIN_FULL => 'full',
            default => 'unavailable',
        };
    }

    public function dropFor(int $personId, int $taskId): string
    {
        return match ($this->signup->drop($personId, $taskId)) {
            '' => 'not_on',
            AssignmentRepository::CANCEL_LOCKED => 'signout_locked',
            default => 'dropped',
        };
    }

    /**
     * Claim for $person and, when it went through, send the same
     * confirmation the bot sends on a join (with the door ticket). The
     * page's sign-up form and its helper box both come here.
     */
    public function claimAndNotify(Person $person, int $taskId, ?bool $countsCredit = null): string
    {
        $notice = $this->claimFor($person->id, $taskId, $countsCredit);

        if ('claimed' === $notice) {
            $this->notifier->signedUp($person, $taskId);
        }

        return $notice;
    }

    /**
     * Cancel for $person and, when it went through, send the cancellation
     * email with the right standing note. Returns the notice code
     * (dropped, dropped_late, dropped_early, not_on, signout_locked).
     */
    public function dropAndNotify(Person $person, int $taskId): string
    {
        $notice = $this->dropFor($person->id, $taskId);

        if ('dropped' !== $notice) {
            return $notice;
        }

        // The drop has resolved the row to late_cancel or cancelled; read it
        // back so the cancellation email carries the right standing note.
        $assignment = $this->assignments->findFor($taskId, $person->id);

        if (null === $assignment) {
            return $notice;
        }

        $this->notifier->cancelled($person, $taskId, $assignment->status);

        return $this->droppedNotice($assignment->status, $taskId);
    }

    /**
     * The notice after a cancel on the page, by what it recorded (Q351): a
     * late cancel says Sign up again undoes it, an early one says why it has
     * no effect on the standing. A free cancel inside the second thoughts
     * window keeps the plain 'dropped'.
     */
    private function droppedNotice(string $status, int $taskId): string
    {
        if (AssignmentStatus::LATE_CANCEL === $status) {
            return 'dropped_late';
        }

        $task = AssignmentStatus::CANCELLED === $status ? $this->tasks->find($taskId) : null;

        return null !== $task && CancelNote::isEarly($task) ? 'dropped_early' : 'dropped';
    }

    /**
     * The account link in every email points at this public page carrying a
     * single-use `web_login` token (see Mailer::manageUrl). Opening it does not
     * use the token: a mail scanner that opens every link first would spend it.
     * The page load shows a "Sign in" button instead, and the button's POST
     * consumes the token - exactly the magic-link sign-in the login form uses,
     * one-time and good for 30 minutes - and lands the person on their own
     * signed-in profile. A stale or reused link drops them on the sign-in form
     * with a notice to request a fresh one.
     */
    public function maybeSignInFromEmailLink(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification -- a single-use signed token authenticates this, not a nonce.
        if (! isset($_GET['eventcrew_login'])) {
            return;
        }

        $token = sanitize_text_field(wp_unslash($_GET['eventcrew_login']));
        // phpcs:enable WordPress.Security.NonceVerification

        // After the sign-in, back to this page without the token in the address.
        $returnTo = remove_query_arg('eventcrew_login');

        if ($this->alreadySignedInFor($token)) {
            wp_safe_redirect($returnTo);

            exit;
        }

        $this->showSignInButton($token, $returnTo);
    }

    /**
     * Whether a sign-in link opened on this device can skip the "Sign in"
     * button: the device is already signed in, and the link is not a live link
     * for someone else (that one still shows the button, so it can switch who
     * is signed in). A used or expired link then just opens the page, with no
     * message. Read-only: the token is never spent here.
     */
    private function alreadySignedInFor(string $token): bool
    {
        $person = $this->currentPerson();

        if (null === $person) {
            return false;
        }

        $owner = $this->tokens->validFor($token, self::LOGIN_PURPOSE);

        return null === $owner || $owner === $person->id;
    }

    /**
     * Answers the GET of a sign-in link with a page holding one "Sign in"
     * button and nothing else. The token goes into the button's form, which
     * POSTs it to LOGIN_ACTION; only that POST spends it and signs in. The page
     * looks the same for a good, a used and an unknown token, so it tells
     * nobody whether the link or an account exists.
     */
    private function showSignInButton(string $token, string $returnTo): never
    {
        // The page holds a live token: no page cache may keep or serve it.
        if (! defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        nocache_headers();
        status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        // Assembled from literal markup and escaped text in signInButtonPage().
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo Language::run($this->pageLanguage(), fn (): string => $this->signInButtonPage($token, $returnTo));

        exit;
    }

    /** The page showSignInButton() sends; split out so it is testable. */
    public function signInButtonPage(string $token, string $returnTo): string
    {
        $lang = Language::current() ?? str_replace('_', '-', (string) get_bloginfo('language'));

        return sprintf(
            // phpcs:ignore Generic.Files.LineLength.TooLong -- one literal HTML document; wrapping it just adds stray whitespace.
            '<!doctype html><html lang="%1$s"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>%2$s</title></head><body style="font-family:sans-serif;max-width:32em;margin:4em auto;padding:0 1em;text-align:center"><h1>%2$s</h1><p>%3$s</p><form method="post" action="%4$s"><input type="hidden" name="action" value="%5$s"><input type="hidden" name="token" value="%6$s"><input type="hidden" name="redirect_to" value="%7$s"><button type="submit" style="font-size:1.2em;padding:.5em 1.5em">%2$s</button></form></body></html>',
            esc_attr($lang),
            esc_html__('Sign in', 'eventcrew'),
            esc_html__('Press the button to finish signing in.', 'eventcrew'),
            esc_url(admin_url('admin-ajax.php')),
            esc_attr(self::LOGIN_ACTION),
            esc_attr($token),
            // safeRedirect() rawurldecode()s it, as it does the emailed link's.
            esc_attr(rawurlencode($returnTo))
        );
    }

    /**
     * The language switch: saves the language on the person's record and
     * returns the notice to show. A value that is not a site language (or
     * English) changes nothing and says nothing.
     */
    public function chooseLanguage(int $personId, string $posted): string
    {
        $language = Language::normalize($posted);

        if (null === $language) {
            return '';
        }

        $this->people->setLanguage($personId, $language);

        return 'language_saved';
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
            // The emailed link. Opening it only shows a "Sign in" button, so a
            // mail scanner that opens links first does not spend the token.
            $token = sanitize_text_field(wp_unslash($_GET['token']));

            if ($this->alreadySignedInFor($token)) {
                wp_safe_redirect($redirect);

                exit;
            }

            $this->showSignInButton($token, $redirect);
        }

        if (self::LOGIN_ACTION === $action && isset($_POST['token'])) {
            // That button's POST: the token is spent here, once.
            ['person_id' => $personId, 'notice' => $notice] = $this->magicLinkSignIn(
                sanitize_text_field(wp_unslash($_POST['token']))
            );

            if (null !== $personId) {
                $this->setSessionCookie(WebSession::mint($personId));
            } elseif ('bad_link' === $notice && null !== $this->currentPerson()) {
                // Used or expired, but this device is signed in already: just
                // the page, with no message.
                wp_safe_redirect($redirect);

                exit;
            }

            $this->redirect($redirect, $notice);
        }

        if (self::CODE_ACTION === $action) {
            // The code from the sign-in email, typed on the page. A form on
            // another site could only sign this browser in to an account
            // whose mailbox that site reads; the Origin check stops even that.
            $email = isset($_POST['email']) ? sanitize_text_field(wp_unslash($_POST['email'])) : '';
            $code = isset($_POST['code']) ? sanitize_text_field(wp_unslash($_POST['code'])) : '';
            ['person_id' => $personId, 'notice' => $notice] = $this->sameOrigin()
                ? $this->signInWithCode($email, $code, $this->clientIp())
                : ['person_id' => null, 'notice' => 'bad_code'];

            if (null !== $personId) {
                $this->setSessionCookie(WebSession::mint($personId));
            }

            nocache_headers();

            if ($isAjax) {
                wp_send_json(['code' => $notice, 'notice' => self::noticeText($notice)]);
            }

            $this->redirect($redirect, $notice);
        }

        if (self::PROFILE_ACTION === $action && isset($_GET['token'])) {
            // The link from the new address's mailbox. It needs no session: the
            // token names the person, and only the new mailbox has it.
            $this->redirect($redirect, $this->confirmEmailChange(sanitize_text_field(wp_unslash($_GET['token']))));
        }

        if (self::TG_START_ACTION === $action) {
            // A login code for the Telegram button. The secret goes only into
            // this browser's HttpOnly cookie, which ties the code to it.
            $started = $this->startTelegramSignIn($this->clientIp());
            $notice = $started['notice'];

            if (isset($started['secret'])) {
                $this->setTelegramLoginCookie($started['secret'], time() + TelegramLogin::TTL);
            }

            nocache_headers();
            wp_send_json([
                'code' => $notice,
                'notice' => self::noticeText($notice),
                'login' => $started['code'] ?? '',
                'url' => $started['url'] ?? '',
                'expires_in' => $started['expires_in'] ?? 0,
                'match' => $started['match'] ?? '',
            ]);
        }

        if (self::TG_STATUS_ACTION === $action) {
            $code = isset($_POST['login']) ? sanitize_text_field(wp_unslash($_POST['login'])) : '';
            $secret = isset($_COOKIE[TelegramLogin::COOKIE])
                ? sanitize_text_field(wp_unslash($_COOKIE[TelegramLogin::COOKIE]))
                : '';

            ['person_id' => $personId, 'notice' => $notice] = $this->telegramStatus($code, $secret);

            if (null !== $personId) {
                // The code is used up, and so is its cookie. Other answers
                // leave the cookie alone: it may belong to a newer code from
                // another tab, and it runs out with the code anyway.
                $this->setTelegramLoginCookie('', time() - HOUR_IN_SECONDS);
                $this->setSessionCookie(WebSession::mint($personId));
            } elseif ('tg_expired' === $notice && null !== $this->currentPerson()) {
                // The bot's "Back to the crew app" button already used the
                // code in this browser and signed it in: the waiting tab
                // reloads signed in instead of saying the link expired.
                $notice = 'signed_in';
            }

            nocache_headers();
            wp_send_json(['code' => $notice, 'notice' => self::noticeText($notice)]);
        }

        if (TelegramLogin::RETURN_ACTION === $action) {
            // The bot's "Back to the crew app" button, opened on the phone.
            // In the browser that pressed Telegram it signs in, like the
            // page's own poll; then it opens the crew app.
            $code = isset($_GET['login']) ? sanitize_text_field(wp_unslash($_GET['login'])) : '';
            $secret = isset($_COOKIE[TelegramLogin::COOKIE])
                ? sanitize_text_field(wp_unslash($_COOKIE[TelegramLogin::COOKIE]))
                : '';
            $crewApp = $this->mailer->boardUrl();
            $crewApp = '' === $crewApp ? home_url('/') : $crewApp;

            ['person_id' => $personId, 'notice' => $notice] = $this->telegramStatus($code, $secret);

            if (null !== $personId) {
                $this->setTelegramLoginCookie('', time() - HOUR_IN_SECONDS);
                $this->setSessionCookie(WebSession::mint($personId));
                $this->redirect($crewApp, 'signed_in');
            }

            if (null !== $this->currentPerson()) {
                // The page's own poll got there first: already signed in.
                wp_safe_redirect($crewApp);

                exit;
            }

            $this->redirect($crewApp, 'blocked' === $notice ? 'blocked' : 'tg_return_failed');
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
            $this->finish($redirect, $this->loginByEmail($email, $this->clientIp()), $isAjax);
        }

        // Everything past here needs an authenticated, CSRF-checked person -
        // logout included. It used to sit above this gate, which made signing
        // somebody out a thing any other site could do to them by pointing a
        // form here. Harmless next to the rest, but there is no reason for it.
        $person = $this->currentPerson();
        $csrf = isset($_POST['csrf']) ? sanitize_text_field(wp_unslash($_POST['csrf'])) : '';
        $taskId = isset($_POST['task_id']) ? (int) $_POST['task_id'] : 0;

        if (null === $person || ! WebSession::verifyCsrf($person->id, $csrf)) {
            $this->finish($redirect, 'please_sign_in', $isAjax);
        }

        // From here on the request is this person's, and every branch ends in
        // a redirect or a JSON answer: their toasts, the re-rendered board and
        // the mail a claim sends are all in their language. Left on exit.
        Language::enter($person->language);

        if (self::LANGUAGE_ACTION === $action) {
            $chosen = isset($_POST['language']) ? sanitize_key(wp_unslash($_POST['language'])) : '';
            $this->redirect($redirect, $this->chooseLanguage($person->id, $chosen));
        }

        if (self::JOIN_ACTION === $action) {
            // The join card's Send, or its Skip: nothing is kept on a skip.
            $answers = isset($_POST[self::JOIN_SKIP_FIELD]) ? [] : JoinAnswers::fromPost(array_map(
                static fn (string $field): string => isset($_POST[$field]) && is_string($_POST[$field])
                    ? sanitize_text_field(wp_unslash($_POST[$field]))
                    : '',
                [
                    JoinAnswers::FIELD_BEFORE => JoinAnswers::FIELD_BEFORE,
                    JoinAnswers::FIELD_TIMES => JoinAnswers::FIELD_TIMES,
                    JoinAnswers::FIELD_TICKETS => JoinAnswers::FIELD_TICKETS,
                ]
            ));
            $this->redirect($redirect, $this->answerJoinQuestions($person, $answers));
        }

        if (self::PROFILE_ACTION === $action) {
            $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
            $email = isset($_POST['email']) ? sanitize_text_field(wp_unslash($_POST['email'])) : '';
            $this->redirect($redirect, $this->updateProfile($person, $name, $email));
        }

        if (self::LOGOUT_ACTION === $action) {
            $this->setSessionCookie('', time() - DAY_IN_SECONDS);
            $this->redirect($redirect, 'signed_out');
        }

        if (self::REVOKE_ACTION === $action) {
            // Moves the line every session cookie is measured against, so the
            // ones we cannot see - another browser, a phone left somewhere -
            // stop working. This one included: clearing the cookie here just
            // saves the person a redirect that would have found them signed out
            // anyway.
            $this->people->revokeSessions($person->id);
            $this->setSessionCookie('', time() - DAY_IN_SECONDS);
            $this->redirect($redirect, 'sessions_revoked');
        }

        if (self::SALES_ACTION === $action) {
            $sales = $this->salesDeskFor($person);

            if ('' === $sales['url']) {
                $this->redirect($redirect, $sales['notice']);
            }

            wp_safe_redirect($sales['url']);

            exit;
        }

        if (self::CLAIM_ACTION === $action) {
            // Sent only by a restricted task's Yes / No buttons ("Count toward
            // free-entry credit?"); every other task ignores it.
            $countsCredit = isset($_POST['counts_credit'])
                ? '1' === sanitize_key(wp_unslash($_POST['counts_credit']))
                : null;
            // The same confirmation the bot sends on a join, so a slot claimed
            // from the web arrives with its door ticket too.
            $this->finish($redirect, $this->claimAndNotify($person, $taskId, $countsCredit), $isAjax);
        }

        if (self::PREFS_ACTION === $action) {
            $posted = isset($_POST['prefs']) && is_array($_POST['prefs']) ? wp_unslash($_POST['prefs']) : [];

            $this->people->setNotifyPrefs($person->id, NotificationPreferences::fromForm($person, $posted));

            // "When both are on" (ChannelChoice), shown only when it applies.
            $channels = ChannelChoice::parse(isset($_POST['channels']) ? sanitize_key(wp_unslash($_POST['channels'])) : '');

            if (null !== $channels && ChannelChoice::applies($person)) {
                $this->people->addRoleNotices($person->id, ChannelChoice::entriesFor($channels, (string) current_time('mysql')));
            }
            $this->finish($redirect, 'prefs_saved', $isAjax);
        }

        if (self::TAKEOVER_ACTION === $action) {
            $assignmentId = isset($_POST['assignment_id']) ? absint(wp_unslash($_POST['assignment_id'])) : 0;
            $result = $this->takeoverFor($person, $assignmentId);

            // The answer names the holder and the deadline, so on an AJAX call
            // it goes out as it is; a no-JS post gets the short fixed wording.
            $this->finishText($redirect, $result['ok'] ? 'takeover_sent' : 'unavailable', $result['text'], $isAjax);
        }

        if (self::GIVE_ACTION === $action || self::CANCEL_OFFER_ACTION === $action) {
            $redemptionId = isset($_POST['redemption_id']) ? absint(wp_unslash($_POST['redemption_id'])) : 0;
            $email = isset($_POST['email']) ? sanitize_text_field(wp_unslash($_POST['email'])) : '';
            $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
            $result = self::GIVE_ACTION === $action
                ? $this->giveTicket($person, $redemptionId, $email, $name)
                : $this->cancelTicketOffer($person, $redemptionId);

            // A refused offer leaves the page as it is, so the typed address
            // and name stay in the form to fix ('changed' => false).
            $this->finishText($redirect, $result['code'], $result['text'], $isAjax, $result['ok']);
        }

        if (self::REDEEM_ACTION === $action) {
            $date = isset($_POST['ticket_date']) ? sanitize_text_field(wp_unslash($_POST['ticket_date'])) : '';
            // The tickets the page saw the person holding for each night.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read through absint below.
            $held = isset($_POST['ticket_held']) && is_array($_POST['ticket_held'])
                ? wp_unslash($_POST['ticket_held'])
                : [];
            $result = $this->tickets->redeem($person->id, $date, 'web', absint($held[$date] ?? 0));

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

        $this->finish($redirect, $this->dropAndNotify($person, $taskId), $isAjax);
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
            'bad_link' => __('This sign-in link has expired.', 'eventcrew'),
            'captcha_failed' => __('Couldn’t verify you’re human — please try again.', 'eventcrew'),
            'signed_out' => __('You’re signed out.', 'eventcrew'),
            'claimed' => __('You’re signed up — thanks!', 'eventcrew'),
            'dropped' => __('You’ve cancelled that task.', 'eventcrew'),
            'dropped_early' => __('You’ve cancelled that task.', 'eventcrew') . ' ' . CancelNote::early(),
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            'dropped_late' => __('You’ve cancelled that task. This close to the event it counts as a late cancellation.', 'eventcrew') . ' ' . CancelNote::undoShort(),
            'already' => __('You were already signed up for that.', 'eventcrew'),
            'full' => __('That slot just filled up.', 'eventcrew'),
            'gated' => __('Sign-ups are paused on your account — please contact the organizer.', 'eventcrew'),
            'waiting_ok' => SignupService::waitingText(),
            'not_ok' => SignupService::turnedDownText(),
            'prefs_saved' => __('Your notification preferences are saved.', 'eventcrew'),
            'language_saved' => __('Your language is saved.', 'eventcrew'),
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            'sessions_revoked' => __('Signed out everywhere. Any other browser or phone that was signed in as you will need a fresh link.', 'eventcrew'),
            'unavailable' => __('That task is no longer available.', 'eventcrew'),
            'crew_only' => SignupService::crewOnlyText(),
            'credit_question' => __('Choose whether this task counts toward free-entry credit.', 'eventcrew'),
            'not_on' => __('You weren’t signed up for that one.', 'eventcrew'),
            'takeover_sent' => __('Request sent.', 'eventcrew'),
            'offer_cancelled' => __('Offer cancelled. Your ticket is yours again.', 'eventcrew'),
            'signout_locked' => SignupService::signoutLockedText(),
            'please_sign_in' => __('Please sign in first.', 'eventcrew'),
            'ticket_ticket_ready' => __('Your free-entry ticket is ready.', 'eventcrew'),
            'ticket_no_credit' => __('You have no free-entry credits to spend.', 'eventcrew'),
            'ticket_entry_closed' => __('Free entry is closed for that date.', 'eventcrew'),
            'ticket_already_redeemed' => __('You already have a free-entry ticket for that night.', 'eventcrew'),
            'ticket_not_eligible' => __('That event is no longer available.', 'eventcrew'),
            'blocked' => __('Your account is blocked. Talk to an organizer.', 'eventcrew'),
            'profile_saved' => __('Your name is saved.', 'eventcrew'),
            'profile_unchanged' => __('Nothing was changed.', 'eventcrew'),
            'profile_no_name' => __('Your name cannot be empty.', 'eventcrew'),
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            'email_sent' => __('We sent a link to the new address. Your email changes when you open it.', 'eventcrew'),
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            'profile_saved_email_sent' => __('Your name is saved. We sent a link to the new address. Your email changes when you open it.', 'eventcrew'),
            'email_taken' => __('That email address belongs to another crew account.', 'eventcrew'),
            'email_changed' => __('Your email address is changed.', 'eventcrew'),
            'bad_email_link' => __('That email change link is invalid or has expired.', 'eventcrew'),
            'tg_failed' => __('Telegram sign-in did not work. Please try again, or sign in with email.', 'eventcrew'),
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            'tg_started' => __('Open Telegram, press Start, then press the code shown on this page. This page signs you in by itself.', 'eventcrew'),
            'tg_pending' => '',
            // The join card posted by someone no longer waiting: nothing to say.
            'join_closed' => '',
            'tg_expired' => __('The link expired. Press Telegram again.', 'eventcrew'),
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            'tg_return_failed' => __('You are not signed in in this browser. Go back to the browser where you pressed Telegram, or press Telegram again here.', 'eventcrew'),
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            'tg_busy' => __('Too many sign-in attempts from here. Wait a few minutes and try again, or sign in with email.', 'eventcrew'),
            'login_busy' => __('Too many sign-in links were asked for. Wait an hour and try again.', 'eventcrew'),
            'bad_code' => __('That code is wrong or has expired.', 'eventcrew'),
            'code_locked' => __('Too many wrong codes. Ask for a new link.', 'eventcrew'),
            'code_busy' => __('Too many wrong codes from here. Wait an hour and try again.', 'eventcrew'),
        ];

        // The Admin tab's outcomes are worded by its own controller.
        if (! isset($map[$code]) && str_starts_with($code, 'admin_')) {
            return CrewAdminController::noticeText($code);
        }

        // Give away / Cancel offer outcomes share the bot's wording.
        if (! isset($map[$code]) && str_starts_with($code, 'transfer_')) {
            return TicketTransferService::message(substr($code, strlen('transfer_')));
        }

        // The sales desk refusals share SalesDeskGate's wording with the bot's sales desk button.
        if (! isset($map[$code]) && str_starts_with($code, 'sales_')) {
            return SalesDeskGate::message(substr($code, strlen('sales_')));
        }

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
     *
     * The raw code goes out beside the human text because the page has to know
     * what actually happened, not just what to say. It only had the wording
     * before, which is why the sign-in form announced "check your inbox" after
     * a refused captcha - it could not tell a sent link from a rejected one.
     */
    private function finish(string $url, string $notice, bool $ajax): never
    {
        if ($ajax) {
            wp_send_json([
                'code' => $notice,
                'notice' => self::noticeText($notice),
                'board' => $this->renderBoard($url),
            ]);
        }

        $this->redirect($url, $notice);
    }

    /**
     * finish() with a text of its own for the toast, for an outcome a fixed
     * notice code cannot word (it names a person or a time). Without
     * JavaScript the redirect carries $code, whose wording is shorter.
     */
    private function finishText(string $url, string $code, string $text, bool $ajax, ?bool $changed = null): never
    {
        if ($ajax) {
            $answer = ['code' => $code, 'notice' => $text, 'board' => $this->renderBoard($url)];

            if (null !== $changed) {
                $answer['changed'] = $changed;
            }

            wp_send_json($answer);
        }

        $this->redirect($url, $code);
    }

    /**
     * The crew page's Take someone's task (Owner 2026-11-08): asks the holder
     * of one assignment to hand it over, with the bot's checks and wording
     * (TakeoverService::requestFor()). The person is signed in; their email
     * is verified by the sign-in itself.
     *
     * @return array{ok: bool, text: string}
     */
    public function takeoverFor(Person $person, int $assignmentId): array
    {
        if (null === $this->takeovers || 0 === $assignmentId) {
            return ['ok' => false, 'text' => __('That task is no longer available.', 'eventcrew')];
        }

        return $this->takeovers->requestFor($assignmentId, $person);
    }

    /**
     * Give away on the Tickets card (Owner 2026-11-08): the bot's offer to an
     * email address, with its checks and texts (TicketTransferService). An
     * address of a member gives the ticket to them at once.
     *
     * @return array{ok: bool, code: string, text: string}
     */
    public function giveTicket(Person $person, int $redemptionId, string $email, string $name): array
    {
        if (null === $this->transfers) {
            return self::transferAnswer(TicketTransferService::DISABLED);
        }

        $result = $this->transfers->offerToEmail($redemptionId, $person->id, $email, $name);

        if (TicketTransferService::OK !== $result['code'] || null === $result['transfer']) {
            $code = TicketTransferService::OK === $result['code'] ? TicketTransferService::GONE : $result['code'];

            return self::transferAnswer($code);
        }

        return ['ok' => true, 'code' => 'transfer_ok', 'text' => $this->transfers->sentText($result['transfer'])];
    }

    /**
     * Cancel offer on the Tickets card: the bot's wording.
     *
     * @return array{ok: bool, code: string, text: string}
     */
    public function cancelTicketOffer(Person $person, int $redemptionId): array
    {
        $code = null === $this->transfers
            ? TicketTransferService::GONE
            : $this->transfers->cancelOffer($redemptionId, $person->id);

        if (TicketTransferService::OK !== $code) {
            return self::transferAnswer($code);
        }

        return ['ok' => true, 'code' => 'offer_cancelled', 'text' => self::noticeText('offer_cancelled')];
    }

    /** @return array{ok: bool, code: string, text: string} */
    private static function transferAnswer(string $code): array
    {
        return ['ok' => false, 'code' => 'transfer_' . $code, 'text' => TicketTransferService::message($code)];
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
     * The language the crew page is shown in, the first that is known of:
     * the signed-in person's choice; the language of the website page the
     * visitor came from (the link's marker, kept for the browser session, see
     * PageLanguage); for a signed-out visitor, the site language the browser
     * prefers. Otherwise English (null).
     */
    public function pageLanguage(): ?string
    {
        $person = $this->currentPerson();

        if (null !== $person?->language) {
            return $person->language;
        }

        $marked = PageLanguage::marked();

        if (null !== $marked) {
            return $marked;
        }

        if (null !== $person) {
            return null;
        }

        // The answer now depends on this header; tell caches so.
        if (! headers_sent()) {
            header('Vary: Accept-Language', false);
        }

        $header = isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])
            ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_ACCEPT_LANGUAGE']))
            : null;

        return Language::fromBrowser($header);
    }

    /**
     * The person the session cookie identifies, or null - also null for a
     * blocked account. The same reading the Admin tab's guard uses.
     */
    private function currentPerson(): ?Person
    {
        if (! $this->personRead) {
            $this->person = (new SessionPerson($this->people))->current();
            $this->personRead = true;
        }

        return $this->person;
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
     * Counts one sign-in link request against the IP address and against the
     * email address. False once either has reached its limit within the last
     * LOGIN_RATE_WINDOW. Both are stored only as hashes, and only for the
     * window. An empty IP (not known) is not counted.
     */
    private function allowLoginRequest(string $email, string $clientIp): bool
    {
        $keys = [
            self::rateKey('email|' . strtolower($email)) => self::LOGIN_LIMIT_PER_EMAIL,
        ];

        if ('' !== $clientIp) {
            $keys[self::rateKey('ip|' . $clientIp)] = self::LOGIN_LIMIT_PER_IP;
        }

        $now = time();
        $counts = [];

        foreach ($keys as $key => $limit) {
            $seen = get_transient($key);

            if (! is_array($seen) || (int) ($seen['since'] ?? 0) + self::LOGIN_RATE_WINDOW <= $now) {
                $seen = ['since' => $now, 'count' => 0];
            }

            if ((int) $seen['count'] >= $limit) {
                return false;
            }

            $counts[$key] = $seen;
        }

        foreach ($counts as $key => $seen) {
            $seen['count'] = (int) $seen['count'] + 1;
            set_transient($key, $seen, max(1, (int) $seen['since'] + self::LOGIN_RATE_WINDOW - $now));
        }

        return true;
    }

    private static function rateKey(string $what): string
    {
        return self::LOGIN_RATE_PREFIX . substr(hash('sha256', 'eventcrew|' . $what), 0, 32);
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

    /**
     * False when the request names another site as its origin (the Origin
     * header, else the Referer). A request with neither is let through:
     * browsers send Origin with every cross-site POST.
     */
    private function sameOrigin(): bool
    {
        // phpcs:disable WordPress.Security.NonceVerification -- read-only header check.
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) wp_unslash($_SERVER['HTTP_ORIGIN']) : '';

        if ('' === $origin && isset($_SERVER['HTTP_REFERER'])) {
            $origin = (string) wp_unslash($_SERVER['HTTP_REFERER']);
        }
        // phpcs:enable WordPress.Security.NonceVerification

        if ('' === $origin || 'null' === $origin) {
            return '' === $origin;
        }

        $host = (string) wp_parse_url($origin, PHP_URL_HOST);

        return '' !== $host && 0 === strcasecmp($host, (string) wp_parse_url((string) home_url('/'), PHP_URL_HOST));
    }

    private function redirect(string $url, string $notice): never
    {
        wp_safe_redirect(add_query_arg('eventcrew_notice', $notice, $url));

        exit;
    }

    private function setTelegramLoginCookie(string $value, int $expires): void
    {
        setcookie(
            TelegramLogin::COOKIE,
            $value,
            [
                'expires' => $expires,
                'path' => '/',
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
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
