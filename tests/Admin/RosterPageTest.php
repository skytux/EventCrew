<?php

declare(strict_types=1);

namespace EventCrew\Tests\Admin;

use Brain\Monkey\Functions;
use EventCrew\Admin\RosterPage;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Support\MarkedBy;
use EventCrew\Support\TicketMail;
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
        $_POST = ['status' => ['11' => 'no_show'], 'shown' => ['11' => 'signed_up'], 'roster_date' => '2026-07-20'];
        $this->wpdb->nextRows[] = ['id' => 11, 'task_id' => 5, 'person_id' => 8, 'status' => 'signed_up'];

        $page = $this->page();
        $this->submit(static fn () => $page->saveRoster());

        $update = $this->wpdb->updates[0];
        self::assertSame('no_show', $update['data']['status']);
        self::assertSame(3, $update['data']['changed_by']);
        self::assertSame(MarkedBy::WP_USER, $update['data']['changed_by_kind']);
    }

    /** @var array{type?: string, message?: string} */
    private array $notice = [];

    /** Captures the notice the save leaves for the next page load. */
    private function captureNotice(): void
    {
        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->notice = $value;

            return true;
        });
    }

    public function testUpdateAttendanceDoesNotWriteARowTheOrganizerLeftAlone(): void
    {
        // Checked in at the door after the page was opened; the page still
        // shows "Signed up" and the organizer did not touch it.
        $_POST = ['status' => ['11' => 'signed_up'], 'shown' => ['11' => 'signed_up'], 'roster_date' => '2026-07-20'];
        $this->wpdb->nextRows[] = ['id' => 11, 'task_id' => 5, 'person_id' => 8, 'status' => 'arrived'];
        $this->captureNotice();

        $page = $this->page();
        $this->submit(static fn () => $page->saveRoster());

        self::assertSame([], $this->wpdb->updates);
        self::assertSame('No changes to save.', $this->notice['message']);
    }

    public function testUpdateAttendanceSkipsARowThatChangedAfterThePageWasOpened(): void
    {
        $_POST = [
            'status' => ['11' => 'arrived', '12' => 'arrived'],
            'shown' => ['11' => 'signed_up', '12' => 'signed_up'],
            'roster_date' => '2026-07-20',
        ];
        // 11 cancelled their own place meanwhile; 12 is still as shown.
        $this->wpdb->nextRows[] = ['id' => 11, 'task_id' => 5, 'person_id' => 8, 'status' => 'cancelled'];
        $this->wpdb->nextRows[] = ['id' => 12, 'task_id' => 5, 'person_id' => 9, 'status' => 'signed_up'];
        $this->captureNotice();

        $page = $this->page();
        $this->submit(static fn () => $page->saveRoster());

        self::assertSame([['id' => 12]], array_column($this->wpdb->updates, 'where'));
        self::assertSame('arrived', $this->wpdb->updates[0]['data']['status']);
        self::assertSame(
            '1 person updated. 1 mark was not saved because it changed after you opened the page; reload to see it.',
            $this->notice['message']
        );
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

    // --- "Cancel sending" ----------------------------------------------------

    /** @var array<string, mixed> */
    private array $options = [];

    /** A ticket send waiting in an in-memory option store. */
    private function aSendIsWaiting(): void
    {
        $this->options = [TicketMail::PENDING_OPTION => ['at' => time() + 300, 'rest' => []]];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('delete_option')->alias(function (string $name): bool {
            unset($this->options[$name]);

            return true;
        });
        Functions\when('wp_clear_scheduled_hook')->justReturn(1);
        Functions\when('sanitize_text_field')->returnArg(1);
        $_POST = ['roster_date' => '2026-07-20'];
    }

    public function testCancelSendingStopsTheWaitingSend(): void
    {
        $this->aSendIsWaiting();

        $page = $this->page();
        $this->submit(static fn () => $page->cancelTicketSend());

        self::assertNull(TicketMail::pending());
    }

    public function testCancelSendingWithoutTheCapabilityIsRefused(): void
    {
        $this->aSendIsWaiting();
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('wp_die')->alias(static function (): void {
            throw new \RuntimeException('died');
        });

        $page = $this->page();

        try {
            $page->cancelTicketSend();
            self::fail('Expected wp_die.');
        } catch (\RuntimeException $e) {
            self::assertSame('died', $e->getMessage());
        }

        self::assertNotNull(TicketMail::pending());
    }

    public function testCancelSendingWithABadNonceIsRefused(): void
    {
        $this->aSendIsWaiting();
        // WordPress dies inside check_admin_referer on a bad nonce.
        Functions\when('check_admin_referer')->alias(static function (string $action): void {
            throw new \RuntimeException('bad nonce for ' . $action);
        });

        $page = $this->page();

        try {
            $page->cancelTicketSend();
            self::fail('Expected the nonce check to stop it.');
        } catch (\RuntimeException $e) {
            self::assertSame('bad nonce for eventcrew_attendance', $e->getMessage());
        }

        self::assertNotNull(TicketMail::pending());
    }

    // --- A guest's email address ---------------------------------------------

    /**
     * Saving a guest row with a new address in wp-admin makes a new ticket:
     * the rev goes up and the 10-minute send is started.
     */
    public function testANewGuestEmailMakesANewTicket(): void
    {
        $this->aSendIsWaiting();
        $this->options = [];
        $scheduled = [];
        Functions\when('update_option')->alias(static function (string $name) use (&$scheduled): bool {
            $scheduled[] = $name;

            return true;
        });
        Functions\when('is_email')->alias(static fn (string $email): bool => str_contains($email, '@'));
        $_POST = [
            'guest_id' => '5',
            'roster_date' => '2026-07-20',
            'guest_name' => 'Anna',
            'guest_email' => 'new@example.com',
        ];
        $this->wpdb->nextRows[] = ['id' => 5, 'event_date' => '2026-07-20', 'name' => 'Anna', 'email' => 'old@example.com'];

        $page = $this->page();
        (function (): void {
            $this->guests = new \EventCrew\Repositories\GuestRepository();
            $this->ticketMail = null;
        })->call($page);
        $this->submit(static fn () => $page->saveGuest());

        $changes = array_values(array_filter(
            $this->wpdb->queries,
            static fn (string $sql): bool => str_contains($sql, 'ticket_rev = ticket_rev + 1')
        ));
        self::assertCount(1, $changes);
        self::assertStringContainsString("NULLIF('new@example.com', '')", $changes[0]);
        self::assertContains(TicketMail::PENDING_OPTION, $scheduled);
    }
}
