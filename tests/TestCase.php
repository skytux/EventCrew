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

        // No history recorder and no acting person left over from a test.
        \EventCrew\Support\History::reset();

        $this->stubCommonFunctions();

        // The count of jobs a request queued lives for one request.
        \EventCrew\Support\BackgroundJobs::resetAdded();
    }

    protected function tearDown(): void
    {
        // A test that failed inside Language::run() must not leave the next
        // one reading Finnish.
        while (null !== Language::current()) {
            Language::leave();
        }

        // Nor leave email links wrapped after a test that booted the kernel.
        \EventCrew\Support\EmailClicks::reset();

        // Nor leave the Crew app's own page picked: the crew page would then
        // hand its language switch to a bar no later test draws.
        (new \ReflectionProperty(\EventCrew\Web\CrewAppPage::class, 'current'))->setValue(null, null);

        Monkey\tearDown();
        parent::tearDown();

        $this->releaseProperties();
    }

    /**
     * PHPUnit keeps every finished test object until the run ends, and with it
     * whatever the test left in its properties: fake databases, repositories,
     * services, captured output. Over the whole suite that no longer fit in
     * 128 MB, so each test drops its own properties once it is done. PHPUnit's
     * own properties (declared on its TestCase) stay; static and readonly ones
     * are left alone. Unset rather than nulled, so typed non-nullable
     * properties are released too; the closure is bound to the declaring
     * class so private properties of every level can be reached.
     */
    private function releaseProperties(): void
    {
        $class = new \ReflectionClass($this);

        while (false !== $class && PHPUnitTestCase::class !== $class->getName()) {
            $scope = $class->getName();

            foreach ($class->getProperties() as $property) {
                if (
                    $property->isStatic()
                    || $property->isReadOnly()
                    || $property->getDeclaringClass()->getName() !== $scope
                ) {
                    continue;
                }

                $name = $property->getName();
                \Closure::bind(function () use ($name): void {
                    unset($this->$name);
                }, $this, $scope)();
            }

            $class = $class->getParentClass();
        }
    }

    /**
     * The handful of WordPress functions that almost every unit touches are
     * plain functions with their default behaviour in stubs/wp-functions.php
     * (stubbing them here for every test cost more than most tests). Only the
     * two that Brain Monkey defines itself are stubbed here. Anything a single
     * test cares about asserting on should be re-stubbed there with an
     * explicit expectation instead.
     */
    private function stubCommonFunctions(): void
    {
        // Harmless by default: several repositories and services fire actions
        // (e.g. eventcrew/board_stale) that most tests do not care about. A
        // test that does care re-stubs this with a capturing alias.
        Functions\when('do_action')->justReturn(null);
        Functions\when('is_wp_error')->justReturn(false);
    }
}
