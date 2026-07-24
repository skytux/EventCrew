<?php

/**
 * Plugin Name: EventCrew
 * Plugin URI: https://github.com/skytux/EventCrew
 * Description: Staffing, attendance and rewards for recurring events, from a Telegram group and a public web page.
 * Version: 1.7.6
 * Requires at least: 6.8
 * Requires PHP: 8.2
 * Author: Lou H
 * Author URI: https://github.com/skytux
 * License: GPLv2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: eventcrew
 * Domain Path: /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('EVENTCREW_VERSION', '1.7.6');
define('EVENTCREW_PLUGIN_FILE', __FILE__);
define('EVENTCREW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EVENTCREW_PLUGIN_URL', plugin_dir_url(__FILE__));

require EVENTCREW_PLUGIN_DIR . 'src/Support/Autoloader.php';

EventCrew\Support\Autoloader::register();

// Load translations from the bundled languages/ directory. Hooked on init
// because WordPress 6.7+ warns when a textdomain loads earlier, and admin
// strings are only needed from admin_menu onward, which fires well after this.
add_action(
    'init',
    static function (): void {
        load_plugin_textdomain(
            'eventcrew',
            false,
            dirname(plugin_basename(EVENTCREW_PLUGIN_FILE)) . '/languages'
        );
    }
);

// Tables are created here for a fresh install, but the same migration also
// runs on plugins_loaded when the stored schema version is behind - see
// Database\Schema. Activation alone is not enough, because WordPress does not
// fire this hook when a plugin is updated through the normal updater.
register_activation_hook(
    EVENTCREW_PLUGIN_FILE,
    static function (): void {
        EventCrew\Database\Schema::migrate();
    }
);

// Clear the notifications cron event on deactivation. The kernel is not booted
// at this point, so this calls the scheduler's own static teardown rather than
// reaching through a container.
register_deactivation_hook(
    EVENTCREW_PLUGIN_FILE,
    static function (): void {
        EventCrew\Support\Scheduler::unschedule();
    }
);

EventCrew\Core\Plugin::boot();
