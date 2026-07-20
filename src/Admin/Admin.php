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

        add_action(
            'admin_post_eventcrew_save_settings',
            [$this->container->get(SettingsPage::class), 'save']
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
            'admin_post_eventcrew_save_person',
            [$this->container->get(PeoplePage::class), 'save']
        );

        add_action(
            'admin_post_eventcrew_delete_person',
            [$this->container->get(PeoplePage::class), 'delete']
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

    public static function redirectTo(string $page, string $message = '', string $type = 'success'): never
    {
        if ('' !== $message) {
            self::notice($type, $message);
        }

        wp_safe_redirect(
            add_query_arg(['page' => $page], admin_url('admin.php'))
        );

        exit;
    }
}
