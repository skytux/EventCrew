<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use EventCrew\Telegram\AssistantActions;
use EventCrew\Telegram\MenuButtons;
use EventCrew\Tests\TestCase;

/**
 * The check on every action the language model proposes: only commands the
 * person could use themselves, only task numbers it was shown, only guests
 * and numbers taken from what the person wrote.
 */
final class AssistantActionsTest extends TestCase
{
    private const TODAY = '2026-07-20';

    /** @return array{organizer: bool, welcomeCrew: bool, verified: bool} */
    private static function member(): array
    {
        return ['organizer' => false, 'welcomeCrew' => false, 'verified' => true];
    }

    /** @return array{organizer: bool, welcomeCrew: bool, verified: bool} */
    private static function organizer(): array
    {
        return ['organizer' => true, 'welcomeCrew' => true, 'verified' => true];
    }

    /** @return array{organizer: bool, welcomeCrew: bool, verified: bool} */
    private static function stranger(): array
    {
        return ['organizer' => false, 'welcomeCrew' => false, 'verified' => false];
    }

    /**
     * @param array{organizer: bool, welcomeCrew: bool, verified: bool} $audience
     * @param array<int, int> $tasks
     * @return array{ok: bool, error: string, action: string, confirm: bool, run: string, payload: string}
     */
    private static function check(string $action, array $audience, ?string $said = null, array $tasks = [12]): array
    {
        return AssistantActions::validate($action, $audience, $said, self::TODAY, $tasks);
    }

    public function testAnUnknownCommandIsRefused(): void
    {
        foreach (['/delete_everything', '/stop', '/gift 5', 'rm -rf', 'menu:unknown', '/ticket'] as $action) {
            $result = self::check($action, self::organizer());

            self::assertFalse($result['ok'], $action);
            self::assertSame(AssistantActions::ERROR_UNKNOWN, $result['error'], $action);
        }
    }

    public function testShowOnlyActionsRunWithoutConfirm(): void
    {
        $me = self::check('/me history', self::member());
        self::assertTrue($me['ok']);
        self::assertFalse($me['confirm']);
        self::assertSame(AssistantActions::RUN_MESSAGE, $me['run']);
        self::assertSame('/me history', $me['payload']);

        $ticket = self::check('menu:ticket', self::member());
        self::assertTrue($ticket['ok']);
        self::assertFalse($ticket['confirm']);
        self::assertSame(AssistantActions::RUN_TAP, $ticket['run']);
        self::assertSame(MenuButtons::TICKET, $ticket['payload']);
    }

    public function testExtraWordsAfterAFixedCommandAreRefused(): void
    {
        self::assertSame(AssistantActions::ERROR_INVALID, self::check('/help me now', self::member())['error']);
        self::assertSame(AssistantActions::ERROR_INVALID, self::check('/me everything', self::member())['error']);
        self::assertSame(AssistantActions::ERROR_INVALID, self::check('menu:web now', self::member())['error']);
    }

    public function testStaffCommandsAreNotPermittedForAMember(): void
    {
        foreach (['/door', 'menu:doorlist', 'menu:guestlist', 'menu:sales', '/people', 'menu:gift', 'menu:roles', 'menu:eligible', '/door add Anna'] as $action) {
            $result = self::check($action, self::member(), 'add Anna');

            self::assertFalse($result['ok'], $action);
            self::assertSame(AssistantActions::ERROR_NOT_PERMITTED, $result['error'], $action);
        }
    }

    public function testWelcomeCrewMayShowButNotChangeTheGuestList(): void
    {
        $crew = ['organizer' => false, 'welcomeCrew' => true, 'verified' => true];

        self::assertTrue(self::check('/roster 1.8.', $crew)['ok']);
        self::assertSame('/roster 2026-08-01', self::check('/roster 1.8.', $crew)['payload']);
        self::assertSame(MenuButtons::GUEST_LIST . '2026-08-01', self::check('menu:guestlist 1.8.', $crew)['payload']);
        self::assertSame(AssistantActions::ERROR_NOT_PERMITTED, self::check('menu:gift', $crew)['error']);
        self::assertSame(
            AssistantActions::ERROR_NOT_PERMITTED,
            self::check('/door add Anna', $crew, 'add Anna to the guest list')['error']
        );
    }

    public function testABadDateIsRefused(): void
    {
        self::assertSame(AssistantActions::ERROR_INVALID, self::check('/roster tomorrowish', self::organizer())['error']);
    }

    public function testJoinNeedsConfirmAndUsesTheBoardButton(): void
    {
        $result = self::check('/join 12', self::member());

        self::assertTrue($result['ok']);
        self::assertTrue($result['confirm']);
        self::assertSame(AssistantActions::RUN_TAP, $result['run']);
        self::assertSame('j:12', $result['payload']);
        self::assertSame('/join 12', $result['action']);

        self::assertSame('l:12', self::check('/leave #12', self::member())['payload']);
        // A leave asks no question of its own: the board asks, with the cost.
        self::assertFalse(self::check('/leave #12', self::member())['confirm']);
    }

    public function testJoinRefusesATaskTheModelWasNotShown(): void
    {
        $result = self::check('/join 13', self::member());

        self::assertFalse($result['ok']);
        self::assertSame(AssistantActions::ERROR_INVALID, $result['error']);
        self::assertFalse(self::check('/join twelve', self::member())['ok']);
    }

    public function testJoinNeedsAConfirmedEmail(): void
    {
        self::assertSame(AssistantActions::ERROR_NOT_REGISTERED, self::check('/join 12', self::stranger())['error']);
    }

    public function testGuestChangesNeedConfirmAndAnOrganizer(): void
    {
        $result = self::check('/door add Anna Virtanen +1', self::organizer(), 'put Anna Virtanen and a friend on the list');

        self::assertTrue($result['ok']);
        self::assertTrue($result['confirm']);
        self::assertSame(AssistantActions::RUN_MESSAGE, $result['run']);
        self::assertSame('/door add Anna Virtanen +1', $result['payload']);
    }

    public function testAGuestNameTheModelMadeUpIsRefused(): void
    {
        $result = self::check('/door add Bob Smith', self::organizer(), 'add my friend to the guest list');

        self::assertFalse($result['ok']);
        self::assertSame(AssistantActions::ERROR_INVALID, $result['error']);
    }

    public function testAGuestNumberTheModelMadeUpIsRefused(): void
    {
        self::assertFalse(self::check('/door remove 4', self::organizer(), 'remove Anna from the list')['ok']);
        self::assertFalse(self::check('/door remove 4', self::organizer(), 'remove guest 14')['ok']);
        self::assertTrue(self::check('/door remove 4', self::organizer(), 'remove guest 4 please')['ok']);
    }

    public function testCatalogueMatchesTheAudience(): void
    {
        $usages = static fn (array $audience): array => array_column(AssistantActions::catalogue($audience), 'usage');

        // A member gets /roster too: RosterService shows them names only, and
        // only for a night they work.
        $memberRoster = array_values(array_filter(
            AssistantActions::catalogue(self::member()),
            static fn (array $entry): bool => '/roster [date]' === $entry['usage']
        ));
        self::assertStringContainsString('names only', $memberRoster[0]['what']);
        self::assertNotContains('/assign [date] <task> ; <person>', $usages(self::member()));
        self::assertContains('/assign [date] <task> ; <person>', $usages(self::organizer()));
        self::assertContains('/unassign [date] <task> ; <person>', $usages(self::organizer()));
        self::assertNotContains('/people', $usages(self::member()));
        self::assertContains('/join <task>', $usages(self::member()));
        self::assertContains('/people', $usages(self::organizer()));

        $roster = array_values(array_filter(
            AssistantActions::catalogue(self::organizer()),
            static fn (array $entry): bool => '/roster [date]' === $entry['usage']
        ));
        self::assertStringContainsString('an organizer marks arrived (only people still signed up), done, late or no-show', $roster[0]['what']);
        self::assertStringContainsString('marks only arrived, for people still signed up', $roster[0]['what']);

        $confirm = array_column(
            array_filter(AssistantActions::catalogue(self::organizer()), static fn (array $entry): bool => $entry['confirm']),
            'usage'
        );

        self::assertSame(
            [
                '/join <task>',
                '/leave <task>',
                '/door add [date] <name> [email] [+N] [; note]',
                '/door edit [date] <number> [name] [+N] [; note]',
                '/door remove [date] <number>',
                '/newsletter <text>',
                '/newsletter reset',
                '/followup <came|missed> <text>',
                '/followup [came|missed] reset',
            ],
            array_values($confirm)
        );
        self::assertNotContains('/followup', $usages(self::member()));
        self::assertNotContains('/newsletter', $usages(self::member()));
        self::assertNotContains(
            '/newsletter',
            $usages(['organizer' => false, 'welcomeCrew' => true, 'verified' => true])
        );
    }

    public function testAMemberMayAskForTheRosterOfTheirNight(): void
    {
        $roster = self::check('/roster 1.8.', self::member());

        self::assertTrue($roster['ok']);
        self::assertSame('/roster 2026-08-01', $roster['payload']);
    }

    public function testAnOrganizerMayAssignAndUnassignAndTheCommandConfirmsItself(): void
    {
        $assign = self::check('/assign Host ; Milla', self::organizer());

        self::assertTrue($assign['ok']);
        self::assertFalse($assign['confirm']);
        self::assertSame(AssistantActions::RUN_MESSAGE, $assign['run']);
        self::assertSame('/assign Host ; Milla', $assign['payload']);

        $dated = self::check('/unassign 21.11. Host ; Milla Virtanen', self::organizer());

        self::assertTrue($dated['ok']);
        self::assertSame('/unassign 21.11. Host ; Milla Virtanen', $dated['payload']);
    }

    public function testAssignIsForOrganizersOnlyAndNeedsATaskAndAName(): void
    {
        $crew = ['organizer' => false, 'welcomeCrew' => true, 'verified' => true];

        self::assertSame(AssistantActions::ERROR_NOT_PERMITTED, self::check('/assign Host ; Milla', self::member())['error']);
        self::assertSame(AssistantActions::ERROR_NOT_PERMITTED, self::check('/unassign Host ; Milla', $crew)['error']);

        foreach (['/assign', '/assign Host', '/assign Host ;', '/assign ; Milla', '/assign 21.11. ; Milla'] as $action) {
            self::assertSame(AssistantActions::ERROR_INVALID, self::check($action, self::organizer())['error'], $action);
        }
    }

    public function testAnOrganizerMayManageTasksAndTheCommandsConfirmThemselves(): void
    {
        foreach (
            [
                '/edittask Host ; places 3',
                '/edittask 21.11. Host ; time 21:00-02:00 ; notes Bring keys',
                '/addtask Host ; time 21-23',
                '/addtask 21.11. Door',
                '/deletetask 21.11. Host',
                '/maketasks',
                '/maketasks 21.11.',
                '/mailtasks',
            ] as $action
        ) {
            $checked = self::check($action, self::organizer());
            self::assertTrue($checked['ok'], $action);
            self::assertFalse($checked['confirm'], $action);
            self::assertSame(AssistantActions::RUN_MESSAGE, $checked['run'], $action);
            self::assertSame($action, $checked['payload'], $action);
        }

        $usages = array_column(AssistantActions::catalogue(self::organizer()), 'usage');
        self::assertContains('/edittask [date] <task> ; <field> <value> [; <field> <value>]', $usages);
        self::assertContains('/mailtasks', $usages);
        self::assertNotContains('/mailtasks', array_column(AssistantActions::catalogue(self::member()), 'usage'));
    }

    public function testTaskCommandsAreForOrganizersOnlyAndNeedTheirParts(): void
    {
        self::assertSame(AssistantActions::ERROR_NOT_PERMITTED, self::check('/edittask Host ; places 3', self::member())['error']);
        self::assertSame(AssistantActions::ERROR_NOT_PERMITTED, self::check('/mailtasks', self::member())['error']);

        foreach (['/edittask Host', '/edittask ; places 3', '/addtask', '/deletetask', '/deletetask Host ; x', '/maketasks Host', '/mailtasks now'] as $action) {
            self::assertSame(AssistantActions::ERROR_INVALID, self::check($action, self::organizer())['error'], $action);
        }
    }

    public function testTheLanguageEntryNamesEverySiteLanguage(): void
    {
        \Brain\Monkey\Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => 'eventcrew_site_languages' === $name ? ['en', 'fi', 'sv'] : $default
        );
        $language = array_values(array_filter(
            AssistantActions::catalogue(self::member()),
            static fn (array $entry): bool => 'menu:language' === $entry['usage']
        ));

        self::assertStringContainsString('English, Finnish, Swedish', $language[0]['what']);
    }

    public function testOnlyAnOrganizerMaySetTheNewsletterText(): void
    {
        $crew = ['organizer' => false, 'welcomeCrew' => true, 'verified' => true];

        foreach (['/newsletter', '/newsletter clear', '/newsletter Doors open at 19.'] as $action) {
            self::assertSame(
                AssistantActions::ERROR_NOT_PERMITTED,
                self::check($action, self::member())['error'],
                $action
            );
            self::assertSame(AssistantActions::ERROR_NOT_PERMITTED, self::check($action, $crew)['error'], $action);
        }
    }

    public function testOnlyAnOrganizerMayPostInTheGroup(): void
    {
        $crew = ['organizer' => false, 'welcomeCrew' => true, 'verified' => true];

        self::assertSame(AssistantActions::ERROR_NOT_PERMITTED, self::check('/say Hello', self::member())['error']);
        self::assertSame(AssistantActions::ERROR_NOT_PERMITTED, self::check('/say Hello', $crew)['error']);
    }

    public function testOnlyAnOrganizerGetsTheEventCards(): void
    {
        $crew = ['organizer' => false, 'welcomeCrew' => true, 'verified' => true];
        $result = self::check('/events', self::organizer());

        self::assertTrue($result['ok']);
        self::assertFalse($result['confirm']);
        self::assertSame(AssistantActions::RUN_MESSAGE, $result['run']);
        self::assertSame('/events', $result['payload']);
        self::assertSame(AssistantActions::ERROR_INVALID, self::check('/events now', self::organizer())['error']);
        self::assertSame(AssistantActions::ERROR_NOT_PERMITTED, self::check('/events', self::member())['error']);
        self::assertSame(AssistantActions::ERROR_NOT_PERMITTED, self::check('/events', $crew)['error']);
        self::assertContains('/events', array_column(AssistantActions::catalogue(self::organizer()), 'usage'));
        self::assertNotContains('/events', array_column(AssistantActions::catalogue($crew), 'usage'));
    }

    public function testAMessageForTheGroupKeepsItsTextExactlyAndRunsWithoutTheHelpersConfirm(): void
    {
        $result = self::check("/say  Hi  all,\n\n\nsee you!  ", self::organizer());

        self::assertTrue($result['ok']);
        self::assertFalse($result['confirm']);
        self::assertSame(AssistantActions::RUN_MESSAGE, $result['run']);
        self::assertSame("/say Hi  all,\n\n\nsee you!", $result['payload']);
    }

    public function testShowingTheNewsletterTextRunsWithoutConfirm(): void
    {
        $result = self::check('/newsletter', self::organizer());

        self::assertTrue($result['ok']);
        self::assertFalse($result['confirm']);
        self::assertSame(AssistantActions::RUN_MESSAGE, $result['run']);
        self::assertSame('/newsletter', $result['payload']);
    }

    public function testSettingAndClearingTheNewsletterTextAskToConfirm(): void
    {
        $set = self::check('/newsletter Doors open at 19.', self::organizer(), 'tell them doors open at 19');

        self::assertTrue($set['ok']);
        self::assertTrue($set['confirm']);
        self::assertSame(AssistantActions::RUN_MESSAGE, $set['run']);
        self::assertSame('/newsletter Doors open at 19.', $set['payload']);
        self::assertSame('/newsletter Doors open at 19.', $set['action']);

        $clear = self::check('/newsletter  CLEAR ', self::organizer());

        self::assertTrue($clear['confirm']);
        self::assertSame('/newsletter reset', $clear['payload']);
        self::assertSame('/newsletter reset', self::check('/newsletter reset', self::organizer())['payload']);
    }

    public function testTheNewsletterTextKeepsItsLineBreaks(): void
    {
        $result = self::check("/newsletter Doors   open at 19.\r\n\n\n\nBring  water. ", self::organizer());

        self::assertSame("/newsletter Doors open at 19.\n\nBring water.", $result['payload']);
        self::assertSame($result['payload'], $result['action']);
    }

    public function testATooLongNewsletterTextIsRefused(): void
    {
        $result = self::check('/newsletter ' . str_repeat('a', 2001), self::organizer());

        self::assertFalse($result['ok']);
        self::assertSame(AssistantActions::ERROR_INVALID, $result['error']);
        self::assertTrue(self::check('/newsletter ' . str_repeat('a', 2000), self::organizer())['ok']);
    }

    public function testAWordThatOnlyStartsWithNewsletterIsNotTheCommand(): void
    {
        self::assertSame(AssistantActions::ERROR_UNKNOWN, self::check('/newsletters', self::organizer())['error']);
    }

    public function testOnlyAnOrganizerMaySetThePostEventText(): void
    {
        $crew = ['organizer' => false, 'welcomeCrew' => true, 'verified' => true];

        foreach (['/followup', '/followup clear', '/followup came Thanks!'] as $action) {
            self::assertSame(
                AssistantActions::ERROR_NOT_PERMITTED,
                self::check($action, self::member())['error'],
                $action
            );
            self::assertSame(AssistantActions::ERROR_NOT_PERMITTED, self::check($action, $crew)['error'], $action);
        }
    }

    public function testShowingThePostEventTextsRunsWithoutConfirm(): void
    {
        $result = self::check('/followup', self::organizer());

        self::assertTrue($result['ok']);
        self::assertFalse($result['confirm']);
        self::assertSame(AssistantActions::RUN_MESSAGE, $result['run']);
        self::assertSame('/followup', $result['payload']);
    }

    public function testSettingAndClearingAPostEventTextAskToConfirm(): void
    {
        $set = self::check("/followup  CAME  Thanks   for coming!\r\n\n\n\nSee you.", self::organizer());

        self::assertTrue($set['ok']);
        self::assertTrue($set['confirm']);
        self::assertSame(AssistantActions::RUN_MESSAGE, $set['run']);
        self::assertSame("/followup came Thanks for coming!\n\nSee you.", $set['payload']);
        self::assertSame($set['payload'], $set['action']);

        $one = self::check('/followup missed Clear', self::organizer());
        self::assertTrue($one['confirm']);
        self::assertSame('/followup missed reset', $one['payload']);

        $both = self::check('/followup CLEAR', self::organizer());
        self::assertTrue($both['confirm']);
        self::assertSame('/followup reset', $both['payload']);
        self::assertSame('/followup came reset', self::check('/followup came reset', self::organizer())['payload']);
    }

    public function testABadPostEventTextIsRefused(): void
    {
        foreach (['/followup maybe Thanks!', '/followup came', '/followup came ' . str_repeat('a', 2001)] as $action) {
            self::assertSame(
                AssistantActions::ERROR_INVALID,
                self::check($action, self::organizer())['error'],
                substr($action, 0, 30)
            );
        }

        self::assertTrue(self::check('/followup came ' . str_repeat('a', 2000), self::organizer())['ok']);
        self::assertSame(AssistantActions::ERROR_UNKNOWN, self::check('/followups', self::organizer())['error']);
    }
}
