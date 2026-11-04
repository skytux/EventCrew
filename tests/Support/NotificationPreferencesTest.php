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
        self::assertSame(['dm' => false, 'email' => true], $map['credit']);
    }
}
