<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\BoardPush;
use EventCrew\Support\EventMeshSyncListener;
use EventCrew\Support\EventSource;
use EventCrew\Support\CronFallbackTrigger;
use EventCrew\Support\OpenTaskCall;
use EventCrew\Support\Reputation;
use EventCrew\Support\Roles;
use EventCrew\Support\Scheduler;
use EventCrew\Support\SignupService;
use EventCrew\Support\StandingCalculator;
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
    private const SEND_NONCE_ACTION = 'eventcrew_send_open_task';

    public function __construct(
        private readonly View $view,
        private readonly PersonRepository $people,
        private readonly TelegramClient $telegram,
        private readonly OpenTaskCall $openTaskCall
    ) {
    }

    public function render(): void
    {
        $this->view->render(
            'settings',
            [
                'roles' => Roles::all(),
                'active_recipients' => count($this->people->activeEmailRecipients()),
                'send_nonce_action' => self::SEND_NONCE_ACTION,
                'nonce_action' => self::NONCE_ACTION,
                'eventmesh_available' => EventSource::isAvailable(),
                'auto_create_tasks' => (bool) get_option(EventMeshSyncListener::OPTION_NAME, false),
                'notice_hours' => max(0, (int) get_option(SignupService::NOTICE_HOURS_OPTION, 48)),
                'reputation_threshold' => (float) get_option(
                    StandingCalculator::THRESHOLD_OPTION,
                    Reputation::DEFAULT_THRESHOLD
                ),
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
                'cron_fallback' => (bool) get_option(CronFallbackTrigger::OPTION, false),
                'cron_next_run' => wp_next_scheduled(Scheduler::HOOK),
                'cron_last_run' => (int) get_option(Scheduler::LAST_RUN_OPTION, 0),
                'app_page_id' => (int) get_option(PwaController::PAGE_OPTION, 0),
                'app_name' => (string) get_option(PwaController::NAME_OPTION, ''),
                'app_theme_color' => (string) get_option(PwaController::COLOR_OPTION, PwaController::DEFAULT_COLOR),
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
            'setup_nonce_action' => self::SETUP_NONCE_ACTION,
        ];
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

        update_option(CronFallbackTrigger::OPTION, isset($_POST['cron_fallback']) ? '1' : '0');

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
     * Sends the open-task email now, for the nearest upcoming date with open
     * slots. The send-once ledger means a second click never re-mails anyone.
     */
    public function sendOpenTaskEmail(): void
    {
        Admin::assertCanSave(self::SEND_NONCE_ACTION);

        $sent = $this->openTaskCall->sendForNextOpenDate();

        Admin::redirectTo(
            self::PAGE_SLUG,
            0 === $sent
                ? __('Nothing to send: no open tasks, or everyone eligible already has it.', 'eventcrew')
                : sprintf(
                    /* translators: %d: number of people emailed */
                    _n('Open-task email sent to %d person.', 'Open-task email sent to %d people.', $sent, 'eventcrew'),
                    $sent
                )
        );
    }

    /**
     * Points Telegram at this site's webhook, generating the shared secret on
     * first run and caching the bot's username for the board's deep-link
     * button. HTTPS with a valid certificate is Telegram's own hard
     * requirement here - it refuses plain HTTP and self-signed certs outright.
     */
    public function setupWebhook(): void
    {
        Admin::assertCanSave(self::SETUP_NONCE_ACTION);

        if (! $this->telegram->isConfigured()) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                __('Add a bot token and save before installing the webhook.', 'eventcrew'),
                'error'
            );
        }

        // The secret is generated first because the admin-post door carries it
        // in its URL, so the URL cannot be built until it exists.
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
            Admin::redirectTo(
                self::PAGE_SLUG,
                sprintf(
                    /* translators: %s: the non-HTTPS URL WordPress generated */
                    __('WordPress generated the webhook URL %s, which is not HTTPS, so Telegram will refuse it. Set the WordPress Address and Site Address (Settings → General) to their https:// forms - or fix the reverse proxy\'s forwarded-protocol header - and try again.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                    $url
                ),
                'error'
            );
        }

        // Telegram keeps last_error_message even across a setWebhook to the same
        // URL, so a plain refresh never clears a stale error. Deleting the
        // webhook first resets that state; the setWebhook below re-installs it
        // clean. Pending updates are preserved (deleteWebhook drops none).
        $this->telegram->deleteWebhook();

        $installed = $this->telegram->setWebhook($url, $secret);

        if (null === $installed) {
            Admin::redirectTo(
                self::PAGE_SLUG,
                sprintf(
                    /* translators: %s: the error Telegram returned */
                    __('Telegram refused the webhook: %s', 'eventcrew'),
                    $this->telegram->lastError()
                ),
                'error'
            );
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
            ['command' => 'myhistory', 'description' => __('DM — your past tasks', 'eventcrew')],
            ['command' => 'stop', 'description' => __('DM — switch your account off (no more emails)', 'eventcrew')],
            ['command' => 'roster', 'description' => __('DM — attendance roster (organizers & crew)', 'eventcrew')],
        ]);

        Admin::redirectTo(
            self::PAGE_SLUG,
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __('Webhook installed. Add the bot to your group and the board will appear there on its own.', 'eventcrew')
        );
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
