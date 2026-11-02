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
    }

    /**
     * Saves an edit of person 8, an organizer before the save, and returns the
     * actions fired.
     *
     * @return array<int, array{0: string, 1: array<int, mixed>}>
     */
    private function editOrganizer(bool $stillOrganizer): array
    {
        $row = ['id' => 8, 'email' => 'anna@example.com', 'display_name' => 'Anna', 'is_organizer' => 1];
        $this->wpdb->nextRows[] = $row; // findByEmail
        $this->wpdb->nextRows[] = $row; // find, before the save
        $this->wpdb->nextRows[] = $row; // find, for the roles

        $_POST = ['person_id' => '8', 'email' => 'anna@example.com', 'display_name' => 'Anna'];

        if ($stillOrganizer) {
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

    public function testUntickingOrganizerAsksForANewCommandMenu(): void
    {
        $fired = $this->editOrganizer(false);

        self::assertContains([\EventCrew\Telegram\CommandMenu::ORGANIZER_CHANGED_HOOK, [8]], $fired);
    }

    public function testSavingWithoutChangingOrganizerLeavesTheCommandMenu(): void
    {
        $fired = $this->editOrganizer(true);

        self::assertNotContains(\EventCrew\Telegram\CommandMenu::ORGANIZER_CHANGED_HOOK, array_column($fired, 0));
    }
}
