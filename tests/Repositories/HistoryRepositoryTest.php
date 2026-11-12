<?php

declare(strict_types=1);

namespace EventCrew\Tests\Repositories;

use Brain\Monkey\Functions;
use EventCrew\Admin\HistoryPage;
use EventCrew\Database\Schema;
use EventCrew\Repositories\HistoryRepository;
use EventCrew\Support\History;
use EventCrew\Tests\TestCase;

/**
 * The history table's storage: the backfill at the schema upgrade, the
 * one-year retention, erasure and the queries behind the History page.
 */
final class HistoryRepositoryTest extends TestCase
{
    public function testBackfillDeletesItsOwnRowsFirstThenFillsEveryKind(): void
    {
        // The DELETE, then twelve INSERT ... SELECT statements, 2 rows each.
        $this->wpdb->nextQueryResults = array_merge([5], array_fill(0, 12, 2));

        $added = (new HistoryRepository())->backfill('2025-07-20 12:00:00');

        self::assertSame(24, $added);
        self::assertCount(13, $this->wpdb->queries);
        self::assertSame(
            "DELETE FROM wp_eventcrew_history WHERE source = 'backfill'",
            $this->wpdb->queries[0]
        );

        foreach (array_slice($this->wpdb->queries, 1) as $sql) {
            self::assertStringStartsWith('INSERT INTO wp_eventcrew_history (', $sql);
            self::assertStringContainsString("'backfill'", $sql);
            self::assertStringContainsString("'2025-07-20 12:00:00'", $sql);
            self::assertStringNotContainsString('%s', $sql);
            // Never a name, an email or a note.
            self::assertStringNotContainsString('email', $sql);
            self::assertStringNotContainsString('notes', $sql);
        }
    }

    public function testBackfillRunsOnceOnlyOnAnUpgradeFromBeforeSchema37(): void
    {
        $claims = 0;
        Functions\when('add_option')->alias(static function () use (&$claims): bool {
            return 1 === ++$claims;
        });

        self::assertSame(-1, Schema::backfillHistory(''), 'a new install has nothing to backfill');
        self::assertSame(-1, Schema::backfillHistory('37'));
        self::assertSame(-1, Schema::backfillHistory('38'));
        self::assertSame([], $this->wpdb->queries);
        self::assertSame(0, $claims);

        $this->wpdb->nextQueryResults = array_merge([0], array_fill(0, 12, 1));
        self::assertSame(12, Schema::backfillHistory('36'));
        self::assertStringContainsString("'2025-07-20 12:00:00'", $this->wpdb->queries[1]);

        $count = count($this->wpdb->queries);
        self::assertSame(-1, Schema::backfillHistory('36'), 'the claim option is taken');
        self::assertCount($count, $this->wpdb->queries);
    }

    public function testMigrateCallsTheBackfill(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Database/Schema.php');

        self::assertStringContainsString('self::backfillHistory((string) get_option(self::VERSION_OPTION', $source);
        self::assertGreaterThanOrEqual(37, (int) Schema::DB_VERSION);
    }

    public function testRetentionDeletesRowsOlderThanOneYear(): void
    {
        $this->wpdb->nextQueryResults[] = 3;

        $deleted = (new HistoryRepository())->deleteBefore(History::cutoff('2026-11-08 12:00:00'));

        self::assertSame(3, $deleted);
        self::assertSame(
            "DELETE FROM wp_eventcrew_history WHERE created_at < '2025-11-08 12:00:00'",
            $this->wpdb->lastQuery()
        );
    }

    public function testPurgeRunsAtMostOnceADay(): void
    {
        $stored = [];
        $purgedAt = 0;
        Functions\when('get_option')->alias(
            static function (string $name, mixed $default = false) use (&$purgedAt): mixed {
                return History::PURGED_OPTION === $name ? $purgedAt : $default;
            }
        );
        Functions\when('update_option')->alias(static function (string $name, mixed $value) use (&$stored): bool {
            $stored[$name] = $value;

            return true;
        });
        $now = (int) strtotime('2026-11-08 12:00:00 UTC');
        $this->wpdb->nextQueryResults[] = 2;

        self::assertSame(2, History::purgeIfDue(new HistoryRepository(), $now));
        self::assertSame($now, $stored[History::PURGED_OPTION]);
        self::assertSame(
            "DELETE FROM wp_eventcrew_history WHERE created_at < '2025-07-20 12:00:00'",
            $this->wpdb->lastQuery()
        );

        $purgedAt = $now;
        $count = count($this->wpdb->queries);

        self::assertSame(-1, History::purgeIfDue(new HistoryRepository(), $now + 3600));
        self::assertCount($count, $this->wpdb->queries);
    }

    public function testTheHeartbeatRunsThePurge(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Support/Scheduler.php');

        self::assertStringContainsString('History::purgeIfDue();', $source);
    }

    public function testForgetPersonEmptiesTheirIdsInEveryColumn(): void
    {
        $this->wpdb->nextQueryResults = [2, 1, 4];

        self::assertSame(7, (new HistoryRepository())->forgetPerson(7));
        self::assertSame(
            [
                'UPDATE wp_eventcrew_history SET person_id = NULL WHERE person_id = 7',
                'UPDATE wp_eventcrew_history SET other_person_id = NULL WHERE other_person_id = 7',
                'UPDATE wp_eventcrew_history SET actor_person_id = NULL WHERE actor_person_id = 7',
            ],
            $this->wpdb->queries
        );
        self::assertSame(0, (new HistoryRepository())->forgetPerson(0));
        self::assertCount(3, $this->wpdb->queries);
    }

    public function testPageFiltersByPersonNightAndKindsNewestFirst(): void
    {
        $this->wpdb->nextResults[] = [['id' => 1, 'kind' => 'signed_up']];

        $rows = (new HistoryRepository())->page(
            ['person' => 7, 'date' => '2026-08-08', 'kinds' => ['signed_up', 'cancelled']],
            50,
            100
        );

        self::assertCount(1, $rows);
        $sql = $this->wpdb->lastQuery();
        self::assertStringContainsString(
            'WHERE 1=1 AND (h.person_id = 7 OR h.other_person_id = 7 OR h.actor_person_id = 7)'
            . " AND h.event_date = '2026-08-08' AND h.kind IN ('signed_up','cancelled')",
            $sql
        );
        self::assertStringContainsString('ORDER BY h.created_at DESC, h.id DESC', $sql);
        self::assertStringContainsString('LIMIT 50 OFFSET 100', $sql);
    }

    public function testCountWithAndWithoutAFilter(): void
    {
        $this->wpdb->nextVars = ['12', '3'];
        $repository = new HistoryRepository();

        self::assertSame(12, $repository->count([]));
        self::assertSame('SELECT COUNT(*) FROM wp_eventcrew_history AS h WHERE 1=1', $this->wpdb->lastQuery());

        self::assertSame(3, $repository->count(['since' => '2026-07-13 12:00:00', 'date' => 'not-a-date']));
        self::assertSame(
            "SELECT COUNT(*) FROM wp_eventcrew_history AS h WHERE 1=1 AND h.created_at >= '2026-07-13 12:00:00'",
            $this->wpdb->lastQuery()
        );
    }

    public function testThePageFilterReadsOnlyValidChoices(): void
    {
        Functions\when('wp_unslash')->returnArg();

        $filter = HistoryPage::filter(['person' => '7', 'night' => '2026-08-08', 'group' => 'door']);

        self::assertSame(7, $filter['person']);
        self::assertSame('2026-08-08', $filter['date']);
        self::assertSame('door', $filter['group']);
        self::assertSame(
            ['person' => 7, 'date' => '2026-08-08', 'kinds' => History::groups()['door']],
            $filter['query']
        );

        $bad = HistoryPage::filter(['person' => '-3', 'night' => '2026-02-31', 'group' => 'nope']);

        self::assertSame(0, $bad['person']);
        self::assertSame('', $bad['date']);
        self::assertSame('', $bad['group']);
        self::assertSame([], $bad['query']);
    }
}
