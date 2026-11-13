<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Support\FollowupOptOut;
use EventCrew\Support\FollowupText;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Telegram\AdminChangeCommands;
use EventCrew\Web\AssistantWebAdmin;
use EventCrew\Web\AssistantWebPeople;
use EventCrew\Web\CrewAdminAiText;
use EventCrew\Web\CrewAdminController;
use EventCrew\Web\CrewAdminFollowup;
use EventCrew\Web\CrewAdminPeople;
use EventCrew\Web\CrewAdminTasks;

/**
 * The Crew app Admin tab's changes as private-chat commands (parityA): an
 * organizer types one, the bot asks with ✅ / ✖️ and runs the Admin tab's
 * method only after ✅. The organizer is person 1, Telegram user 555.
 */
final class AdminChangeCommandsTest extends TelegramTestCase
{
    use CommandTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommandTest();
        Functions\when('is_email')->alias(
            static fn (string $e): bool => 1 === preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $e)
        );
    }

    private function command(): AdminChangeCommands
    {
        $people = new PersonRepository();
        $screen = new CrewAdminPeople($people, new CreditGrantRepository(), new RedemptionRepository(), $this->standing());
        // The questions tested here never reach the Admin tab's controller or Tasks screen.
        $admin = (new \ReflectionClass(CrewAdminController::class))->newInstanceWithoutConstructor();
        $tasks = (new \ReflectionClass(CrewAdminTasks::class))->newInstanceWithoutConstructor();

        return new AdminChangeCommands(
            $people,
            static fn (): AssistantWebPeople => new AssistantWebPeople($people, $admin, $screen),
            static fn (): AssistantWebAdmin => new AssistantWebAdmin(
                $people,
                $screen,
                $tasks,
                new CrewAdminFollowup(new FollowupText(new LlmClient(new Logger()), new Logger())),
                new CrewAdminAiText()
            ),
            $this->client()
        );
    }

    public function testAMemberIsRefusedAndAGroupIsSentToThePrivateChat(): void
    {
        $this->wpdb->nextRows[] = $this->person(7, 'Milla Virtanen', ['telegram_user_id' => 555]);
        $this->command()->onCommand(555, 555, '/oncemore Kim', true, AssistantWebAdmin::AT_RISK_ONCE_MORE);
        self::assertSame('Only organizers can use /oncemore.', $this->lastText());

        $this->command()->onCommand(-100, 555, '/optout', false, AssistantWebAdmin::OPTOUT);
        self::assertSame('Use /optout in a private chat with me.', $this->lastText());
        self::assertSame([], $this->transients);
    }

    public function testWithoutWordsTheCommandShowsHowToWriteIt(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();
        $this->command()->onCommand(555, 555, '/gift', true, AdminChangeCommands::GIFT);

        self::assertStringStartsWith('Give credits: /gift name ; credits ; reason', $this->lastText());
        self::assertSame([], $this->transients);
    }

    public function testOneMoreSignUpIsGivenOnlyAfterTheTap(): void
    {
        $milla = $this->person(7, 'Milla Virtanen');
        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextResults[] = [$this->organizer(), $milla];
        $this->wpdb->nextRows[] = $milla;

        $this->command()->onCommand(555, 555, '/oncemore milla', true, AssistantWebAdmin::AT_RISK_ONCE_MORE);

        self::assertSame('Let Milla Virtanen sign up once more past the sign-up gate? They are told.', $this->lastText());
        $id = $this->requestId(AdminChangeCommands::PREFIX);
        self::assertSame(['ac:y:' . $id, 'ac:x:' . $id], $this->buttonData());
        self::assertSame([], $this->wpdb->updates);

        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextRows[] = $milla;
        $this->command()->onCallback($this->tap('ac:y:' . $id));

        self::assertSame(1, $this->wpdb->updates[0]['data']['at_risk_pass'] ?? null);
        self::assertStringContainsString('Done. They may sign up once more.', $this->lastText());
        self::assertSame([], $this->transients);
    }

    public function testDeletingWarnsAndSeveralMatchesAreButtons(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextResults[] = [$this->person(5, 'Sam K'), $this->person(6, 'Sam L')];

        $this->command()->onCommand(555, 555, '/deleteperson Sam', true, AdminChangeCommands::DELETE);

        self::assertSame('Which member?', $this->lastText());
        $id = $this->requestId(AdminChangeCommands::PREFIX);
        self::assertSame(['ac:p:' . $id . ':0', 'ac:p:' . $id . ':1', 'ac:x:' . $id], $this->buttonData());

        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextRows[] = $this->person(6, 'Sam L');
        $this->command()->onCallback($this->tap('ac:p:' . $id . ':1'));

        self::assertSame('⚠️ Delete Sam L? Their data and history are erased. This cannot be undone.', $this->lastText());
        self::assertStringStartsWith('✅ Delete', (string) ($this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'][0][0]['text'] ?? ''));
        self::assertSame([], $this->wpdb->deletes);
    }

    public function testTheDoNotSendListIsShownAndChangedAfterTheTap(): void
    {
        $this->options[FollowupOptOut::OPTION] = ['old@example.com' => '2026-07-01 10:00:00'];

        $this->wpdb->nextRows[] = $this->organizer();
        $this->command()->onCommand(555, 555, '/optout', true, AssistantWebAdmin::OPTOUT);
        self::assertStringContainsString('• old@example.com', $this->lastText());

        $this->wpdb->nextRows[] = $this->organizer();
        $this->command()->onCommand(555, 555, '/optout add new@example.com', true, AssistantWebAdmin::OPTOUT);
        self::assertStringStartsWith('Put new@example.com on the do-not-send list?', $this->lastText());
        self::assertArrayNotHasKey('new@example.com', $this->options[FollowupOptOut::OPTION]);

        $this->wpdb->nextRows[] = $this->organizer();
        $this->command()->onCallback($this->tap('ac:y:' . $this->requestId(AdminChangeCommands::PREFIX)));
        self::assertArrayHasKey('new@example.com', $this->options[FollowupOptOut::OPTION]);
    }
}
