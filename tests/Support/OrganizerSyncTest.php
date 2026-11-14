<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\OrganizerSync;
use EventCrew\Support\Roles;
use EventCrew\Telegram\CommandMenu;
use EventCrew\Tests\TestCase;

/**
 * Being an organizer follows the roles a person holds (Owner 2026-11-08,
 * Q371/Q372): a role with Admin access makes its holders organizers, and no
 * change may leave the site without one.
 */
final class OrganizerSyncTest extends TestCase
{
    /**
     * @param array<int, array<string, mixed>>|null $roles The stored role list, null for none.
     */
    private function rolesStored(?array $roles): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => Roles::OPTION_NAME === $name ? $roles : $default
        );
    }

    /** @return array<int, array<string, mixed>> */
    private static function withLeadAccess(): array
    {
        return [
            ['slug' => 'clean', 'label' => 'Clean'],
            ['slug' => 'lead', 'label' => 'Lead', 'admin_access' => true],
            ['slug' => 'organize', 'label' => 'Organize', 'admin_access' => true],
        ];
    }

    public function testOrganizeGivesAdminAccessByDefault(): void
    {
        $this->rolesStored(null);

        self::assertTrue(OrganizerSync::holdsAdmin(['clean', 'organize']));
        self::assertFalse(OrganizerSync::holdsAdmin(['clean']));
        self::assertSame(1, OrganizerSync::organizerFor(['organize']));
        self::assertSame(0, OrganizerSync::organizerFor([]));
    }

    public function testMakingSomeoneAnOrganizerGivesThemOrganize(): void
    {
        $this->rolesStored(null);

        self::assertSame(['clean', 'organize'], OrganizerSync::withAdmin(['clean']));
        // Already one: unchanged.
        self::assertSame(['organize'], OrganizerSync::withAdmin(['organize']));
    }

    public function testAnotherAdminAccessRoleIsEnoughAndIsTakenAwayWithOrganize(): void
    {
        $this->rolesStored(self::withLeadAccess());

        self::assertTrue(OrganizerSync::holdsAdmin(['lead']));
        self::assertSame(['lead'], OrganizerSync::withAdmin(['lead']));
        self::assertSame(['clean'], OrganizerSync::withoutAdmin(['clean', 'lead', 'organize']));
    }

    public function testWithoutAdminAccessOnOrganizeTheFirstAdminRoleIsGiven(): void
    {
        $this->rolesStored([
            ['slug' => 'clean', 'label' => 'Clean'],
            ['slug' => 'lead', 'label' => 'Lead', 'admin_access' => true],
            ['slug' => 'organize', 'label' => 'Organize', 'admin_access' => false],
        ]);

        self::assertSame(['clean', 'lead'], OrganizerSync::withAdmin(['clean']));
        // Organize goes too, though it gives nothing just now.
        self::assertSame(['clean'], OrganizerSync::withoutAdmin(['clean', 'organize']));
    }

    /**
     * The stored copy is rewritten where it differs from the roles: here an
     * organizer without an Admin access role, and an anonymised one.
     */
    public function testRefreshAllWritesTheCopyWhereItDiffers(): void
    {
        $this->rolesStored(null);
        $this->wpdb->nextResults[] = [
            ['id' => 1, 'role_slugs' => 'organize', 'is_organizer' => 1, 'anonymized_at' => null],
            ['id' => 2, 'role_slugs' => 'clean', 'is_organizer' => 1, 'anonymized_at' => null],
            ['id' => 3, 'role_slugs' => 'organize', 'is_organizer' => 1, 'anonymized_at' => '2026-10-01 10:00:00'],
            ['id' => 4, 'role_slugs' => 'clean', 'is_organizer' => 0, 'anonymized_at' => null],
        ];
        $fired = [];
        Functions\when('do_action')->alias(static function (string $hook, mixed ...$args) use (&$fired): void {
            $fired[] = [$hook, $args];
        });

        self::assertSame(2, OrganizerSync::refreshAll());

        self::assertSame([['id' => 2], ['id' => 3]], array_column($this->wpdb->updates, 'where'));
        self::assertSame(0, $this->wpdb->updates[0]['data']['is_organizer']);
        self::assertSame(0, $this->wpdb->updates[1]['data']['is_organizer']);
        self::assertContains([CommandMenu::ORGANIZER_CHANGED_HOOK, [2]], $fired);
        self::assertContains([CommandMenu::ORGANIZER_CHANGED_HOOK, [3]], $fired);
    }

    public function testTakingAdminAccessFromTheOnlyOrganizersRoleLeavesNone(): void
    {
        $this->rolesStored(self::withLeadAccess());
        $this->wpdb->nextResults[] = [
            ['id' => 1, 'role_slugs' => 'organize', 'is_organizer' => 1, 'anonymized_at' => null],
            ['id' => 2, 'role_slugs' => 'clean', 'is_organizer' => 0, 'anonymized_at' => null],
        ];

        self::assertTrue(OrganizerSync::wouldLeaveNone(['lead']));
    }

    public function testSomeoneHoldingAnotherAdminRoleKeepsTheSiteGoing(): void
    {
        $this->rolesStored(self::withLeadAccess());
        $this->wpdb->nextResults[] = [
            ['id' => 1, 'role_slugs' => 'organize', 'is_organizer' => 1, 'anonymized_at' => null],
            ['id' => 2, 'role_slugs' => 'lead', 'is_organizer' => 1, 'anonymized_at' => null],
        ];

        self::assertFalse(OrganizerSync::wouldLeaveNone(['lead']));
    }

    public function testNothingTakenAwayOrNoOrganizersIsNeverBlocked(): void
    {
        $this->rolesStored(self::withLeadAccess());

        // Nothing taken away: not even read.
        self::assertFalse(OrganizerSync::wouldLeaveNone(['lead', 'organize', 'clean']));
        self::assertSame([], $this->wpdb->queries);

        // A site with no organizers yet.
        $this->wpdb->nextResults[] = [
            ['id' => 2, 'role_slugs' => 'clean', 'is_organizer' => 0, 'anonymized_at' => null],
        ];
        self::assertFalse(OrganizerSync::wouldLeaveNone([]));
    }

    public function testTheLastOrganizerCannotLoseTheirAdminRole(): void
    {
        $this->rolesStored(null);

        // Not an organizer, or keeping Organize: nothing is read.
        self::assertFalse(OrganizerSync::personWouldLeaveNone(8, false, []));
        self::assertFalse(OrganizerSync::personWouldLeaveNone(8, true, ['organize']));
        self::assertSame([], $this->wpdb->queries);

        $this->wpdb->nextVars[] = '0';
        self::assertTrue(OrganizerSync::personWouldLeaveNone(8, true, ['clean']));
        self::assertStringContainsString('is_organizer = 1 AND id <> 8', $this->wpdb->queries[0]);

        $this->wpdb->nextVars[] = '2';
        self::assertFalse(OrganizerSync::personWouldLeaveNone(8, true, ['clean']));
    }
}
