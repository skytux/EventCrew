<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\LeaderGate;
use EventCrew\Tests\TestCase;

final class LeaderGateTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        Functions\when('get_option')->alias(fn (string $n, mixed $d = false): mixed => $this->options[$n] ?? $d);
        Functions\when('update_option')->alias(function (string $n, mixed $v): bool {
            $this->options[$n] = $v;

            return true;
        });
    }

    public function testDefaultsToTheGlobalSetting(): void
    {
        self::assertFalse((new LeaderGate())->isEnabled('2026-08-01'));

        $this->options[LeaderGate::ENABLED_OPTION] = true;
        self::assertTrue((new LeaderGate())->isEnabled('2026-08-01'));
    }

    public function testAPerDateOverrideWinsOverTheDefault(): void
    {
        $this->options[LeaderGate::ENABLED_OPTION] = true; // on by default

        (new LeaderGate())->disable('2026-08-01');

        self::assertFalse((new LeaderGate())->isEnabled('2026-08-01')); // overridden off
        self::assertTrue((new LeaderGate())->isEnabled('2026-09-01'));  // still follows the default
    }
}
