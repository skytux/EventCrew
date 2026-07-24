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
    }
}
