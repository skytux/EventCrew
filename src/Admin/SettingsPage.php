<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Support\BoardPush;
use EventCrew\Support\EventMeshSyncListener;
use EventCrew\Support\EventSource;
use EventCrew\Support\CronFallbackTrigger;
use EventCrew\Support\Credits;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Mailer;
use EventCrew\Support\OpenTaskCall;
use EventCrew\Support\LeaderEligibility;
use EventCrew\Support\LeaderGate;
use EventCrew\Support\Reputation;
use EventCrew\Support\ReputationSettings;
use EventCrew\Support\Roles;
use EventCrew\Support\Scheduler;
use EventCrew\Support\Signature;
use EventCrew\Support\SignupService;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\Turnstile;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\TelegramClient;
use EventCrew\Telegram\UpdateRouter;
use EventCrew\Telegram\WebhookController;
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
    private const EMAIL_NONCE_ACTION = 'eventcrew_email_template';

    /** The plugin version the webhook was last (re)installed for; see maybeInstallOnUpdate(). */
    private const WEBHOOK_VERSION_OPTION = 'eventcrew_webhook_version';

    public function __construct(
        private readonly View $view,
        private readonly TelegramClient $telegram,
        private readonly Mailer $mailer,
        private readonly EmailTemplate $emailTemplate,
        private readonly BoardService $board
    ) {
    }

    public function render(): void
    {
        $reputation = new ReputationSettings();

        $this->view->render(
            'settings',
            [
                'roles' => Roles::all(),
                'nonce_action' => self::NONCE_ACTION,
                'eventmesh_available' => EventSource::isAvailable(),
                'auto_create_tasks' => (bool) get_option(EventMeshSyncListener::OPTION_NAME, false),
                'notice_hours' => max(0, (int) get_option(SignupService::NOTICE_HOURS_OPTION, 48)),
                'reputation_threshold' => (float) get_option(
                    StandingCalculator::THRESHOLD_OPTION,
                    Reputation::DEFAULT_THRESHOLD
                ),
                'reputation_weights' => $this->reputationWeightRows(),
                'tasks_per_credit' => $reputation->tasksPerCredit(),
                'min_rated_tasks' => $reputation->minRatedTasks(),
                'reputation_half_life' => $reputation->halfLifeDays(),
                'reputation_gate' => (bool) get_option(SignupService::GATE_OPTION, true),
                'leader_enabled' => (bool) get_option(LeaderGate::ENABLED_OPTION, false),
                'leader_experience' => max(1, (int) get_option(LeaderEligibility::THRESHOLD_OPTION, 2)),
                'board_push_enabled' => (bool) get_option(BoardPush::ENABLED_OPTION, true),
                'board_push_lead_week' => max(
                    0,
                    (int) get_option(BoardPush::LEAD_WEEK_OPTION, BoardPush::LEAD_WEEK_DEFAULT)
                ),
                'board_push_lead_soon' => max(
                    0,
                    (int) get_option(BoardPush::LEAD_SOON_OPTION, BoardPush::LEAD_SOON_DEFAULT)
                ),
                'open_task_lead_week' => max(
                    0,
                    (int) get_option(OpenTaskCall::LEAD_WEEK_OPTION, OpenTaskCall::LEAD_WEEK_DEFAULT)
                ),
                'open_task_lead_soon' => max(
                    0,
                    (int) get_option(OpenTaskCall::LEAD_SOON_OPTION, OpenTaskCall::LEAD_SOON_DEFAULT)
                ),
                'turnstile_site_key' => (string) get_option(Turnstile::SITE_KEY_OPTION, ''),
                'turnstile_secret' => (string) get_option(Turnstile::SECRET_KEY_OPTION, ''),
                'cron_fallback' => (bool) get_option(CronFallbackTrigger::OPTION, false),
                'cron_next_run' => wp_next_scheduled(Scheduler::HOOK),
                'cron_last_run' => (int) get_option(Scheduler::LAST_RUN_OPTION, 0),
                'app_page_id' => (int) get_option(PwaController::PAGE_OPTION, 0),
                'app_name' => (string) get_option(PwaController::NAME_OPTION, ''),
                'app_theme_color' => (string) get_option(PwaController::COLOR_OPTION, PwaController::DEFAULT_COLOR),
                'signature' => Signature::text(),
                'email_html' => EmailTemplate::enabled(),
                'email_logo' => (string) get_option(EmailTemplate::LOGO_OPTION, ''),
                'email_edit_url' => $this->emailTemplate->editUrl(),
                'email_nonce_action' => self::EMAIL_NONCE_ACTION,
                'email_test_to' => (string) wp_get_current_user()->user_email,
                'telegram' => $this->telegramView(),
            ]
        );
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
        $board = get_option(BoardService::BOARD_OPTION, []);

        $useFallback = (bool) get_option(WebhookController::USE_FALLBACK_OPTION, false);
        $secret = trim((string) get_option(WebhookController::SECRET_OPTION, ''));

        // The exact URL Telegram is pointed at, secret and all, so it can be
        // tested by hand. Shown only to an administrator, on their own settings
        // screen - the same person who could read it from the database anyway.
        $testUrl = '' === $secret ? '' : WebhookController::webhookUrl($secret);

        // The informational URL (no secret) for the status line.
        $displayUrl = $useFallback
            ? admin_url('admin-ajax.php') . '?action=' . WebhookController::FALLBACK_ACTION
            : rest_url(WebhookController::ROUTE_NAMESPACE . WebhookController::ROUTE);

        return [
            'token' => (string) get_option(TelegramClient::TOKEN_OPTION, ''),
            'configured' => $configured,
            'dns_bypass' => (bool) get_option(TelegramClient::DNS_BYPASS_OPTION, false),
            'use_fallback' => $useFallback,
            'webhook_url' => $displayUrl,
            'test_url' => $testUrl,
            'secret' => $secret,
            'webhook_info' => $configured ? $this->telegram->getWebhookInfo() : null,
            'bot_username' => (string) get_option(BoardService::USERNAME_OPTION, ''),
            'board_chat_id' => is_array($board) ? (int) ($board['chat_id'] ?? 0) : 0,
            'group_link' => (string) get_option(BoardService::GROUP_LINK_OPTION, ''),
            'group_link_auto' => (string) get_option(BoardService::GROUP_LINK_AUTO_OPTION, ''),
            'group_lock' => (bool) get_option(BoardService::LOCK_OPTION, true),
            'setup_nonce_action' => self::SETUP_NONCE_ACTION,
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

        // Checkbox: absent means unticked, same reasoning as the role
        // archive checkboxes above - an absent field is a real "off", not
        // "leave unchanged".
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        update_option(EventMeshSyncListener::OPTION_NAME, isset($_POST['auto_create_tasks']));

        $token = isset($_POST['telegram_bot_token'])
            ? sanitize_text_field(wp_unslash($_POST['telegram_bot_token']))
            : '';
        update_option(TelegramClient::TOKEN_OPTION, $token);

        update_option(TelegramClient::DNS_BYPASS_OPTION, isset($_POST['telegram_dns_bypass']));
        update_option(WebhookController::USE_FALLBACK_OPTION, isset($_POST['telegram_use_fallback']));

        $groupLink = isset($_POST['telegram_group_link'])
            ? esc_url_raw(wp_unslash($_POST['telegram_group_link']))
            : '';
        update_option(BoardService::GROUP_LINK_OPTION, $groupLink);

        // Checkbox: absent means unticked (unlocked), so the board can be moved
        // to another group on the next add / /board. Default is on.
        update_option(BoardService::LOCK_OPTION, isset($_POST['telegram_group_lock']) ? '1' : '0');

        $noticeHours = isset($_POST['notice_hours']) ? max(0, (int) $_POST['notice_hours']) : 48;
        update_option(SignupService::NOTICE_HOURS_OPTION, $noticeHours);

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

        update_option(LeaderGate::ENABLED_OPTION, isset($_POST['leader_enabled']));
        $leaderExperience = isset($_POST['leader_experience'])
            ? max(1, (int) $_POST['leader_experience'])
            : 2;
        update_option(LeaderEligibility::THRESHOLD_OPTION, $leaderExperience);

        update_option(BoardPush::ENABLED_OPTION, isset($_POST['board_push_enabled']) ? '1' : '0');
        $leadWeek = isset($_POST['board_push_lead_week'])
            ? max(0, (int) $_POST['board_push_lead_week'])
            : BoardPush::LEAD_WEEK_DEFAULT;
        $leadSoon = isset($_POST['board_push_lead_soon'])
            ? max(0, (int) $_POST['board_push_lead_soon'])
            : BoardPush::LEAD_SOON_DEFAULT;
        update_option(BoardPush::LEAD_WEEK_OPTION, $leadWeek);
        update_option(BoardPush::LEAD_SOON_OPTION, $leadSoon);

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

        update_option(
            Turnstile::SITE_KEY_OPTION,
            isset($_POST['turnstile_site_key'])
                ? sanitize_text_field(wp_unslash($_POST['turnstile_site_key']))
                : ''
        );
        update_option(
            Turnstile::SECRET_KEY_OPTION,
            isset($_POST['turnstile_secret'])
                ? sanitize_text_field(wp_unslash($_POST['turnstile_secret']))
                : ''
        );

        update_option(CronFallbackTrigger::OPTION, isset($_POST['cron_fallback']) ? '1' : '0');

        // sanitize_textarea_field, not sanitize_text_field: a sign-off may run
        // to two lines, and the newline is the whole difference.
        update_option(
            Signature::OPTION,
            isset($_POST['signature']) ? sanitize_textarea_field(wp_unslash($_POST['signature'])) : ''
        );

        update_option(EmailTemplate::HTML_OPTION, isset($_POST['email_html']) ? '1' : '0');
        update_option(
            EmailTemplate::LOGO_OPTION,
            isset($_POST['email_logo']) ? esc_url_raw(wp_unslash($_POST['email_logo'])) : ''
        );

        $appPageId = isset($_POST['app_page_id']) ? max(0, (int) $_POST['app_page_id']) : 0;
        update_option(PwaController::PAGE_OPTION, $appPageId);
        update_option(
            PwaController::NAME_OPTION,
            isset($_POST['app_name']) ? sanitize_text_field(wp_unslash($_POST['app_name'])) : ''
        );

        $appColor = isset($_POST['app_theme_color'])
            ? sanitize_hex_color(wp_unslash($_POST['app_theme_color']))
            : '';
        update_option(
            PwaController::COLOR_OPTION,
            '' === (string) $appColor ? PwaController::DEFAULT_COLOR : $appColor
        );
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        Admin::redirectTo(
            self::PAGE_SLUG,
            __('Settings saved.', 'eventcrew')
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
     * The Settings "Reset the template" button. The edit being discarded stays
     * in the post's revisions, so this is recoverable.
     */
    public function resetEmailTemplate(): void
    {
        Admin::assertCanSave(self::EMAIL_NONCE_ACTION);

        if ($this->emailTemplate->reset()) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('The email template was reset to the built-in design.', 'eventcrew')
            );
        }

        Admin::redirectTo(
            self::PAGE_SLUG,
            __('The email template could not be reset.', 'eventcrew'),
            'error'
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

        $this->installWebhook();

        // Stamp regardless of outcome, so a persistent misconfiguration (a
        // non-HTTPS site) is attempted once per release rather than on every
        // admin page load.
        update_option(self::WEBHOOK_VERSION_OPTION, EVENTCREW_VERSION);
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
     * @return array{ok: bool, error: string}
     */
    public function installWebhook(): array
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
        $this->telegram->deleteWebhook();

        $installed = $this->telegram->setWebhook($url, $secret);

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

        // So /board shows in the command menu and reaches the bot reliably even
        // under group privacy mode.
        // Each description leads with where the command is used - Group for the
        // shared board, DM for anything personal - so the menu itself says
        // which chat to run it in.
        $this->telegram->setMyCommands([
            ['command' => 'start', 'description' => __('DM — set yourself up to sign up for tasks', 'eventcrew')],
            ['command' => 'board', 'description' => __('Group — show the board of open tasks', 'eventcrew')],
            ['command' => 'replace', 'description' => __('DM — cover someone else’s task', 'eventcrew')],
            ['command' => 'me', 'description' => __('DM — your standing, credits and upcoming tasks', 'eventcrew')],
            ['command' => 'ticket', 'description' => __('DM — spend a free-entry credit for a ticket', 'eventcrew')],
            ['command' => 'myhistory', 'description' => __('DM — your past tasks', 'eventcrew')],
            ['command' => 'mytickets', 'description' => __('DM — your upcoming and past tickets', 'eventcrew')],
            ['command' => 'web', 'description' => __('DM — get a sign-in link for the web board', 'eventcrew')],
            ['command' => 'notifications', 'description' => __('DM — choose what reaches you, and how', 'eventcrew')],
            ['command' => 'roster', 'description' => __('DM — attendance roster (organizers & crew)', 'eventcrew')],
            ['command' => 'gift', 'description' => __('DM — organizers: give someone a free-entry credit', 'eventcrew')],
            ['command' => 'allow', 'description' => __('DM — organizers: set leader / pass / admin', 'eventcrew')],
            ['command' => 'leaders', 'description' => __('DM — organizers: who is eligible and allowed to lead', 'eventcrew')],
        ]);

        return ['ok' => true, 'error' => ''];
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

        $labels = isset($_POST['role_label']) && is_array($_POST['role_label'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['role_label']))
            : [];

        $emojis = isset($_POST['role_emoji']) && is_array($_POST['role_emoji'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['role_emoji']))
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
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $archivedIndexes = array_flip($archived);

        $types = [];

        foreach (array_keys($labels) as $index) {
            $types[] = [
                'slug' => $slugs[$index] ?? '',
                'label' => $labels[$index] ?? '',
                'emoji' => $emojis[$index] ?? '',
                'capacity' => $capacities[$index] ?? 1,
                'archived' => isset($archivedIndexes[$index]),
                'anchor' => $anchors[$index] ?? Roles::ANCHOR_START,
                // '' means "no offset", which is not the same as 0 ("exactly
                // on the anchor"), so the blank is preserved rather than
                // being cast to an integer here.
                'start_offset' => $this->offset($startOffsets[$index] ?? ''),
                'end_offset' => $this->offset($endOffsets[$index] ?? ''),
            ];
        }

        return $types;
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
