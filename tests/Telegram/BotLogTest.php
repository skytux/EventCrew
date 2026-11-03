<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Telegram\BotLog;
use EventCrew\Telegram\UpdateRouter;

/**
 * The command log, through the real router: which updates make a row, with
 * which source, and that nothing of what the person wrote is in it.
 */
final class BotLogTest extends TelegramTestCase
{
    /** @var array<string, mixed> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->transients = [];

        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->transients[$key] = $value;

            return true;
        });
        Functions\when('get_transient')->alias(fn (string $key): mixed => $this->transients[$key] ?? false);
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);

            return true;
        });
        Functions\when('get_the_title')->justReturn('');
        Functions\when('sanitize_email')->alias(static fn (string $email): string => trim($email));
        Functions\when('is_email')->alias(
            static fn (string $email): bool => 1 === preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)
        );
        Functions\when('rest_url')->alias(
            static fn (string $path = ''): string => 'https://example.test/wp-json/' . $path
        );
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_mail')->justReturn(true);
    }

    private function router(): UpdateRouter
    {
        return $this->updateRouter(null, null, null, null, new BotLog());
    }

    /** @return list<string> */
    private function logInserts(): array
    {
        return array_values(array_filter(
            $this->wpdb->queries,
            static fn (string $sql): bool => str_starts_with($sql, 'INSERT INTO wp_eventcrew_bot_log')
        ));
    }

    /** @return array<string, mixed> */
    private static function message(string $text, string $chatType = 'private'): array
    {
        return [
            'message' => [
                'text' => $text,
                'chat' => ['id' => 'private' === $chatType ? 555 : -100123, 'type' => $chatType],
                'from' => ['id' => 555, 'first_name' => 'Sam'],
            ],
        ];
    }

    public function testATypedCommandMakesOneTypedRow(): void
    {
        $this->router()->dispatch(self::message('/roster 14.11.'));

        $rows = $this->logInserts();
        self::assertCount(1, $rows);
        self::assertStringContainsString("'private', '/roster', 'typed', NULL, '2026-11-14', 'ok'", $rows[0]);
        self::assertStringContainsString('WHERE telegram_user_id = 555', $rows[0]);
    }

    public function testAButtonMakesOneButtonRowWithOnlyThePrefix(): void
    {
        $this->router()->dispatch([
            'callback_query' => [
                'id' => 'cbq',
                'from' => ['id' => 555],
                'message' => ['message_id' => 42, 'chat' => ['id' => 555, 'type' => 'private']],
                'data' => 'rm:12:34',
            ],
        ]);

        $rows = $this->logInserts();
        self::assertCount(1, $rows);
        self::assertStringContainsString("'private', '', 'button', 'rm:', NULL, 'ok'", $rows[0]);
        self::assertStringNotContainsString('12:34', $rows[0]);
    }

    public function testADateInButtonDataIsKept(): void
    {
        $this->router()->dispatch([
            'callback_query' => [
                'id' => 'cbq',
                'from' => ['id' => 555],
                'message' => ['message_id' => 5, 'chat' => ['id' => 100, 'type' => 'supergroup']],
                'data' => 'bd:2026-08-08',
            ],
        ]);

        $rows = $this->logInserts();
        self::assertCount(1, $rows);
        self::assertStringContainsString("'group', '', 'button', 'bd:', '2026-08-08', 'ok'", $rows[0]);
    }

    public function testAHelperActionMakesOneHelperRow(): void
    {
        $router = $this->router();
        $runner = (new \ReflectionMethod(UpdateRouter::class, 'runner'))->invoke($router, null);

        $runner(self::message('/roster 2026-11-14'));

        $rows = $this->logInserts();
        self::assertCount(1, $rows);
        self::assertStringContainsString("'private', '/roster', 'helper', NULL, '2026-11-14', 'ok'", $rows[0]);
    }

    public function testNoTextOrArgumentsAreStored(): void
    {
        $this->router()->dispatch(self::message('/door add Anna Virtanen anna.virtanen@example.com +1 15.11.'));
        $this->router()->dispatch(self::message('hello, my name is Anna Virtanen, anna.virtanen@example.com'));

        $rows = $this->logInserts();
        self::assertCount(1, $rows, 'Plain text makes no row.');
        self::assertStringContainsString("'/door'", $rows[0]);
        self::assertStringContainsString("'2026-11-15'", $rows[0]);

        foreach ($rows as $sql) {
            self::assertStringNotContainsString('Anna', $sql);
            self::assertStringNotContainsString('Virtanen', $sql);
            self::assertStringNotContainsString('example.com', $sql);
            self::assertStringNotContainsString('+1', $sql);
        }
    }

    public function testAnUnknownCommandIsStoredAsOtherAndRefused(): void
    {
        $this->router()->dispatch(self::message('/secretcode 123456'));

        $rows = $this->logInserts();
        self::assertCount(1, $rows);
        self::assertStringContainsString("'other', 'typed', NULL, NULL, 'refused'", $rows[0]);
        self::assertStringNotContainsString('secretcode', $rows[0]);
        self::assertStringNotContainsString('123456', $rows[0]);
    }

    public function testACommandForAnotherBotIsNotStored(): void
    {
        $this->options['eventcrew_telegram_bot_username'] = 'crewbot';

        self::assertNull(BotLog::describe(self::message('/weather@otherbot', 'supergroup'), '', '2026-07-20'));
        self::assertNotNull(BotLog::describe(self::message('/roster@crewbot', 'supergroup'), '', '2026-07-20'));
    }

    public function testTheDateArgumentIsParsed(): void
    {
        self::assertSame(
            ['command' => '/roster', 'date' => '2026-11-14'],
            BotLog::commandOf('/roster 14.11.', '2026-07-20')
        );
        self::assertSame(
            ['command' => '/door', 'date' => '2026-08-08'],
            BotLog::commandOf('/DOOR@CrewBot list 2026-08-08', '2026-07-20')
        );
        self::assertSame(['command' => '/me', 'date' => null], BotLog::commandOf('/me', '2026-07-20'));
        self::assertSame(['command' => 'menu:ticket', 'date' => null], BotLog::commandOf('menu:ticket', '2026-07-20'));
    }

    public function testALoggingFailureDoesNotBreakHandling(): void
    {
        $GLOBALS['wpdb'] = $this->wpdb = new ThrowingOnBotLogWpdb();

        $this->router()->dispatch([
            'message' => [
                'text' => '/roster',
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 555],
            ],
        ]);

        // The roster still answered.
        self::assertSame(
            'Only organizers or people rostered on the day can see the roster.',
            $this->lastCallTo('sendMessage')['text']
        );
    }

    public function testAHandlerThatThrowsMarksTheRowAsAnErrorAndStillThrows(): void
    {
        $GLOBALS['wpdb'] = $this->wpdb = new ThrowingOnReadWpdb();
        $this->wpdb->nextQueryResults[] = 1;
        $thrown = null;

        try {
            $this->router()->dispatch(self::message('/roster'));
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'The log does not swallow the exception.');
        self::assertCount(1, $this->logInserts());
        self::assertCount(1, $this->wpdb->updates);
        self::assertSame('wp_eventcrew_bot_log', $this->wpdb->updates[0]['table']);
        self::assertSame(['outcome' => 'error'], $this->wpdb->updates[0]['data']);
        self::assertSame(['id' => $this->wpdb->insert_id], $this->wpdb->updates[0]['where']);
    }

    public function testFailedMarksTheRowAsAnError(): void
    {
        (new BotLog())->failed(9);
        (new BotLog())->failed(0);

        self::assertCount(1, $this->wpdb->updates);
        self::assertSame('wp_eventcrew_bot_log', $this->wpdb->updates[0]['table']);
        self::assertSame(['outcome' => 'error'], $this->wpdb->updates[0]['data']);
        self::assertSame(['id' => 9], $this->wpdb->updates[0]['where']);
    }

    public function testTheMarkerHasTheCommandAndTheDateOnly(): void
    {
        self::assertSame('[showed /roster 14.11.]', BotLog::marker('/roster 2026-11-14', false, '2026-07-20'));
        self::assertSame('[ran /door]', BotLog::marker('/door add Anna anna@example.com', true, '2026-07-20'));
        self::assertSame('[showed menu:ticket]', BotLog::marker('menu:ticket', false, '2026-07-20'));
    }

    public function testTheCutoffIsSixtyDaysAgo(): void
    {
        $now = (int) strtotime('2026-11-03 12:00:00 UTC');

        self::assertSame('2026-09-04 12:00:00', BotLog::cutoff($now));
    }
}

/** A wpdb whose row reads throw, so the handler fails after the row is written. */
final class ThrowingOnReadWpdb extends \FakeWpdb
{
    public function get_row(string $sql, string $output = 'OBJECT'): mixed
    {
        throw new \RuntimeException('read failed');
    }
}

/**
 * A wpdb whose every statement on the bot log throws, as a missing table or
 * a dropped connection would.
 */
final class ThrowingOnBotLogWpdb extends \FakeWpdb
{
    public function query(string $sql): int|bool
    {
        if (str_contains($sql, 'eventcrew_bot_log')) {
            throw new \RuntimeException('bot log table is gone');
        }

        return parent::query($sql);
    }

    public function update(string $table, array $data, array $where): int
    {
        if (str_contains($table, 'eventcrew_bot_log')) {
            throw new \RuntimeException('bot log table is gone');
        }

        return parent::update($table, $data, $where);
    }
}
