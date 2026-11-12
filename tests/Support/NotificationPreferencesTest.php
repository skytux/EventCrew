<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Models\Person;
use EventCrew\Support\NotificationPreferences;
use EventCrew\Tests\TestCase;

final class NotificationPreferencesTest extends TestCase
{
    /**
     * @param array<string, mixed> $row
     */
    private function person(array $row): Person
    {
        return Person::fromRow($row + ['id' => 7, 'email' => 'sam@example.com']);
    }

    private function prefs(): NotificationPreferences
    {
        return new NotificationPreferences();
    }

    public function testLockedCommitmentTypesAlwaysSend(): void
    {
        // Even with everything switched off, a locked type is allowed.
        $person = $this->person([
            'telegram_chat_id' => 1,
            'notify_prefs' => json_encode(['standing' => ['dm' => false, 'email' => false]]),
        ]);

        self::assertTrue($this->prefs()->dmAllowed($person, NotificationPreferences::SIGNUP));
        self::assertTrue($this->prefs()->emailAllowed($person, NotificationPreferences::SIGNUP));
    }

    public function testAToggleableChannelCanBeTurnedOffIndependently(): void
    {
        $person = $this->person([
            'telegram_chat_id' => 1,
            'notify_prefs' => json_encode(['credit' => ['dm' => false, 'email' => true]]),
        ]);

        self::assertFalse($this->prefs()->dmAllowed($person, NotificationPreferences::CREDIT));
        self::assertTrue($this->prefs()->emailAllowed($person, NotificationPreferences::CREDIT));
    }

    public function testNoLinkedChatMeansNoDm(): void
    {
        self::assertFalse($this->prefs()->dmAllowed($this->person([]), NotificationPreferences::CREDIT));
    }

    public function testALegacyMutedAccountDefaultsToDmOff(): void
    {
        $person = $this->person(['telegram_chat_id' => 1, 'notify_muted' => 1]);

        // The retired mute flag seeds toggleable DMs off, but email stays on...
        self::assertFalse($this->prefs()->dmAllowed($person, NotificationPreferences::CREDIT));
        self::assertTrue($this->prefs()->emailAllowed($person, NotificationPreferences::CREDIT));
        // ...and a locked commitment still reaches them.
        self::assertTrue($this->prefs()->dmAllowed($person, NotificationPreferences::SIGNUP));
    }

    public function testTheSwitchesAreTheFiveThatStillDoSomething(): void
    {
        self::assertSame(
            ['standing', 'credit', 'ticket', 'permission', 'open_task'],
            array_keys(NotificationPreferences::labels())
        );
    }

    /**
     * The removed "Cover arranged" switch may still be stored for a person.
     * It is left out of the map both UIs save, so the next save drops it.
     */
    public function testAStoredCoverPreferenceIsLeftOutOfTheSavedMap(): void
    {
        $person = $this->person([
            'telegram_chat_id' => 1,
            'notify_prefs' => json_encode([
                'cover' => ['dm' => false, 'email' => false],
                'credit' => ['dm' => false, 'email' => true],
            ]),
        ]);

        $map = $this->prefs()->asMap($person);

        self::assertArrayNotHasKey('cover', $map);
        self::assertSame(['dm' => false, 'email' => true, 'push' => true], $map['credit']);
    }

    public function testOrganizersAlsoGetTheOrganizerRowsDefaultingOn(): void
    {
        $organizer = $this->person(['telegram_chat_id' => 1, 'is_organizer' => 1]);
        $member = $this->person(['telegram_chat_id' => 1]);

        self::assertSame(
            ['standing', 'credit', 'ticket', 'permission', 'open_task', 'role_news', 'role_met', 'chat_forward', 'list_import', 'newcomer'],
            array_keys($this->prefs()->matrix($organizer))
        );
        self::assertSame(array_keys(NotificationPreferences::labels()), array_keys($this->prefs()->matrix($member)));
        self::assertSame(NotificationPreferences::labels(true), NotificationPreferences::labelsFor($organizer));

        $types = [
            NotificationPreferences::ROLE_NEWS,
            NotificationPreferences::ROLE_MET,
            NotificationPreferences::CHAT_FORWARD,
            NotificationPreferences::LIST_IMPORT,
            NotificationPreferences::NEWCOMER,
        ];

        foreach ($types as $type) {
            self::assertTrue($this->prefs()->dmAllowed($organizer, $type));
            self::assertTrue($this->prefs()->emailAllowed($organizer, $type));
        }
    }

    public function testAnOrganizerRowCanBeTurnedOffPerChannel(): void
    {
        $organizer = $this->person([
            'telegram_chat_id' => 1,
            'is_organizer' => 1,
            'notify_prefs' => json_encode([
                'role_met' => ['dm' => false, 'email' => true],
                'chat_forward' => ['dm' => true, 'email' => false],
            ]),
        ]);

        self::assertFalse($this->prefs()->dmAllowed($organizer, NotificationPreferences::ROLE_MET));
        self::assertTrue($this->prefs()->emailAllowed($organizer, NotificationPreferences::ROLE_MET));
        self::assertTrue($this->prefs()->dmAllowed($organizer, NotificationPreferences::CHAT_FORWARD));
        self::assertFalse($this->prefs()->emailAllowed($organizer, NotificationPreferences::CHAT_FORWARD));
        self::assertTrue($this->prefs()->emailAllowed($organizer, NotificationPreferences::ROLE_NEWS));
        self::assertSame(['dm' => true, 'email' => false, 'push' => true], $this->prefs()->asMap($organizer)['chat_forward']);
    }

    public function testAMutedOrganizerDefaultsToNoDmOnTheOrganizerRowsToo(): void
    {
        // The old behaviour of the role and chat notices: the bot mute stopped the DM.
        $organizer = $this->person(['telegram_chat_id' => 1, 'is_organizer' => 1, 'notify_muted' => 1]);

        self::assertFalse($this->prefs()->dmAllowed($organizer, NotificationPreferences::ROLE_NEWS));
        self::assertTrue($this->prefs()->emailAllowed($organizer, NotificationPreferences::ROLE_NEWS));
    }

    public function testTheWebFormSavesOnlyTheRowsThePersonSees(): void
    {
        $posted = [
            'credit' => ['email' => '1'],
            'role_met' => ['dm' => '1', 'email' => '1', 'push' => '1'],
            'cover' => ['dm' => '1'],
        ];

        $member = NotificationPreferences::fromForm($this->person([]), $posted);
        self::assertSame(array_keys(NotificationPreferences::labels()), array_keys($member));
        self::assertSame(['dm' => false, 'email' => true, 'push' => false], $member['credit']);
        self::assertSame(['dm' => false, 'email' => false, 'push' => false], $member['standing']);

        $organizer = NotificationPreferences::fromForm($this->person(['is_organizer' => 1]), $posted);
        self::assertSame(['dm' => true, 'email' => true, 'push' => true], $organizer['role_met']);
        self::assertSame(['dm' => false, 'email' => false, 'push' => false], $organizer['chat_forward']);
        self::assertArrayNotHasKey('cover', $organizer);
    }

    /** The labels sit in the bot's keyboard, so they stay short. */
    public function testTheLabelsAreShortEnoughForTheKeyboard(): void
    {
        foreach (NotificationPreferences::labels(true) as $label) {
            self::assertLessThanOrEqual(33, mb_strlen($label), $label);
        }
    }

    public function testNothingIsPushedWithoutADeviceThatTurnedAppNotificationsOn(): void
    {
        $person = $this->person([]);

        // No lookup set (before boot): no one has a device.
        self::assertFalse($this->prefs()->pushAllowed($person, NotificationPreferences::CREDIT));

        NotificationPreferences::useDeviceLookup(static fn (int $id): bool => false);
        self::assertFalse($this->prefs()->pushAllowed($person, NotificationPreferences::CREDIT));

        NotificationPreferences::useDeviceLookup(static fn (int $id): bool => 7 === $id);
        self::assertTrue($this->prefs()->pushAllowed($person, NotificationPreferences::CREDIT));
        self::assertTrue($this->prefs()->pushAllowed($person, NotificationPreferences::SIGNUP));
    }

    public function testTheAppSwitchIsPerTypeAndLockedTypesAlwaysPush(): void
    {
        $asked = 0;
        NotificationPreferences::useDeviceLookup(static function (int $id) use (&$asked): bool {
            ++$asked;

            return true;
        });
        $person = $this->person([
            'notify_prefs' => json_encode(['credit' => ['dm' => true, 'email' => true, 'push' => false]]),
        ]);

        self::assertFalse($this->prefs()->pushAllowed($person, NotificationPreferences::CREDIT));
        // The switch is read first: off costs no device lookup.
        self::assertSame(0, $asked);
        self::assertTrue($this->prefs()->pushAllowed($person, NotificationPreferences::TICKET));
        self::assertTrue($this->prefs()->pushAllowed($person, NotificationPreferences::REMINDER));
        self::assertFalse($this->prefs()->matrix($person)['credit']['push']);
        self::assertTrue($this->prefs()->matrix($person)['ticket']['push']);
    }

    public function testExistingAccountsGetTheAppSwitchOnlyWhereTelegramAndEmailAreBothOn(): void
    {
        $person = $this->person([
            'is_organizer' => 1,
            'notify_prefs' => json_encode([
                'credit' => ['dm' => true, 'email' => false],
                'ticket' => ['dm' => false, 'email' => true],
                'standing' => ['dm' => true, 'email' => true],
                'open_task' => ['dm' => false, 'email' => false, 'push' => true],
                'cover' => ['dm' => false, 'email' => false],
            ]),
        ]);

        $seeded = $this->prefs()->withPushSeeded($person);

        self::assertSame(['dm' => true, 'email' => false, 'push' => false], $seeded['credit']);
        self::assertSame(['dm' => false, 'email' => true, 'push' => false], $seeded['ticket']);
        self::assertSame(['dm' => true, 'email' => true, 'push' => true], $seeded['standing']);
        // A stored app value is kept.
        self::assertTrue($seeded['open_task']['push']);
        // Nothing stored: both default on, so app on; organizer rows too.
        self::assertSame(['push' => true], $seeded['permission']);
        self::assertSame(['push' => true], $seeded['newcomer']);
        // Other stored keys are left as they are.
        self::assertSame(['dm' => false, 'email' => false], $seeded['cover']);

        foreach (NotificationPreferences::types() as $type) {
            self::assertArrayHasKey('push', $seeded[$type]);
        }
    }

    public function testALegacyMutedAccountGetsTheAppSwitchOff(): void
    {
        // "Mute all DMs" reads as Telegram off on every row, so app is off too.
        $person = $this->person(['telegram_chat_id' => 1, 'notify_muted' => 1]);

        foreach ($this->prefs()->withPushSeeded($person) as $row) {
            self::assertFalse($row['push']);
        }
    }

    public function testTheTypesAreTheOrganizersRows(): void
    {
        self::assertSame(array_keys(NotificationPreferences::labels(true)), NotificationPreferences::types());
    }
}
