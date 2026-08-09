<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\SendWindow;
use EventCrew\Tests\TestCase;

/**
 * The hours scheduled notifications are allowed out, so a task at three in the
 * morning stops producing a reminder at three in the morning.
 */
final class SendWindowTest extends TestCase
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
    }

    private function at(string $time): int
    {
        return (int) strtotime($time . ' UTC');
    }

    public function testTheWindowRunsTwelveHoursFromTheChosenHour(): void
    {
        // Default 09:00, so open through to just before 21:00.
        self::assertFalse(SendWindow::isOpen($this->at('2026-08-09 03:00:00')));
        self::assertFalse(SendWindow::isOpen($this->at('2026-08-09 08:59:00')));
        self::assertTrue(SendWindow::isOpen($this->at('2026-08-09 09:00:00')));
        self::assertTrue(SendWindow::isOpen($this->at('2026-08-09 20:30:00')));
        self::assertFalse(SendWindow::isOpen($this->at('2026-08-09 21:00:00')));
    }

    public function testAnEveningWindowWrapsPastMidnight(): void
    {
        // 18:00 means open until 06:00, which needs no second rule.
        $this->options[SendWindow::OPTION] = 18;

        self::assertTrue(SendWindow::isOpen($this->at('2026-08-09 23:00:00')));
        self::assertTrue(SendWindow::isOpen($this->at('2026-08-09 02:00:00')));
        self::assertFalse(SendWindow::isOpen($this->at('2026-08-09 07:00:00')));
    }

    public function testAnHourThatIsNotATimeOfDayFallsBackToTheDefault(): void
    {
        // Stored nonsense must not quietly switch every notification off.
        $this->options[SendWindow::OPTION] = 99;

        self::assertSame(SendWindow::DEFAULT_HOUR, SendWindow::hour());
    }

    public function testNextOpeningIsTheComingNineOClock(): void
    {
        $opening = SendWindow::nextOpening($this->at('2026-08-09 03:20:45'));

        self::assertSame($this->at('2026-08-09 09:00:00'), $opening);
    }

    public function testNextOpeningIsNowWhileTheWindowIsAlreadyOpen(): void
    {
        $now = $this->at('2026-08-09 10:00:00');

        self::assertSame($now, SendWindow::nextOpening($now));
    }
}
