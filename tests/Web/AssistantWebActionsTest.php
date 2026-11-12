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
            ['eventcrew-past-tasks', 'eventcrew-panel-me'],
            AssistantWebActions::plan('/me history')['targets'] ?? null
        );
        self::assertSame([HelpPage::PANEL_ANCHOR], AssistantWebActions::plan('/help')['targets'] ?? null);
        self::assertSame(['eventcrew-panel-settings'], AssistantWebActions::plan('/name Sam')['targets'] ?? null);
    }

    public function testTheRestIsForTheTelegramBot(): void
    {
        self::assertSame(['kind' => AssistantWebActions::TELEGRAM], AssistantWebActions::plan('/roster 14.11.'));
        self::assertSame(['kind' => AssistantWebActions::TELEGRAM], AssistantWebActions::plan('/join twelve'));
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
