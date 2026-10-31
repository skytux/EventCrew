<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Tests\TestCase;

final class FreeEntryGateTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(
            function (string $name, mixed $value): bool {
                $this->options[$name] = $value;

                return true;
            }
        );
    }

    public function testAnUnlistedDateIsOpen(): void
    {
        self::assertFalse((new FreeEntryGate())->isClosed('2026-08-01'));
    }

    public function testCloseThenReopenADate(): void
    {
        $gate = new FreeEntryGate();

        $gate->close('2026-08-01');
        self::assertTrue($gate->isClosed('2026-08-01'));
        // A different date is unaffected.
        self::assertFalse($gate->isClosed('2026-08-02'));

        $gate->open('2026-08-01');
        self::assertFalse($gate->isClosed('2026-08-01'));
    }

    public function testClosingIsIdempotent(): void
    {
        $gate = new FreeEntryGate();

        $gate->close('2026-08-01');
        $gate->close('2026-08-01');

        self::assertSame(['2026-08-01'], $this->options[FreeEntryGate::OPTION]);
    }
}
