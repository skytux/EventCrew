<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Models\Task;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\AssistantKnowledge;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\BackgroundJobs;
use EventCrew\Support\BoardPush;
use EventCrew\Support\EventMeshSyncListener;
use EventCrew\Support\EventSource;
use EventCrew\Support\CronFallbackTrigger;
use EventCrew\Support\Credits;
use EventCrew\Support\DoorLinkSender;
use EventCrew\Support\DoorQuota;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\FollowupMail;
use EventCrew\Support\HybridPolicy;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\Newsletter;
use EventCrew\Support\NewsletterCrew;
use EventCrew\Support\NewsletterSchedule;
use EventCrew\Support\NewsletterWriter;
use EventCrew\Support\OpenTaskCall;
use EventCrew\Support\EventTimes;
use EventCrew\Support\Organization;
use EventCrew\Support\PrivacyPolicy;
use EventCrew\Support\Reputation;
use EventCrew\Support\ReputationSettings;
use EventCrew\Support\SalesDeskGate;
use EventCrew\Support\SecretMask;
use EventCrew\Support\SecureUrl;
use EventCrew\Support\Roles;
use EventCrew\Support\Scheduler;
use EventCrew\Support\SendWindow;
use EventCrew\Support\SecondThoughts;
use EventCrew\Support\SignupService;
use EventCrew\Support\SiteTexts;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\TakeoverDeadline;
use EventCrew\Support\TelegramLogin;
use EventCrew\Support\TicketFormats;
use EventCrew\Support\TicketReasons;
use EventCrew\Support\TicketText;
use EventCrew\Support\TranslatedText;
use EventCrew\Support\Turnstile;
use EventCrew\Support\WelcomeSender;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\CommandMenu;
use EventCrew\Telegram\GroupChat;
use EventCrew\Telegram\TelegramClient;
use EventCrew\Telegram\TicketController;
use EventCrew\Telegram\UpdateRouter;
use EventCrew\Telegram\WebhookController;
use EventCrew\MailImport\JmapMailbox;
use EventCrew\MailImport\MailFetchSettings;
use EventCrew\MailImport\MailedListImport;
use EventCrew\Web\PwaController;

/**
 * Settings carries only what the shipped features actually read. Options for
 * behaviour that does not exist yet - reminder lead times, cron windows -
 * arrive with the release that uses them, so the page never lists a control
 * that does nothing. The Telegram bot credentials appear here now only because
 * v0.4 is the release whose code reads them; the reputation controls, because
 * v0.7's join gate and standing calculator read them.
 */
final class SettingsPage
{
    public const PAGE_SLUG = 'eventcrew-settings';
    private const NONCE_ACTION = 'eventcrew_settings';
    private const SETUP_NONCE_ACTION = 'eventcrew_telegram_setup';

    public const EMAIL_NONCE_ACTION = 'eventcrew_email_template';

    private const WELCOME_NONCE_ACTION = 'eventcrew_welcome_test';
    private const PRIVACY_NONCE_ACTION = 'eventcrew_privacy_page';

    /** The language-model helper's Test connection and Remove key links. */
    private const ASSISTANT_NONCE_ACTION = 'eventcrew_assistant';

    /** How many published pages the page choice lists. */
    private const ASSISTANT_PAGE_CHOICES = 100;

    /** The plugin version the webhook was last (re)installed for; see maybeInstallOnUpdate(). */
    /** The background job that re-installs the webhook after the address moved. */
    public const WEBHOOK_JOB = 'telegram_webhook';
    public const WEBHOOK_TARGET = 'telegram:webhook';

    /** The BackgroundJobs kind and target of "Post the board in the group" (boardJob()). */
    public const BOARD_JOB = 'telegram_board_post';
    public const BOARD_TARGET = 'telegram:board';

    private const WEBHOOK_VERSION_OPTION = 'eventcrew_webhook_version';

    /** When the last automatic install failed; installOnUpdate() waits WEBHOOK_RETRY_GAP after it. */
    private const WEBHOOK_FAILED_OPTION = 'eventcrew_webhook_failed_at';

    private const WEBHOOK_RETRY_GAP = 10 * 60;

    /** setWebhook attempts when Telegram answers "retry after N", and the longest wait between them. */
    private const WEBHOOK_TRIES = 3;

    private const WEBHOOK_MAX_WAIT = 5;

    /** The translation error kind from saving the role names and descriptions, '' when none. */
    private string $rolesError = '';

    public function __construct(
        private readonly View $view,
        private readonly TelegramClient $telegram,
        private readonly Mailer $mailer,
        private readonly EmailTemplate $emailTemplate,
        private readonly BoardService $board,
        private readonly WelcomeSender $welcome,
        private readonly PersonRepository $people,
        private readonly CommandMenu $commandMenu,
        private readonly ?LlmClient $llm = null
    ) {
    }

    public function render(): void
    {
        $reputation = new ReputationSettings();

        // The Media Library picker for the sign-up bar's image. Its scripts
        // print in the admin footer, so asking for them here is early enough.
        if (function_exists('wp_enqueue_media')) {
            wp_enqueue_media();
        }

        $this->view->render(
            'settings',
            [
                'roles' => Roles::all(),
                'guide_url' => Roles::guideUrl(),
                'organize_plus_ones' => Roles::defaultPlusOnes(Roles::ORGANIZE_SLUG),
                'nonce_action' => self::NONCE_ACTION,
                'eventmesh_available' => EventSource::isAvailable(),
                'auto_create_tasks' => (bool) get_option(EventMeshSyncListener::OPTION_NAME, false),
                'notice_hours' => max(0, (int) get_option(SignupService::NOTICE_HOURS_OPTION, 48)),
                'signout_lock_minutes' => SignupService::signoutLockMinutes(),
                'second_thoughts_minutes' => SecondThoughts::minutes(),
                'takeover_rows' => $this->takeoverRows(),
                'reputation_threshold' => (float) get_option(
                    StandingCalculator::THRESHOLD_OPTION,
                    Reputation::DEFAULT_THRESHOLD
                ),
                'reputation_weights' => $this->reputationWeightRows(),
                'tasks_per_credit' => $reputation->tasksPerCredit(),
                'min_rated_tasks' => $reputation->minRatedTasks(),
                'reputation_half_life' => $reputation->halfLifeDays(),
                'reputation_gate' => (bool) get_option(SignupService::GATE_OPTION, true),
                'board_push_enabled' => (bool) get_option(BoardPush::ENABLED_OPTION, true),
                'board_push_lead_week' => max(
                    0,
                    (int) get_option(BoardPush::LEAD_WEEK_OPTION, BoardPush::LEAD_WEEK_DEFAULT)
                ),
                'board_push_lead_soon' => max(
                    0,
                    (int) get_option(BoardPush::LEAD_SOON_OPTION, BoardPush::LEAD_SOON_DEFAULT)
                ),
                'notify_hour' => SendWindow::hour(),
                'open_task_lead_week' => max(
                    0,
                    (int) get_option(OpenTaskCall::LEAD_WEEK_OPTION, OpenTaskCall::LEAD_WEEK_DEFAULT)
                ),
                'open_task_lead_soon' => max(
                    0,
                    (int) get_option(OpenTaskCall::LEAD_SOON_OPTION, OpenTaskCall::LEAD_SOON_DEFAULT)
                ),
                'door_link_lead' => DoorLinkSender::leadMinutes(),
                'door_link_crew' => SalesDeskGate::crewMinutes(),
                'door_scan_open' => SalesDeskGate::scanOpenMinutes(),
                'door_scan_close' => SalesDeskGate::scanCloseMinutes(),
                'door_max_people' => DoorQuota::max(),
                'door_allow_over_max' => DoorQuota::allowsOver(),
                'welcome_enabled' => WelcomeSender::enabled(),
                'newsletter_enabled' => Newsletter::enabled(),
                'followup_enabled' => FollowupMail::enabled(),
                'ticket_text' => TicketText::settings(),
                'ticket_reasons' => TicketReasons::allSettings(),
                'newsletter_days' => Newsletter::days(),
                'newsletter_crew' => NewsletterCrew::enabled(),
                'newsletter_preview_mode' => NewsletterSchedule::previewMode(),
                'newsletter_preview_hours' => NewsletterSchedule::previewHours(),
                'newsletter_preview_days' => NewsletterSchedule::previewDays(),
                'newsletter_preview_time' => NewsletterSchedule::previewTime(),
                'newsletter_send_time' => NewsletterSchedule::sendTime(),
                'newsletter_bar_image' => Newsletter::barImageId(),
                'newsletter_bar_image_url' => Newsletter::barImageId() > 0
                    ? (string) wp_get_attachment_image_url(Newsletter::barImageId(), 'medium')
                    : '',
                'newsletter_write' => NewsletterWriter::enabled(),
                'newsletter_words' => NewsletterWriter::words(),
                'llm_configured' => AssistantSettings::isConfigured(),
                'turnstile_site_key' => (string) get_option(Turnstile::SITE_KEY_OPTION, ''),
                'turnstile_secret' => SecretMask::mask((string) get_option(Turnstile::SECRET_KEY_OPTION, '')),
                'cron_fallback' => (bool) get_option(CronFallbackTrigger::OPTION, false),
                'cron_next_run' => wp_next_scheduled(Scheduler::HOOK),
                'cron_last_run' => (int) get_option(Scheduler::LAST_RUN_OPTION, 0),
                'app_page_id' => (int) get_option(PwaController::PAGE_OPTION, 0),
                'orphan_signup_page' => $this->unselectedSignupPage(),
                'app_name' => SiteTexts::value('app_name'),
                'app_theme_color' => (string) get_option(PwaController::COLOR_OPTION, PwaController::DEFAULT_COLOR),
                'app_accent_color' => (string) (
                    get_option(PwaController::ACCENT_OPTION, '')
                    ?: get_option(PwaController::COLOR_OPTION, PwaController::DEFAULT_COLOR)
                ),
                'signature' => SiteTexts::value('signature'),
                'email_html' => EmailTemplate::enabled(),
                'email_logo' => (string) get_option(EmailTemplate::LOGO_OPTION, ''),
                'email_background' => (string) get_option(EmailTemplate::BACKGROUND_OPTION, ''),
                'hybrid' => self::hybridView(),
                'organization_name' => Organization::stored(),
                'ticket_background' => (string) get_option(TicketController::BACKGROUND_OPTION, ''),
                'ticket_note' => SiteTexts::value('ticket_note'),
                'doors_close_on' => EventTimes::doorsOn(),
                'doors_close_minutes' => EventTimes::doorsMinutes(),
                'infrastructure_note' => SiteTexts::value('infrastructure_note'),
                'email_edit_url' => $this->emailTemplate->editUrl(),
                'email_nonce_action' => self::EMAIL_NONCE_ACTION,
                'welcome_nonce_action' => self::WELCOME_NONCE_ACTION,
                'email_test_to' => (string) wp_get_current_user()->user_email,
                'import_mailbox' => MailedListImport::mailbox(),
                'import_senders' => MailedListImport::extraSenders(),
                'ticket_formats' => TicketFormats::all(),
                'mail_fetch' => MailFetchSettings::get(),
                'mail_fetch_status' => MailFetchSettings::status(),
                'telegram' => $this->telegramView(),
                'assistant' => $this->assistantView(),
                'privacy' => $this->privacyView(),
            ]
        );
    }

    /**
     * The Hybrid tick of each message kind, for Settings > Email.
     *
     * @return array{allowed: bool, warning: string, kinds: array<string, array{label: string, ticked: bool}>}
     */
    public static function hybridView(): array
    {
        $kinds = [];

        foreach (HybridPolicy::labels() as $kind => $label) {
            $kinds[$kind] = ['label' => $label, 'ticked' => HybridPolicy::ticked($kind)];
        }

        return [
            'allowed' => HybridPolicy::isAllowed(),
            'warning' => HybridPolicy::isAllowed() ? '' : HybridPolicy::offWarning(),
            'kinds' => $kinds,
        ];
    }

    /**
     * The title of a published page carrying the signup shortcode when none has
     * been selected below, or '' when there is nothing to say.
     *
     * This option is presented as mobile-app configuration, but it decides more
     * than that: Mailer::manageUrl() reads it to answer "is there a page to send
     * people to", and when it says no, every email footer falls back to a
     * standalone page reached by a link that never expires. So a site with a
     * perfectly good signup page, whose organizer simply never picked it here,
     * quietly mails out longer-lived links than one that did. Naming the page
     * we found is the cheapest way to make that visible where the choice is.
     *
     * Runs on the settings screen only, and stops at the first match.
     */
    private function unselectedSignupPage(): string
    {
        if ((int) get_option(PwaController::PAGE_OPTION, 0) > 0) {
            return '';
        }

        foreach (get_pages(['post_status' => 'publish']) ?: [] as $page) {
            if (has_shortcode((string) $page->post_content, 'eventcrew_signup')) {
                return (string) $page->post_title;
            }
        }

        return '';
    }

    /**
     * The Privacy tab's state.
     *
     * `pages` is the same list the app-page picker uses, so the organizer picks
     * an existing page rather than being handed a shortcode to paste; `configured`
     * drives the warning that the public notice is refusing to publish, which is
     * otherwise invisible from wp-admin - the page itself hides the reason from
     * everyone but an administrator.
     *
     * @return array<string, mixed>
     */
    private function privacyView(): array
    {
        return [
            'controller' => PrivacyPolicy::controller(),
            'address' => PrivacyPolicy::address(),
            'contact' => PrivacyPolicy::contact(),
            'dpo_name' => PrivacyPolicy::dpoName(),
            'dpo_contact' => PrivacyPolicy::dpoContact(),
            'retention_years' => PrivacyPolicy::retentionYears(),
            'retention_min' => PrivacyPolicy::RETENTION_MIN,
            'retention_max' => PrivacyPolicy::RETENTION_MAX,
            'purge' => PrivacyPolicy::purgeEnabled(),
            'page_id' => PrivacyPolicy::pageId(),
            'configured' => PrivacyPolicy::isConfigured(),
            'notice_url' => PrivacyPolicy::noticeUrl(),
            'buyer_page_id' => PrivacyPolicy::buyerPageId(),
            'buyer_notice_url' => PrivacyPolicy::buyerNoticeUrl(),
            'create_buyer_url' => wp_nonce_url(
                admin_url('admin-post.php?action=eventcrew_create_buyer_privacy_page'),
                self::PRIVACY_NONCE_ACTION
            ),
            'core_policy_url' => admin_url('options-privacy.php'),
            'create_nonce_action' => self::PRIVACY_NONCE_ACTION,
        ];
    }

    /**
     * The Telegram fieldset's state. The live webhook status is fetched only
     * when a token exists, so an un-configured install never makes an outbound
     * call just to render the page. The template documents the full shape.
     *
     * @return array<string, mixed>
     */
    private function telegramView(): array
    {
        $configured = $this->telegram->isConfigured();
        $useFallback = (bool) get_option(WebhookController::USE_FALLBACK_OPTION, false);
        $secret = trim((string) get_option(WebhookController::SECRET_OPTION, ''));

        // The URL Telegram is pointed at, so it can be tested by hand. The
        // secret in it, if any, is shown only by its last 4 characters, so a
        // screenshot of the page does not carry it.
        $testUrl = '' === $secret ? '' : WebhookController::webhookUrl(SecretMask::mask($secret));

        // The same request with the secret moved out of the URL and into the
        // header. On the fallback door this is what the endpoint would receive
        // once the URL copy is switched off, so it can be tried with curl
        // before anything about the live webhook changes - which is the whole
        // difference between a verified change and a hopeful one.
        $headerOnlyUrl = '';

        if ('' !== $secret && $useFallback) {
            $headerOnlyUrl = add_query_arg(
                ['action' => WebhookController::FALLBACK_ACTION],
                admin_url('admin-ajax.php')
            );
        }

        // The informational URL (no secret) for the status line.
        $displayUrl = $useFallback
            ? admin_url('admin-ajax.php') . '?action=' . WebhookController::FALLBACK_ACTION
            : rest_url(WebhookController::ROUTE_NAMESPACE . WebhookController::ROUTE);

        return [
            'token' => SecretMask::mask((string) get_option(TelegramClient::TOKEN_OPTION, '')),
            'configured' => $configured,
            'dns_bypass' => (bool) get_option(TelegramClient::DNS_BYPASS_OPTION, false),
            'web_login' => self::webLoginView(),
            'use_fallback' => $useFallback,
            'secret_in_url' => WebhookController::secretTravelsInUrl(),
            'webhook_url' => $displayUrl,
            'test_url' => $testUrl,
            'header_only_url' => $headerOnlyUrl,
            'secret' => SecretMask::mask($secret),
            'webhook_info' => $configured ? $this->telegram->getWebhookInfo() : null,
            'bot_username' => (string) get_option(BoardService::USERNAME_OPTION, ''),
            'board_chat_id' => GroupChat::current(),
            'group_link' => (string) get_option(BoardService::GROUP_LINK_OPTION, ''),
            'group_link_auto' => (string) get_option(BoardService::GROUP_LINK_AUTO_OPTION, ''),
            'group_lock' => (bool) get_option(BoardService::LOCK_OPTION, true),
            'board_cards' => BoardService::cardsOn(),
            'profile' => [
                'about' => BotProfileSettings::value('about'),
                'description' => BotProfileSettings::value('description'),
            ],
            'setup_nonce_action' => self::SETUP_NONCE_ACTION,
        ];
    }

    /**
     * The "Sign in with Telegram" row: the on/off switch for signing in to
     * the Crew app through the bot. The switch alone is not enough: the
     * button also needs the bot's username, which installing the webhook
     * stores. needs_username is true when the switch is on and the username
     * is still empty.
     *
     * @return array{enabled: bool, needs_username: bool}
     */
    public static function webLoginView(): array
    {
        $enabled = (bool) get_option(TelegramLogin::ENABLED_OPTION, false);

        return [
            'enabled' => $enabled,
            'needs_username' => $enabled && '' === TelegramLogin::botUsername(),
        ];
    }

    /**
     * The language-model helper's fields. The API key itself is never part of
     * it: only whether one is stored and readable.
     *
     * @return array<string, mixed>
     */
    private function assistantView(): array
    {
        $pages = get_posts([
            'post_type' => 'page',
            'post_status' => 'publish',
            'numberposts' => self::ASSISTANT_PAGE_CHOICES,
            'orderby' => 'title',
            'order' => 'ASC',
            'has_password' => false,
        ]);

        $choices = [];

        foreach (is_array($pages) ? $pages : [] as $page) {
            $choices[(int) $page->ID] = (string) $page->post_title;
        }

        $defaults = [];

        foreach (AssistantKnowledge::defaultPageIds() as $id) {
            $defaults[] = (string) get_the_title($id);
        }

        $link = static fn (string $action): string => wp_nonce_url(
            add_query_arg('action', $action, admin_url('admin-post.php')),
            self::ASSISTANT_NONCE_ACTION
        );

        return [
            'enabled' => AssistantSettings::isEnabled(),
            'forward' => AssistantSettings::forwardEnabled(),
            'url' => AssistantSettings::url(),
            'model' => AssistantSettings::model(),
            'key_status' => AssistantSettings::keyStatus(),
            'private_limit' => AssistantSettings::privateLimit(),
            'group_limit' => AssistantSettings::groupLimit(),
            'pages' => AssistantSettings::pageIds(),
            'page_choices' => $choices,
            'default_pages' => $defaults,
            'test_url' => $link('eventcrew_assistant_test'),
            'remove_key_url' => $link('eventcrew_assistant_remove_key'),
        ];
    }

    /**
     * The takeover request settings as rows for the Settings form: the field
     * name, the option it saves to, the current value, the smallest allowed
     * value, and the label and help text.
     *
     * @return array<int, array{name: string, option: string, value: int, min: int, label: string, help: string}>
     */
    private function takeoverRows(): array
    {
        return [
            [
                'name' => 'takeover_near_hours',
                'option' => TakeoverDeadline::NEAR_HOURS_OPTION,
                'value' => TakeoverDeadline::nearHours(),
                'min' => 0,
                'label' => __('Short notice (hours before the start)', 'eventcrew'),
                'help' => __('A request made less than this many hours before the task starts uses the short-notice answer time. Default 48.', 'eventcrew'),
            ],
            [
                'name' => 'takeover_near_answer_hours',
                'option' => TakeoverDeadline::NEAR_ANSWER_OPTION,
                'value' => TakeoverDeadline::nearAnswerHours(),
                'min' => 1,
                'label' => __('Answer time at short notice (hours)', 'eventcrew'),
                'help' => __('How long the holder has to answer a short-notice request. Default 24.', 'eventcrew'),
            ],
            [
                'name' => 'takeover_week_days',
                'option' => TakeoverDeadline::WEEK_DAYS_OPTION,
                'value' => TakeoverDeadline::weekDays(),
                'min' => 0,
                'label' => __('Medium notice (days before the start)', 'eventcrew'),
                'help' => __('A request made less than this many days before the task starts, but not at short notice, uses the medium-notice answer time. Default 7.', 'eventcrew'),
            ],
            [
                'name' => 'takeover_week_answer_hours',
                'option' => TakeoverDeadline::WEEK_ANSWER_OPTION,
                'value' => TakeoverDeadline::weekAnswerHours(),
                'min' => 1,
                'label' => __('Answer time at medium notice (hours)', 'eventcrew'),
                'help' => __('How long the holder has to answer a medium-notice request. Default 48.', 'eventcrew'),
            ],
            [
                'name' => 'takeover_far_answer_hours',
                'option' => TakeoverDeadline::FAR_ANSWER_OPTION,
                'value' => TakeoverDeadline::farAnswerHours(),
                'min' => 1,
                'label' => __('Answer time further ahead (hours)', 'eventcrew'),
                'help' => __('How long the holder has to answer a request made further ahead. Default 72.', 'eventcrew'),
            ],
            [
                'name' => 'takeover_cutoff_hours',
                'option' => TakeoverDeadline::CUTOFF_HOURS_OPTION,
                'value' => TakeoverDeadline::cutoffHours(),
                'min' => 0,
                'label' => __('Last answer (hours before the start)', 'eventcrew'),
                'help' => __('The answer deadline is never later than this many hours before the task starts. When that time has passed, nobody can ask any more. Default 1.', 'eventcrew'),
            ],
        ];
    }

    /**
     * The per-outcome reputation weights as ordered rows the Settings form
     * renders - each with the status slug it posts under, its human label and
     * the current percentage.
     *
     * @return array<int, array{status: string, label: string, percent: int}>
     */
    private function reputationWeightRows(): array
    {
        $percents = (new ReputationSettings())->weightPercents();
        $rows = [];

        foreach (Reputation::outcomeLabels() as $status => $label) {
            $rows[] = [
                'status' => $status,
                'label' => $label,
                'percent' => $percents[$status] ?? 0,
            ];
        }

        return $rows;
    }

    public function save(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        Roles::save($this->submittedRoles());

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in assertCanSave().
        $guideUrl = isset($_POST['guide_url']) ? esc_url_raw(wp_unslash($_POST['guide_url'])) : '';
        update_option(Roles::GUIDE_URL_OPTION, $guideUrl);

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in assertCanSave().
        $organizePlusOnes = isset($_POST['organize_plus_ones'])
            ? trim(wp_unslash((string) $_POST['organize_plus_ones']))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if (is_numeric($organizePlusOnes)) {
            update_option(Roles::ORGANIZE_PLUS_ONES_OPTION, Task::clampPlusOnes((int) $organizePlusOnes));
        }

        // Checkbox: absent means unticked, same reasoning as the role
        // archive checkboxes above - an absent field is a real "off", not
        // "leave unchanged".
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        update_option(EventMeshSyncListener::OPTION_NAME, isset($_POST['auto_create_tasks']));

        // The page never shows the saved token, so an empty field keeps it;
        // only the Remove box clears it.
        $token = isset($_POST['telegram_bot_token'])
            ? sanitize_text_field(wp_unslash($_POST['telegram_bot_token']))
            : '';

        if (isset($_POST['telegram_bot_token_remove'])) {
            update_option(TelegramClient::TOKEN_OPTION, '');
        } elseif ('' !== $token) {
            update_option(TelegramClient::TOKEN_OPTION, $token);
        }

        update_option(TelegramClient::DNS_BYPASS_OPTION, isset($_POST['telegram_dns_bypass']));
        // The group board's card format; the board changes at its next refresh.
        update_option(BoardService::CARDS_OPTION, isset($_POST['telegram_board_cards']));
        update_option(TelegramLogin::ENABLED_OPTION, isset($_POST['telegram_web_login']));

        // Both of these change the URL Telegram must post to, so the webhook has
        // to be re-installed or the bot goes silent - and it goes silent with no
        // error anywhere, because from WordPress's side nothing is wrong. The
        // old URL is captured before the write and compared after, so the
        // re-install happens only when something actually moved.
        $urlBefore = WebhookController::webhookUrl('x');

        update_option(WebhookController::USE_FALLBACK_OPTION, isset($_POST['telegram_use_fallback']));
        update_option(
            WebhookController::SECRET_IN_URL_OPTION,
            isset($_POST['telegram_secret_in_url']) ? '1' : '0'
        );

        $doorMoved = $urlBefore !== WebhookController::webhookUrl('x');

        $groupLink = isset($_POST['telegram_group_link'])
            ? esc_url_raw(wp_unslash($_POST['telegram_group_link']))
            : '';
        update_option(BoardService::GROUP_LINK_OPTION, $groupLink);

        // Checkbox: absent means unticked (unlocked), so the board can be moved
        // to another group on the next add / /board. Default is on.
        update_option(BoardService::LOCK_OPTION, isset($_POST['telegram_group_lock']) ? '1' : '0');

        $noticeHours = isset($_POST['notice_hours']) ? max(0, (int) $_POST['notice_hours']) : 48;
        update_option(SignupService::NOTICE_HOURS_OPTION, $noticeHours);

        $lockMinutes = isset($_POST['signout_lock_minutes'])
            ? max(0, (int) $_POST['signout_lock_minutes'])
            : SignupService::SIGNOUT_LOCK_DEFAULT;
        update_option(SignupService::SIGNOUT_LOCK_OPTION, $lockMinutes);

        $secondThoughts = isset($_POST['second_thoughts_minutes'])
            ? max(0, (int) $_POST['second_thoughts_minutes'])
            : SecondThoughts::DEFAULT;
        update_option(SecondThoughts::OPTION, $secondThoughts);

        foreach ($this->takeoverRows() as $row) {
            if (isset($_POST[$row['name']]) && is_numeric($_POST[$row['name']])) {
                update_option($row['option'], max($row['min'], (int) $_POST[$row['name']]));
            }
        }

        // A threshold outside (0,1] would make the join gate never or always
        // fire; fall back to the default rather than store nonsense.
        $threshold = isset($_POST['reputation_threshold'])
            ? (float) $_POST['reputation_threshold']
            : Reputation::DEFAULT_THRESHOLD;

        if ($threshold <= 0.0 || $threshold > 1.0) {
            $threshold = Reputation::DEFAULT_THRESHOLD;
        }

        update_option(StandingCalculator::THRESHOLD_OPTION, $threshold);

        // Per-outcome weights, posted as reputation_weight[<status>] percentages.
        // Only the known scoring statuses are read, each clamped to 0..100, so a
        // stray field can never widen the set or store nonsense.
        $postedWeights = isset($_POST['reputation_weight']) && is_array($_POST['reputation_weight'])
            ? wp_unslash($_POST['reputation_weight'])
            : [];
        $weights = [];

        foreach (array_keys(Reputation::defaultWeights()) as $status) {
            if (isset($postedWeights[$status]) && is_numeric($postedWeights[$status])) {
                $weights[$status] = max(0, min(100, (int) $postedWeights[$status]));
            }
        }

        update_option(ReputationSettings::WEIGHTS_OPTION, $weights);

        // The rating and credit knobs, each floored at 1 - zero would divide by
        // zero (credits) or rate everyone from their first outcome (min tasks).
        $tasksPerCredit = isset($_POST['tasks_per_credit'])
            ? max(1, (int) $_POST['tasks_per_credit'])
            : Credits::TASKS_PER_CREDIT;
        update_option(ReputationSettings::TASKS_PER_CREDIT_OPTION, $tasksPerCredit);

        $minRatedTasks = isset($_POST['min_rated_tasks'])
            ? max(1, (int) $_POST['min_rated_tasks'])
            : Reputation::MIN_RATED_TASKS;
        update_option(ReputationSettings::MIN_RATED_TASKS_OPTION, $minRatedTasks);

        $halfLife = isset($_POST['reputation_half_life'])
            ? max(1, (int) $_POST['reputation_half_life'])
            : Reputation::HALF_LIFE_DAYS;
        update_option(ReputationSettings::HALF_LIFE_OPTION, $halfLife);

        update_option(SignupService::GATE_OPTION, isset($_POST['reputation_gate']) ? '1' : '0');

        update_option(BoardPush::ENABLED_OPTION, isset($_POST['board_push_enabled']) ? '1' : '0');
        $leadWeek = isset($_POST['board_push_lead_week'])
            ? max(0, (int) $_POST['board_push_lead_week'])
            : BoardPush::LEAD_WEEK_DEFAULT;
        $leadSoon = isset($_POST['board_push_lead_soon'])
            ? max(0, (int) $_POST['board_push_lead_soon'])
            : BoardPush::LEAD_SOON_DEFAULT;
        update_option(BoardPush::LEAD_WEEK_OPTION, $leadWeek);
        update_option(BoardPush::LEAD_SOON_OPTION, $leadSoon);

        // The hour scheduled notifications may start going out. Anything
        // outside 0-23 is not a time of day, so it falls back to the default
        // rather than being stored and quietly disabling every send.
        $notifyHour = isset($_POST['notify_hour']) ? (int) $_POST['notify_hour'] : SendWindow::DEFAULT_HOUR;
        update_option(
            SendWindow::OPTION,
            $notifyHour >= 0 && $notifyHour <= 23 ? $notifyHour : SendWindow::DEFAULT_HOUR
        );

        // The open-task call's own leads. Zero is meaningful here - it turns
        // that one of the two sends off - so these floor at 0 rather than 1.
        update_option(
            OpenTaskCall::LEAD_WEEK_OPTION,
            isset($_POST['open_task_lead_week'])
                ? max(0, (int) $_POST['open_task_lead_week'])
                : OpenTaskCall::LEAD_WEEK_DEFAULT
        );
        update_option(
            OpenTaskCall::LEAD_SOON_OPTION,
            isset($_POST['open_task_lead_soon'])
                ? max(0, (int) $_POST['open_task_lead_soon'])
                : OpenTaskCall::LEAD_SOON_DEFAULT
        );

        // The sales desk link's timing, in minutes. Zero is meaningful: the
        // link goes out at the task's start itself.
        update_option(
            DoorLinkSender::LEAD_OPTION,
            isset($_POST['door_link_lead'])
                ? max(0, (int) $_POST['door_link_lead'])
                : DoorLinkSender::DEFAULT_LEAD_MINUTES
        );
        // The organizer notice's channels: whichever boxes are ticked, none
        // meaning the notice is off.
        $orgChannels = isset($_POST['organizer_notice_channels']) && is_array($_POST['organizer_notice_channels'])
            ? array_map('sanitize_key', wp_unslash($_POST['organizer_notice_channels']))
            : [];
        update_option(
            DoorLinkSender::ORGANIZER_CHANNELS_OPTION,
            array_values(array_intersect(DoorLinkSender::ORGANIZER_CHANNELS, $orgChannels))
        );
        update_option(
            SalesDeskGate::CREW_MINUTES_OPTION,
            isset($_POST['door_link_crew'])
                ? max(0, (int) $_POST['door_link_crew'])
                : SalesDeskGate::DEFAULT_CREW_MINUTES
        );
        // When ticket QR scans are taken, around the event's start and end.
        update_option(
            SalesDeskGate::SCAN_OPEN_OPTION,
            isset($_POST['door_scan_open'])
                ? max(0, (int) $_POST['door_scan_open'])
                : SalesDeskGate::DEFAULT_SCAN_OPEN_MINUTES
        );
        update_option(
            SalesDeskGate::SCAN_CLOSE_OPTION,
            isset($_POST['door_scan_close'])
                ? max(0, (int) $_POST['door_scan_close'])
                : SalesDeskGate::DEFAULT_SCAN_CLOSE_MINUTES
        );
        // The most people let in on one night; empty or 0 means no max.
        update_option(
            DoorQuota::MAX_OPTION,
            isset($_POST['door_max_people']) ? max(0, (int) $_POST['door_max_people']) : 0
        );
        update_option(DoorQuota::OVER_OPTION, isset($_POST['door_allow_over_max']) ? '1' : '0');
        update_option(WelcomeSender::ENABLED_OPTION, isset($_POST['welcome_enabled']) ? '1' : '0');
        update_option(Newsletter::ENABLED_OPTION, isset($_POST['newsletter_enabled']) ? '1' : '0');
        FollowupMail::setEnabled(isset($_POST['followup_enabled']));
        update_option(
            Newsletter::DAYS_OPTION,
            isset($_POST['newsletter_days'])
                ? Newsletter::clampDays((int) $_POST['newsletter_days'])
                : Newsletter::DEFAULT_DAYS
        );
        update_option(NewsletterCrew::OPTION, isset($_POST['newsletter_crew']) ? '1' : '0');
        // Only an image from the Media Library is kept; anything else means none.
        $barImage = isset($_POST['newsletter_bar_image']) ? max(0, (int) $_POST['newsletter_bar_image']) : 0;
        update_option(
            Newsletter::BAR_IMAGE_OPTION,
            $barImage > 0 && wp_attachment_is_image($barImage) ? $barImage : 0
        );
        $this->saveNewsletterSchedule();
        update_option(NewsletterWriter::ENABLED_OPTION, isset($_POST['newsletter_write']) ? '1' : '0');
        update_option(
            NewsletterWriter::WORDS_OPTION,
            NewsletterWriter::cleanWords(
                isset($_POST['newsletter_words'])
                    ? sanitize_textarea_field(wp_unslash((string) $_POST['newsletter_words']))
                    : ''
            ),
            false
        );

        update_option(
            Turnstile::SITE_KEY_OPTION,
            isset($_POST['turnstile_site_key'])
                ? sanitize_text_field(wp_unslash($_POST['turnstile_site_key']))
                : ''
        );
        // Same as the bot token: an empty field keeps the saved secret key.
        $turnstileSecret = isset($_POST['turnstile_secret'])
            ? sanitize_text_field(wp_unslash($_POST['turnstile_secret']))
            : '';

        if (isset($_POST['turnstile_secret_remove'])) {
            update_option(Turnstile::SECRET_KEY_OPTION, '');
        } elseif ('' !== $turnstileSecret) {
            update_option(Turnstile::SECRET_KEY_OPTION, $turnstileSecret);
        }

        update_option(CronFallbackTrigger::OPTION, isset($_POST['cron_fallback']) ? '1' : '0');

        update_option(EmailTemplate::HTML_OPTION, isset($_POST['email_html']) ? '1' : '0');

        // A greyed-out tick posts nothing, so the ticks are kept as they are
        // while hybrid is off for the number of languages.
        if (HybridPolicy::isAllowed()) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only the keys are read, against HybridPolicy::KINDS.
            update_option(HybridPolicy::OPTION, HybridPolicy::fromPost(wp_unslash($_POST['hybrid'] ?? [])));
        }
        update_option(
            EmailTemplate::LOGO_OPTION,
            isset($_POST['email_logo']) ? esc_url_raw(wp_unslash($_POST['email_logo'])) : ''
        );
        update_option(
            EmailTemplate::BACKGROUND_OPTION,
            isset($_POST['email_background']) ? esc_url_raw(wp_unslash($_POST['email_background'])) : ''
        );
        if (isset($_POST['organization_name'])) {
            Organization::save(wp_unslash((string) $_POST['organization_name']));
        }

        EventTimes::saveDoorsSetting(
            isset($_POST['doors_close_on']),
            isset($_POST['doors_close_minutes']) ? sanitize_text_field(wp_unslash($_POST['doors_close_minutes'])) : ''
        );

        update_option(
            TicketController::BACKGROUND_OPTION,
            isset($_POST['ticket_background']) ? esc_url_raw(wp_unslash($_POST['ticket_background'])) : ''
        );

        $appPageId = isset($_POST['app_page_id']) ? max(0, (int) $_POST['app_page_id']) : 0;
        update_option(PwaController::PAGE_OPTION, $appPageId);

        $appColor = isset($_POST['app_theme_color'])
            ? sanitize_hex_color(wp_unslash($_POST['app_theme_color']))
            : '';
        update_option(
            PwaController::COLOR_OPTION,
            '' === (string) $appColor ? PwaController::DEFAULT_COLOR : $appColor
        );

        $accentColor = isset($_POST['app_accent_color'])
            ? sanitize_hex_color(wp_unslash($_POST['app_accent_color']))
            : '';

        if ('' !== (string) $accentColor) {
            update_option(PwaController::ACCENT_OPTION, $accentColor);
        }
        $this->savePrivacy();
        $assistantError = $this->saveAssistant();
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        $profile = $this->saveBotProfile();
        $ticketError = $this->saveTicketText();
        $reasonError = $this->saveTicketReasons();
        $siteError = $this->saveSiteTexts();
        $textsNotice = TranslatedText::errorNotice(
            current(array_filter([$ticketError, $reasonError, $siteError, $this->rolesError])) ?: ''
        );

        // Both use the same notice; it is said once.
        if ('' !== $textsNotice && ! str_contains($profile['message'], $textsNotice)) {
            $profile = ['ok' => false, 'message' => trim($textsNotice . ' ' . $profile['message'])];
        }

        $profileSuffix = '' === $profile['message'] ? '' : ' ' . $profile['message'];

        // Telegram is still posting to the old URL until it is told otherwise,
        // so a moved door is re-installed here rather than left to the organizer
        // to remember. Only when a bot is configured - there is nothing to
        // install otherwise - and a failure is reported rather than swallowed,
        // since the alternative is a bot that has quietly stopped receiving.
        // The Telegram calls run in the background (webhookJob); the Webhook
        // heading shows the job's badge, a failure included.
        if ($doorMoved && $this->telegram->isConfigured()) {
            BackgroundJobs::enqueue(self::WEBHOOK_JOB, self::WEBHOOK_TARGET, []);
        }

        if ('' !== $assistantError) {
            Admin::redirectTo(self::PAGE_SLUG, $assistantError . $profileSuffix, 'error');
        }

        Admin::redirectTo(
            self::PAGE_SLUG,
            __('Settings saved.', 'eventcrew') . $profileSuffix,
            $profile['ok'] ? 'success' : 'error'
        );
    }

    /**
     * Saves the bot's About and Description texts (BotProfileSettings) and
     * sends each changed language text to Telegram.
     *
     * @return array{ok: bool, message: string}
     */
    private function saveBotProfile(): array
    {
        $profile = new BotProfileSettings($this->telegram, $this->translator());

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in assertCanSave(); sanitized per field in TranslatedText::fromPost().
        return $profile->save($_POST[BotProfileSettings::POST_NAME] ?? null);
    }

    /**
     * Saves the ticket email's text (TicketText). Returns the translation
     * error kind, '' when none.
     */
    private function saveTicketText(): string
    {
        $text = new TicketText($this->llm ?? new LlmClient(new Logger()), new Logger(), true);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in assertCanSave(); sanitized per field in TranslatedText::fromPost().
        return $text->saveSettings($_POST[TicketText::POST_NAME] ?? null);
    }

    /**
     * Saves the four {reason} lines of the ticket email (TicketReasons).
     * Returns the translation error kind, '' when none.
     */
    private function saveTicketReasons(): string
    {
        $reasons = new TicketReasons($this->llm ?? new LlmClient(new Logger()), new Logger(), true);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in assertCanSave(); sanitized per field in TranslatedText::fromPost().
        return $reasons->saveSettings($_POST[TicketReasons::POST_NAME] ?? null);
    }

    /**
     * Saves the signature, ticket note, infrastructure note and app name
     * (SiteTexts). Returns the translation error kind, '' when none.
     */
    private function saveSiteTexts(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in assertCanSave(); sanitized per field in TranslatedText::fromPost().
        return SiteTexts::save($this->translator(), $_POST);
    }

    /**
     * The translator for the written-once texts on this page. It queues the
     * translations in the background (BackgroundJobs), so the save answers
     * at once.
     */
    private function translator(): TranslatedText
    {
        return new TranslatedText($this->llm ?? new LlmClient(new Logger()), new Logger(), true);
    }

    /**
     * When the newsletter's preview and the newsletter go out
     * (NewsletterSchedule). A field that was not posted, or a time that is
     * not a time of day, keeps the stored value.
     */
    private function saveNewsletterSchedule(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in assertCanSave().
        if (isset($_POST['newsletter_preview_mode'])) {
            $mode = sanitize_key(wp_unslash($_POST['newsletter_preview_mode']));
            update_option(
                NewsletterSchedule::PREVIEW_MODE_OPTION,
                NewsletterSchedule::PREVIEW_BEFORE_EVENT === $mode
                    ? NewsletterSchedule::PREVIEW_BEFORE_EVENT
                    : NewsletterSchedule::PREVIEW_AFTER_PREVIOUS
            );
        }
        if (isset($_POST['newsletter_preview_hours'])) {
            update_option(
                NewsletterSchedule::PREVIEW_HOURS_OPTION,
                NewsletterSchedule::clampPreviewHours((int) $_POST['newsletter_preview_hours'])
            );
        }
        if (isset($_POST['newsletter_preview_days'])) {
            update_option(
                NewsletterSchedule::PREVIEW_DAYS_OPTION,
                NewsletterSchedule::clampPreviewDays((int) $_POST['newsletter_preview_days'])
            );
        }
        $times = [
            'newsletter_preview_time' => NewsletterSchedule::PREVIEW_TIME_OPTION,
            'newsletter_send_time' => NewsletterSchedule::SEND_TIME_OPTION,
        ];
        foreach ($times as $field => $option) {
            if (! isset($_POST[$field])) {
                continue;
            }
            $time = NewsletterSchedule::parseTime(sanitize_text_field(wp_unslash($_POST[$field])));
            if (null !== $time) {
                update_option($option, $time);
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    /**
     * The language-model helper's fields on the Telegram bot tab. An empty
     * key field keeps the stored key. Returns an error to show, or ''.
     *
     * Nothing changes when the fields were not posted at all, so a form from
     * before this section existed cannot switch the helper off.
     */
    private function saveAssistant(): string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in assertCanSave().
        if (! isset($_POST['assistant_url'])) {
            return '';
        }

        update_option(AssistantSettings::ENABLED_OPTION, isset($_POST['assistant_enabled']), false);
        update_option(AssistantSettings::FORWARD_OPTION, isset($_POST['assistant_forward']), false);

        $rawUrl = trim(sanitize_text_field(wp_unslash((string) $_POST['assistant_url'])));
        $model = isset($_POST['assistant_model'])
            ? sanitize_text_field(wp_unslash((string) $_POST['assistant_model']))
            : '';
        // Not sanitize_text_field(): it would eat a "%xx" or "<" inside a key.
        // A key has no spaces or control characters, so those are dropped.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned by the preg_replace.
        $rawKey = isset($_POST['assistant_key']) ? wp_unslash((string) $_POST['assistant_key']) : '';
        $key = (string) preg_replace('/[\x00-\x20\x7F]+/', '', $rawKey);

        foreach (
            [
                'assistant_private_limit' => AssistantSettings::PRIVATE_LIMIT_OPTION,
                'assistant_group_limit' => AssistantSettings::GROUP_LIMIT_OPTION,
            ] as $field => $option
        ) {
            if (isset($_POST[$field]) && is_numeric(wp_unslash((string) $_POST[$field]))) {
                update_option($option, min(10000, absint(wp_unslash((string) $_POST[$field]))), false);
            }
        }

        $pages = isset($_POST['assistant_pages']) && is_array($_POST['assistant_pages'])
            ? array_values(array_filter(array_map('absint', wp_unslash($_POST['assistant_pages']))))
            : [];
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        update_option(AssistantSettings::PAGES_OPTION, $pages, false);
        update_option(AssistantSettings::MODEL_OPTION, trim($model), false);

        if ('' !== $key) {
            AssistantSettings::saveKey($key);
        }

        AssistantKnowledge::flush();

        $url = AssistantSettings::normalizeUrl($rawUrl);

        if ('' !== $rawUrl && '' === $url) {
            return __('Settings saved, except the LLM API URL: it must start with https:// (http:// only to localhost or 127.0.0.1).', 'eventcrew');
        }

        update_option(AssistantSettings::URL_OPTION, $url, false);

        return '';
    }

    /**
     * Test connection: one tiny request to the language model, reported as OK
     * or as the kind of error.
     */
    public function testAssistant(): void
    {
        Admin::assertCanSave(self::ASSISTANT_NONCE_ACTION);

        if (! AssistantSettings::isConfigured()) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('Enter the LLM API URL and the model, and save, before testing.', 'eventcrew'),
                'error'
            );
        }

        $error = ($this->llm ?? new LlmClient(new Logger()))->test();

        if ('' === $error) {
            Admin::redirectTo(self::PAGE_SLUG, __('The language model answered. The connection is OK.', 'eventcrew'));
        }

        Admin::redirectTo(
            self::PAGE_SLUG,
            /* translators: %s: the kind of error, e.g. timeout or http_401 */
            sprintf(__('The connection test failed: %s.', 'eventcrew'), $error),
            'error'
        );
    }

    /** Remove key: deletes the stored API key. */
    public function removeAssistantKey(): void
    {
        Admin::assertCanSave(self::ASSISTANT_NONCE_ACTION);

        AssistantSettings::removeKey();

        Admin::redirectTo(self::PAGE_SLUG, __('The API key was removed.', 'eventcrew'));
    }

    /**
     * The admin notice for a stored API key that can no longer be decrypted
     * (the site's salts changed, or the database came from another site).
     * Shown to administrators on every wp-admin page while the helper is on.
     */
    public function unreadableKeyNotice(): void
    {
        if (
            ! current_user_can(Admin::CAPABILITY)
            || ! AssistantSettings::isEnabled()
            || AssistantSettings::KEY_UNREADABLE !== AssistantSettings::keyStatus()
        ) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__(
                'EventCrew: the stored LLM API key cannot be decrypted, so the bot sends no key. Enter the key again in Settings > Telegram bot.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                'eventcrew'
            )
        );
    }

    /**
     * The admin notice for a stored mailbox password that can no longer be
     * decrypted (the site's salts changed, or the database came from another
     * site). Shown to administrators on every wp-admin page while reading the
     * mailbox is switched on.
     */
    public function unreadableMailPasswordNotice(): void
    {
        if (
            ! current_user_can(Admin::CAPABILITY)
            || MailFetchSettings::OFF === MailFetchSettings::get()['protocol']
            || MailFetchSettings::PASSWORD_UNREADABLE !== MailFetchSettings::passwordStatus()
        ) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__(
                'EventCrew: the stored mailbox password cannot be decrypted, so the site cannot read the mailbox. Enter the password again in Settings > Email.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                'eventcrew'
            )
        );
    }

    /**
     * The Privacy tab's fields.
     *
     * The controller's postal address is a textarea because addresses have
     * lines, and sanitize_textarea_field is what keeps them. Everything is
     * stamped with an "updated" time when any of it changes - the notice
     * publishes that date, and a reader needs it to tell whether the terms have
     * moved since they signed up, so it must not be touched when nothing has.
     */
    private function savePrivacy(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- checked by save().
        $before = [
            PrivacyPolicy::controller(),
            PrivacyPolicy::address(),
            PrivacyPolicy::contact(),
            PrivacyPolicy::dpoName(),
            PrivacyPolicy::dpoContact(),
            (string) PrivacyPolicy::retentionYears(),
            PrivacyPolicy::purgeEnabled() ? '1' : '0',
        ];

        update_option(
            PrivacyPolicy::CONTROLLER_OPTION,
            isset($_POST['privacy_controller'])
                ? sanitize_text_field(wp_unslash($_POST['privacy_controller']))
                : ''
        );
        update_option(
            PrivacyPolicy::ADDRESS_OPTION,
            isset($_POST['privacy_address'])
                ? sanitize_textarea_field(wp_unslash($_POST['privacy_address']))
                : ''
        );
        update_option(
            PrivacyPolicy::CONTACT_OPTION,
            isset($_POST['privacy_contact'])
                ? sanitize_text_field(wp_unslash($_POST['privacy_contact']))
                : ''
        );
        update_option(
            PrivacyPolicy::DPO_NAME_OPTION,
            isset($_POST['privacy_dpo_name'])
                ? sanitize_text_field(wp_unslash($_POST['privacy_dpo_name']))
                : ''
        );
        update_option(
            PrivacyPolicy::DPO_CONTACT_OPTION,
            isset($_POST['privacy_dpo_contact'])
                ? sanitize_text_field(wp_unslash($_POST['privacy_dpo_contact']))
                : ''
        );

        $years = isset($_POST['privacy_retention_years'])
            ? (int) $_POST['privacy_retention_years']
            : PrivacyPolicy::RETENTION_DEFAULT;
        update_option(
            PrivacyPolicy::RETENTION_OPTION,
            min(PrivacyPolicy::RETENTION_MAX, max(PrivacyPolicy::RETENTION_MIN, $years))
        );

        update_option(PrivacyPolicy::PURGE_OPTION, isset($_POST['privacy_purge']) ? '1' : '0');

        update_option(
            PrivacyPolicy::PAGE_OPTION,
            isset($_POST['privacy_page_id']) ? max(0, (int) $_POST['privacy_page_id']) : 0
        );
        update_option(
            PrivacyPolicy::BUYER_PAGE_OPTION,
            isset($_POST['privacy_buyer_page_id']) ? max(0, (int) $_POST['privacy_buyer_page_id']) : 0
        );
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $after = [
            PrivacyPolicy::controller(),
            PrivacyPolicy::address(),
            PrivacyPolicy::contact(),
            PrivacyPolicy::dpoName(),
            PrivacyPolicy::dpoContact(),
            (string) PrivacyPolicy::retentionYears(),
            PrivacyPolicy::purgeEnabled() ? '1' : '0',
        ];

        if ($before !== $after) {
            PrivacyPolicy::stampUpdated();
        }
    }

    /**
     * Creates a published page carrying the [eventcrew_privacy] shortcode and
     * selects it, so the notice goes live without anyone learning what a
     * shortcode is.
     *
     * Refuses when a page is already chosen: the button is meant to be the
     * first step, not a way to litter the site with drafts on a double-click.
     */
    public function createPrivacyPage(): void
    {
        Admin::assertCanSave(self::PRIVACY_NONCE_ACTION);

        if (PrivacyPolicy::pageId() > 0) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('A privacy page is already chosen.', 'eventcrew'),
                'error'
            );
        }

        $pageId = wp_insert_post([
            'post_title' => __('Crew privacy notice', 'eventcrew'),
            'post_content' => '[eventcrew_privacy]',
            'post_status' => 'publish',
            'post_type' => 'page',
        ]);

        if (is_wp_error($pageId) || 0 === (int) $pageId) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('The privacy page could not be created.', 'eventcrew'),
                'error'
            );
        }

        update_option(PrivacyPolicy::PAGE_OPTION, (int) $pageId);

        Admin::redirectTo(
            self::PAGE_SLUG,
            __('Privacy page created and selected.', 'eventcrew')
        );
    }

    /**
     * Creates a published page carrying the [eventcrew_ticket_privacy]
     * shortcode and selects it. Same rules as createPrivacyPage(): refuses
     * when a ticket buyer page is already chosen.
     */
    public function createBuyerPrivacyPage(): void
    {
        Admin::assertCanSave(self::PRIVACY_NONCE_ACTION);

        if (PrivacyPolicy::buyerPageId() > 0) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('A ticket buyer privacy page is already chosen.', 'eventcrew'),
                'error'
            );
        }

        $pageId = wp_insert_post([
            'post_title' => __('Ticket buyer privacy notice', 'eventcrew'),
            'post_content' => '[eventcrew_ticket_privacy]',
            'post_status' => 'publish',
            'post_type' => 'page',
        ]);

        if (is_wp_error($pageId) || 0 === (int) $pageId) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('The privacy page could not be created.', 'eventcrew'),
                'error'
            );
        }

        update_option(PrivacyPolicy::BUYER_PAGE_OPTION, (int) $pageId);

        Admin::redirectTo(
            self::PAGE_SLUG,
            __('Ticket buyer privacy page created and selected.', 'eventcrew')
        );
    }

    /**
     * The Settings "Send a test email" button. It goes to the logged-in
     * administrator's own address - the one person we know is entitled to see
     * it - so the button can never be turned into a way to mail someone else.
     */
    public function sendTestEmail(): void
    {
        Admin::assertCanSave(self::EMAIL_NONCE_ACTION);

        $to = (string) wp_get_current_user()->user_email;

        if ('' === $to) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('Your user account has no email address to send to.', 'eventcrew'),
                'error'
            );
        }

        if ($this->mailer->sendTest($to)) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                sprintf(
                    /* translators: %s: the administrator's email address */
                    __('Test email sent to %s.', 'eventcrew'),
                    $to
                )
            );
        }

        Admin::redirectTo(
            self::PAGE_SLUG,
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __('The test email could not be sent. Check the site’s mail configuration, then look at Diagnostics for the logged reason.', 'eventcrew'),
            'error'
        );
    }

    /**
     * The "Send me the welcome message" button: every welcome message the
     * member with this administrator's email address would get, to that member
     * only, recorded nowhere. Works while the feature is still switched off.
     */
    public function sendWelcomeTest(): void
    {
        Admin::assertCanSave(self::WELCOME_NONCE_ACTION);

        $email = (string) wp_get_current_user()->user_email;
        $person = '' === $email ? null : $this->people->findByEmail($email);

        if (null === $person || $person->isAnonymized()) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                sprintf(
                    /* translators: %s: the administrator's email address */
                    __('No member has the email address %s. Sign up as a member with this address first.', 'eventcrew'),
                    '' === $email ? '-' : $email
                ),
                'error'
            );
        }

        $results = $this->welcome->sendTest($person);
        $names = [
            WelcomeSender::KIND_EMAIL => __('member email', 'eventcrew'),
            WelcomeSender::KIND_DM => __('member Telegram message', 'eventcrew'),
            WelcomeSender::KIND_ORG_EMAIL => __('organizer email', 'eventcrew'),
            WelcomeSender::KIND_ORG_DM => __('organizer Telegram message', 'eventcrew'),
        ];
        $sent = [];
        $failed = [];

        foreach ($results as $kind => $ok) {
            if (true === $ok) {
                $sent[] = $names[$kind];
            } elseif (false === $ok) {
                $failed[] = $names[$kind];
            }
        }

        $message = sprintf(
            /* translators: 1: list of messages sent, 2: the member's email address */
            __('Sent to %2$s: %1$s.', 'eventcrew'),
            [] === $sent ? __('nothing', 'eventcrew') : implode(', ', $sent),
            $person->email
        );

        if ([] !== $failed) {
            $message .= ' ' . sprintf(
                /* translators: %s: list of messages that failed */
                __('Failed: %s. Diagnostics shows the logged reason.', 'eventcrew'),
                implode(', ', $failed)
            );
        }

        Admin::redirectTo(self::PAGE_SLUG, $message, [] === $failed ? 'success' : 'error');
    }

    /**
     * The Settings "Reset the template" button. The edit being discarded stays
     * in the post's revisions, so this is recoverable. The same button is on
     * the Messages page's Email look tab (back=messages): it returns there.
     */
    public function resetEmailTemplate(): void
    {
        Admin::assertCanSave(self::EMAIL_NONCE_ACTION);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in assertCanSave().
        $toMessages = 'messages' === sanitize_key(wp_unslash((string) ($_POST['back'] ?? '')));
        $page = $toMessages ? MessagesPage::PAGE_SLUG : self::PAGE_SLUG;
        $args = $toMessages ? ['tab' => MessagesPage::TAB_EMAIL] : [];

        if ($this->emailTemplate->reset()) {
            Admin::redirectTo(
                $page,
                __('The email template was reset to the built-in design.', 'eventcrew'),
                'success',
                $args
            );
        }

        Admin::redirectTo(
            $page,
            __('The email template could not be reset.', 'eventcrew'),
            'error',
            $args
        );
    }

    /**
     * The Settings "Install / refresh webhook" button: runs the install and
     * reports the outcome as an admin notice.
     */
    public function setupWebhook(): void
    {
        Admin::assertCanSave(self::SETUP_NONCE_ACTION);

        $result = $this->installWebhook();

        // The moment the bot is known-good is the moment to look for a link to
        // the group, rather than leaving it to the next weekly sweep.
        $this->board->refreshGroupLink(true);

        if ($result['ok']) {
            // Stamp the version so the automatic on-update refresh does not run
            // again this release after a manual install.
            update_option(self::WEBHOOK_VERSION_OPTION, EVENTCREW_VERSION);

            Admin::redirectTo(
                self::PAGE_SLUG,
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                __('Webhook installed. Add the bot to your group and the board will appear there on its own.', 'eventcrew')
            );
        }

        Admin::redirectTo(self::PAGE_SLUG, $result['error'], 'error');
    }

    /**
     * The Settings "Refresh the board" button: redraw the group's board now.
     *
     * The board already redraws itself whenever anything it shows changes - a
     * join, a task edit, the hourly heartbeat - so this is not part of the
     * normal loop. It is for the times that loop cannot cover: an upgrade that
     * changes how the board is laid out, a message edited or removed in the
     * group, or simply wanting to see the effect of a change without waiting
     * for the next tick.
     *
     * The two ways it can be unavailable are reported separately, because they
     * need different things doing: no token is a Settings problem, no board
     * chat means the bot has never been asked to post one.
     */
    public function refreshBoard(): void
    {
        Admin::assertCanSave(self::SETUP_NONCE_ACTION);

        if (! $this->telegram->isConfigured()) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('Add a bot token first, then install the webhook.', 'eventcrew'),
                'error'
            );
        }

        if (! $this->board->hasHome()) {
            Admin::redirectTo(self::PAGE_SLUG, self::noGroupText(), 'error');
        }

        if ($this->board->refresh()) {
            Admin::redirectTo(self::PAGE_SLUG, __('The board has been redrawn.', 'eventcrew'));
        }

        Admin::redirectTo(
            self::PAGE_SLUG,
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __('The board could not be redrawn — it may have been deleted from the group. Use Post the board in the group to post a fresh one. Diagnostics has the logged reason.', 'eventcrew'),
            'error'
        );
    }

    /**
     * What to do when the site knows no Telegram group: shown by the board
     * buttons and on the Telegram tab.
     */
    public static function noGroupText(): string
    {
        // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
        return __('The site does not know your Telegram group. To fix it, remove the bot from the group and add it again, or tap any button on a bot message there. Then reload this page.', 'eventcrew');
    }

    /**
     * "Post the board in the group": the /board repost, from wp-admin, with
     * no command in the group. A fresh board at the foot of the group, and
     * the old one deleted where Telegram allows. The Telegram calls run in
     * the background (boardJob()); the board section shows the job's badge.
     */
    public function postBoard(): void
    {
        Admin::assertCanSave(self::SETUP_NONCE_ACTION);

        if (! $this->telegram->isConfigured()) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('Add a bot token first, then install the webhook.', 'eventcrew'),
                'error'
            );
        }

        if (! $this->board->hasHome()) {
            Admin::redirectTo(self::PAGE_SLUG, self::noGroupText(), 'error');
        }

        BackgroundJobs::enqueue(self::BOARD_JOB, self::BOARD_TARGET, [], __('Post the board in the group', 'eventcrew'));

        // The notice below says it; the general "translations run in the
        // background" sentence would not fit.
        BackgroundJobs::resetAdded();

        Admin::redirectTo(
            self::PAGE_SLUG,
            __('The board is being posted in the group. This page shows when it is done.', 'eventcrew')
        );
    }

    /**
     * The background job (BOARD_JOB) of "Post the board in the group".
     *
     * @param array<string, mixed> $args None.
     * @return array{ok: bool, error: string}
     */
    public function boardJob(array $args = []): array
    {
        if (! $this->board->hasHome()) {
            return ['ok' => false, 'error' => self::noGroupText()];
        }

        if ($this->board->repost()) {
            return ['ok' => true, 'error' => ''];
        }

        return [
            'ok' => false,
            'error' => __('The board could not be posted in the group. Diagnostics has the logged reason.', 'eventcrew'),
        ];
    }

    /**
     * Replaces the webhook secret with a fresh one and re-installs.
     *
     * The secret is the bot's only authentication: anything that can post a
     * valid update can claim to be any Telegram user, an organizer included.
     * Until now it was generated once on first install and never changed, so a
     * copy that had escaped - most likely into a server access log, which is
     * where the fallback door's URL puts it - stayed valid for ever.
     *
     * Rotating is deleting and re-installing: installWebhook() already mints a
     * new secret when it finds none and points Telegram at it, so this needs no
     * generation code of its own and cannot drift from the install path. The
     * order matters - the option is cleared first, so a failed setWebhook
     * leaves a site whose stored secret no longer matches Telegram's, which is
     * a visibly broken bot the button can fix, rather than a silently stale one.
     */
    public function rotateWebhookSecret(): void
    {
        Admin::assertCanSave(self::SETUP_NONCE_ACTION);

        if (! $this->telegram->isConfigured()) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('Add a bot token first, then install the webhook.', 'eventcrew'),
                'error'
            );
        }

        delete_option(WebhookController::SECRET_OPTION);

        $result = $this->installWebhook();

        if ($result['ok']) {
            update_option(self::WEBHOOK_VERSION_OPTION, EVENTCREW_VERSION);

            Admin::redirectTo(
                self::PAGE_SLUG,
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                __('A new webhook secret was generated and installed. Any copy of the old one — in a server log, for instance — is now useless.', 'eventcrew')
            );
        }

        Admin::redirectTo(self::PAGE_SLUG, $result['error'], 'error');
    }

    /**
     * The Settings "Test the webhook" button: the site posts an empty update
     * (WebhookController::TEST_BODY) to the address Telegram posts to, with
     * the real secret, the way Telegram sends it. The notice shows the HTTP
     * status and what it means. The response body is never shown, and the
     * secret is masked in any error text. The empty update makes the bot do
     * nothing, so no Telegram message is sent.
     */
    public function testWebhook(): void
    {
        Admin::assertCanSave(self::SETUP_NONCE_ACTION);

        $secret = trim((string) get_option(WebhookController::SECRET_OPTION, ''));

        if ('' === $secret) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('Install the webhook first, then test it.', 'eventcrew'),
                'error'
            );
        }

        $response = wp_remote_post(
            WebhookController::webhookUrl($secret),
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                    WebhookController::SECRET_HEADER => $secret,
                ],
                'body' => WebhookController::TEST_BODY,
                'timeout' => 15,
                // Telegram does not follow redirects, so neither does the test.
                'redirection' => 0,
            ]
        );

        if (is_wp_error($response)) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                sprintf(
                    /* translators: %s: the reason, e.g. a connection timeout */
                    __('The test request could not be sent: %s', 'eventcrew'),
                    str_replace($secret, SecretMask::mask($secret), $response->get_error_message())
                ),
                'error'
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        if (200 === $status) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('The webhook answered HTTP 200. It works: messages from Telegram reach the bot.', 'eventcrew')
            );
        }

        $meaning = 403 === $status
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            ? __('The site did not accept the secret. If the secret is not in the address, your host may remove the secret header.', 'eventcrew')
            : __('Telegram gets the same answer, so the bot does not receive messages.', 'eventcrew');

        Admin::redirectTo(
            self::PAGE_SLUG,
            sprintf(
                /* translators: 1: an HTTP status code, e.g. 403; 2: what it means */
                __('The webhook answered HTTP %1$d. %2$s', 'eventcrew'),
                $status,
                $meaning
            ),
            'error'
        );
    }

    /**
     * Saves the import settings: the address organizers mail ticket buyer
     * lists to, the extra sender addresses and the ticket file formats. An
     * empty mailbox removes it, and the guide and the Roster page then leave
     * the email import out.
     */
    public function saveImportMailbox(): void
    {
        Admin::assertCanSave(self::EMAIL_NONCE_ACTION);

        $this->saveImportSenders();
        $this->saveTicketFormats();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by assertCanSave().
        $posted = wp_unslash((string) ($_POST['import_mailbox'] ?? ''));
        $address = trim(sanitize_text_field($posted));

        if ('' === $address) {
            delete_option(MailedListImport::MAILBOX_OPTION);
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('The import settings were saved. There is no import mailbox.', 'eventcrew')
            );
            return;
        }

        if (false === filter_var($address, FILTER_VALIDATE_EMAIL)) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __(
                    'The import mailbox is not an email address, so it was not changed. The other import settings were saved.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                    'eventcrew'
                ),
                'error'
            );
            return;
        }

        update_option(MailedListImport::MAILBOX_OPTION, $address, false);
        Admin::redirectTo(self::PAGE_SLUG, __('The import settings were saved.', 'eventcrew'));
    }

    /**
     * The extra sender addresses, one per line. Lines that are not an email
     * address are dropped. Only when the form sent the field.
     */
    private function saveImportSenders(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by assertCanSave() in the caller.
        if (! isset($_POST['import_senders'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked by assertCanSave(); each line is validated as an email address below.
        $posted = (string) wp_unslash((string) $_POST['import_senders']);
        $senders = [];

        foreach (preg_split('/[\s,;]+/', strtolower($posted)) ?: [] as $address) {
            if (false !== filter_var($address, FILTER_VALIDATE_EMAIL) && ! in_array($address, $senders, true)) {
                $senders[] = $address;
            }
        }

        update_option(MailedListImport::SENDERS_OPTION, $senders, false);
    }

    /**
     * The ticket file formats from the form. A header field holds one header
     * text, or several accepted ones separated by "|". A format with "Remove"
     * ticked is left out. The decoder is stored exactly as typed: only users
     * who may manage the site's options reach this, and it is only ever
     * printed escaped. Only when the form sent the field.
     */
    private function saveTicketFormats(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by assertCanSave() in the caller.
        if (! isset($_POST['ticket_formats']) || ! is_array($_POST['ticket_formats'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked by assertCanSave(); each field is sanitized below, the decoder is stored as typed on purpose.
        $posted = (array) wp_unslash($_POST['ticket_formats']);
        $text = static fn (array $row, string $key): string => sanitize_text_field((string) ($row[$key] ?? ''));
        $formats = [];

        foreach ($posted as $row) {
            if (! is_array($row) || ! empty($row['remove'])) {
                continue;
            }

            $columns = [];

            foreach (TicketFormats::COLUMNS as $column) {
                $columns[$column] = explode('|', $text($row, 'col_' . $column));
            }

            $formats[] = [
                'id' => $text($row, 'id'),
                'name' => $text($row, 'name'),
                'columns' => $columns,
                'decoder_js' => str_replace("\r\n", "\n", (string) ($row['decoder_js'] ?? '')),
            ];
        }

        TicketFormats::save($formats);
    }

    /**
     * Saves how the site reads the import mailbox itself. An empty password
     * keeps the saved one, so the page never has to show it.
     */
    public function saveMailFetch(): void
    {
        Admin::assertCanSave(self::EMAIL_NONCE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- checked by assertCanSave().
        $field = static fn (string $name): string => sanitize_text_field(wp_unslash((string) ($_POST[$name] ?? '')));
        $password = (string) wp_unslash((string) ($_POST['mail_fetch_password'] ?? ''));
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $settings = [
            'protocol' => $field('mail_fetch_protocol'),
            'server' => $field('mail_fetch_server'),
            'port' => (int) $field('mail_fetch_port'),
            'security' => $field('mail_fetch_security'),
            'username' => $field('mail_fetch_username'),
            'folder' => $field('mail_fetch_folder'),
            'imported_folder' => $field('mail_fetch_imported_folder'),
            'rejected_folder' => $field('mail_fetch_rejected_folder'),
            'authserv_id' => $field('mail_fetch_authserv_id'),
        ];

        // No 'password' key keeps the stored one as it is.
        if ('' !== $password) {
            $settings['password'] = $password;
        }

        // The JMAP login travels to this address, so plain http is refused
        // (except to this same machine) and the saved address is kept.
        $insecure = MailFetchSettings::JMAP === $settings['protocol']
            && '' !== $settings['server']
            && ! SecureUrl::isAllowed(JmapMailbox::sessionUrlFor($settings['server']));

        if ($insecure) {
            $settings['server'] = MailFetchSettings::get()['server'];
        }

        MailFetchSettings::save($settings);

        Admin::redirectTo(
            self::PAGE_SLUG,
            $insecure
                ? __('The mailbox settings were saved, except the server: a JMAP address must start with https:// (http:// only to localhost or 127.0.0.1).', 'eventcrew')
                : __('The mailbox settings were saved.', 'eventcrew'),
            $insecure ? 'warning' : 'success'
        );
    }

    /**
     * Re-installs the webhook whenever the running plugin version differs from
     * the one last installed for - i.e. on every update, from any prior version,
     * however it arrived (the updater or an FTP drop) - so a release that adds
     * or renames a bot command, or changes the webhook, takes effect on the next
     * admin request without anyone clicking the button. The stored-version check
     * is only a once-per-update guard (it mirrors Schema::maybeMigrate); it never
     * needs to know which version the site came from. Runs only when a bot token
     * is configured; failures are logged by TelegramClient and the manual button
     * remains for a deliberate retry.
     */
    public function installOnUpdate(): void
    {
        if (EVENTCREW_VERSION === (string) get_option(self::WEBHOOK_VERSION_OPTION, '')) {
            return;
        }

        // An un-configured install has nothing to point at; leave the version
        // unstamped so configuring a token later triggers this on the next load.
        if (! $this->telegram->isConfigured()) {
            return;
        }

        // A failed install is tried again, but not on every admin page load:
        // at most once per WEBHOOK_RETRY_GAP, by the next admin page or the
        // hourly cron run after that.
        $failedAt = (int) get_option(self::WEBHOOK_FAILED_OPTION, 0);

        if ($failedAt > 0 && time() - $failedAt < self::WEBHOOK_RETRY_GAP) {
            return;
        }

        // Stamp only on success; stamping a failure would stop every retry.
        // Not clean: the webhook is replaced in place, never deleted first, so
        // a refused setWebhook leaves the working webhook as it was and the
        // bot never goes deaf because of an update.
        if ($this->installWebhook(false)['ok']) {
            update_option(self::WEBHOOK_VERSION_OPTION, EVENTCREW_VERSION);
            update_option(self::WEBHOOK_FAILED_OPTION, 0);

            return;
        }

        update_option(self::WEBHOOK_FAILED_OPTION, time());
    }

    /**
     * The background job (WEBHOOK_JOB) queued by a settings save that moved
     * the webhook address: installs the webhook on the new address.
     *
     * @param array<string, mixed> $args None.
     * @return array{ok: bool, error: string}
     */
    public function webhookJob(array $args = []): array
    {
        $result = $this->installWebhook();

        return [
            'ok' => $result['ok'],
            'error' => $result['ok'] ? '' : sprintf(
                /* translators: %s: the reason the webhook could not be installed */
                __('The webhook could not be re-installed: %s', 'eventcrew'),
                $result['error']
            ),
        ];
    }

    /**
     * Points Telegram at this site's webhook, generating the shared secret on
     * first run, caching the bot's username for the board's deep-link button,
     * and (re)publishing the command menu. HTTPS with a valid certificate is
     * Telegram's own hard requirement - it refuses plain HTTP and self-signed
     * certs outright. Returns whether it succeeded and, if not, a ready-to-show
     * reason; it never redirects or checks a nonce, so both the Settings button
     * and the automatic on-update refresh can drive it.
     *
     * $clean deletes the webhook first, which clears Telegram's stored last
     * error. The buttons do that; the automatic refresh does not, because a
     * setWebhook refused right after the delete leaves no webhook at all.
     *
     * @return array{ok: bool, error: string}
     */
    public function installWebhook(bool $clean = true): array
    {
        if (! $this->telegram->isConfigured()) {
            return ['ok' => false, 'error' => __('Add a bot token and save before installing the webhook.', 'eventcrew')];
        }

        $secret = trim((string) get_option(WebhookController::SECRET_OPTION, ''));

        if ('' === $secret) {
            $secret = bin2hex(random_bytes(32));
            update_option(WebhookController::SECRET_OPTION, $secret);
        }

        $url = WebhookController::webhookUrl($secret);

        // Telegram rejects any non-HTTPS webhook outright, and the usual cause
        // is WordPress's own address being http:// behind a TLS-terminating
        // proxy - so this is caught before the call and names the real fix,
        // rather than surfacing as Telegram's opaque "HTTPS url must be
        // provided".
        if (! str_starts_with($url, 'https://')) {
            return [
                'ok' => false,
                'error' => sprintf(
                    /* translators: %s: the non-HTTPS URL WordPress generated */
                    __('WordPress generated the webhook URL %s, which is not HTTPS, so Telegram will refuse it. Set the WordPress Address and Site Address (Settings → General) to their https:// forms - or fix the reverse proxy\'s forwarded-protocol header - and try again.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                    $url
                ),
            ];
        }

        // Telegram keeps last_error_message even across a setWebhook to the same
        // URL, so a plain refresh never clears a stale error. Deleting the
        // webhook first resets that state; the setWebhook below re-installs it
        // clean. Pending updates are preserved (deleteWebhook drops none).
        if ($clean) {
            $this->telegram->deleteWebhook();
        }

        $installed = $this->setWebhookWithRetry($url, $secret);

        if (null === $installed) {
            return [
                'ok' => false,
                'error' => sprintf(
                    /* translators: %s: the error Telegram returned */
                    __('Telegram refused the webhook: %s', 'eventcrew'),
                    $this->telegram->lastError()
                ),
            ];
        }

        // Reset the idempotency high-water mark. An injected update - most
        // likely a run of tools/concurrency-check.php, whose synthetic update_ids
        // start at 900000000 - can push it above every real update_id, after
        // which the router drops all genuine updates and the bot goes silent
        // while still receiving everything. Reinstalling the webhook is the
        // deliberate "start clean" action, so clearing it here is the fix.
        update_option(UpdateRouter::LAST_UPDATE_OPTION, 0);

        $me = $this->telegram->getMe();

        if (is_array($me) && isset($me['username'])) {
            update_option(BoardService::USERNAME_OPTION, (string) $me['username']);
        }

        if (is_array($me) && isset($me['first_name'])) {
            update_option(BoardService::NAME_OPTION, (string) $me['first_name']);
        }

        // So /board shows in the command menu and reaches the bot reliably even
        // under group privacy mode. Each person's menu lists only the commands
        // they may use; CommandMenu sends all of them.
        $this->commandMenu->install();

        return ['ok' => true, 'error' => ''];
    }

    /**
     * setWebhook, tried again when Telegram rate-limits it. A setWebhook sent
     * right after the deleteWebhook above is sometimes answered "Too Many
     * Requests: retry after 1"; without a retry the webhook stays deleted and
     * the bot goes silent.
     *
     * @return array<string, mixed>|null
     */
    private function setWebhookWithRetry(string $url, string $secret): ?array
    {
        for ($try = 1;; $try++) {
            $installed = $this->telegram->setWebhook($url, $secret);
            $wait = $this->telegram->lastRetryAfter();

            if (null !== $installed || 0 === $wait || $try >= self::WEBHOOK_TRIES) {
                return $installed;
            }

            sleep(min($wait, self::WEBHOOK_MAX_WAIT));
        }
    }

    /**
     * The form posts parallel arrays rather than one array of rows, because a
     * repeatable fieldset is far simpler to render that way. Reassembling them
     * here keeps that detail out of Roles, which should not know how the
     * form is laid out.
     *
     * @return array<int, array<string, mixed>>
     */
    private function submittedRoles(): array
    {
        // Nonce and capability were both verified in assertCanSave() above;
        // phpcs cannot follow that across the method boundary.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $slugs = isset($_POST['role_slug']) && is_array($_POST['role_slug'])
            ? array_map('sanitize_key', wp_unslash($_POST['role_slug']))
            : [];

        // Each name and description is a written-once text (TranslatedText),
        // sanitized per field in roleText().
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see above.
        $labels = isset($_POST['role_label']) && is_array($_POST['role_label']) ? $_POST['role_label'] : [];

        $emojis = isset($_POST['role_emoji']) && is_array($_POST['role_emoji'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['role_emoji']))
            : [];

        $titleContains = isset($_POST['role_title_contains']) && is_array($_POST['role_title_contains'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['role_title_contains']))
            : [];

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in roleText().
        $descriptions = isset($_POST['role_description']) && is_array($_POST['role_description'])
            ? $_POST['role_description']
            : [];

        $plusOnes = isset($_POST['role_plus_ones']) && is_array($_POST['role_plus_ones'])
            ? array_map('intval', wp_unslash($_POST['role_plus_ones']))
            : [];

        $capacities = isset($_POST['role_capacity']) && is_array($_POST['role_capacity'])
            ? array_map('intval', wp_unslash($_POST['role_capacity']))
            : [];

        $anchors = isset($_POST['role_anchor']) && is_array($_POST['role_anchor'])
            ? array_map('sanitize_key', wp_unslash($_POST['role_anchor']))
            : [];

        $startOffsets = isset($_POST['role_start_offset']) && is_array($_POST['role_start_offset'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['role_start_offset']))
            : [];

        $endOffsets = isset($_POST['role_end_offset']) && is_array($_POST['role_end_offset'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['role_end_offset']))
            : [];

        // Checkboxes only post when ticked, so an absent index means "not
        // archived" rather than "unchanged" - which is what makes unticking
        // the box work at all.
        $archived = isset($_POST['role_archived']) && is_array($_POST['role_archived'])
            ? array_map('intval', array_keys(wp_unslash($_POST['role_archived'])))
            : [];
        $restricted = isset($_POST['role_restricted']) && is_array($_POST['role_restricted'])
            ? array_map('intval', array_keys(wp_unslash($_POST['role_restricted'])))
            : [];
        $everyNight = isset($_POST['role_every_night']) && is_array($_POST['role_every_night'])
            ? array_map('intval', array_keys(wp_unslash($_POST['role_every_night'])))
            : [];
        $salesDesk = isset($_POST['role_sales_desk']) && is_array($_POST['role_sales_desk'])
            ? array_map('intval', array_keys(wp_unslash($_POST['role_sales_desk'])))
            : [];
        $onBoard = isset($_POST['role_on_board']) && is_array($_POST['role_on_board'])
            ? array_map('intval', array_keys(wp_unslash($_POST['role_on_board'])))
            : [];

        // Conditions: role_count[index][slug], role_min_roles[index] and
        // role_times_each[index]. Roles::normalize() drops blanks and zeros.
        $counts = isset($_POST['role_count']) && is_array($_POST['role_count'])
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is cast to int below.
            ? wp_unslash($_POST['role_count'])
            : [];
        $minRoles = isset($_POST['role_min_roles']) && is_array($_POST['role_min_roles'])
            ? array_map('intval', wp_unslash($_POST['role_min_roles']))
            : [];
        $timesEach = isset($_POST['role_times_each']) && is_array($_POST['role_times_each'])
            ? array_map('intval', wp_unslash($_POST['role_times_each']))
            : [];
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $archivedIndexes = array_flip($archived);
        $restrictedIndexes = array_flip($restricted);
        $everyNightIndexes = array_flip($everyNight);
        $salesDeskIndexes = array_flip($salesDesk);
        $onBoardIndexes = array_flip($onBoard);

        $types = [];
        $translator = $this->translator();
        $this->rolesError = '';

        // The slugs a new role cannot get: Roles::save() would drop it, or
        // put it in place of a role posted after it.
        $taken = array_fill_keys(array_merge([Roles::ORGANIZE_SLUG], array_values(array_filter($slugs))), true);

        foreach (array_keys($labels) as $index) {
            $previous = '' === ($slugs[$index] ?? '') ? null : Roles::find($slugs[$index]);
            // A role translates in the background, as its Translations page
            // row. A new role gets its slug from its name on save
            // (Roles::save()), so its row is known now. A new row whose name
            // gives no slug of its own (an empty line, or a slug taken) is
            // left out, as Roles::save() would, and nothing is translated.
            $row = null === $previous || Roles::ORGANIZE_SLUG === $previous['slug'] ? null : 'role.' . $previous['slug'];

            if ('' === ($slugs[$index] ?? '')) {
                $newSlug = self::newRoleSlug($labels[$index] ?? null, $taken);

                if (null === $newSlug) {
                    continue;
                }

                $row = 'role.' . $newSlug;
                $taken[$newSlug] = true;
            }

            $label = $this->roleText(
                $translator,
                $previous['label_text'] ?? '',
                $labels[$index] ?? null,
                false,
                null === $row ? null : $row . '.label'
            );
            $description = $this->roleText(
                $translator,
                $previous['description_text'] ?? '',
                $descriptions[$index] ?? null,
                true,
                null === $row ? null : $row . '.description'
            );
            $types[] = [
                'slug' => $slugs[$index] ?? '',
                'label' => $label['source'],
                'label_text' => $label,
                'emoji' => $emojis[$index] ?? '',
                'capacity' => $capacities[$index] ?? 1,
                'archived' => isset($archivedIndexes[$index]),
                'restricted' => isset($restrictedIndexes[$index]),
                'every_night' => isset($everyNightIndexes[$index]),
                'sales_desk' => isset($salesDeskIndexes[$index]),
                'on_board' => isset($onBoardIndexes[$index]),
                'title_contains' => $titleContains[$index] ?? '',
                'plus_ones' => $plusOnes[$index] ?? 0,
                'conditions' => [
                    'counts' => is_array($counts[$index] ?? null)
                        ? array_map('intval', $counts[$index])
                        : [],
                    'min_roles' => $minRoles[$index] ?? 0,
                    'times_each' => $timesEach[$index] ?? 0,
                ],
                'anchor' => $anchors[$index] ?? Roles::ANCHOR_START,
                // '' means "no offset", which is not the same as 0 ("exactly
                // on the anchor"), so the blank is preserved rather than
                // being cast to an integer here.
                'start_offset' => $this->offset($startOffsets[$index] ?? ''),
                'end_offset' => $this->offset($endOffsets[$index] ?? ''),
                'description' => $description['source'],
                'description_text' => $description,
            ];
        }

        return $types;
    }

    /**
     * One posted role name or description applied to its stored value,
     * translating where needed. The field posts as TranslatedText::field()
     * draws it; a plain string (an older form) is taken as the new source.
     * A translation error is kept in $rolesError for the save notice. $row
     * is the text's Translations page row, so it can be translated in the
     * background; null translates now.
     *
     * @param array<string, mixed>|string $previous
     * @return array<string, mixed> A TranslatedText value.
     */
    private function roleText(
        TranslatedText $translator,
        array|string $previous,
        mixed $raw,
        bool $multiline,
        ?string $row = null
    ): array {
        if (is_scalar($raw)) {
            // fromPost() unslashes and sanitizes it.
            $raw = ['source' => (string) $raw];
        }

        $result = $translator->save($previous, $raw, 0, $multiline, $row);

        if (null === $result) {
            return TranslatedText::normalize($previous);
        }

        if ('' === $this->rolesError && '' !== $result['error']) {
            $this->rolesError = $result['error'];
        }

        return $result['value'];
    }

    /**
     * The slug Roles::save() makes for a new role named $raw (a posted
     * TranslatedText field, or a plain string), or null when it would make
     * none of its own: an empty slug, or one in $taken.
     *
     * @param array<string, true> $taken
     */
    private static function newRoleSlug(mixed $raw, array $taken): ?string
    {
        $input = TranslatedText::fromPost(is_scalar($raw) ? ['source' => (string) $raw] : $raw, false);

        if (null === $input) {
            return null;
        }

        $slug = sanitize_key(TranslatedText::clean($input['source'], 0, false));

        return '' === $slug || isset($taken[$slug]) ? null : $slug;
    }

    /**
     * A blank offset field stays null so the role produces untimed tasks;
     * anything numeric becomes minutes, negative meaning "before the anchor".
     */
    private function offset(string $value): ?int
    {
        $value = trim($value);

        return '' === $value || 1 !== preg_match('/^-?\d+$/', $value) ? null : (int) $value;
    }
}
