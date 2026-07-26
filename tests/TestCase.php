<?php

declare(strict_types=1);

namespace EventCrew\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
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
        Functions\when('__')->returnArg(1);
        Functions\when('_x')->returnArg(1);
        // Returns the singular or plural template by count, the way WordPress
        // does; sprintf in the caller still fills in the number.
        Functions\when('_n')->alias(
            static fn (string $single, string $plural, int $number): string => 1 === $number ? $single : $plural
        );
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('esc_attr__')->returnArg(1);
        Functions\when('sanitize_key')->alias(
            static fn (string $key): string => strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $key) ?? '')
        );
        Functions\when('sanitize_text_field')->alias(
            static fn (string $value): string => trim(strip_tags($value))
        );
        Functions\when('current_time')->alias(
            static fn (string $format): string => 'mysql' === $format
                ? '2026-07-20 12:00:00'
                : gmdate($format, strtotime('2026-07-20 12:00:00'))
        );
        // Harmless by default: several repositories and services fire actions
        // (e.g. eventcrew/board_stale) that most tests do not care about. A
        // test that does care re-stubs this with a capturing alias.
        Functions\when('do_action')->justReturn(null);

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
        Functions\when('do_blocks')->returnArg(1);
        Functions\when('get_post')->justReturn(null);
        Functions\when('get_post_type')->justReturn(false);
        Functions\when('wp_insert_post')->justReturn(123);
        Functions\when('update_option')->justReturn(true);
        Functions\when('is_wp_error')->justReturn(false);
    }
}
