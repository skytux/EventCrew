<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\Assignment;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\MarkedBy;
use EventCrew\Tests\TestCase;

/**
 * The "marked by <name> at <time>" line on the roster views. The clock reads
 * 2026-07-20 12:00, the site zone is UTC.
 */
final class MarkedByTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // wp_date is left undefined on purpose: stubbing it would define it for
        // every later test, and Dates::dayLabel() checks function_exists().
        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('UTC'));
        Functions\when('get_userdata')->alias(
            static fn (int $id): object|false => 3 === $id ? (object) ['display_name' => 'Lou'] : false
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function line(array $row): string
    {
        $assignment = Assignment::fromRow($row + ['id' => 11, 'task_id' => 5, 'person_id' => 8]);

        return MarkedBy::line($assignment, new PersonRepository());
    }

    public function testAPersonMarkTodayShowsTheirNameAndTheTime(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam'];

        self::assertSame('marked by Sam at 21:42', $this->line([
            'status' => 'arrived',
            'status_changed_at' => '2026-07-20 21:42:10',
            'changed_by' => 7,
            'changed_by_kind' => MarkedBy::PERSON,
        ]));
    }

    public function testAWordPressUserMarkShowsTheUsersName(): void
    {
        self::assertSame('marked by Lou at 09:05', $this->line([
            'status' => 'no_show',
            'status_changed_at' => '2026-07-20 09:05:00',
            'changed_by' => 3,
            'changed_by_kind' => MarkedBy::WP_USER,
        ]));
    }

    public function testAMarkOnAnEarlierDayShowsTheDate(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam'];

        self::assertSame('marked by Sam at Sun 19 Jul 23:10', $this->line([
            'status' => 'completed',
            'status_changed_at' => '2026-07-19 23:10:00',
            'changed_by' => 7,
            'changed_by_kind' => MarkedBy::PERSON,
        ]));
    }

    public function testAnOldRowWithNoKindShowsOnlyTheTime(): void
    {
        // A person row is queued: if the id were read as a person id, the line
        // would wrongly name Sam.
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam'];

        self::assertSame('marked at 21:42', $this->line([
            'status' => 'arrived',
            'status_changed_at' => '2026-07-20 21:42:00',
            'changed_by' => 7,
        ]));
    }

    public function testAMarkWithNobodyRecordedShowsOnlyTheTime(): void
    {
        self::assertSame('marked at 21:42', $this->line([
            'status' => 'completed',
            'status_changed_at' => '2026-07-20 21:42:00',
        ]));
    }

    public function testADeletedMarkerShowsOnlyTheTime(): void
    {
        // people->find(7) -> nothing queued: the person is gone.
        self::assertSame('marked at 21:42', $this->line([
            'status' => 'arrived',
            'status_changed_at' => '2026-07-20 21:42:00',
            'changed_by' => 7,
            'changed_by_kind' => MarkedBy::PERSON,
        ]));
    }

    public function testAStatusThatIsNotAnAttendanceMarkHasNoLine(): void
    {
        self::assertSame('', $this->line([
            'status' => 'signed_up',
            'status_changed_at' => '2026-07-20 21:42:00',
        ]));
        self::assertSame('', $this->line([
            'status' => 'cancelled',
            'status_changed_at' => '2026-07-20 21:42:00',
            'changed_by' => 7,
            'changed_by_kind' => MarkedBy::PERSON,
        ]));
        self::assertSame('', $this->line(['status' => 'arrived']));
    }

    public function testSignedUpAgainAfterAMarkSaysWhoSignedThemOut(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam'];

        self::assertSame('signed out by Sam at 21:42', $this->line([
            'status' => 'signed_up',
            'status_changed_at' => '2026-07-20 21:42:00',
            'changed_by' => 7,
            'changed_by_kind' => MarkedBy::PERSON,
        ]));
    }
}
