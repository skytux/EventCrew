<?php

declare(strict_types=1);

namespace EventCrew\Tests\Repositories;

use Brain\Monkey\Functions;
use EventCrew\Admin\BotLogPage;
use EventCrew\Admin\View;
use EventCrew\Repositories\BotLogRepository;
use EventCrew\Telegram\BotLog;
use EventCrew\Tests\TestCase;

/**
 * The command log's storage: the insert, the 90-day retention and the queries
 * behind the wp-admin Bot log page.
 */
final class BotLogRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // wp_date is left undefined on purpose: stubbing it would define it for
        // every later test, which reads function_exists('wp_date').
        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('Europe/Helsinki'));
    }

    public function testInsertFindsThePersonInTheSameStatementAndWritesNulls(): void
    {
        $this->wpdb->nextQueryResults[] = 1;

        $id = (new BotLogRepository())->insert([
            'created_at' => '2026-07-20 09:00:00',
            'telegram_user_id' => 555,
            'chat_kind' => 'private',
            'command' => '/me',
            'source' => 'typed',
            'button_kind' => null,
            'date_arg' => null,
            'outcome' => 'ok',
        ]);

        self::assertGreaterThan(0, $id);
        self::assertSame(
            'INSERT INTO wp_eventcrew_bot_log (created_at, person_id, telegram_user_id, chat_kind, command, source,'
            . " button_kind, date_arg, outcome) VALUES ('2026-07-20 09:00:00', (SELECT id FROM wp_eventcrew_people"
            . " WHERE telegram_user_id = 555 LIMIT 1), NULLIF(555, 0), 'private', '/me', 'typed', NULL, NULL, 'ok')",
            $this->wpdb->lastQuery()
        );
    }

    public function testInsertReturnsZeroWhenNothingWasWritten(): void
    {
        $id = (new BotLogRepository())->insert([
            'created_at' => '2026-07-20 09:00:00',
            'telegram_user_id' => 555,
            'chat_kind' => 'group',
            'command' => '',
            'source' => 'button',
            'button_kind' => 'rm:',
            'date_arg' => '2026-08-08',
            'outcome' => 'ok',
        ]);

        self::assertSame(0, $id);
        self::assertStringContainsString("'group', '', 'button', 'rm:', '2026-08-08', 'ok')", $this->wpdb->lastQuery());
    }

    public function testRetentionDeletesRowsOlderThanNinetyDays(): void
    {
        $this->wpdb->nextQueryResults[] = 4;
        $now = (int) strtotime('2026-11-03 12:00:00 UTC');

        $deleted = (new BotLogRepository())->deleteBefore(BotLog::cutoff($now));

        self::assertSame(4, $deleted);
        self::assertSame(
            "DELETE FROM wp_eventcrew_bot_log WHERE created_at < '2026-08-05 12:00:00'",
            $this->wpdb->lastQuery()
        );
    }

    /** The old rows are deleted once a day, not on every hourly heartbeat (Q212). */
    public function testRetentionRunsOnceADay(): void
    {
        $now = (int) strtotime('2026-11-03 12:00:00 UTC');
        $saved = [];
        Functions\when('update_option')->alias(static function (string $name, mixed $value) use (&$saved): bool {
            $saved[$name] = $value;

            return true;
        });

        // Last run 23 hours ago: not due, nothing deleted.
        Functions\when('get_option')->justReturn($now - 23 * HOUR_IN_SECONDS);
        self::assertSame(-1, BotLog::purgeIfDue(new BotLogRepository(), $now));
        self::assertSame([], $this->wpdb->queries);
        self::assertSame([], $saved);

        // Last run a day ago: due; deletes and remembers when.
        $this->wpdb->nextQueryResults[] = 2;
        Functions\when('get_option')->justReturn($now - DAY_IN_SECONDS);
        self::assertSame(2, BotLog::purgeIfDue(new BotLogRepository(), $now));
        self::assertSame(
            "DELETE FROM wp_eventcrew_bot_log WHERE created_at < '2026-08-05 12:00:00'",
            $this->wpdb->lastQuery()
        );
        self::assertSame([BotLog::PURGED_OPTION => $now], $saved);
    }

    public function testTheHeartbeatRunsTheRetention(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Support/Scheduler.php');

        self::assertStringContainsString('BotLog::purgeIfDue();', $source);
    }

    public function testTheDayIsTheSiteTimeZoneDayInUtc(): void
    {
        // Helsinki is UTC+3 in summer, UTC+2 in winter.
        self::assertSame(['2026-07-19 21:00:00', '2026-07-20 21:00:00'], BotLogPage::utcRange('2026-07-20'));
        self::assertSame(['2026-11-02 22:00:00', '2026-11-03 22:00:00'], BotLogPage::utcRange('2026-11-03'));
    }

    public function testTheAdminPageQueriesOneDayNewestFirst(): void
    {
        $this->wpdb->nextVars[] = '3';
        $this->wpdb->nextResults[] = [
            ['person_id' => 7, 'unlinked_id' => null, 'display_name' => 'Sam', 'commands' => 2, 'first_id' => 10],
            ['person_id' => null, 'unlinked_id' => 900, 'display_name' => null, 'commands' => 1, 'first_id' => 12],
        ];
        $this->wpdb->nextResults[] = [
            [
                'id' => 12, 'created_at' => '2026-07-20 10:05:00', 'person_id' => null, 'display_name' => null,
                'telegram_user_id' => 900, 'chat_kind' => 'group', 'command' => '', 'source' => 'button',
                'button_kind' => 'j:', 'date_arg' => null, 'outcome' => 'ok',
            ],
            [
                'id' => 11, 'created_at' => '2026-07-20 09:30:00', 'person_id' => 7, 'display_name' => 'Sam',
                'telegram_user_id' => 555, 'chat_kind' => 'private', 'command' => '/roster', 'source' => 'helper',
                'button_kind' => null, 'date_arg' => '2026-07-25', 'outcome' => 'refused',
            ],
        ];

        $data = (new BotLogPage(new View()))->data('2026-07-20', 1);

        $queries = $this->wpdb->queries;
        self::assertSame(
            "SELECT COUNT(*) FROM wp_eventcrew_bot_log WHERE created_at >= '2026-07-19 21:00:00'"
            . " AND created_at < '2026-07-20 21:00:00'",
            $queries[0]
        );
        self::assertStringContainsString('GROUP BY l.person_id, unlinked_id', $queries[1]);
        self::assertStringContainsString('ORDER BY commands DESC, first_id ASC', $queries[1]);
        self::assertStringContainsString(
            "WHERE l.created_at >= '2026-07-19 21:00:00' AND l.created_at < '2026-07-20 21:00:00'"
            . ' ORDER BY l.created_at DESC, l.id DESC LIMIT 50 OFFSET 0',
            $queries[2]
        );

        self::assertSame(
            [['name' => 'Sam', 'commands' => 2], ['name' => 'unlinked user #1', 'commands' => 1]],
            $data['people']
        );
        self::assertSame(
            [
                'time' => '13:05', 'person' => 'unlinked user #1', 'chat' => 'group', 'command' => '',
                'source' => 'button', 'button' => 'j:', 'date' => '', 'outcome' => 'ok',
            ],
            $data['rows'][0]
        );
        self::assertSame('12:30', $data['rows'][1]['time']);
        self::assertSame('Sam', $data['rows'][1]['person']);
        self::assertSame('helper', $data['rows'][1]['source']);
        self::assertSame('refused', $data['rows'][1]['outcome']);
        self::assertStringNotContainsString('900', (string) json_encode($data), 'The Telegram number is never shown.');
        self::assertSame(1, $data['pages']);
        self::assertSame(3, $data['total']);
    }

    public function testAnInvalidDayMeansTodayAndTheLastPageIsTheLimit(): void
    {
        $this->wpdb->nextVars[] = '120';

        $data = (new BotLogPage(new View()))->data('2026-02-31', 9);

        self::assertSame('2026-07-20', $data['day']);
        self::assertSame(3, $data['pages']);
        self::assertSame(3, $data['page']);
        self::assertStringEndsWith('LIMIT 50 OFFSET 100', $this->wpdb->queries[2]);
    }
}
