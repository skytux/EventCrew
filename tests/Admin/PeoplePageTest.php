<?php

declare(strict_types=1);

namespace EventCrew\Tests\Admin;

use Brain\Monkey\Functions;
use EventCrew\Admin\PeoplePage;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\Roles;
use EventCrew\Tests\TestCase;

/**
 * The wp-admin People page's "Add person" form: a new person keeps every
 * ticked role as its own role.
 */
final class PeoplePageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_admin_referer')->justReturn(1);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('sanitize_email')->returnArg(1);
        Functions\when('sanitize_textarea_field')->returnArg(1);
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('wp_get_current_user')->justReturn((object) ['display_name' => 'Owner']);
        Functions\when('is_email')->alias(static fn (string $email): bool => false !== strpos($email, '@'));
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => Roles::OPTION_NAME === $name
                ? [
                    ['slug' => 'decorate', 'label' => 'Decorate'],
                    ['slug' => 'dj', 'label' => 'DJ', 'restricted' => true],
                    ['slug' => 'vj', 'label' => 'VJ', 'restricted' => true],
                ]
                : $default
        );
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
     * The page with only the person repository set: the save under test uses
     * nothing else.
     */
    private function page(): PeoplePage
    {
        $page = (new \ReflectionClass(PeoplePage::class))->newInstanceWithoutConstructor();
        (function (PersonRepository $people): void {
            $this->people = $people;
        })->call($page, new PersonRepository());

        return $page;
    }

    public function testAddingAPersonWithTwoRolesStoresBothRoles(): void
    {
        $_POST = [
            'person_id' => '0',
            'email' => 'anna@example.com',
            'display_name' => 'Anna',
            'roles' => ['dj', 'vj'],
        ];

        $page = $this->page();
        $fired = [];
        Functions\when('do_action')->alias(static function (string $hook, mixed ...$args) use (&$fired): void {
            $fired[] = [$hook, $args];
        });

        try {
            $page->save();
            self::fail('Expected the redirect.');
        } catch (\RuntimeException $e) {
            self::assertSame('redirected', $e->getMessage());
        }

        $people = array_values(array_filter(
            $this->wpdb->inserts,
            static fn (array $insert): bool => str_ends_with($insert['table'], 'eventcrew_people')
        ));
        self::assertCount(1, $people);
        self::assertSame('dj,vj', $people[0]['data']['role_slugs']);
        // Each new role is announced, and the person told (AccessNotifier listens).
        self::assertContains(\EventCrew\Support\RoleNotifier::GIVEN_HOOK, array_column($fired, 0));
    }

    public function testGrantingAPassTellsThePerson(): void
    {
        $_POST = ['person_id' => '8'];
        $this->wpdb->nextRows[] = ['id' => 8, 'email' => 'anna@example.com', 'display_name' => 'Anna'];
        $fired = [];
        Functions\when('do_action')->alias(static function (string $hook, mixed ...$args) use (&$fired): void {
            $fired[] = [$hook, $args];
        });

        try {
            $this->page()->grantPass();
            self::fail('Expected the redirect.');
        } catch (\RuntimeException $e) {
            self::assertSame('redirected', $e->getMessage());
        }

        self::assertSame(1, (int) $this->wpdb->updates[0]['data']['at_risk_pass']);
        self::assertContains([\EventCrew\Support\AccessNotifier::PASS_HOOK, [8]], $fired);
    }

    /**
     * Saves an edit of person 8, an organizer before the save unless
     * $wasOrganizer is false, with Organize ticked when $stillOrganizer, and
     * returns the actions fired.
     *
     * @return array<int, array{0: string, 1: array<int, mixed>}>
     */
    private function editOrganizer(bool $stillOrganizer, bool $wasOrganizer = true, string $otherOrganizers = '1'): array
    {
        $row = [
            'id' => 8,
            'email' => 'anna@example.com',
            'display_name' => 'Anna',
            'is_organizer' => (int) $wasOrganizer,
        ];
        $this->wpdb->nextRows[] = $row; // findByEmail
        $this->wpdb->nextRows[] = $row; // find, before the save
        $this->wpdb->nextRows[] = $row; // find, for the roles
        $this->wpdb->nextVars[] = $otherOrganizers; // for the lockout guard

        $_POST = ['person_id' => '8', 'email' => 'anna@example.com', 'display_name' => 'Anna'];

        // Organize is one of the role ticks (Owner 2026-11-14); the old
        // is_organizer field is gone and ignored.
        if ($stillOrganizer) {
            $_POST['roles'] = [Roles::ORGANIZE_SLUG];
        } else {
            $_POST['is_organizer'] = '1';
        }

        $fired = [];
        Functions\when('do_action')->alias(static function (string $hook, mixed ...$args) use (&$fired): void {
            $fired[] = [$hook, $args];
        });

        try {
            $this->page()->save();
            self::fail('Expected the redirect.');
        } catch (\RuntimeException $e) {
            self::assertSame('redirected', $e->getMessage());
        }

        return $fired;
    }

    public function testUntickingOrganizeAsksForANewCommandMenu(): void
    {
        $fired = $this->editOrganizer(false);

        self::assertContains([\EventCrew\Telegram\CommandMenu::ORGANIZER_CHANGED_HOOK, [8]], $fired);
        self::assertSame(0, $this->wpdb->updates[0]['data']['is_organizer']);
    }

    /**
     * Unticking the last organizer would leave the site without one: nothing
     * is saved (Owner 2026-11-08, Q372).
     */
    public function testTheLastOrganizerCannotBeUnticked(): void
    {
        $fired = $this->editOrganizer(false, true, '0');

        self::assertSame([], $this->wpdb->updates);
        self::assertNotContains(\EventCrew\Telegram\CommandMenu::ORGANIZER_CHANGED_HOOK, array_column($fired, 0));
    }

    public function testSavingWithoutChangingOrganizerLeavesTheCommandMenu(): void
    {
        $fired = $this->editOrganizer(true);

        self::assertNotContains(\EventCrew\Telegram\CommandMenu::ORGANIZER_CHANGED_HOOK, array_column($fired, 0));
        self::assertNotContains(\EventCrew\Support\RoleNotifier::ORGANIZER_HOOK, array_column($fired, 0));
    }

    public function testTickingOrganizerAnnouncesItWithTheWpAdminUsersName(): void
    {
        $fired = $this->editOrganizer(true, false);

        self::assertContains([\EventCrew\Support\RoleNotifier::ORGANIZER_HOOK, [8, 'Owner']], $fired);
    }

    /**
     * The page with the person repository and the newcomer approval set.
     */
    private function pageWithNewcomers(): PeoplePage
    {
        $page = $this->page();
        $approval = new \EventCrew\Support\NewcomerApproval(
            new PersonRepository(),
            new \EventCrew\Support\Mailer(new \EventCrew\Support\Logger(), new \EventCrew\Support\EmailTemplate(new \EventCrew\Support\Logger())),
            new \EventCrew\Telegram\TelegramClient(new \EventCrew\Support\Logger(), new \EventCrew\Telegram\DohResolver(new \EventCrew\Support\Logger()))
        );
        (function (\EventCrew\Support\NewcomerApproval $newcomers): void {
            $this->newcomers = $newcomers;
        })->call($page, $approval);

        return $page;
    }

    /** Brief AB point 6: the Turned down list shows when, who, by whom and the reason. */
    public function testTheTurnedDownListShowsWhenWhoAndTheReason(): void
    {
        Functions\when('current_time')->justReturn('2026-07-20 12:00:00');
        $this->wpdb->nextResults[] = [
            [
                'id' => 8, 'display_name' => 'Sam', 'email' => 'sam@example.com', 'approval' => 'no', 'approval_by' => 'Kim',
                'role_notices' => '{"newcomer:decided":"2026-07-19 18:30:00","newcomer:reason":"unknown"}',
            ],
            [
                'id' => 9, 'display_name' => 'Jo', 'email' => 'jo@example.com', 'approval' => 'no', 'approval_by' => '',
                'role_notices' => '{"newcomer:decided":"2026-07-18 09:00:00","newcomer:reason":"timeout"}',
            ],
            [
                'id' => 10, 'display_name' => 'Ari', 'email' => 'ari@example.com', 'approval' => 'no', 'approval_by' => 'Kim',
                'role_notices' => '{"newcomer:decided":"2026-07-17 09:00:00","newcomer:reason":"none"}',
            ],
        ];

        $rows = $this->pageWithNewcomers()->turnedDownRows();

        self::assertSame([8, 9, 10], array_column($rows, 'id'));
        self::assertSame(['Sam', 'Jo', 'Ari'], array_column($rows, 'name'));
        self::assertSame(['Kim', 'Nobody (timed out)', 'Kim'], array_column($rows, 'by'));
        self::assertSame(
            [
                'Unknown to the organizers. They were told.',
                'No organizer answered in 48 hours. They were told.',
                'No reason. They were not told.',
            ],
            array_column($rows, 'reason')
        );
        self::assertNotSame('', $rows[0]['when']);
        self::assertStringContainsString("approval = 'no'", (string) end($this->wpdb->queries));
    }

    /** Accept on someone no longer turned down changes nothing and says so. */
    public function testAcceptOnSomeoneNoLongerTurnedDownSaysSo(): void
    {
        $_POST = ['person_id' => '8'];
        $notice = null;
        Functions\when('set_transient')->alias(static function (string $key, mixed $value) use (&$notice): bool {
            $notice = $value;

            return true;
        });
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Sam', 'email' => 'sam@example.com', 'approval' => 'ok'];
        $this->wpdb->nextQueryResults[] = 0;

        try {
            $this->pageWithNewcomers()->acceptNewcomer();
            self::fail('Expected the redirect.');
        } catch (\RuntimeException $e) {
            self::assertSame('redirected', $e->getMessage());
        }

        self::assertSame('error', $notice['type']);
        self::assertSame('That person could not be accepted. Someone may have done it already.', $notice['message']);
    }

    /** Accept on a turned-down person gives the OK, recorded as the wp-admin user. */
    public function testAcceptGivesTheOkAsTheAdminUser(): void
    {
        $_POST = ['person_id' => '8'];
        $notice = null;
        Functions\when('set_transient')->alias(static function (string $key, mixed $value) use (&$notice): bool {
            $notice = $value;

            return true;
        });
        Functions\when('current_time')->justReturn('2026-07-20 12:00:00');
        Functions\when('wp_mail')->justReturn(true);
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Sam', 'email' => 'sam@example.com', 'approval' => 'no'];
        $this->wpdb->nextQueryResults[] = 1;

        try {
            $this->pageWithNewcomers()->acceptNewcomer();
            self::fail('Expected the redirect.');
        } catch (\RuntimeException $e) {
            self::assertSame('redirected', $e->getMessage());
        }

        $update = array_values(array_filter(
            array_map('strval', $this->wpdb->queries),
            static fn (string $q): bool => str_contains($q, 'SET approval')
        ));
        self::assertStringContainsString("approval_by = 'Owner'", $update[0]);
        self::assertSame('success', $notice['type']);
        self::assertSame('Accepted. They can take tasks now, and they are told.', $notice['message']);
    }
}
