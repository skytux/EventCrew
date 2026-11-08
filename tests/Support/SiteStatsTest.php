<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\SiteStats;
use EventCrew\Tests\TestCase;

/**
 * The monthly click and campaign counters: one option per month, counts
 * only, capped, and old months dropped.
 */
final class SiteStatsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, mixed> */
    private array $autoload = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->autoload = [];
        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('Europe/Helsinki'));
        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value, mixed $autoload = null): bool {
            $this->options[$name] = $value;
            $this->autoload[$name] = $autoload;

            return true;
        });
        Functions\when('delete_option')->alias(function (string $name): bool {
            unset($this->options[$name]);

            return true;
        });
    }

    public function testClicksAndVisitsAreCountedPerMonthWithoutAutoload(): void
    {
        $now = new \DateTimeImmutable('2026-11-08 12:00', new \DateTimeZone('Europe/Helsinki'));

        SiteStats::addClick('ticket', 'open', $now);
        SiteStats::addClick('ticket', 'open', $now);
        SiteStats::addVisit('newsletter', $now);

        self::assertSame(
            ['clicks' => ['ticket' => ['open' => 2]], 'campaigns' => ['newsletter' => 1], 'visits' => 1],
            SiteStats::month('2026-11')
        );
        self::assertFalse($this->autoload['eventcrew_stats_2026_11']);
        self::assertSame(['clicks' => [], 'campaigns' => [], 'visits' => 0], SiteStats::month('2026-10'));
    }

    public function testCampaignsBeyondTheCapGoUnderOther(): void
    {
        $now = new \DateTimeImmutable('2026-11-08 12:00');

        for ($i = 0; $i < SiteStats::MAX_CAMPAIGNS; $i++) {
            SiteStats::addVisit('c' . $i, $now);
        }

        SiteStats::addVisit('one-more', $now);
        SiteStats::addVisit('c1', $now);

        $month = SiteStats::month('2026-11');
        self::assertCount(SiteStats::MAX_CAMPAIGNS + 1, $month['campaigns']);
        self::assertSame(1, $month['campaigns'][SiteStats::OTHER]);
        self::assertSame(2, $month['campaigns']['c1']);
        self::assertSame(SiteStats::MAX_CAMPAIGNS + 2, $month['visits']);
    }

    public function testClickPairsBeyondTheCapGoUnderOther(): void
    {
        $now = new \DateTimeImmutable('2026-11-08 12:00');

        for ($i = 0; $i < SiteStats::MAX_CLICK_PAIRS; $i++) {
            SiteStats::addClick('mail', 'b' . $i, $now);
        }

        SiteStats::addClick('other_mail', 'x', $now);

        self::assertSame(1, SiteStats::month('2026-11')['clicks'][SiteStats::OTHER][SiteStats::OTHER]);
    }

    public function testCampaignNamesAreCleaned(): void
    {
        self::assertSame('spring_2027-fb', SiteStats::campaignName(' Spring_2027-FB! '));
        self::assertSame('', SiteStats::campaignName('<>"'));
        self::assertSame(SiteStats::CAMPAIGN_MAX, strlen(SiteStats::campaignName(str_repeat('a', 90))));
    }

    public function testRobotsAndMissingAgentsAreBots(): void
    {
        self::assertTrue(SiteStats::isBot(''));
        self::assertTrue(SiteStats::isBot('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'));
        self::assertTrue(SiteStats::isBot('curl/8.4.0'));
        self::assertFalse(SiteStats::isBot(
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 '
            . '(KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'
        ));
    }

    public function testPruneDropsMonthsOlderThanTheKeptOnes(): void
    {
        $this->options['eventcrew_stats_2025_10'] = ['visits' => 1];
        $this->options['eventcrew_stats_2025_09'] = ['visits' => 1];
        $this->options['eventcrew_stats_2024_12'] = ['visits' => 1];

        SiteStats::prune(new \DateTimeImmutable('2026-11-08 12:00'));

        self::assertArrayHasKey('eventcrew_stats_2025_10', $this->options);
        self::assertArrayNotHasKey('eventcrew_stats_2025_09', $this->options);
        self::assertArrayNotHasKey('eventcrew_stats_2024_12', $this->options);
    }
}
