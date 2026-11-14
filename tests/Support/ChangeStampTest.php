<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\ChangeStamp;
use EventCrew\Tests\TestCase;

final class ChangeStampTest extends TestCase
{
    protected function tearDown(): void
    {
        ChangeStamp::reset();
        parent::tearDown();
    }

    public function testOnlyWritesToTheTablesTheAppShowsCount(): void
    {
        $p = $this->wpdb->prefix;

        self::assertTrue(ChangeStamp::isWrite("INSERT INTO `{$p}eventcrew_assignments` (`task_id`) VALUES (1)"));
        self::assertTrue(ChangeStamp::isWrite("UPDATE {$p}eventcrew_people SET display_name = 'x' WHERE id = 1"));
        self::assertTrue(ChangeStamp::isWrite("  delete FROM `{$p}eventcrew_tasks` WHERE id = 3"));
        self::assertTrue(ChangeStamp::isWrite("REPLACE INTO {$p}eventcrew_redemptions(id) VALUES (1)"));
        // Reads, other tables, and a table name only in a value do not.
        self::assertFalse(ChangeStamp::isWrite("SELECT * FROM {$p}eventcrew_assignments"));
        self::assertFalse(ChangeStamp::isWrite("INSERT INTO `{$p}eventcrew_logs` (`message`) VALUES ('x')"));
        self::assertFalse(ChangeStamp::isWrite("UPDATE {$p}options SET option_value = 'x' WHERE option_name = 'y'"));
        self::assertFalse(ChangeStamp::isWrite("INSERT INTO `{$p}eventcrew_logs` (`m`) VALUES ('UPDATE {$p}eventcrew_people ')"));
        self::assertFalse(ChangeStamp::isWrite("UPDATE {$p}eventcrew_people_extra SET a = 1"));
    }

    public function testAWriteSavesOneNewStampAtTheEnd(): void
    {
        $saved = [];
        Functions\when('update_option')->alias(static function (string $name, mixed $value) use (&$saved): bool {
            $saved[] = [$name, $value];

            return true;
        });
        $p = $this->wpdb->prefix;

        ChangeStamp::save();
        self::assertSame([], $saved, 'Nothing written, nothing saved.');

        $query = "UPDATE {$p}eventcrew_tasks SET capacity = 2 WHERE id = 1";
        self::assertSame($query, ChangeStamp::watch($query));
        ChangeStamp::watch("DELETE FROM {$p}eventcrew_assignments WHERE id = 1");
        self::assertTrue(ChangeStamp::pending());

        ChangeStamp::save();
        ChangeStamp::save();

        self::assertCount(1, $saved);
        self::assertSame(ChangeStamp::OPTION, $saved[0][0]);
        self::assertMatchesRegularExpression('/^\d+$/', (string) $saved[0][1]);
        self::assertFalse(ChangeStamp::pending());
    }
}
