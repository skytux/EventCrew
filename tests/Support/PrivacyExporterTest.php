<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\PrivacyExporter;
use EventCrew\Tests\TestCase;

/**
 * WordPress's Export Personal Data tool, for people who are not WordPress
 * users - which is all of them here, so core's own exporters see nothing.
 */
final class PrivacyExporterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_option')->justReturn(false);
        Functions\when('get_the_title')->justReturn('');
    }

    private function exporter(): PrivacyExporter
    {
        return new PrivacyExporter(
            new PersonRepository(),
            new AssignmentRepository(),
            new TaskRepository(),
            new RedemptionRepository(),
            new CreditGrantRepository()
        );
    }

    public function testAnUnknownAddressExportsNothingAndIsDone(): void
    {
        // findByEmail -> no row. Nothing to say, and nothing to resume.
        $this->wpdb->nextRows[] = null;

        $result = $this->exporter()->export('stranger@example.com');

        self::assertSame([], $result['data']);
        self::assertTrue($result['done']);
    }

    public function testAKnownPersonGetsTheirProfileBack(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam'];
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam'];

        $result = $this->exporter()->export('sam@example.com');

        self::assertTrue($result['done']);
        self::assertNotSame([], $result['data']);

        $values = array_column($result['data'][0]['data'], 'value', 'name');
        self::assertSame('sam@example.com', $values['Email']);
        self::assertSame('Sam', $values['Name']);
    }

    public function testItRegistersItselfWithWordPresssExportTool(): void
    {
        $exporters = $this->exporter()->register([]);

        self::assertArrayHasKey('eventcrew', $exporters);
        self::assertIsCallable($exporters['eventcrew']['callback']);
    }
}
