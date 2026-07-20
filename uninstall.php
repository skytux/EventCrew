<?php

/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Volunteer records, shift history and earned credits are the kind of data
 * that is painful to lose and impossible to reconstruct, so deletion is
 * opt-in: without the setting, uninstalling leaves every table in place and a
 * reinstall picks up exactly where it left off.
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if ('1' !== (string) get_option('eventcrew_delete_data_on_uninstall', '0')) {
    return;
}

if (! defined('EVENTCREW_PLUGIN_DIR')) {
    define('EVENTCREW_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

require_once EVENTCREW_PLUGIN_DIR . 'src/Support/Autoloader.php';

EventCrew\Support\Autoloader::register();

EventCrew\Database\Schema::dropAll();

delete_option('eventcrew_task_types');
delete_option('eventcrew_recent_logs');
delete_option('eventcrew_delete_data_on_uninstall');
delete_transient('eventcrew_admin_notice');
