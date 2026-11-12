<?php

/**
 * The WordPress functions that almost every unit touches, as plain functions
 * with the test suite's default behaviour.
 *
 * These used to be Brain Monkey stubs set up again in every TestCase::setUp().
 * A stubbed function goes through Patchwork on every call (two
 * debug_backtrace() calls and a closure, some 10 microseconds), and __() alone
 * is called hundreds of times to build the message kinds, so the stubs cost
 * more than most tests. A plain function costs well under a microsecond.
 *
 * This file is preprocessed by Patchwork (patchwork.json whitelists it), so a
 * test can still re-stub any of these with Functions\when() or expect them
 * with Functions\expect(); Monkey\tearDown() then brings back the default
 * here. Parameters are untyped and cast, and the file is not strict_types,
 * the way the stubs (called through Patchwork) coerced them.
 *
 * Not here: do_action and is_wp_error, which Brain Monkey defines itself
 * (TestCase still stubs those per test).
 */

use Brain\Monkey\Expectation\EscapeHelper;
use EventCrew\Tests\FinnishCatalog;

// English as written, unless the code under test is inside a Finnish
// Language::run(); then the shipped .mo answers (see FinnishCatalog).
function __($text, $domain = 'default')
{
    return FinnishCatalog::translate((string) $text);
}

function _x($text, $context, $domain = 'default')
{
    return FinnishCatalog::translateInContext((string) $text, (string) $context);
}

// Returns the singular or plural template by count, the way WordPress does;
// sprintf in the caller still fills in the number.
function _n($single, $plural, $number, $domain = 'default')
{
    return FinnishCatalog::translatePlural((string) $single, (string) $plural, (int) $number);
}

function esc_html__($text, $domain = 'default')
{
    return FinnishCatalog::translate((string) $text);
}

function esc_attr__($text, $domain = 'default')
{
    return FinnishCatalog::translate((string) $text);
}

function sanitize_key($key)
{
    return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $key) ?? '');
}

function sanitize_text_field($value)
{
    return trim(strip_tags((string) $value));
}

// 'timestamp' answers with a real integer, not gmdate('timestamp'):
// SendWindow asks for one, and a garbage value would read as midnight and hold
// every scheduled notification in every test.
function current_time($format, $gmt = 0)
{
    $format = (string) $format;
    $at = (int) strtotime('2026-07-20 12:00:00');

    if ('mysql' === $format) {
        return '2026-07-20 12:00:00';
    }

    return 'timestamp' === $format ? $at : gmdate($format, $at);
}

// Language reads the site's languages (SiteLanguages) from an option, so
// nearly every unit reads one. Nothing stored: the defaults. A test with
// options of its own re-stubs this.
function get_option($name, $default = false)
{
    return $default;
}

// The webhook claim writes its option in SQL and then drops the cached copy;
// there is no cache in the tests to drop.
function wp_cache_delete($key, $group = '')
{
    return true;
}

// Brain Monkey's stubEscapeFunctions(), as plain functions.
function esc_js($text)
{
    return EscapeHelper::esc($text);
}

function esc_sql($data)
{
    return addslashes($data);
}

function esc_attr($text)
{
    return EscapeHelper::esc($text);
}

function esc_html($text)
{
    return EscapeHelper::esc($text);
}

function esc_textarea($text)
{
    return EscapeHelper::esc($text);
}

function esc_url($url)
{
    return EscapeHelper::escUrl($url);
}

function esc_url_raw($url)
{
    return EscapeHelper::escUrlRaw($url);
}

function esc_xml($text)
{
    return EscapeHelper::escXml($text);
}

// What rendering an email touches. Every notification goes through the HTML
// template, so any unit that sends one reaches these whether or not it is what
// the test is about. The defaults describe a plain site - no custom logo, no
// Site Icon, no edited template - which lands on the built-in design; a test
// about the template itself re-stubs them.
function sanitize_hex_color($color)
{
    return $color;
}

function get_bloginfo($show = '', $filter = 'raw')
{
    return 'Test Site';
}

function home_url($path = '', $scheme = null)
{
    return 'https://site.test' . $path;
}

function get_theme_mod($name, $default = false)
{
    return false;
}

function has_site_icon($blog_id = 0)
{
    return false;
}

function get_site_icon_url($size = 512, $url = '', $blog_id = 0)
{
    return '';
}

function wp_get_attachment_image_src($attachment_id, $size = 'thumbnail', $icon = false)
{
    return false;
}

function wp_get_global_settings($path = [], $context = [])
{
    return [];
}

function do_blocks($content)
{
    return $content;
}

function get_post($post = null, $output = 'OBJECT', $filter = 'raw')
{
    return null;
}

function get_post_type($post = null)
{
    return false;
}

// EventMesh is off unless a test fakes its events (EventTimes reads them).
function post_type_exists($post_type)
{
    return false;
}

function wp_insert_post($postarr, $wp_error = false, $fire_after_hooks = true)
{
    return 123;
}

function update_option($option, $value, $autoload = null)
{
    return true;
}

// The Crew app page makes the site's push key on first use (VapidKeys).
// Nothing is stored, so get_option() above still answers the default.
function add_option($option, $value = '', $deprecated = '', $autoload = 'yes')
{
    return false;
}

// An import asks WP-Cron to email the tickets (TicketMail::schedule).
function wp_next_scheduled($hook, $args = [])
{
    return false;
}

function wp_schedule_single_event($timestamp, $hook, $args = [], $wp_error = false)
{
    return true;
}

// Admin::notice() keeps each notice per administrator.
function get_current_user_id()
{
    return 1;
}
