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
        $state = AppInstallPrompt::state(self::person(), self::SESSION, self::NOW);

        self::assertSame(['popin' => true, 'installed' => false, 'offer_notify' => true], $state);
    }

    public function testNeverShownWithoutASession(): void
    {
        self::assertFalse(AppInstallPrompt::state(self::person(), null, self::NOW)['popin']);
    }

    public function testNeverShownAgainOnceInstalled(): void
    {
        $state = AppInstallPrompt::state(
            self::person([AppInstallPrompt::KEY_INSTALLED => '2026-11-12 10:00:00']),
            self::SESSION,
            self::NOW
        );

        self::assertFalse($state['popin']);
        self::assertTrue($state['installed']);
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
            'The first install time is kept.'
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
