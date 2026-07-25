<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Core\Container;

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

        // Re-publish the webhook and command menu once after a plugin update, so
        // a release that changes a bot command takes effect on its own.
        add_action('admin_init', [$this->container->get(SettingsPage::class), 'installOnUpdate']);

        add_action(
            'admin_post_eventcrew_save_settings',
            [$this->container->get(SettingsPage::class), 'save']
        );

        add_action(
            'admin_post_eventcrew_telegram_setup',
            [$this->container->get(SettingsPage::class), 'setupWebhook']
        );

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
            'admin_post_eventcrew_toggle_ticket_closed',
            [$this->container->get(RosterPage::class), 'toggleTicketClosed']
        );

        add_action(
            'admin_post_eventcrew_toggle_leader',
            [$this->container->get(RosterPage::class), 'toggleLeader']
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

        // Grouped with People via a tab on both pages (see their templates),
        // but kept a real menu item: hiding it with remove_submenu_page also
        // strips the capability WordPress needs to grant access to the URL.
        add_submenu_page(
            self::MENU_SLUG,
            __('Leadership', 'eventcrew'),
            __('Leadership', 'eventcrew'),
            self::CAPABILITY,
            LeadersPage::PAGE_SLUG,
            [$this->container->get(LeadersPage::class), 'render']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Settings', 'eventcrew'),
            __('Settings', 'eventcrew'),
            self::CAPABILITY,
            'eventcrew-settings',
            [$this->container->get(SettingsPage::class), 'render']
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
     */
    public static function notice(string $type, string $message): void
    {
        set_transient(
            self::NOTICE_TRANSIENT,
            ['type' => $type, 'message' => $message],
            60
        );
    }

    public function renderNotice(): void
    {
        $notice = get_transient(self::NOTICE_TRANSIENT);

        if (! is_array($notice) || ! isset($notice['message'])) {
            return;
        }

        delete_transient(self::NOTICE_TRANSIENT);

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
     */
    public static function redirectTo(
        string $page,
        string $message = '',
        string $type = 'success',
        array $args = []
    ): never {
        if ('' !== $message) {
            self::notice($type, $message);
        }

        wp_safe_redirect(
            add_query_arg(['page' => $page] + $args, admin_url('admin.php'))
        );

        exit;
    }
}
