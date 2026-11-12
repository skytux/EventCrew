<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Support\AppInstallPrompt;
use EventCrew\Tests\TestCase;

/**
 * Who is due the Crew app's "Get the app" pop-in, and what its buttons store.
 */
final class AppInstallPromptTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const SESSION = 1_799_990_000;
    private const SITE_NOW = '2026-11-13 12:00:00';

    /** @param array<string, string> $record */
    private static function person(array $record = []): Person
    {
        return Person::fromRow([
            'id' => 7,
            'email' => 'sam@example.com',
            'role_notices' => [] === $record ? null : (string) json_encode($record),
        ]);
    }

    public function testShownToSomeoneWhoHasDoneNothingYet(): void
    {
        $state = AppInstallPrompt::state(self::person(), self::SESSION, self::NOW, self::SITE_NOW);

        self::assertSame(
            ['popin' => true, 'installed' => false, 'ios_due' => true, 'record_open' => true, 'offer_notify' => true],
            $state
        );
    }

    public function testNeverShownWithoutASession(): void
    {
        self::assertFalse(AppInstallPrompt::state(self::person(), null, self::NOW)['popin']);
    }

    /**
     * Owner 2026-11-13: an installed app can be removed again, so having
     * installed it once no longer hides the pop-in; the browser (Chrome's
     * install offer) or the iPhone's last opening decides.
     */
    public function testInstalledOnceNoLongerHidesIt(): void
    {
        $state = AppInstallPrompt::state(
            self::person([AppInstallPrompt::KEY_INSTALLED => '2026-11-12 10:00:00']),
            self::SESSION,
            self::NOW,
            self::SITE_NOW
        );

        self::assertTrue($state['popin']);
        self::assertTrue($state['installed']);
        self::assertTrue($state['ios_due'], 'Never opened from the Home Screen: Safari may offer it.');
    }

    public function testAnIphoneThatOpenedTheAppLatelyIsNotOfferedItInSafari(): void
    {
        $at = static fn (string $lastOpen): array => AppInstallPrompt::state(
            self::person([
                AppInstallPrompt::KEY_INSTALLED => '2026-01-01 10:00:00',
                AppInstallPrompt::KEY_LAST_OPEN => $lastOpen,
            ]),
            self::SESSION,
            self::NOW,
            self::SITE_NOW
        );

        self::assertFalse($at('2026-11-13 08:00:00')['ios_due'], 'Opened this morning.');
        self::assertFalse($at('2026-10-31 12:00:01')['ios_due'], 'Opened 13 days and a bit ago.');
        self::assertTrue($at('2026-10-30 12:00:00')['ios_due'], 'Not opened for 14 days.');
        self::assertTrue($at('2026-10-30 12:00:00')['popin']);
    }

    public function testTheIphoneWindowFollowsTheRemindDaysSetting(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed
                => AppInstallPrompt::REMIND_DAYS_OPTION === $name ? '3' : $default
        );
        $person = self::person([AppInstallPrompt::KEY_LAST_OPEN => '2026-11-10 11:00:00']);

        self::assertTrue(AppInstallPrompt::state($person, self::SESSION, self::NOW, self::SITE_NOW)['ios_due']);
    }

    public function testTheAppRecordsItsOpeningAtMostOnceADay(): void
    {
        $state = static fn (array $record): bool => AppInstallPrompt::state(
            self::person($record),
            self::SESSION,
            self::NOW,
            self::SITE_NOW
        )['record_open'];
        $installed = [AppInstallPrompt::KEY_INSTALLED => '2026-01-01 10:00:00'];

        self::assertTrue($state($installed), 'Never recorded.');
        self::assertFalse($state($installed + [AppInstallPrompt::KEY_LAST_OPEN => '2026-11-13 08:00:00']));
        self::assertTrue($state($installed + [AppInstallPrompt::KEY_LAST_OPEN => '2026-11-12 11:59:59']));
        self::assertTrue(
            $state([AppInstallPrompt::KEY_LAST_OPEN => '2026-11-13 08:00:00']),
            'Not marked installed yet.'
        );
        self::assertTrue(
            $state($installed + [
                AppInstallPrompt::KEY_LAST_OPEN => '2026-11-13 08:00:00',
                AppInstallPrompt::KEY_INSTALL_PENDING => '2026-11-13 09:00:00',
            ]),
            'A pending install is settled on the next opening.'
        );
    }

    public function testOpenedStoresTheTimeOnceADayAndMarksItInstalled(): void
    {
        $opened = static fn (array $record): ?array => AppInstallPrompt::entriesFor(
            'opened',
            self::person($record),
            self::SESSION,
            self::NOW,
            self::SITE_NOW,
            14
        );
        $installed = [AppInstallPrompt::KEY_INSTALLED => '2026-01-01 10:00:00'];

        self::assertSame(
            [AppInstallPrompt::KEY_INSTALLED => self::SITE_NOW, AppInstallPrompt::KEY_LAST_OPEN => self::SITE_NOW],
            $opened([])
        );
        self::assertSame([], $opened($installed + [AppInstallPrompt::KEY_LAST_OPEN => '2026-11-13 00:00:01']));
        self::assertSame(
            [AppInstallPrompt::KEY_LAST_OPEN => self::SITE_NOW],
            $opened($installed + [AppInstallPrompt::KEY_LAST_OPEN => '2026-11-12 12:00:00'])
        );
    }

    /**
     * After a reinstall, the app offers notifications again, once: the
     * appinstalled event, or the first opening after the iPhone steps.
     */
    public function testANewInstallOffersNotificationsAgainOnce(): void
    {
        $offered = [
            AppInstallPrompt::KEY_INSTALLED => '2026-01-01 10:00:00',
            AppInstallPrompt::KEY_NOTIFY_OFFERED => '2026-01-01 10:01:00',
            AppInstallPrompt::KEY_LAST_OPEN => '2026-11-13 08:00:00',
        ];
        $entries = static fn (string $op, array $record): ?array => AppInstallPrompt::entriesFor(
            $op,
            self::person($record),
            self::SESSION,
            self::NOW,
            self::SITE_NOW,
            14
        );

        // Chrome, Edge: the browser's appinstalled.
        self::assertSame([AppInstallPrompt::KEY_NOTIFY_OFFERED => ''], $entries('installed', $offered));
        self::assertSame(
            [AppInstallPrompt::KEY_INSTALLED => self::SITE_NOW],
            $entries('installed', []),
            'A first install: nothing offered yet to clear.'
        );

        // iPhone: the steps, then the first opening.
        self::assertSame(
            [AppInstallPrompt::KEY_INSTALL_PENDING => self::SITE_NOW],
            $entries('installing', $offered)
        );
        $pending = $offered + [AppInstallPrompt::KEY_INSTALL_PENDING => '2026-11-13 11:00:00'];
        self::assertTrue(
            AppInstallPrompt::state(self::person($pending), self::SESSION, self::NOW, self::SITE_NOW)['offer_notify'],
            'The opening that settles the install offers it already.'
        );
        self::assertSame(
            [AppInstallPrompt::KEY_NOTIFY_OFFERED => '', AppInstallPrompt::KEY_INSTALL_PENDING => ''],
            $entries('opened', $pending)
        );

        // Settled: later openings change nothing, so it is offered once.
        $settled = array_merge($pending, [
            AppInstallPrompt::KEY_NOTIFY_OFFERED => '2026-11-13 12:00:00',
            AppInstallPrompt::KEY_INSTALL_PENDING => '',
        ]);
        self::assertSame([], $entries('opened', $settled));
        self::assertFalse(
            AppInstallPrompt::state(self::person($settled), self::SESSION, self::NOW, self::SITE_NOW)['offer_notify']
        );
    }

    public function testTheNewKeysAreErasedWithThePerson(): void
    {
        self::assertContains(AppInstallPrompt::KEY_LAST_OPEN, AppInstallPrompt::KEYS);
        self::assertContains(AppInstallPrompt::KEY_INSTALL_PENDING, AppInstallPrompt::KEYS);
    }

    public function testRemindMeLaterHidesItUntilTheDate(): void
    {
        $future = self::person([AppInstallPrompt::KEY_REMIND_AFTER => (string) (self::NOW + 60)]);
        $past = self::person([AppInstallPrompt::KEY_REMIND_AFTER => (string) (self::NOW - 60)]);

        self::assertFalse(AppInstallPrompt::state($future, self::SESSION, self::NOW)['popin']);
        self::assertTrue(AppInstallPrompt::state($past, self::SESSION, self::NOW)['popin']);
    }

    public function testClosedHidesItForThatSessionOnlyAndANewSignInBringsItBack(): void
    {
        $closed = self::person([AppInstallPrompt::KEY_CLOSED => '123,' . self::SESSION]);

        self::assertFalse(AppInstallPrompt::state($closed, self::SESSION, self::NOW)['popin']);
        self::assertTrue(AppInstallPrompt::state($closed, self::SESSION + 500, self::NOW)['popin']);
    }

    public function testTheInstalledAppOffersNotificationsOnce(): void
    {
        $offered = self::person([AppInstallPrompt::KEY_NOTIFY_OFFERED => '2026-11-12 10:00:00']);

        self::assertFalse(AppInstallPrompt::state($offered, self::SESSION, self::NOW)['offer_notify']);
    }

    public function testWhatEachOpStores(): void
    {
        $person = self::person([AppInstallPrompt::KEY_CLOSED => '1,2,3,4,5']);
        $at = '2026-11-12 10:00:00';

        self::assertSame(
            [AppInstallPrompt::KEY_CLOSED => '2,3,4,5,' . self::SESSION],
            AppInstallPrompt::entriesFor('close', $person, self::SESSION, self::NOW, $at, 14),
            'The session is added and only the last five are kept.'
        );
        self::assertNull(AppInstallPrompt::entriesFor('close', $person, null, self::NOW, $at, 14));
        self::assertSame(
            [AppInstallPrompt::KEY_REMIND_AFTER => (string) (self::NOW + 3 * DAY_IN_SECONDS)],
            AppInstallPrompt::entriesFor('later', $person, self::SESSION, self::NOW, $at, 3)
        );
        self::assertSame(
            [AppInstallPrompt::KEY_INSTALLED => $at],
            AppInstallPrompt::entriesFor('installed', $person, self::SESSION, self::NOW, $at, 14)
        );
        self::assertSame(
            [],
            AppInstallPrompt::entriesFor(
                'installed',
                self::person([AppInstallPrompt::KEY_INSTALLED => '2026-01-01 00:00:00']),
                self::SESSION,
                self::NOW,
                $at,
                14
            ),
            'The first install time is kept, as history.'
        );
        self::assertSame(
            [AppInstallPrompt::KEY_NOTIFY_OFFERED => $at],
            AppInstallPrompt::entriesFor('offered', $person, self::SESSION, self::NOW, $at, 14)
        );
        self::assertNull(AppInstallPrompt::entriesFor('wipe', $person, self::SESSION, self::NOW, $at, 14));
    }

    public function testTheRenewalCarriesAClosedSessionOver(): void
    {
        $closed = self::person([AppInstallPrompt::KEY_CLOSED => '9,' . self::SESSION]);

        self::assertSame(
            [AppInstallPrompt::KEY_CLOSED => '9,' . self::NOW],
            AppInstallPrompt::carryOver($closed, self::SESSION, self::NOW)
        );
        self::assertNull(AppInstallPrompt::carryOver(self::person(), self::SESSION, self::NOW));
    }

    public function testRemindDaysDefaultsToFourteenAndIsSanitized(): void
    {
        self::assertSame(14, AppInstallPrompt::remindDays());
        self::assertSame(14, AppInstallPrompt::sanitizeRemindDays(''));
        self::assertSame(14, AppInstallPrompt::sanitizeRemindDays('soon'));
        self::assertSame(7, AppInstallPrompt::sanitizeRemindDays(' 7 '));
        self::assertSame(1, AppInstallPrompt::sanitizeRemindDays('0'));
        self::assertSame(1, AppInstallPrompt::sanitizeRemindDays('-5'));
        self::assertSame(365, AppInstallPrompt::sanitizeRemindDays('9999'));
        self::assertSame(2, AppInstallPrompt::sanitizeRemindDays('2.7'));
    }

    public function testRemindDaysSavesTheSanitizedNumber(): void
    {
        $saved = [];
        Functions\when('update_option')->alias(function (string $name, mixed $value) use (&$saved): bool {
            $saved[$name] = $value;

            return true;
        });

        AppInstallPrompt::saveRemindDays('30');
        self::assertSame([AppInstallPrompt::REMIND_DAYS_OPTION => 30], $saved);

        AppInstallPrompt::saveRemindDays('');
        self::assertSame([AppInstallPrompt::REMIND_DAYS_OPTION => 14], $saved);

        Functions\when('get_option')->justReturn('21');
        self::assertSame(21, AppInstallPrompt::remindDays());
    }
}
