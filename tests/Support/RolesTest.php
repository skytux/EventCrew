<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\Roles;
use EventCrew\Tests\TestCase;

final class RolesTest extends TestCase
{
    public function testShipsWithTheThreeDanceEventGroups(): void
    {
        Functions\when('get_option')->justReturn(null);

        $slugs = array_column(Roles::all(), 'slug');

        self::assertSame(['decorate', 'welcome', 'clean'], $slugs);
    }

    public function testDecorateDefaultsToTwoPeople(): void
    {
        Functions\when('get_option')->justReturn(null);

        self::assertSame(2, Roles::defaultCapacity('decorate'));
    }

    /**
     * A stored option that has been emptied or corrupted must not leave the
     * plugin with no roles at all, since a task cannot then be created.
     */
    public function testFallsBackToDefaultsWhenTheStoredOptionIsUnusable(): void
    {
        Functions\when('get_option')->justReturn([]);
        self::assertCount(3, Roles::all());

        Functions\when('get_option')->justReturn('not an array');
        self::assertCount(3, Roles::all());
    }

    public function testDropsRowsTheOrganizerLeftCompletelyBlank(): void
    {
        $saved = $this->captureSavedRoles([
            ['slug' => 'decorate', 'label' => 'Decorate', 'emoji' => '🎈', 'capacity' => 2],
            ['slug' => '', 'label' => '', 'emoji' => '', 'capacity' => 1],
        ]);

        self::assertCount(1, $saved);
        self::assertSame('decorate', $saved[0]['slug']);
    }

    public function testDerivesASlugFromTheLabelWhenOnlyALabelWasTyped(): void
    {
        $saved = $this->captureSavedRoles([
            ['slug' => '', 'label' => 'Bar', 'emoji' => '🍹', 'capacity' => 2],
        ]);

        self::assertSame('bar', $saved[0]['slug']);
        self::assertSame('Bar', $saved[0]['label']);
    }

    public function testKeepsOnlyTheFirstOfTwoRowsSharingASlug(): void
    {
        $saved = $this->captureSavedRoles([
            ['slug' => 'clean', 'label' => 'Clean', 'emoji' => '🧹', 'capacity' => 3],
            ['slug' => 'clean', 'label' => 'Cleaning', 'emoji' => '🧽', 'capacity' => 9],
        ]);

        self::assertCount(1, $saved);
        self::assertSame('Clean', $saved[0]['label']);
    }

    public function testNeverStoresACapacityBelowOne(): void
    {
        $saved = $this->captureSavedRoles([
            ['slug' => 'welcome', 'label' => 'Welcome', 'emoji' => '', 'capacity' => 0],
        ]);

        self::assertSame(1, $saved[0]['capacity']);
    }

    /**
     * Wiping every group would leave the organizer unable to create a task,
     * with no obvious way back, so an empty save restores the defaults.
     */
    public function testRestoresDefaultsWhenEveryRowWasCleared(): void
    {
        $saved = $this->captureSavedRoles([
            ['slug' => '', 'label' => '', 'emoji' => '', 'capacity' => 1],
        ]);

        self::assertCount(3, $saved);
    }

    public function testDisplayCombinesEmojiAndLabel(): void
    {
        Functions\when('get_option')->justReturn(null);

        self::assertSame('🎈 Decorate', Roles::display('decorate'));
    }

    public function testDisplayFallsBackToTheSlugForAnUnknownGroup(): void
    {
        Functions\when('get_option')->justReturn(null);

        self::assertSame('karaoke', Roles::display('karaoke'));
        self::assertFalse(Roles::exists('karaoke'));
    }

    /**
     * Archiving rather than deleting is what keeps a task created months ago
     * able to say what it was: the slug on that row still resolves to a
     * label, and the credit earned under it keeps its meaning.
     */
    public function testAnArchivedRoleStillResolvesItsLabelAndEmoji(): void
    {
        Functions\when('get_option')->justReturn([
            ['slug' => 'karaoke', 'label' => 'Karaoke', 'emoji' => '🎤', 'capacity' => 1, 'archived' => true],
        ]);

        self::assertSame('🎤 Karaoke', Roles::display('karaoke'));
        self::assertTrue(Roles::exists('karaoke'));
        self::assertTrue(Roles::isArchived('karaoke'));
    }

    public function testActiveOmitsArchivedRolesWhileAllKeepsThem(): void
    {
        Functions\when('get_option')->justReturn([
            ['slug' => 'decorate', 'label' => 'Decorate', 'emoji' => '🎈', 'capacity' => 2],
            ['slug' => 'karaoke', 'label' => 'Karaoke', 'emoji' => '🎤', 'capacity' => 1, 'archived' => true],
        ]);

        self::assertSame(['decorate'], array_column(Roles::active(), 'slug'));
        self::assertSame(['decorate', 'karaoke'], array_column(Roles::all(), 'slug'));
        self::assertSame(['karaoke'], array_column(Roles::archived(), 'slug'));
    }

    /**
     * Roles stored before archiving and offsets existed must read back as
     * ordinary active roles rather than tripping every consumer on a missing
     * key.
     */
    public function testRolesStoredBeforeTheseFieldsExistedGainSaneDefaults(): void
    {
        Functions\when('get_option')->justReturn([
            ['slug' => 'decorate', 'label' => 'Decorate', 'emoji' => '🎈', 'capacity' => 2],
        ]);

        $role = Roles::all()[0];

        self::assertFalse($role['archived']);
        self::assertSame(Roles::ANCHOR_START, $role['anchor']);
        self::assertNull($role['start_offset']);
        self::assertNull($role['end_offset']);
    }

    /**
     * Zero is a real offset - "ends exactly when the doors open" - so it must
     * survive a save that a naive empty check would flatten to null.
     */
    public function testAZeroOffsetSurvivesASaveWhileABlankOneStaysNull(): void
    {
        $saved = $this->captureSavedRoles([
            [
                'slug' => 'decorate',
                'label' => 'Decorate',
                'emoji' => '🎈',
                'capacity' => 2,
                'anchor' => Roles::ANCHOR_START,
                'start_offset' => -120,
                'end_offset' => 0,
            ],
            [
                'slug' => 'bar',
                'label' => 'Bar',
                'emoji' => '🍹',
                'capacity' => 1,
                'start_offset' => null,
                'end_offset' => null,
            ],
        ]);

        self::assertSame(-120, $saved[0]['start_offset']);
        self::assertSame(0, $saved[0]['end_offset']);
        self::assertNull($saved[1]['start_offset']);
    }

    public function testAnUnknownAnchorFallsBackToTheEventStart(): void
    {
        $saved = $this->captureSavedRoles([
            ['slug' => 'clean', 'label' => 'Clean', 'emoji' => '', 'capacity' => 3, 'anchor' => 'nonsense'],
        ]);

        self::assertSame(Roles::ANCHOR_START, $saved[0]['anchor']);
    }

    public function testArchivingAndRestoringFlipTheFlagWithoutLosingTheRole(): void
    {
        $stored = [
            ['slug' => 'decorate', 'label' => 'Decorate', 'emoji' => '🎈', 'capacity' => 2],
        ];

        $captured = [];

        Functions\when('get_option')->alias(static fn (): array => $stored);
        Functions\when('update_option')->alias(
            static function (string $name, mixed $value) use (&$captured): bool {
                $captured = $value;

                return true;
            }
        );

        Roles::archive('decorate');

        self::assertCount(1, $captured);
        self::assertTrue($captured[0]['archived']);
        self::assertSame('Decorate', $captured[0]['label']);

        Roles::restore('decorate');

        self::assertFalse($captured[0]['archived']);
    }

    /**
     * @param array<int, array<string, mixed>> $input
     * @return array<int, array{slug: string, label: string, emoji: string, capacity: int, archived: bool, anchor: string, start_offset: int|null, end_offset: int|null}>
     */
    private function captureSavedRoles(array $input): array
    {
        $captured = [];

        Functions\when('get_option')->justReturn(null);
        Functions\when('update_option')->alias(
            static function (string $name, mixed $value) use (&$captured): bool {
                $captured = $value;

                return true;
            }
        );

        Roles::save($input);

        return $captured;
    }
}
