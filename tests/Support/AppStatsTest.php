<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\AppInstallPrompt;
use EventCrew\Support\AppStats;
use EventCrew\Tests\TestCase;

/**
 * The Crew app install numbers: the month's counters plus installs from
 * before counting began, and the 30-day numbers, read once.
 */
final class AppStatsTest extends TestCase
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

    public function testTheSummaryHasThisMonthLastMonthAndTheLast30Days(): void
    {
        $this->options['eventcrew_stats_2026_11'] = ['app' => ['tried' => 5, 'installed' => 2]];
        $this->options[AppStats::SINCE_OPTION] = '2026-11-12 18:00:00';
        $this->wpdb->nextCols[] = [
            // Installed before counting began, in November and in October.
            (string) json_encode([
                AppInstallPrompt::KEY_INSTALLED => '2026-11-12 10:00:00',
                AppInstallPrompt::KEY_LAST_OPEN => '2026-11-13 09:00:00',
            ]),
            (string) json_encode([
                AppInstallPrompt::KEY_INSTALLED => '2026-10-30 10:00:00',
                AppInstallPrompt::KEY_LAST_OPEN => '2026-10-14 12:00:00',
            ]),
            // Counted (after the start); not opened for over 30 days.
            (string) json_encode([
                AppInstallPrompt::KEY_INSTALLED => '2026-11-13 10:00:00',
                AppInstallPrompt::KEY_LAST_OPEN => '2026-10-14 11:59:59',
            ]),
            // Only a try, no install.
            (string) json_encode([AppInstallPrompt::KEY_TRIED_MONTH => '2026-11']),
        ];
        $this->wpdb->nextRows[] = ['total' => '9', 'recent' => '6'];

        $summary = (new AppStats())->summary(new \DateTimeImmutable('2026-11-13 12:00:00'));

        self::assertSame(
            [
                'this_month' => ['tried' => 6, 'installed' => 3],
                'last_month' => ['tried' => 1, 'installed' => 1],
                'active' => 2,
                'devices' => ['recent' => 6, 'total' => 9],
            ],
            $summary
        );

        // One read of the people, one of the devices.
        self::assertCount(2, $this->wpdb->queries);
        self::assertStringContainsString("last_ok_at >= '2026-10-14 12:00:00'", $this->wpdb->queries[1]);
    }

    public function testBeforeCountingBeganEveryInstallCountsFromItsTime(): void
    {
        $this->wpdb->nextCols[] = [
            (string) json_encode([AppInstallPrompt::KEY_INSTALLED => '2026-11-02 10:00:00']),
            (string) json_encode([AppInstallPrompt::KEY_INSTALLED => '']),
            'not json',
        ];

        self::assertSame(['tried' => 1, 'installed' => 1], (new AppStats())->month('2026-11'));
    }
}
