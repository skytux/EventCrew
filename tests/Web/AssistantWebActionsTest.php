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
        self::assertSame(['eventcrew-panel-settings'], AssistantWebActions::plan('/name Sam')['targets'] ?? null);
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

        self::assertSame(
            ['eventcrew-admin-tasks', 'eventcrew-panel-admin'],
            AssistantWebActions::plan('/addtask', true, '2026-11-13')['targets'] ?? null
        );
        self::assertSame(
            ['eventcrew-admin-board', 'eventcrew-panel-admin'],
            AssistantWebActions::plan('/newboard', true, '2026-11-13')['targets'] ?? null
        );
        // The Open-task email card is on Messages (Owner 2026-11-13).
        self::assertSame(
            ['eventcrew-admin-open-task', 'eventcrew-panel-admin'],
            AssistantWebActions::plan('/mailtasks', true, '2026-11-13')['targets'] ?? null
        );
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
