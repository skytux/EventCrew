<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\WebSession;
use EventCrew\Telegram\BoardService;
use EventCrew\Tests\TestCase;
use EventCrew\Web\AssistantWebActions;
use EventCrew\Web\AssistantWebController;
use EventCrew\Web\SessionPerson;

/**
 * The Crew app page's helper box: who may ask, how much, and what happens
 * with Confirm. The helper itself is a stand-in here (AssistantServiceTest
 * covers answerWeb() with the model mocked).
 */
final class AssistantWebControllerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, mixed> */
    private array $transients = [];

    private FakeWebAssistant $assistant;

    private FakeTaskSignup $signup;

    private FakeWebChanges $changes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [
            AssistantSettings::ENABLED_OPTION => true,
            AssistantSettings::URL_OPTION => 'https://llm.example.test/v1',
            AssistantSettings::MODEL_OPTION => 'test-model',
            BoardService::USERNAME_OPTION => 'crew_bot',
        ];
        $this->transients = [];
        $this->assistant = new FakeWebAssistant();
        $this->signup = new FakeTaskSignup();
        $this->changes = new FakeWebChanges();

        Functions\when('get_option')->alias(fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default);
        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->transients[$key] = $value;

            return true;
        });
        Functions\when('get_transient')->alias(fn (string $key): mixed => $this->transients[$key] ?? false);
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);

            return true;
        });
    }

    private function controller(): AssistantWebController
    {
        return new AssistantWebController(
            $this->assistant,
            $this->signup,
            new TaskRepository(),
            new SessionPerson(new PersonRepository()),
            $this->changes
        );
    }

    private function person(int $id = 7, ?int $telegram = 555): Person
    {
        return Person::fromRow(['id' => $id, 'email' => 'sam@example.com', 'telegram_user_id' => $telegram]);
    }

    /**
     * @param array<string, string> $posted
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function post(array $posted, ?Person $person = null): array
    {
        $person ??= $this->person();

        return $this->controller()->handle($person, $posted + ['csrf' => WebSession::csrfToken($person->id)]);
    }

    /** @param array<string, mixed> $body */
    private static function text(array $body): string
    {
        return implode('', array_map(static fn (array $s): string => $s['t'], $body['reply']));
    }

    /** Asks for /join 12 and returns the kept question's id. */
    private function askToJoin(?Person $person = null): string
    {
        $this->assistant->next = FakeWebAssistant::answer('Sure, the bar on Saturday.', '/join 12', true);
        [, $body] = $this->post(['op' => 'ask', 'text' => 'sign me up for the bar'], $person);

        return (string) $body['action']['id'];
    }

    public function testNoSessionIsRefused(): void
    {
        [$status, $body] = $this->controller()->handle(null, ['op' => 'ask', 'text' => 'hi', 'csrf' => 'x']);

        self::assertSame(401, $status);
        self::assertSame('session', $body['error']);
        self::assertSame([], $this->assistant->asked);
    }

    public function testAWrongCsrfTokenIsRefused(): void
    {
        [$status, $body] = $this->controller()->handle($this->person(), ['op' => 'ask', 'text' => 'hi', 'csrf' => 'nope']);

        self::assertSame(403, $status);
        self::assertSame('csrf', $body['error']);
        self::assertSame([], $this->assistant->asked);
    }

    public function testTheTokenOfAnotherPersonIsRefused(): void
    {
        [$status] = $this->controller()->handle(
            $this->person(7),
            ['op' => 'ask', 'text' => 'hi', 'csrf' => WebSession::csrfToken(8)]
        );

        self::assertSame(403, $status);
    }

    public function testWithTheHelperOffNothingIsAskedAndThePageHasNoBox(): void
    {
        $this->options[AssistantSettings::ENABLED_OPTION] = false;

        [$status, $body] = $this->post(['op' => 'ask', 'text' => 'hi']);

        self::assertSame(404, $status);
        self::assertSame('off', $body['error']);
        self::assertSame([], $this->assistant->asked);
        self::assertNull(AssistantWebController::view($this->person()));
    }

    public function testWithTheHelperOnThePageGetsTheBoxInThePersonsSpeechLanguage(): void
    {
        $finn = Person::fromRow(['id' => 7, 'email' => 'sam@example.com', 'language' => 'fi']);

        self::assertSame(
            ['action' => AssistantWebController::ACTION, 'speech' => 'fi-FI'],
            AssistantWebController::view($finn)
        );
    }

    public function testAnEmptyOrTooLongMessageIsRefused(): void
    {
        [$status, $body] = $this->post(['op' => 'ask', 'text' => '   ']);
        self::assertSame([400, 'empty'], [$status, $body['error']]);

        [$status, $body] = $this->post(['op' => 'ask', 'text' => str_repeat('a', AssistantWebController::MAX_CHARS + 1)]);
        self::assertSame([400, 'too_long'], [$status, $body['error']]);

        self::assertSame([], $this->assistant->asked);
    }

    public function testFiveHundredCharactersAreFine(): void
    {
        $this->assistant->next = FakeWebAssistant::answer('Ok.');

        [$status] = $this->post(['op' => 'ask', 'text' => str_repeat('ä', AssistantWebController::MAX_CHARS)]);

        self::assertSame(200, $status);
    }

    public function testAnUnknownOpIsRefused(): void
    {
        [$status, $body] = $this->post(['op' => 'delete']);

        self::assertSame([400, 'op'], [$status, $body['error']]);
    }

    public function testAPlainAnswerComesBackAsTextWithItsLinks(): void
    {
        $this->assistant->next = FakeWebAssistant::answer('See https://example.test/faq. Or ask.');

        [$status, $body] = $this->post(['op' => 'ask', 'text' => 'where is the faq?']);

        self::assertSame(200, $status);
        self::assertNull($body['action']);
        self::assertSame(
            [
                ['t' => 'See '],
                ['t' => 'https://example.test/faq', 'u' => 'https://example.test/faq'],
                ['t' => '.'],
                ['t' => ' Or ask.'],
            ],
            $body['reply']
        );
        self::assertSame([[7, 'where is the faq?']], $this->assistant->asked);
    }

    public function testTheLimitAnswerIsPassedOn(): void
    {
        $this->assistant->next = ['status' => 'limited', 'text' => 'That is all.', 'lang' => 'en', 'action' => null];

        [$status, $body] = $this->post(['op' => 'ask', 'text' => 'hi']);

        self::assertSame(200, $status);
        self::assertSame('limited', $body['status']);
        self::assertSame('That is all.', self::text($body));
    }

    public function testModelMarkupStaysText(): void
    {
        $this->assistant->next = FakeWebAssistant::answer('<img src=x onerror=alert(1)> javascript:alert(1)');

        [, $body] = $this->post(['op' => 'ask', 'text' => 'hi']);

        // One plain-text segment: the page puts it in with textContent, and
        // nothing that is not http(s) ever becomes a link.
        self::assertSame([['t' => '<img src=x onerror=alert(1)> javascript:alert(1)']], $body['reply']);
    }

    public function testASignUpIsAskedFirstAndKeptForThisPerson(): void
    {
        $id = $this->askToJoin();

        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $id);
        self::assertSame(
            ['person' => 7, 'action' => '/join 12'],
            $this->transients[AssistantWebController::PENDING_PREFIX . $id]
        );
        self::assertSame([], $this->signup->ran, 'Nothing runs before Confirm.');
    }

    public function testConfirmRunsTheSignUpOnceAndNotesIt(): void
    {
        $id = $this->askToJoin();
        $this->assistant->recheck = '/join 12';

        [$status, $body] = $this->post(['op' => 'confirm', 'id' => $id]);

        self::assertSame(200, $status);
        self::assertTrue($body['changed']);
        self::assertSame('claimed', $body['code']);
        self::assertSame([['join', 7, 12]], $this->signup->ran);
        self::assertSame([[7, '/join 12', true]], $this->assistant->noted);

        // A second Confirm (a double tap, a replay) finds nothing.
        [, $again] = $this->post(['op' => 'confirm', 'id' => $id]);

        self::assertSame('expired', $again['code']);
        self::assertCount(1, $this->signup->ran);
    }

    public function testCancellingATaskIsAskedAndRunsTheCancel(): void
    {
        $this->assistant->next = FakeWebAssistant::answer('Cancel the bar?', '/leave 12');
        [, $body] = $this->post(['op' => 'ask', 'text' => 'I cannot come']);
        $this->assistant->recheck = '/leave 12';

        self::assertSame(AssistantWebActions::CONFIRM, $body['action']['kind']);

        $this->post(['op' => 'confirm', 'id' => (string) $body['action']['id']]);

        self::assertSame([['leave', 7, 12]], $this->signup->ran);
    }

    public function testSomeoneElsesQuestionCannotBeConfirmed(): void
    {
        $id = $this->askToJoin();
        $this->assistant->recheck = '/join 12';

        [, $body] = $this->post(['op' => 'confirm', 'id' => $id], $this->person(8));

        self::assertSame('expired', $body['code']);
        self::assertSame([], $this->signup->ran);
        self::assertArrayHasKey(AssistantWebController::PENDING_PREFIX . $id, $this->transients, 'Still Sam\'s to answer.');
    }

    public function testAnExpiredOrMadeUpIdIsRefused(): void
    {
        $id = $this->askToJoin();
        unset($this->transients[AssistantWebController::PENDING_PREFIX . $id]);

        [, $expired] = $this->post(['op' => 'confirm', 'id' => $id]);
        [, $madeUp] = $this->post(['op' => 'confirm', 'id' => '../../etc']);

        self::assertSame('expired', $expired['code']);
        self::assertSame('This question has expired. Please write again.', self::text($expired));
        self::assertSame('expired', $madeUp['code']);
        self::assertSame([], $this->signup->ran);
    }

    public function testConfirmChecksAgainAndRefusesWhatCanNoLongerBeDone(): void
    {
        $id = $this->askToJoin();
        $this->assistant->recheck = null;

        [, $body] = $this->post(['op' => 'confirm', 'id' => $id]);

        self::assertSame('refused', $body['code']);
        self::assertSame([], $this->signup->ran);
    }

    public function testCancelDropsTheQuestion(): void
    {
        $id = $this->askToJoin();

        [, $body] = $this->post(['op' => 'cancel', 'id' => $id]);

        self::assertSame('cancelled', $body['code']);
        self::assertSame([], $this->transients);
        self::assertSame([], $this->signup->ran);
    }

    public function testWhatThePageHasOpensThatPart(): void
    {
        $this->assistant->next = FakeWebAssistant::answer('Here are your tickets.', '/me tickets');

        [, $body] = $this->post(['op' => 'ask', 'text' => 'my tickets']);

        self::assertSame(
            ['kind' => AssistantWebActions::GOTO, 'targets' => ['eventcrew-my-tickets', 'eventcrew-panel-tickets']],
            $body['action']
        );
        self::assertSame([[7, '/me tickets', false]], $this->assistant->noted);
    }

    public function testWhatThePageDoesNotHaveGetsNoActionAndNoTelegram(): void
    {
        $this->assistant->next = FakeWebAssistant::answer('The roster is not on your page.', '/roster');

        [, $body] = $this->post(['op' => 'ask', 'text' => 'who works on Saturday?']);

        self::assertNull($body['action']);
        self::assertSame('The roster is not on your page.', self::text($body));
    }

    public function testAnOrganizerIsTakenToTheAdminScreen(): void
    {
        $this->assistant->next = FakeWebAssistant::answer('On the roster.', '/roster');
        $organizer = Person::fromRow(['id' => 7, 'email' => 'sam@example.com', 'is_organizer' => 1]);

        [, $body] = $this->post(['op' => 'ask', 'text' => 'who works on Saturday?'], $organizer);

        self::assertSame(AssistantWebActions::GOTO, $body['action']['kind']);
        self::assertSame(['eventcrew-admin-roster', 'eventcrew-panel-admin'], $body['action']['targets']);
        self::assertStringNotContainsString('Telegram', self::text($body));
    }

    private function organizer(): Person
    {
        return Person::fromRow(['id' => 7, 'email' => 'sam@example.com', 'is_organizer' => 1]);
    }

    public function testAnOrganizersChangeIsAskedWithTheCommandsOwnQuestion(): void
    {
        $this->assistant->next = FakeWebAssistant::answer('Done, the ticket is gone.', '/unticket 14.11. Lou H');

        [, $body] = $this->post(['op' => 'ask', 'text' => 'remove my free ticket'], $this->organizer());

        self::assertSame(AssistantWebActions::CONFIRM, $body['action']['kind']);
        self::assertSame('', $body['action']['summary']);
        // The model's "Done" is not shown: the question is the answer.
        self::assertSame('Take the ticket away?', self::text($body));
        self::assertSame([], $this->changes->ran);
        $kept = $this->transients[AssistantWebController::PENDING_PREFIX . $body['action']['id']];
        self::assertSame(['person' => 7, 'action' => '/unticket 14.11. Lou H', 'state' => ['person' => 9]], $kept);
    }

    public function testConfirmRunsTheChangeAndShowsTheRealOutcome(): void
    {
        $this->assistant->next = FakeWebAssistant::answer('Sure.', '/unticket 14.11. Lou H');
        [, $asked] = $this->post(['op' => 'ask', 'text' => 'remove my free ticket'], $this->organizer());
        $this->assistant->recheck = '/unticket 14.11. Lou H';

        [, $body] = $this->post(['op' => 'confirm', 'id' => $asked['action']['id']], $this->organizer());

        self::assertTrue($body['changed']);
        self::assertSame('changed', $body['code']);
        self::assertSame('✅ Ticket taken away.', self::text($body));
        self::assertSame([[7, '/unticket 14.11. Lou H', ['person' => 9]]], $this->changes->ran);
        self::assertSame([[7, '/unticket 14.11. Lou H', true]], $this->assistant->noted);

        [, $again] = $this->post(['op' => 'confirm', 'id' => $asked['action']['id']], $this->organizer());
        self::assertSame('expired', $again['code']);
        self::assertCount(1, $this->changes->ran);
    }

    public function testAChangeThatDidNotGoThroughSaysSo(): void
    {
        $this->changes->outcome = ['changed' => false, 'text' => '⚠️ Nothing was changed.'];
        $this->assistant->next = FakeWebAssistant::answer('Sure.', '/unticket Lou H');
        [, $asked] = $this->post(['op' => 'ask', 'text' => 'remove my free ticket'], $this->organizer());
        $this->assistant->recheck = '/unticket Lou H';

        [, $body] = $this->post(['op' => 'confirm', 'id' => $asked['action']['id']], $this->organizer());

        self::assertFalse($body['changed']);
        self::assertSame('unchanged', $body['code']);
        self::assertSame('⚠️ Nothing was changed.', self::text($body));
        self::assertSame([], $this->assistant->noted);
    }

    public function testAChangeThatCannotBeDoneHasNoButtons(): void
    {
        $this->changes->prepared = ['ok' => false, 'text' => 'Several match: Lou H, Lou K. Write it more exactly.', 'state' => []];
        $this->assistant->next = FakeWebAssistant::answer('Sure.', '/unticket Lou');

        [, $body] = $this->post(['op' => 'ask', 'text' => 'remove Lou\'s ticket'], $this->organizer());

        self::assertNull($body['action']);
        self::assertSame('Several match: Lou H, Lou K. Write it more exactly.', self::text($body));
        self::assertSame([], $this->transients);
    }

    public function testAChangeThePageOnlyOpensSaysItIsNotDone(): void
    {
        $this->assistant->next = FakeWebAssistant::answer('Given.', 'menu:gift');

        [, $body] = $this->post(['op' => 'ask', 'text' => 'give Sam credit'], $this->organizer());

        self::assertSame(AssistantWebActions::GOTO, $body['action']['kind']);
        self::assertSame("Not done yet: open it here.\nGiven.", self::text($body));
    }

    /** Lou 2026-11-14: several matches get one button each, then the question with Confirm. */
    public function testSeveralMatchesAreChoiceButtonsThenTheQuestion(): void
    {
        $this->changes->prepared = ['ok' => false, 'text' => 'Which Sam?', 'state' => [], 'choices' => [
            ['label' => 'Sam K', 'state' => ['person' => 5]],
            ['label' => 'Sam L', 'state' => ['person' => 6]],
        ]];
        $this->assistant->next = FakeWebAssistant::answer('Sure.', '/mark 14.11. Sam');

        [, $asked] = $this->post(['op' => 'ask', 'text' => 'mark Sam no-show'], $this->organizer());

        self::assertSame('choose', $asked['action']['kind']);
        self::assertSame(['Sam K', 'Sam L'], $asked['action']['choices']);
        self::assertSame('Which Sam?', self::text($asked));

        // Choice buttons are never a Confirm.
        $this->assistant->recheck = '/mark 14.11. Sam';
        [, $refused] = $this->post(['op' => 'confirm', 'id' => $asked['action']['id']], $this->organizer());
        self::assertSame('refused', $refused['code']);
        self::assertSame([], $this->changes->ran);

        [, $again] = $this->post(['op' => 'ask', 'text' => 'mark Sam no-show'], $this->organizer());
        $this->assistant->next = FakeWebAssistant::answer('Sure.', '/mark 14.11. Sam');
        [, $chosen] = $this->post(['op' => 'choose', 'id' => $again['action']['id'], 'choice' => '1'], $this->organizer());

        self::assertSame([['person' => 6]], $this->changes->choseStates);
        self::assertSame(AssistantWebActions::CONFIRM, $chosen['action']['kind']);
        self::assertSame('Mark Sam as no-show?', self::text($chosen));

        [, $done] = $this->post(['op' => 'confirm', 'id' => $chosen['action']['id']], $this->organizer());
        self::assertTrue($done['changed']);
        self::assertSame([[7, '/mark 14.11. Sam', ['person' => 5]]], $this->changes->ran);
    }

    public function testAChoiceOfSomeoneElseOrOutOfRangeIsRefused(): void
    {
        $this->changes->prepared = ['ok' => false, 'text' => 'Which Sam?', 'state' => [], 'choices' => [
            ['label' => 'Sam K', 'state' => ['person' => 5]],
        ]];
        $this->assistant->next = FakeWebAssistant::answer('Sure.', '/mark 14.11. Sam');
        [, $asked] = $this->post(['op' => 'ask', 'text' => 'mark Sam'], $this->organizer());
        $this->assistant->recheck = '/mark 14.11. Sam';

        [, $body] = $this->post(['op' => 'choose', 'id' => $asked['action']['id'], 'choice' => '3'], $this->organizer());

        self::assertSame('refused', $body['code']);
        self::assertSame([], $this->changes->choseStates);
    }

    /** Lou 2026-11-14: "give myself a ticket" is asked and spent on the page, for any member. */
    public function testAMemberSpendsTheirOwnCreditOnConfirm(): void
    {
        $this->assistant->next = FakeWebAssistant::answer('You can get one on the Tickets tab.', 'menu:ticket 2026-11-14');

        [, $asked] = $this->post(['op' => 'ask', 'text' => 'give myself a ticket'], $this->person());

        self::assertSame(AssistantWebActions::CONFIRM, $asked['action']['kind']);
        self::assertStringNotContainsString('Tickets tab', self::text($asked));
        self::assertSame([], $this->changes->ran);

        $this->assistant->recheck = 'menu:ticket 2026-11-14';
        [, $body] = $this->post(['op' => 'confirm', 'id' => $asked['action']['id']], $this->person());

        self::assertTrue($body['changed']);
        self::assertSame([[7, 'menu:ticket 2026-11-14', ['person' => 9]]], $this->changes->ran);
    }

    /** Lou 2026-11-14: one Open my ticket button after a credit is spent; the address is in no text or note. */
    public function testASpentCreditGetsOneOpenTicketButton(): void
    {
        $url = 'https://site.test/wp-json/eventcrew/v1/ticket?token=abc';
        $this->changes->outcome = ['changed' => true, 'text' => '✅ Credit spent on Sat 14 Nov.', 'ticket' => $url];
        $this->assistant->next = FakeWebAssistant::answer('Sure.', 'menu:ticket 2026-11-14');
        [, $asked] = $this->post(['op' => 'ask', 'text' => 'give myself a ticket'], $this->person());
        $this->assistant->recheck = 'menu:ticket 2026-11-14';

        [, $body] = $this->post(['op' => 'confirm', 'id' => $asked['action']['id']], $this->person());

        self::assertSame(['kind' => 'ticket', 'label' => 'Open my ticket', 'url' => $url], $body['action']);
        self::assertStringNotContainsString('token', self::text($body));
        self::assertStringNotContainsString('token', (string) json_encode($this->assistant->noted));
    }

    public function testAChangeWithoutATicketHasNoButton(): void
    {
        $this->changes->outcome = ['changed' => false, 'text' => '⚠️ No credit.', 'ticket' => 'https://site.test/x'];
        $this->assistant->next = FakeWebAssistant::answer('Sure.', 'menu:ticket 2026-11-14');
        [, $asked] = $this->post(['op' => 'ask', 'text' => 'give myself a ticket'], $this->person());
        $this->assistant->recheck = 'menu:ticket 2026-11-14';

        [, $body] = $this->post(['op' => 'confirm', 'id' => $asked['action']['id']], $this->person());

        self::assertArrayNotHasKey('action', $body);
    }
}
