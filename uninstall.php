<?php

/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Person records, task history and earned credits are the kind of data
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

global $wpdb;

// The email template is a real post, so leaving it behind would strand an
// orphan of an unregistered type in the posts table. Done before the options
// go, since that is where its id is recorded.
$eventcrew_template = (int) get_option('eventcrew_email_template_id', 0);

if ($eventcrew_template > 0) {
    wp_delete_post($eventcrew_template, true);
}

/*
 * Every option and transient this plugin has ever written, by prefix rather
 * than by name.
 *
 * A hand-kept list is one more thing to remember when a setting is added, and
 * the once it is forgotten is the once somebody explicitly asked for their data
 * to be gone. Naming them individually had already fallen behind by about
 * thirty: the bot token, the Turnstile secret, every reputation weight and all
 * the notification lead times were being left in the database.
 *
 * Transients carry two rows each - the value and its timeout - and both are
 * named after the transient, so the same sweep catches them. Underscores are
 * LIKE wildcards, hence esc_like.
 */
$eventcrew_prefix = $wpdb->esc_like('eventcrew_') . '%';

$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options}
        WHERE option_name LIKE %s
           OR option_name LIKE %s
           OR option_name LIKE %s",
        $eventcrew_prefix,
        $wpdb->esc_like('_transient_eventcrew_') . '%',
        $wpdb->esc_like('_transient_timeout_eventcrew_') . '%'
    )
);
