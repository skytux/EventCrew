<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use EventCrew\Support\HelpPage;
use EventCrew\Tests\TestCase;
use EventCrew\Web\AssistantWebActions;

/** What the Crew app page does with an action line the helper gave. */
final class AssistantWebActionsTest extends TestCase
{
    public function testSigningUpAndCancellingAreConfirmed(): void
    {
        self::assertSame(
            ['kind' => AssistantWebActions::CONFIRM, 'op' => AssistantWebActions::JOIN, 'task' => 12],
            AssistantWebActions::plan('/join 12')
        );
        self::assertSame(
            ['kind' => AssistantWebActions::CONFIRM, 'op' => AssistantWebActions::LEAVE, 'task' => 3],
            AssistantWebActions::plan(' /leave  3 ')
        );
    }

    public function testWhatThePageHasOpensItsPart(): void
    {
        self::assertSame(['eventcrew-panel-board'], AssistantWebActions::plan('/board')['targets'] ?? null);
        self::assertSame(
            ['eventcrew-past-tasks', 'eventcrew-panel-tasks'],
            AssistantWebActions::plan('/me history')['targets'] ?? null
        );
        self::assertSame([HelpPage::PANEL_ANCHOR], AssistantWebActions::plan('/help')['targets'] ?? null);
        self::assertSame(['eventcrew-panel-settings'], AssistantWebActions::plan('/name')['targets'] ?? null);
        self::assertSame(['eventcrew-panel-settings'], AssistantWebActions::plan('/email sam@example.test')['targets'] ?? null);
    }

    public function testWhatThePageDoesNotHaveIsNoAction(): void
    {
        self::assertSame(['kind' => AssistantWebActions::NONE], AssistantWebActions::plan('/roster 14.11.'));
        self::assertSame(['kind' => AssistantWebActions::NONE], AssistantWebActions::plan('/join twelve'));
        self::assertSame(['kind' => AssistantWebActions::NONE], AssistantWebActions::plan('/crew'));
    }

    public function testAMemberNeverGetsAnAdminScreen(): void
    {
        self::assertSame(['kind' => AssistantWebActions::NONE], AssistantWebActions::plan('/standing Lou'));
        self::assertSame(['eventcrew-door-scanner'], AssistantWebActions::plan('menu:sales')['targets'] ?? null);
    }

    public function testAnOrganizerGetsTheAdminScreenWithTheDateOrName(): void
    {
        $roster = AssistantWebActions::plan('/roster 14.11.', true, '2026-11-13');
        self::assertSame(AssistantWebActions::GOTO, $roster['kind']);
        self::assertSame(['eventcrew-admin-roster', 'eventcrew-panel-admin'], $roster['targets']);
        self::assertSame(['roster_date' => '2026-11-14'], $roster['query'] ?? null);

        $standing = AssistantWebActions::plan('/standing Lou H', true, '2026-11-13');
        self::assertSame(['eventcrew-admin-people', 'eventcrew-panel-admin'], $standing['targets']);
        self::assertSame(['people_q' => 'Lou H'], $standing['query'] ?? null);

        self::assertSame(['eventcrew-admin-door', 'eventcrew-panel-admin'], AssistantWebActions::plan('/door 14.11.', true, '2026-11-13')['targets'] ?? null);
    }

    public function testAnOrganizersChangeIsConfirmedAndRunOnThePage(): void
    {
        foreach (['/assign 14.11. Bar Sam', '/unassign 14.11. Bar Sam', '/mark 14.11. Sam', '/say Hello all', '/tell 14.11. Bar Doors at 8', '/subscribe Sam', '/newsletter Hello all', '/followup Thanks', '/newcomer ok Sam', '/newcomer no', '/organizer on Heidi', '/organizer off Heidi', '/deleteperson Sam', 'menu:gift Sam ; 2', 'menu:roles add DJ ; Sam', '/unticket 14.11. Lou H', '/ticket Sam', '/unban Sam', '/freeentry close', '/stop email', '/newboard', '/sendtickets', '/addtask DJ 14.11.', '/edittask 12 +2', '/deletetask 12', '/maketasks', '/mailtasks', '/door add Anna', '/door remove 2'] as $line) {
            $head = (string) strtok($line, ' ');
            self::assertSame(
                ['kind' => AssistantWebActions::CONFIRM, 'op' => AssistantWebActions::CHANGE, 'command' => $head],
                AssistantWebActions::plan($line, true, '2026-11-13'),
                $line
            );
        }

        // A member never: those are organizer commands.
        self::assertSame(['kind' => AssistantWebActions::NONE], AssistantWebActions::plan('/unticket Lou'));
        self::assertNotSame(AssistantWebActions::CHANGE, AssistantWebActions::plan('/organizer on Sam')['op'] ?? '');
        self::assertNotSame(AssistantWebActions::CHANGE, AssistantWebActions::plan('/deleteperson Sam')['op'] ?? '');

        // Without the person or text there is nothing to confirm.
        self::assertNotSame(AssistantWebActions::CHANGE, AssistantWebActions::plan('/organizer', true)['op'] ?? '');
        self::assertNotSame(AssistantWebActions::CHANGE, AssistantWebActions::plan('/newcomer', true)['op'] ?? '');
        self::assertNotSame(AssistantWebActions::CHANGE, AssistantWebActions::plan('/newcomer Sam', true)['op'] ?? '');
    }

    /** Anyone changes their own name and language on Confirm. */
    public function testAnyoneChangesTheirOwnNameAndLanguageOnConfirm(): void
    {
        foreach (['/name Sam K' => '/name', 'menu:language fi' => 'menu:language'] as $line => $head) {
            self::assertSame(
                ['kind' => AssistantWebActions::CONFIRM, 'op' => AssistantWebActions::CHANGE, 'command' => $head],
                AssistantWebActions::plan($line),
                $line
            );
        }
    }

    /** Lou 2026-11-14: "give myself a ticket" spends the person's own credit on Confirm. */
    public function testAnyoneSpendsTheirOwnCreditOnConfirm(): void
    {
        foreach (['menu:ticket', 'menu:ticket 2026-11-14'] as $line) {
            self::assertSame(
                ['kind' => AssistantWebActions::CONFIRM, 'op' => AssistantWebActions::CHANGE, 'command' => AssistantWebActions::OWN_TICKET],
                AssistantWebActions::plan($line),
                $line
            );
        }

        self::assertFalse(AssistantWebActions::leavesUndone('menu:ticket'));
    }

    public function testWhatThePageLeavesUndoneIsKnown(): void
    {
        self::assertTrue(AssistantWebActions::leavesUndone('/email sam@example.test'));
        self::assertTrue(AssistantWebActions::leavesUndone('menu:gift'));
        self::assertTrue(AssistantWebActions::leavesUndone('menu:notifications'));
        self::assertFalse(AssistantWebActions::leavesUndone('menu:gift Sam ; 2'));
        self::assertFalse(AssistantWebActions::leavesUndone('menu:language fi'));
        self::assertFalse(AssistantWebActions::leavesUndone('/mark 14.11. Sam'));
        self::assertFalse(AssistantWebActions::leavesUndone('/newsletter Hello all'));
        self::assertFalse(AssistantWebActions::leavesUndone('/newsletter'));
        self::assertFalse(AssistantWebActions::leavesUndone('/roster 14.11.'));
        self::assertFalse(AssistantWebActions::leavesUndone('/unticket Sam'));
    }

    public function testTelegramLinksAreDropped(): void
    {
        self::assertSame(
            [['t' => 'Ask '], ['t' => ' or read '], ['t' => 'https://a.test/', 'u' => 'https://a.test/']],
            AssistantWebActions::segments('Ask https://t.me/crew_bot or read https://a.test/')
        );
        self::assertTrue(AssistantWebActions::isTelegram('https://t.me/crew_bot'));
        self::assertFalse(AssistantWebActions::isTelegram('https://a.test/t.me'));
    }

    public function testOnlyWebAddressesBecomeLinks(): void
    {
        self::assertSame(
            [
                ['t' => 'Read '],
                ['t' => 'http://a.test/x?y=1', 'u' => 'http://a.test/x?y=1'],
                ['t' => ','],
                ['t' => ' not javascript:alert(1) or <b>this</b>'],
            ],
            AssistantWebActions::segments('Read http://a.test/x?y=1, not javascript:alert(1) or <b>this</b>')
        );
        self::assertSame([], AssistantWebActions::segments(''));
    }
}
