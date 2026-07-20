<?php

declare(strict_types=1);

namespace EventCrew\Tests\Database;

use Brain\Monkey\Functions;
use EventCrew\Database\Schema;
use EventCrew\Tests\TestCase;
use ReflectionMethod;

/**
 * The schema cannot be exercised against a real MySQL server here, so these
 * tests stand in for the build step: they assert the CREATE TABLE strings say
 * what the rest of the plugin assumes they say. A missing unique key would
 * otherwise only surface as duplicate rows in production.
 */
final class SchemaTest extends TestCase
{
    public function testPrefixesEveryTableWithTheSiteTablePrefix(): void
    {
        self::assertSame('wp_eventcrew_people', Schema::table(Schema::PEOPLE));
        self::assertSame('wp_eventcrew_assignments', Schema::table(Schema::ASSIGNMENTS));
    }

    public function testKnowsEveryTableItCreates(): void
    {
        $statements = $this->statements();

        self::assertCount(count(Schema::tableNames()), $statements);

        foreach (Schema::tableNames() as $name) {
            $table = Schema::table($name);

            self::assertNotEmpty(
                array_filter(
                    $statements,
                    static fn (string $sql): bool => str_contains($sql, "CREATE TABLE {$table} ")
                ),
                sprintf('No CREATE TABLE statement for %s.', $table)
            );
        }
    }

    /**
     * This index is what actually stops the same person holding two slots
     * in one task when they double-tap the group button.
     */
    public function testAssignmentsAreUniquePerTaskAndPerson(): void
    {
        self::assertStringContainsString(
            'UNIQUE KEY task_person (task_id,person_id)',
            $this->statementFor(Schema::ASSIGNMENTS)
        );
    }

    /**
     * The send-once guard for both notification kinds. Without it a cron tick
     * that dies mid-batch re-mails everyone it already reached.
     */
    public function testNotificationsAreUniquePerKindPersonAndDate(): void
    {
        self::assertStringContainsString(
            'UNIQUE KEY kind_person_date (kind,person_id,task_date)',
            $this->statementFor(Schema::NOTIFICATIONS)
        );
    }

    public function testPersonEmailAndTelegramIdAreUnique(): void
    {
        $sql = $this->statementFor(Schema::PEOPLE);

        self::assertStringContainsString('UNIQUE KEY email (email)', $sql);
        self::assertStringContainsString('UNIQUE KEY telegram_user_id (telegram_user_id)', $sql);
    }

    /**
     * Two spaces after PRIMARY KEY is not a typo - dbDelta parses these
     * strings with regular expressions and silently fails to detect the key
     * with one space, leaving tables it cannot later alter.
     */
    public function testEveryStatementUsesTheSpacingDbDeltaRequires(): void
    {
        foreach ($this->statements() as $sql) {
            self::assertStringContainsString('PRIMARY KEY  (id)', $sql);
            self::assertStringNotContainsString('INDEX ', $sql);
        }
    }

    public function testEmailColumnFitsInAUtf8mb4UniqueIndex(): void
    {
        // 255 chars of utf8mb4 exceeds the 767-byte index limit on MySQL 5.7,
        // which shared hosts still run; 191 is the largest safe width.
        self::assertStringContainsString(
            'email varchar(191) NOT NULL',
            $this->statementFor(Schema::PEOPLE)
        );
    }

    public function testConsentColumnsDefaultToNoConsent(): void
    {
        $sql = $this->statementFor(Schema::PEOPLE);

        self::assertStringContainsString('email_opt_in_at datetime DEFAULT NULL', $sql);
        self::assertStringContainsString('email_verified_at datetime DEFAULT NULL', $sql);
    }

    /**
     * The first real install came up MyISAM, because dbDelta never states an
     * engine and the host's default was not InnoDB. Declaring it explicitly
     * is what stops that happening on the next install; ensureInnoDb() repairs
     * the ones already made.
     *
     * Without InnoDB there is no crash recovery for signup history and no
     * transaction to make v0.5's "spend a credit, write the redemption" atomic.
     */
    public function testEveryTableDeclaresInnoDb(): void
    {
        foreach ($this->statements() as $sql) {
            self::assertStringContainsString('ENGINE=InnoDB', $sql);
        }
    }

    /**
     * Bare times could not express a task that runs past midnight, which is
     * every clean-up after an evening event. task_date deliberately stays a
     * DATE: it is the day the task is filed under, not the day it starts.
     */
    public function testTaskTimesAreDatetimesWhileTheTaskDateStaysADate(): void
    {
        $sql = $this->statementFor(Schema::TASKS);

        self::assertStringContainsString('task_date date NOT NULL', $sql);
        self::assertStringContainsString('starts_at datetime DEFAULT NULL', $sql);
        self::assertStringContainsString('ends_at datetime DEFAULT NULL', $sql);
    }

    public function testSkipsMigrationWhenTheStoredVersionIsCurrent(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with(Schema::VERSION_OPTION, '')
            ->andReturn(Schema::DB_VERSION);

        // A migration would have to call update_option; never stubbing it
        // means this test fails loudly if maybeMigrate() runs anyway.
        Schema::maybeMigrate();

        self::assertTrue(true);
    }

    private function statementFor(string $table): string
    {
        $needle = 'CREATE TABLE ' . Schema::table($table) . ' ';

        foreach ($this->statements() as $sql) {
            if (str_contains($sql, $needle)) {
                return $sql;
            }
        }

        self::fail(sprintf('No CREATE TABLE statement for %s.', $table));
    }

    /**
     * @return array<int, string>
     */
    private function statements(): array
    {
        $method = new ReflectionMethod(Schema::class, 'statements');
        $method->setAccessible(true);

        return $method->invoke(null, 'DEFAULT CHARACTER SET utf8mb4');
    }
}
