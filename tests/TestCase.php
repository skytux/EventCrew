<?php

declare(strict_types=1);

namespace EventCrew\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use EventCrew\Support\Language;
use FakeWpdb;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase
{
    protected FakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // A clean database double per test, so a queued result left over from
        // one test can never satisfy a query in the next.
        $this->wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        $this->stubCommonFunctions();
    }

    protected function tearDown(): void
    {
        // A test that failed inside Language::run() must not leave the next
        // one reading Finnish.
        while (null !== Language::current()) {
            Language::leave();
        }

        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * The handful of WordPress functions that almost every unit touches.
     * Anything a single test cares about asserting on should be re-stubbed
     * there with an explicit expectation instead.
     */
    private function stubCommonFunctions(): void
    {
        // English as written, unless the code under test is inside a Finnish
        // Language::run(); then the shipped .mo answers (see FinnishCatalog).
        $translate = static fn (string $text): string => FinnishCatalog::translate($text);
        Functions\when('__')->alias($translate);
        Functions\when('_x')->alias($translate);
        // Returns the singular or plural template by count, the way WordPress
        // does; sprintf in the caller still fills in the number.
        Functions\when('_n')->alias(
            static fn (string $single, string $plural, int $number): string => FinnishCatalog::translatePlural(
                $single,
                $plural,
                $number
            )
        );
        Functions\when('esc_html__')->alias($translate);
        Functions\when('esc_attr__')->alias($translate);
        Functions\when('sanitize_key')->alias(
            static fn (string $key): string => strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $key) ?? '')
        );
        Functions\when('sanitize_text_field')->alias(
            static fn (string $value): string => trim(strip_tags($value))
        );
        // 'timestamp' answers with a real integer, not gmdate('timestamp'):
        // SendWindow asks for one, and a garbage value would read as midnight
        // and hold every scheduled notification in every test.
        Functions\when('current_time')->alias(
            static function (string $format): string|int {
                $at = (int) strtotime('2026-07-20 12:00:00');

                if ('mysql' === $format) {
                    return '2026-07-20 12:00:00';
                }

                return 'timestamp' === $format ? $at : gmdate($format, $at);
            }
        );
        // Harmless by default: several repositories and services fire actions
        // (e.g. eventcrew/board_stale) that most tests do not care about. A
        // test that does care re-stubs this with a capturing alias.
        Functions\when('do_action')->justReturn(null);
        // The webhook claim writes its option in SQL and then drops the cached
        // copy; there is no cache in the tests to drop.
        Functions\when('wp_cache_delete')->justReturn(true);

        $this->stubEmailFunctions();
    }

    /**
     * What rendering an email touches. Every notification now goes through the
     * HTML template, so any unit that sends one reaches these whether or not it
     * is what the test is about. The defaults describe a plain site - no custom
     * logo, no Site Icon, no edited template - which lands on the built-in
     * design; a test about the template itself re-stubs them.
     */
    private function stubEmailFunctions(): void
    {
        Functions\stubEscapeFunctions();

        Functions\when('sanitize_hex_color')->returnArg(1);
        Functions\when('get_bloginfo')->justReturn('Test Site');
        Functions\when('home_url')->alias(
            static fn (string $path = ''): string => 'https://site.test' . $path
        );
        Functions\when('get_theme_mod')->justReturn(false);
        Functions\when('has_site_icon')->justReturn(false);
        Functions\when('get_site_icon_url')->justReturn('');
        Functions\when('wp_get_attachment_image_src')->justReturn(false);
        Functions\when('wp_get_global_settings')->justReturn([]);
        Functions\when('do_blocks')->returnArg(1);
        Functions\when('get_post')->justReturn(null);
        Functions\when('get_post_type')->justReturn(false);
        Functions\when('wp_insert_post')->justReturn(123);
        Functions\when('update_option')->justReturn(true);
        Functions\when('is_wp_error')->justReturn(false);
        // An import asks WP-Cron to email the tickets (TicketMail::schedule).
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->justReturn(true);
    }
}
