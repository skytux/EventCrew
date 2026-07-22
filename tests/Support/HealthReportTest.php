<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Database\Schema;
use EventCrew\Support\Diagnostic;
use EventCrew\Support\HealthReport;
use EventCrew\Telegram\UpdateRouter;
use EventCrew\Tests\TestCase;

/**
 * Light coverage of the diagnostics read-model: that it runs every check
 * without a live call, and that the database check - the one that actually
 * reads the schema - flags a missing table and a stale version.
 */
final class HealthReportTest extends TestCase
{
    /**
     * @param array<string, mixed> $options
     */
    private function withOptions(array $options): void
    {
        Functions\when('get_option')->alias(
            static fn (string $key, mixed $default = false): mixed => $options[$key] ?? $default
        );
    }

    public function testChecksRunWithoutAnyOutboundCall(): void
    {
        // Every table present, current version, nothing else configured.
        $this->wpdb->nextVars = array_fill(0, count(Schema::tableNames()), 'wp_eventcrew_people');
        $this->withOptions([Schema::VERSION_OPTION => Schema::DB_VERSION]);

        $checks = (new HealthReport())->checks();

        self::assertCount(6, $checks);

        foreach ($checks as $check) {
            self::assertInstanceOf(Diagnostic::class, $check);
            self::assertNotSame('', $check->label);
            self::assertNotSame('', $check->detail);
        }

        // The schema check is first and should be green here.
        self::assertSame(Diagnostic::OK, $checks[0]->status);
    }

    public function testMissingTablesAreAnError(): void
    {
        $this->wpdb->nextVars = []; // every SHOW TABLES returns null -> missing
        $this->withOptions([Schema::VERSION_OPTION => Schema::DB_VERSION]);

        self::assertSame(Diagnostic::ERROR, (new HealthReport())->checks()[0]->status);
    }

    public function testStaleSchemaVersionIsAWarning(): void
    {
        $this->wpdb->nextVars = array_fill(0, count(Schema::tableNames()), 'wp_eventcrew_people');
        $this->withOptions([Schema::VERSION_OPTION => '1']);

        self::assertSame(Diagnostic::WARN, (new HealthReport())->checks()[0]->status);
    }

    public function testRecentActivityIsNewestFirstAndLastUpdateIsRead(): void
    {
        $this->withOptions([
            'eventcrew_recent_logs' => [
                ['level' => 'WARNING', 'message' => 'older', 'timestamp' => 100],
                ['level' => 'ERROR', 'message' => 'newer', 'timestamp' => 200],
            ],
            UpdateRouter::LAST_UPDATE_OPTION => 4321,
        ]);

        $report = new HealthReport();

        $activity = $report->recentActivity();

        self::assertSame('newer', $activity[0]['message']);
        self::assertSame('older', $activity[1]['message']);
        self::assertSame(4321, $report->lastUpdateId());
    }
}
