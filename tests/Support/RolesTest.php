<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\Language;
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

    /**
     * The organize role is reserved: named by the plugin,
     * known to exist, and never in the editable list.
     */
    public function testTheOrganizeRoleIsReservedAndNamed(): void
    {
        Functions\when('get_option')->justReturn(null);

        self::assertSame('Organize', Roles::label(Roles::ORGANIZE_SLUG));
        self::assertSame('⭐ Organize', Roles::display(Roles::ORGANIZE_SLUG));
        self::assertTrue(Roles::exists(Roles::ORGANIZE_SLUG));
        self::assertNotContains(Roles::ORGANIZE_SLUG, array_column(Roles::all(), 'slug'));
    }

    public function testAHandTypedRoleCannotTakeTheOrganizeSlug(): void
    {
        $saved = $this->captureSavedRoles([
            ['slug' => 'decorate', 'label' => 'Decorate', 'emoji' => '🎈', 'capacity' => 2],
            ['slug' => 'organize', 'label' => 'Organize', 'emoji' => '⭐', 'capacity' => 5],
        ]);

        self::assertSame(['decorate'], array_column($saved, 'slug'));
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

    public function testKeepsACapacityOfZeroButNeverGoesNegative(): void
    {
        // Zero is a real choice - a role that stays defined but generates no
        // active slots (its tasks drop off the boards). Negative is clamped.
        $saved = $this->captureSavedRoles([
            ['slug' => 'welcome', 'label' => 'Welcome', 'emoji' => '', 'capacity' => 0],
            ['slug' => 'bar', 'label' => 'Bar', 'emoji' => '', 'capacity' => -3],
        ]);

        self::assertSame(0, $saved[0]['capacity']);
        self::assertSame(0, $saved[1]['capacity']);
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
        // The guest ticks (Owner 2026-11-07): shown, open to guests, with names.
        self::assertTrue($role['on_board']);
        self::assertTrue($role['guest_signup']);
        self::assertFalse($role['anonymous']);
        self::assertFalse($role['members_display']);
        self::assertFalse(Roles::membersDisplay('decorate'));
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

    public function testEachShippedRoleDescribesTheJob(): void
    {
        Functions\when('get_option')->justReturn(null);

        self::assertStringContainsString('LED candles', Roles::description('decorate'));
        self::assertStringContainsString('tickets', Roles::description('welcome'));
        self::assertStringContainsString('final check', Roles::description('clean'));
        self::assertSame('', Roles::description('nonexistent'));
    }

    /**
     * Roles saved before descriptions existed pick up the shipped text for
     * their slug, while one the organizer deliberately cleared stays blank.
     */
    public function testAStoredRoleWithoutADescriptionGainsTheShippedOneButAClearedOneStaysBlank(): void
    {
        Functions\when('get_option')->justReturn([
            ['slug' => 'decorate', 'label' => 'Decorate', 'emoji' => '🎈', 'capacity' => 2],
            ['slug' => 'welcome', 'label' => 'Welcome', 'emoji' => '👋', 'capacity' => 1, 'description' => ''],
            ['slug' => 'bar', 'label' => 'Bar', 'emoji' => '🍹', 'capacity' => 1],
        ]);

        self::assertStringContainsString('LED candles', Roles::description('decorate'));
        self::assertSame('', Roles::description('welcome'));
        self::assertSame('', Roles::description('bar'));
    }

    public function testADescriptionSurvivesASave(): void
    {
        $saved = $this->captureSavedRoles([
            ['slug' => 'bar', 'label' => 'Bar', 'emoji' => '🍹', 'capacity' => 2, 'description' => '  Pour drinks.  '],
        ]);

        self::assertSame('Pour drinks.', $saved[0]['description']);
    }

    /**
     * Title text survives a save trimmed; a role without it fits every
     * event, one with it only titles that contain it in any case.
     */
    public function testTitleTextIsTrimmedAndMatchedIgnoringCase(): void
    {
        $saved = $this->captureSavedRoles([
            ['slug' => 'bar', 'label' => 'Bar'],
            ['slug' => 'cacao', 'label' => 'Cacao', 'title_contains' => '  Cacao '],
        ]);

        self::assertSame('', $saved[0]['title_contains']);
        self::assertSame('Cacao', $saved[1]['title_contains']);
        self::assertTrue(Roles::fitsEvent($saved[0], 'Anything'));
        self::assertTrue(Roles::fitsEvent($saved[1], 'Ecstatic Dance with CACAO ceremony'));
        self::assertFalse(Roles::fitsEvent($saved[1], 'Ecstatic Dance'));
    }

    public function testPlusOnesAreKeptBetweenNoneAndTwenty(): void
    {
        $saved = $this->captureSavedRoles([
            ['slug' => 'bar', 'label' => 'Bar'],
            ['slug' => 'dj', 'label' => 'DJ', 'plus_ones' => 1],
            ['slug' => 'big', 'label' => 'Big', 'plus_ones' => 99],
            ['slug' => 'neg', 'label' => 'Neg', 'plus_ones' => -3],
        ]);

        self::assertSame([0, 1, 20, 0], array_column($saved, 'plus_ones'));
    }

    public function testTheOrganizeTaskAllowsOneGuestUntilItsSettingIsSaved(): void
    {
        Functions\when('get_option')->justReturn('');
        self::assertSame(1, Roles::defaultPlusOnes(Roles::ORGANIZE_SLUG));

        Functions\when('get_option')->justReturn('0');
        self::assertSame(0, Roles::defaultPlusOnes(Roles::ORGANIZE_SLUG));
    }

    public function testTheGuideLinkIsTrimmedAndBlankByDefault(): void
    {
        Functions\when('get_option')->justReturn('');
        self::assertSame('', Roles::guideUrl());

        Functions\when('get_option')->justReturn('  https://docs.example/guide ');
        self::assertSame('https://docs.example/guide', Roles::guideUrl());
    }

    /**
     * A role saved before the sales desk flag existed opens the desk only if
     * it is the shipped door role, Welcome; a saved flag wins either way.
     */
    public function testTheSalesDeskFlagDefaultsToWelcomeOnlyAndASavedFlagWins(): void
    {
        Functions\when('get_option')->justReturn([
            ['slug' => 'decorate', 'label' => 'Decorate', 'emoji' => '🎈', 'capacity' => 2],
            ['slug' => 'welcome', 'label' => 'Welcome', 'emoji' => '👋', 'capacity' => 1],
            ['slug' => 'leader', 'label' => 'Crew leader', 'emoji' => '🧭', 'capacity' => 1, 'sales_desk' => true],
            ['slug' => 'clean', 'label' => 'Clean', 'emoji' => '🧹', 'capacity' => 3, 'sales_desk' => false],
        ]);

        self::assertFalse(Roles::opensSalesDesk('decorate'));
        self::assertTrue(Roles::opensSalesDesk('welcome'));
        self::assertTrue(Roles::opensSalesDesk('leader'));
        self::assertFalse(Roles::opensSalesDesk('clean'));
        self::assertFalse(Roles::opensSalesDesk('nonexistent'));
    }

    public function testConditionsAreNormalizedAndSurviveASave(): void
    {
        $saved = $this->captureSavedRoles([
            ['slug' => 'welcome', 'label' => 'Welcome', 'emoji' => '', 'capacity' => 1],
            [
                'slug' => 'leader',
                'label' => 'Crew leader',
                'emoji' => '🧭',
                'capacity' => 1,
                'restricted' => true,
                'conditions' => [
                    'counts' => ['welcome' => '2', 'clean' => 0, 'leader' => 4],
                    'min_roles' => '3',
                    'times_each' => '2',
                ],
            ],
            [
                'slug' => 'dj',
                'label' => 'DJ',
                'emoji' => '',
                'capacity' => 1,
                'conditions' => ['min_roles' => 3, 'times_each' => 0],
            ],
        ]);

        // No conditions at all; a role cannot ask for its own tasks; zero
        // counts are dropped; and one of n / x at zero clears both.
        self::assertSame(['counts' => [], 'min_roles' => 0, 'times_each' => 0], $saved[0]['conditions']);
        self::assertSame(['counts' => ['welcome' => 2], 'min_roles' => 3, 'times_each' => 2], $saved[1]['conditions']);
        self::assertSame(['counts' => [], 'min_roles' => 0, 'times_each' => 0], $saved[2]['conditions']);
        self::assertTrue(Roles::hasConditions($saved[1]['conditions']));
        self::assertFalse(Roles::hasConditions($saved[0]['conditions']));
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

    /**
     * A role stored before names were translated keeps its plain name as
     * the source, and every reader gets it until the next save translates it.
     */
    public function testAPlainNameIsTheSource(): void
    {
        Functions\when('get_option')->justReturn([
            ['slug' => 'decorate', 'label' => 'Koristelu', 'emoji' => '🎈', 'description' => 'Ripusta valot.'],
        ]);

        $role = Roles::find('decorate');

        self::assertSame('Koristelu', $role['label']);
        self::assertSame('Koristelu', $role['label_text']['source']);
        self::assertSame('', $role['label_text']['lang']);
        self::assertSame('Ripusta valot.', $role['description']);
        self::assertSame('Koristelu', Language::run('en', static fn (): string => Roles::label('decorate')));
    }

    /**
     * A translated name is read in the reader's language, else the main
     * language; the option keeps the source as the plain name.
     */
    public function testATranslatedNameIsReadInTheReadersLanguage(): void
    {
        $text = [
            'source' => 'Decorate',
            'lang' => 'en',
            'detected' => true,
            'texts' => ['fi' => 'Koristelu', 'en' => 'Decorate'],
            'manual' => ['fi' => false, 'en' => false],
        ];
        $stored = [['slug' => 'decorate', 'label' => 'Decorate', 'label_text' => $text, 'emoji' => '🎈']];
        Functions\when('get_option')->justReturn($stored);

        self::assertSame('Koristelu', Roles::label('decorate'));
        self::assertSame('Decorate', Language::run('en', static fn (): string => Roles::label('decorate')));
        self::assertSame('Koristelu', Language::run('fi', static fn (): string => Roles::label('decorate')));

        $saved = $this->captureSavedRoles(Roles::all());

        self::assertSame('Decorate', $saved[0]['label']);
        self::assertSame($text, $saved[0]['label_text']);
    }
}
