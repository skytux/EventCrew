<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Core\Container;
use EventCrew\MailImport\MailImporter;
use EventCrew\Support\BackgroundJobs;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\HybridPolicy;
use EventCrew\Support\Scheduler;

/**
 * Single manifest of every admin hook, and the only place the required
 * capability is named.
 */
final class Admin
{
    public const CAPABILITY = 'manage_options';

    public const MENU_SLUG = 'eventcrew';

    /** Short-lived transient carrying a one-off notice across a redirect. */
    public const NOTICE_TRANSIENT = 'eventcrew_admin_notice';

    public function __construct(
        private readonly Container $container
    ) {
    }

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'registerMenus']);
        add_action('admin_notices', [$this, 'renderNotice']);
        add_action('admin_notices', [self::class, 'hybridOffNotice']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueStyles']);

        // The background jobs of a save: the bar at the top, the poll that
        // runs them while the page is open, and "Try again".
        add_action('admin_notices', [$this->container->get(JobsStatus::class), 'topBar']);
        add_action('wp_ajax_' . JobsStatus::ACTION, [$this->container->get(JobsStatus::class), 'poll']);
        add_action('admin_post_' . JobsStatus::RETRY_ACTION, [$this->container->get(JobsStatus::class), 'retry']);

        // Re-publish the webhook and command menu once after a plugin update, so
        // a release that changes a bot command takes effect on its own: on the
        // next wp-admin request, or else on the next hourly WP-Cron run, so it
        // does not wait for someone to open wp-admin.
        add_action('admin_init', [$this->container->get(SettingsPage::class), 'installOnUpdate']);
        add_action(Scheduler::HOOK, [$this->container->get(SettingsPage::class), 'installOnUpdate']);

        add_action(
            'admin_post_eventcrew_save_settings',
            [$this->container->get(SettingsPage::class), 'save']
        );

        add_action(
            'admin_post_eventcrew_telegram_setup',
            [$this->container->get(SettingsPage::class), 'setupWebhook']
        );

        add_action(
            'admin_post_eventcrew_send_test_email',
            [$this->container->get(SettingsPage::class), 'sendTestEmail']
        );

        add_action(
            'admin_post_eventcrew_send_welcome_test',
            [$this->container->get(SettingsPage::class), 'sendWelcomeTest']
        );

        add_action(
            'admin_post_eventcrew_reset_email_template',
            [$this->container->get(SettingsPage::class), 'resetEmailTemplate']
        );

        add_action(
            'admin_post_eventcrew_create_privacy_page',
            [$this->container->get(SettingsPage::class), 'createPrivacyPage']
        );

        add_action(
            'admin_post_eventcrew_create_buyer_privacy_page',
            [$this->container->get(SettingsPage::class), 'createBuyerPrivacyPage']
        );

        add_action(
            'admin_post_eventcrew_refresh_board',
            [$this->container->get(SettingsPage::class), 'refreshBoard']
        );
        add_action(
            'admin_post_eventcrew_post_board',
            [$this->container->get(SettingsPage::class), 'postBoard']
        );

        add_action(
            'admin_post_eventcrew_rotate_webhook_secret',
            [$this->container->get(SettingsPage::class), 'rotateWebhookSecret']
        );

        add_action(
            'admin_post_eventcrew_test_webhook',
            [$this->container->get(SettingsPage::class), 'testWebhook']
        );

        add_action(
            'admin_post_eventcrew_assistant_test',
            [$this->container->get(SettingsPage::class), 'testAssistant']
        );

        add_action(
            'admin_post_eventcrew_assistant_remove_key',
            [$this->container->get(SettingsPage::class), 'removeAssistantKey']
        );

        add_action('admin_notices', [$this->container->get(SettingsPage::class), 'unreadableKeyNotice']);
        add_action('admin_notices', [$this->container->get(SettingsPage::class), 'unreadableMailPasswordNotice']);

        add_action(
            'admin_post_eventcrew_send_open_task',
            [$this->container->get(TasksPage::class), 'sendOpenTaskEmail']
        );

        add_action(
            'admin_post_eventcrew_save_task',
            [$this->container->get(TasksPage::class), 'save']
        );

        add_action(
            'admin_post_eventcrew_delete_task',
            [$this->container->get(TasksPage::class), 'delete']
        );

        add_action(
            'admin_post_eventcrew_apply_template',
            [$this->container->get(TasksPage::class), 'applyTemplate']
        );

        add_action(
            'admin_post_eventcrew_save_roster',
            [$this->container->get(RosterPage::class), 'saveRoster']
        );

        add_action(
            'admin_post_eventcrew_mark_all',
            [$this->container->get(RosterPage::class), 'markAllForTask']
        );

        add_action(
            'admin_post_eventcrew_redeem_credit',
            [$this->container->get(RosterPage::class), 'redeemCredit']
        );

        add_action(
            'admin_post_eventcrew_remove_redemption',
            [$this->container->get(RosterPage::class), 'removeRedemption']
        );

        add_action(
            'admin_post_eventcrew_save_guest',
            [$this->container->get(RosterPage::class), 'saveGuest']
        );

        add_action(
            'admin_post_eventcrew_remove_guest',
            [$this->container->get(RosterPage::class), 'removeGuest']
        );

        add_action(
            'admin_post_eventcrew_import_buyers',
            [$this->container->get(RosterPage::class), 'importBuyers']
        );

        add_action(
            'admin_post_eventcrew_reset_buyers',
            [$this->container->get(RosterPage::class), 'resetBuyers']
        );

        add_action(
            'admin_post_eventcrew_send_ticket_test',
            [$this->container->get(RosterPage::class), 'sendTicketTest']
        );

        add_action(
            'admin_post_eventcrew_send_tickets_now',
            [$this->container->get(RosterPage::class), 'sendTicketsNow']
        );

        add_action(
            'admin_post_eventcrew_cancel_ticket_send',
            [$this->container->get(RosterPage::class), 'cancelTicketSend']
        );

        add_action(
            'admin_post_eventcrew_send_followup_test',
            [$this->container->get(RosterPage::class), 'sendFollowupTest']
        );

        add_action(
            'admin_post_eventcrew_stop_followup',
            [$this->container->get(RosterPage::class), 'stopFollowup']
        );

        add_action(
            'admin_post_eventcrew_save_import_mailbox',
            [$this->container->get(SettingsPage::class), 'saveImportMailbox']
        );

        add_action(
            'admin_post_eventcrew_save_mail_fetch',
            [$this->container->get(SettingsPage::class), 'saveMailFetch']
        );

        add_action(
            'admin_post_eventcrew_fetch_mail_now',
            [$this->container->get(MailImporter::class), 'runFromAdmin']
        );

        add_action(
            'admin_post_eventcrew_toggle_ticket_closed',
            [$this->container->get(RosterPage::class), 'toggleTicketClosed']
        );

        add_action(
            'admin_post_eventcrew_save_person',
            [$this->container->get(PeoplePage::class), 'save']
        );

        add_action(
            'admin_post_eventcrew_delete_person',
            [$this->container->get(PeoplePage::class), 'delete']
        );

        add_action(
            'admin_post_eventcrew_grant_credit',
            [$this->container->get(PeoplePage::class), 'grantCredit']
        );

        add_action(
            'admin_post_eventcrew_grant_pass',
            [$this->container->get(PeoplePage::class), 'grantPass']
        );

        add_action(
            'admin_post_eventcrew_unban_person',
            [$this->container->get(PeoplePage::class), 'unban']
        );

        add_action(
            'admin_post_' . TranslationsPage::SAVE_SETTINGS_ACTION,
            [$this->container->get(TranslationsPage::class), 'saveSettings']
        );

        add_action(
            'admin_post_' . TranslationsPage::SAVE_ROW_ACTION,
            [$this->container->get(TranslationsPage::class), 'saveRow']
        );

        add_action(
            'admin_post_' . TranslationsPage::TRANSLATE_MISSING_ACTION,
            [$this->container->get(TranslationsPage::class), 'translateMissing']
        );

        add_action(
            'wp_ajax_' . TranslationsPage::PROGRESS_ACTION,
            [$this->container->get(TranslationsPage::class), 'progress']
        );

        add_action(
            'admin_post_' . NewsletterPage::COPY_ACTION,
            [$this->container->get(NewsletterPage::class), 'viewCopy']
        );

        add_action(
            'admin_post_' . MessagesPage::SAVE_ACTION,
            [$this->container->get(MessagesPage::class), 'save']
        );

        add_action(
            'wp_ajax_' . MessagesPage::SAVE_ACTION,
            [$this->container->get(MessagesPage::class), 'saveAjax']
        );

        add_action(
            'admin_post_' . MessagesPage::TICKET_ACTION,
            [$this->container->get(MessagesPage::class), 'saveTicket']
        );

        add_action(
            'wp_ajax_' . MessagesPage::TICKET_ACTION,
            [$this->container->get(MessagesPage::class), 'saveTicketAjax']
        );

        add_action(
            'admin_post_' . MessagesPage::RESET_ACTION,
            [$this->container->get(MessagesPage::class), 'reset']
        );

        add_action(
            'admin_post_' . AiPromptsPage::PROMPTS_ACTION,
            [$this->container->get(AiPromptsPage::class), 'savePrompts']
        );

        add_action(
            'admin_post_' . AiPromptsPage::PLACEHOLDERS_ACTION,
            [$this->container->get(AiPromptsPage::class), 'savePlaceholders']
        );

        add_action(
            'admin_post_' . AiPromptsPage::EVENT_TEXT_ACTION,
            [$this->container->get(AiPromptsPage::class), 'saveEventText']
        );

        add_action(
            'admin_post_' . AiPromptsPage::REWRITE_ACTION,
            [$this->container->get(AiPromptsPage::class), 'rewriteEventText']
        );

        add_action(
            'admin_post_' . AiPromptsPage::FOLLOWUP_TEXT_ACTION,
            [$this->container->get(AiPromptsPage::class), 'saveFollowupText']
        );

        add_action(
            'admin_post_' . AiPromptsPage::NEWSLETTER_TEXT_ACTION,
            [$this->container->get(AiPromptsPage::class), 'saveNewsletterText']
        );

        add_action(
            'admin_post_' . AiPromptsPage::DOORS_ACTION,
            [$this->container->get(AiPromptsPage::class), 'saveDoorsClose']
        );

        // The Email template's block editor opens from Messages > Email look,
        // so the menu shows Messages as the open page while it is edited.
        add_filter('parent_file', [self::class, 'emailTemplateParent']);
        add_filter('submenu_file', [self::class, 'emailTemplateSubmenu']);
    }

    /** parent_file: EventCrew while the Email template is edited. */
    public static function emailTemplateParent(mixed $parent): mixed
    {
        return self::editingEmailTemplate() ? self::MENU_SLUG : $parent;
    }

    /** submenu_file: Messages while the Email template is edited. */
    public static function emailTemplateSubmenu(mixed $submenu): mixed
    {
        return self::editingEmailTemplate() ? MessagesPage::PAGE_SLUG : $submenu;
    }

    private static function editingEmailTemplate(): bool
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        return is_object($screen) && EmailTemplate::POST_TYPE === ($screen->post_type ?? '');
    }

    /**
     * The admin stylesheet, on EventCrew's own pages only. Every one of them
     * is admin.php?page=eventcrew…, so the page parameter is the test; the
     * Email template is the core post editor and needs nothing from it.
     */
    public function enqueueStyles(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: picks a stylesheet, changes nothing.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';

        if (! str_starts_with($page, self::MENU_SLUG)) {
            return;
        }

        wp_enqueue_style(
            'eventcrew-admin',
            EVENTCREW_PLUGIN_URL . 'assets/eventcrew-admin.css',
            [],
            EVENTCREW_VERSION
        );

        wp_enqueue_script(
            'eventcrew-jobs',
            EVENTCREW_PLUGIN_URL . 'assets/eventcrew-jobs.js',
            [],
            EVENTCREW_VERSION,
            true
        );
        wp_localize_script('eventcrew-jobs', 'eventcrewJobs', [
            'url' => admin_url('admin-ajax.php'),
            'action' => JobsStatus::ACTION,
            'nonce' => wp_create_nonce(JobsStatus::ACTION),
            'done' => JobsStatus::barText(0),
            'reload' => __('Reload', 'eventcrew'),
        ]);
    }

    public function registerMenus(): void
    {
        add_menu_page(
            __('EventCrew', 'eventcrew'),
            __('EventCrew', 'eventcrew'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this->container->get(TasksPage::class), 'render'],
            'dashicons-groups',
            26
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Tasks', 'eventcrew'),
            __('Tasks', 'eventcrew'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this->container->get(TasksPage::class), 'render']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Roster', 'eventcrew'),
            __('Roster', 'eventcrew'),
            self::CAPABILITY,
            RosterPage::PAGE_SLUG,
            [$this->container->get(RosterPage::class), 'render']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('People', 'eventcrew'),
            __('People', 'eventcrew'),
            self::CAPABILITY,
            'eventcrew-people',
            [$this->container->get(PeoplePage::class), 'render']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Settings', 'eventcrew'),
            __('Settings', 'eventcrew'),
            self::CAPABILITY,
            'eventcrew-settings',
            [$this->container->get(SettingsPage::class), 'render']
        );

        // Messages holds every message text, the AI prompts, the texts for the
        // coming events and the link to the Email template's editor (tabs).
        add_submenu_page(
            self::MENU_SLUG,
            __('Messages', 'eventcrew'),
            __('Messages', 'eventcrew'),
            self::CAPABILITY,
            MessagesPage::PAGE_SLUG,
            [$this->container->get(MessagesPage::class), 'render']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Newsletter', 'eventcrew'),
            __('Newsletter', 'eventcrew'),
            self::CAPABILITY,
            NewsletterPage::PAGE_SLUG,
            [$this->container->get(NewsletterPage::class), 'render']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Translations', 'eventcrew'),
            __('Translations', 'eventcrew'),
            self::CAPABILITY,
            TranslationsPage::PAGE_SLUG,
            [$this->container->get(TranslationsPage::class), 'render']
        );

        // The old AI prompts page, now tabs of Messages: kept as a hidden page
        // (no menu item) so its old address still opens and redirects there.
        $oldAiPage = add_submenu_page(
            'options.php',
            __('Messages', 'eventcrew'),
            '',
            self::CAPABILITY,
            AiPromptsPage::PAGE_SLUG,
            '__return_null'
        );

        if (is_string($oldAiPage) && '' !== $oldAiPage) {
            add_action('load-' . $oldAiPage, [AiPromptsPage::class, 'redirectOldPage']);
        }

        add_submenu_page(
            self::MENU_SLUG,
            __('Bot log', 'eventcrew'),
            __('Bot log', 'eventcrew'),
            self::CAPABILITY,
            BotLogPage::PAGE_SLUG,
            [$this->container->get(BotLogPage::class), 'render']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Diagnostics', 'eventcrew'),
            __('Diagnostics', 'eventcrew'),
            self::CAPABILITY,
            DiagnosticsPage::PAGE_SLUG,
            [$this->container->get(DiagnosticsPage::class), 'render']
        );
    }

    /**
     * Queues a notice to show after the redirect that follows a POST handler.
     * It is kept per administrator, so it shows to the one who saved and
     * never on another administrator's next page.
     */
    public static function notice(string $type, string $message): void
    {
        set_transient(
            self::noticeKey(),
            ['type' => $type, 'message' => $message],
            60
        );
    }

    /** The notice transient of the signed-in administrator. */
    public static function noticeKey(): string
    {
        return self::NOTICE_TRANSIENT . '_' . (int) get_current_user_id();
    }

    /**
     * The warning on the plugin's wp-admin pages while hybrid messages are
     * off because the site has more languages than they work with.
     */
    public static function hybridOffNotice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: picks where the notice shows.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';

        if (
            ! str_starts_with($page, self::MENU_SLUG)
            || ! current_user_can(self::CAPABILITY)
            || HybridPolicy::isAllowed()
        ) {
            return;
        }

        printf('<div class="notice notice-warning"><p>%s</p></div>', esc_html(HybridPolicy::offWarning()));
    }

    public function renderNotice(): void
    {
        $key = self::noticeKey();
        $notice = get_transient($key);

        if (! is_array($notice) || ! isset($notice['message'])) {
            return;
        }

        delete_transient($key);

        $type = (string) ($notice['type'] ?? 'success');
        $class = 'error' === $type ? 'notice-error' : 'notice-success';

        printf(
            '<div class="notice %s is-dismissible"><p>%s</p></div>',
            esc_attr($class),
            esc_html((string) $notice['message'])
        );
    }

    /**
     * Guard shared by every POST handler: the capability check and the nonce,
     * in that order, so a user without permission never reaches the nonce
     * failure and learns nothing from the difference.
     */
    public static function assertCanSave(string $nonceAction): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to do this.', 'eventcrew'));
        }

        check_admin_referer($nonceAction);
    }

    /**
     * @param array<string, string> $args Extra query args to carry back, e.g.
     *        the roster's selected date, so a POST handler returns to the same
     *        view it was submitted from.
     * @param string $anchor A part of the page to land on (#ec-event-12), or ''.
     *
     * When this request left work for the background (BackgroundJobs), the
     * notice says so.
     */
    public static function redirectTo(
        string $page,
        string $message = '',
        string $type = 'success',
        array $args = [],
        string $anchor = ''
    ): never {
        if (
            '' !== $message
            && BackgroundJobs::addedNow() > 0
            && ! str_contains($message, BackgroundJobs::queuedNotice())
        ) {
            $message .= ' ' . BackgroundJobs::queuedNotice();
        }

        if ('' !== $message) {
            self::notice($type, $message);
        }

        wp_safe_redirect(
            add_query_arg(['page' => $page] + $args, admin_url('admin.php'))
            . ('' === $anchor ? '' : '#' . $anchor)
        );

        exit;
    }
}
