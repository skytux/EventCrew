<?php

declare(strict_types=1);

namespace EventCrew\Tests\Admin;

use Brain\Monkey\Functions;
use EventCrew\Admin\RosterPage;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Support\MarkedBy;
use EventCrew\Tests\TestCase;

/**
 * The wp-admin Roster page's two status writes record the WordPress user who
 * made them, marked as a WordPress user id rather than an EventCrew person id.
 */
final class RosterPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_admin_referer')->justReturn(1);
        Functions\when('get_current_user_id')->justReturn(3);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('admin_url')->alias(static fn (string $path = ''): string => 'https://site.test/wp-admin/' . $path);
        Functions\when('add_query_arg')->justReturn('https://site.test/wp-admin/admin.php');
        Functions\when('wp_safe_redirect')->alias(static function (): void {
            throw new \RuntimeException('redirected');
        });
    }

    protected function tearDown(): void
    {
        $_POST = [];

        parent::tearDown();
    }

    /**
     * The page with only the assignment repository set: the two writes under
     * test use nothing else.
     */
    private function page(): RosterPage
    {
        $page = (new \ReflectionClass(RosterPage::class))->newInstanceWithoutConstructor();
        (function (AssignmentRepository $assignments): void {
            $this->assignments = $assignments;
        })->call($page, new AssignmentRepository());

        return $page;
    }

    private function submit(callable $action): void
    {
        try {
            $action();
            self::fail('Expected the redirect.');
        } catch (\RuntimeException $e) {
            self::assertSame('redirected', $e->getMessage());
        }
    }

    public function testUpdateAttendanceRecordsTheWordPressUser(): void
    {
        $_POST = ['status' => ['11' => 'no_show'], 'roster_date' => '2026-07-20'];
        $this->wpdb->nextRows[] = ['id' => 11, 'task_id' => 5, 'person_id' => 8, 'status' => 'signed_up'];

        $page = $this->page();
        $this->submit(static fn () => $page->saveRoster());

        $update = $this->wpdb->updates[0];
        self::assertSame('no_show', $update['data']['status']);
        self::assertSame(3, $update['data']['changed_by']);
        self::assertSame(MarkedBy::WP_USER, $update['data']['changed_by_kind']);
    }

    public function testMarkAllRecordsTheWordPressUser(): void
    {
        $_POST = ['task_id' => '5', 'status' => 'arrived', 'roster_date' => '2026-07-20'];
        $this->wpdb->nextResults[] = [['id' => 11, 'task_id' => 5, 'person_id' => 8, 'status' => 'signed_up']];

        $page = $this->page();
        $this->submit(static fn () => $page->markAllForTask());

        $update = $this->wpdb->updates[0];
        self::assertSame('arrived', $update['data']['status']);
        self::assertSame(3, $update['data']['changed_by']);
        self::assertSame(MarkedBy::WP_USER, $update['data']['changed_by_kind']);
    }
}
