<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use EventCrew\Telegram\AssistantActions;
use EventCrew\Telegram\AssistantParityLines;
use EventCrew\Web\AssistantWebParity;

/**
 * The helper's lines for the Crew app actions that had none (parityB), in
 * Telegram and in the Crew app's helper box.
 */
final class AssistantParityLinesTest extends TelegramTestCase
{
    private const MEMBER = ['organizer' => false];
    private const ORGANIZER = ['organizer' => true];

    public function testSignOutAndReplaceRunTheMenuButtons(): void
    {
        $signOut = AssistantParityLines::validate('menu:signout', '', self::MEMBER);
        self::assertTrue($signOut['ok']);
        self::assertSame(AssistantActions::RUN_TAP, $signOut['run']);

        $replace = AssistantParityLines::validate('menu:replace', '12', self::MEMBER);
        self::assertTrue($replace['ok']);
        self::assertStringEndsWith('12', $replace['payload']);

        self::assertFalse(AssistantParityLines::validate('menu:replace', 'bar', self::MEMBER)['ok']);
        // The bare menu button stays the usual menu line.
        self::assertNull(AssistantParityLines::validate('menu:replace', '', self::MEMBER));
    }

    public function testANotificationSwitchIsAskedFirst(): void
    {
        $line = AssistantParityLines::validate('menu:notifications', 'credit telegram off', self::MEMBER);

        self::assertTrue($line['ok']);
        self::assertTrue($line['confirm']);
        self::assertSame('/notify credit telegram off', $line['payload']);
        self::assertFalse(AssistantParityLines::validate('menu:notifications', 'credit fax off', self::MEMBER)['ok']);
        self::assertTrue(AssistantParityLines::validate('/notify', 'both', self::MEMBER)['confirm']);
    }

    public function testOrganizerLinesAreForOrganizersOnly(): void
    {
        self::assertSame(
            AssistantActions::ERROR_NOT_PERMITTED,
            AssistantParityLines::validate('/checkmail', '', self::MEMBER)['error']
        );
        self::assertTrue(AssistantParityLines::validate('/checkmail', '', self::ORGANIZER)['ok']);
        self::assertFalse(AssistantParityLines::validate('/checkmail', 'now', self::ORGANIZER)['ok']);
        self::assertFalse(AssistantParityLines::validate('/markall', '', self::ORGANIZER)['ok']);
        self::assertSame('/buyers reset', AssistantParityLines::validate('/buyers', 'reset', self::ORGANIZER)['payload']);
        self::assertNull(AssistantParityLines::validate('/join', '12', self::ORGANIZER));
    }

    public function testTheCatalogueListsOrganizerLinesOnlyToOrganizers(): void
    {
        $member = array_column(AssistantParityLines::entries(self::MEMBER), 'usage');
        $organizer = array_column(AssistantParityLines::entries(self::ORGANIZER), 'usage');

        self::assertContains('menu:signout', $member);
        self::assertNotContains('/checkmail', $member);
        self::assertContains('/checkmail', $organizer);
    }

    public function testTheHelperBoxTakesTheSameLines(): void
    {
        self::assertTrue(AssistantWebParity::handles('menu:sales', '', false));
        self::assertTrue(AssistantWebParity::handles('menu:replace', '12', false));
        self::assertFalse(AssistantWebParity::handles('menu:replace', '', false));
        self::assertTrue(AssistantWebParity::handles('/giveaway', 'cancel', false));
        self::assertFalse(AssistantWebParity::handles('/markall', 'bar ; done', false));
        self::assertTrue(AssistantWebParity::handles('/markall', 'bar ; done', true));
    }
}
