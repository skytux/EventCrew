<?php

declare(strict_types=1);

namespace EventCrew\Tests\Core;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use EventCrew\Core\Kernel;
use EventCrew\Database\Schema;
use EventCrew\Tests\TestCase;

/**
 * The kernel boots on plugins_loaded, before init. WordPress 6.7+ warns when
 * the eventcrew textdomain is loaded that early, and any translation call
 * loads it. So nothing the boot runs may translate: not boot() itself, and not
 * the cron_schedules filter it adds, which wp_schedule_event() applies during
 * that same boot.
 */
final class KernelBootTest extends TestCase
{
    /** @var list<string> */
    private array $translated = [];

    /** @var list<callable> */
    private array $scheduleFilters = [];

    private int $initCount = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $record = function (string $text): string {
            $this->translated[] = $text;

            return $text;
        };

        foreach (['__', '_x', 'esc_html__', 'esc_attr__', 'esc_html_x', 'esc_attr_x', 'translate'] as $function) {
            Functions\when($function)->alias($record);
        }

        foreach (['_e', 'esc_html_e', 'esc_attr_e', '_ex'] as $function) {
            Functions\when($function)->alias(
                function (string $text): void {
                    $this->translated[] = $text;
                }
            );
        }

        Functions\when('_n')->alias(
            function (string $single, string $plural, int $number): string {
                $this->translated[] = $single;

                return 1 === $number ? $single : $plural;
            }
        );

        Functions\when('did_action')->alias(
            fn (string $hook): int => 'init' === $hook ? $this->initCount : 0
        );
        Functions\when('doing_action')->justReturn(false);

        // A current schema, so the boot does not try to run dbDelta.
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => Schema::VERSION_OPTION === $name
                ? Schema::DB_VERSION
                : $default
        );

        Filters\expectAdded('cron_schedules')->zeroOrMoreTimes()->whenHappen(
            function (callable $callback): void {
                $this->scheduleFilters[] = $callback;
            }
        );

        // Nothing is scheduled yet, so the boot schedules its events. The real
        // wp_schedule_event() checks the recurrence against wp_get_schedules(),
        // which applies cron_schedules; this does the same.
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_event')->alias(
            function (): bool {
                $this->cronSchedules();

                return true;
            }
        );

        Functions\when('add_shortcode')->justReturn(null);
    }

    /** @return array<string, array{interval: int, display: string}> */
    private function cronSchedules(): array
    {
        $schedules = [];

        foreach ($this->scheduleFilters as $callback) {
            $schedules = $callback($schedules);
        }

        return $schedules;
    }

    public function testBootingBeforeInitTranslatesNothing(): void
    {
        (new Kernel())->boot();

        self::assertNotSame([], $this->scheduleFilters, 'The boot adds the five-minute schedule.');
        self::assertSame([], $this->translated);
    }

    public function testTheCronScheduleLabelIsTranslatedFromInitOn(): void
    {
        (new Kernel())->boot();

        $this->initCount = 1;
        $schedules = $this->cronSchedules();

        self::assertSame(
            'Every five minutes (EventCrew sales desk links)',
            $schedules['eventcrew_five_minutes']['display']
        );
        self::assertSame(['Every five minutes (EventCrew sales desk links)'], $this->translated);
    }
}
