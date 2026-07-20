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
     * @param array<int, array<string, mixed>> $input
     * @return array<int, array{slug: string, label: string, emoji: string, capacity: int}>
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
