<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Support\FollowupOptOut;
use EventCrew\Support\FollowupText;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\Roles;
use EventCrew\Support\ScheduledMessages;
use EventCrew\Tests\Support\AiTextOptions;
use EventCrew\Tests\Telegram\TelegramTestCase;
use EventCrew\Web\AssistantWebAdmin;
use EventCrew\Web\CrewAdminAiText;
use EventCrew\Web\CrewAdminFollowup;
use EventCrew\Web\CrewAdminPeople;
use EventCrew\Web\CrewAdminTasks;

/**
 * The Admin tab's remaining changes as typed lines (parityA): the helper
 * box and the bot ask these questions, and nothing changes before Confirm.
 * The clock reads 2026-07-20 12:00; person 1 is the organizer.
 */
final class AssistantWebAdminTest extends TelegramTestCase
{
    use AiTextOptions;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('sanitize_email')->alias(static fn (string $e): string => trim($e));
        Functions\when('sanitize_textarea_field')->alias(static fn (string $text): string => trim($text));
        Functions\when('is_email')->alias(
            static fn (string $e): bool => 1 === preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $e)
        );
    }

    private function admin(): AssistantWebAdmin
    {
        $people = new PersonRepository();

        return new AssistantWebAdmin(
            $people,
            new CrewAdminPeople($people, new CreditGrantRepository(), new RedemptionRepository(), $this->standing()),
            // The role questions never reach the Tasks screen's object.
            (new \ReflectionClass(CrewAdminTasks::class))->newInstanceWithoutConstructor(),
            new CrewAdminFollowup(new FollowupText(new LlmClient(new Logger()), new Logger())),
            new CrewAdminAiText(),
            new ScheduledMessages()
        );
    }

    /** @return array<string, mixed> */
    private static function row(int $id, string $name, array $extra = []): array
    {
        return $extra + [
            'id' => $id,
            'email' => 'p' . $id . '@example.com',
            'display_name' => $name,
            'email_verified_at' => '2026-07-01 00:00:00',
            'is_organizer' => 0,
            'role_slugs' => '',
        ];
    }

    private static function me(): Person
    {
        return Person::fromRow(self::row(1, 'Lou H', ['is_organizer' => 1]));
    }

    public function testAMemberIsNeverAsked(): void
    {
        $member = Person::fromRow(self::row(8, 'Sam K'));

        foreach (AssistantWebAdmin::COMMANDS as $command) {
            self::assertFalse($this->admin()->prepare($member, $command, 'Sam')['ok'], $command);
        }

        self::assertFalse($this->admin()->run($member, ['op' => AssistantWebAdmin::AT_RISK_ONCE_MORE, 'person' => 8])['changed']);
        self::assertSame([], $this->wpdb->updates);
    }

    public function testOneMoreSignUpIsAskedThenGiven(): void
    {
        $this->wpdb->nextResults[] = [self::row(1, 'Lou H', ['is_organizer' => 1]), self::row(7, 'Milla Virtanen')];
        $this->wpdb->nextRows[] = self::row(7, 'Milla Virtanen');

        $asked = $this->admin()->prepare(self::me(), AssistantWebAdmin::AT_RISK_ONCE_MORE, 'Milla');

        self::assertTrue($asked['ok']);
        self::assertSame('Let Milla Virtanen sign up once more past the sign-up gate? They are told.', $asked['text']);
        self::assertSame([], $this->wpdb->updates);

        $this->wpdb->nextRows[] = self::row(7, 'Milla Virtanen');
        $done = $this->admin()->run(self::me(), $asked['state']);

        self::assertTrue($done['changed']);
        self::assertSame('Done. They may sign up once more.', $done['text']);
        self::assertSame(1, $this->wpdb->updates[0]['data']['at_risk_pass'] ?? null);
    }

    public function testSomeoneWhoMaySignUpOnceMoreAlreadyIsSaid(): void
    {
        $this->wpdb->nextRows[] = self::row(7, 'Milla Virtanen', ['at_risk_pass' => 1]);

        $asked = $this->admin()->choose(self::me(), ['op' => AssistantWebAdmin::AT_RISK_ONCE_MORE, 'person' => 7]);

        self::assertFalse($asked['ok']);
        self::assertSame('Milla Virtanen may already sign up once more.', $asked['text']);
    }

    public function testAddingAPersonIsAskedAndATakenEmailIsRefused(): void
    {
        $this->wpdb->nextRows[] = null;
        $asked = $this->admin()->prepare(self::me(), AssistantWebAdmin::ADD_PERSON, 'Milla V ; Milla@Example.com ; Bar');

        self::assertTrue($asked['ok']);
        self::assertSame("Add Milla V (milla@example.com) as a new person?\nNotes: Bar", $asked['text']);
        self::assertSame(
            ['op' => AssistantWebAdmin::ADD_PERSON, 'name' => 'Milla V', 'email' => 'milla@example.com', 'notes' => 'Bar'],
            $asked['state']
        );

        $this->wpdb->nextRows[] = self::row(7, 'Milla Virtanen');
        self::assertFalse($this->admin()->prepare(self::me(), AssistantWebAdmin::ADD_PERSON, 'Milla ; p7@example.com')['ok']);
        self::assertFalse($this->admin()->prepare(self::me(), AssistantWebAdmin::ADD_PERSON, 'Milla ; not-an-address')['ok']);
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testEditingAPersonShowsOnlyWhatChanges(): void
    {
        $this->wpdb->nextResults[] = [self::row(7, 'Milla Virtanen')];
        $this->wpdb->nextRows[] = self::row(7, 'Milla Virtanen');

        $asked = $this->admin()->prepare(self::me(), AssistantWebAdmin::EDIT_PERSON, 'Milla ; notes Bar on Fridays');

        self::assertTrue($asked['ok']);
        self::assertSame("Change Milla Virtanen?\nNotes: Bar on Fridays", $asked['text']);
        self::assertSame(['person' => 7, 'op' => AssistantWebAdmin::EDIT_PERSON, 'notes' => 'Bar on Fridays'], $asked['state']);

        self::assertFalse($this->admin()->prepare(self::me(), AssistantWebAdmin::EDIT_PERSON, 'Milla ; age 30')['ok']);
        self::assertFalse($this->admin()->prepare(self::me(), AssistantWebAdmin::EDIT_PERSON, 'Milla')['ok']);
    }

    public function testEditingARoleListsTheChangesAndKeepsTheRest(): void
    {
        $this->options[Roles::OPTION_NAME] = [
            ['slug' => 'bar', 'label' => 'Bar', 'capacity' => 2, 'description' => 'Pour drinks'],
            ['slug' => 'door', 'label' => 'Door'],
        ];

        $asked = $this->admin()->prepare(self::me(), AssistantWebAdmin::EDIT_ROLE, 'bar ; people 3 ; guestsignup off');

        self::assertTrue($asked['ok']);
        self::assertStringStartsWith('Change the role ', $asked['text']);
        self::assertStringEndsWith("\n• people 3\n• guestsignup off", $asked['text']);
        self::assertSame(['role_capacity' => 3, 'role_guest_signup' => false], $asked['state']['changes']);

        $post = (new \ReflectionMethod(AssistantWebAdmin::class, 'rolePost'))->invoke(null, 'bar', $asked['state']['changes']);

        self::assertSame('3', $post['role_capacity']);
        self::assertSame('Bar', $post['role_label']);
        self::assertSame('Pour drinks', $post['role_description']);
        self::assertArrayNotHasKey('role_guest_signup', $post);
        self::assertSame('1', $post['role_on_board']);

        $bad = $this->admin()->prepare(self::me(), AssistantWebAdmin::EDIT_ROLE, 'bar ; colour red');
        self::assertFalse($bad['ok']);
        self::assertStringStartsWith('“colour red” could not be read.', $bad['text']);
        self::assertFalse($this->admin()->prepare(self::me(), AssistantWebAdmin::EDIT_ROLE, 'bar ; count bar 2')['ok']);
    }

    public function testTheDoNotSendListIsAskedThenChanged(): void
    {
        $asked = $this->admin()->prepare(self::me(), AssistantWebAdmin::OPTOUT, 'add Anna@Example.com');

        self::assertTrue($asked['ok']);
        self::assertSame(
            'Put anna@example.com on the do-not-send list? That address gets no post-event email.',
            $asked['text']
        );
        self::assertArrayNotHasKey(FollowupOptOut::OPTION, $this->options);

        self::assertTrue($this->admin()->run(self::me(), $asked['state'])['changed']);
        self::assertArrayHasKey('anna@example.com', $this->options[FollowupOptOut::OPTION]);

        self::assertFalse($this->admin()->prepare(self::me(), AssistantWebAdmin::OPTOUT, 'add anna@example.com')['ok']);
        self::assertStringContainsString('anna@example.com', $this->admin()->optOutList());

        $remove = $this->admin()->prepare(self::me(), AssistantWebAdmin::OPTOUT, 'remove anna@example.com');
        self::assertTrue($remove['ok']);
        self::assertTrue($this->admin()->run(self::me(), $remove['state'])['changed']);
        self::assertArrayNotHasKey('anna@example.com', $this->options[FollowupOptOut::OPTION]);
        self::assertFalse($this->admin()->prepare(self::me(), AssistantWebAdmin::OPTOUT, 'remove anna@example.com')['ok']);
    }

    /**
     * On and off are easy to undo and send nothing (Owner 2026-11-14): done
     * at once, with no Confirm, as /notify is.
     */
    public function testAScheduledMessageIsSwitchedAtOnce(): void
    {
        $option = ScheduledMessages::OPTIONS[ScheduledMessages::REMINDERS];
        $done = $this->admin()->prepare(self::me(), AssistantWebAdmin::SCHEDULED, 'reminders off');

        self::assertFalse($done['ok']);
        self::assertTrue($done['ran']);
        self::assertTrue($done['changed']);
        self::assertSame([], $done['state']);
        self::assertSame('Task reminder: off.', $done['text']);
        self::assertSame('0', $this->options[$option]);

        $on = $this->admin()->prepare(self::me(), AssistantWebAdmin::SCHEDULED, 'reminders on');
        self::assertTrue($on['ran']);
        self::assertSame('1', $this->options[$option]);

        // Sending a message still asks; switched off, the newsletter says so.
        $newsletter = $this->admin()->prepare(self::me(), AssistantWebAdmin::SCHEDULED, 'newsletter send');
        self::assertFalse($newsletter['ok']);
        self::assertSame('"Newsletter" is switched off. Switch it on to send it now.', $newsletter['text']);

        // A message sent to one person when its time comes has no "send now".
        $send = $this->admin()->prepare(self::me(), AssistantWebAdmin::SCHEDULED, 'takeover send');
        self::assertFalse($send['ok']);
        self::assertFalse($this->admin()->prepare(self::me(), AssistantWebAdmin::SCHEDULED, 'nosuch on')['ok']);
        self::assertStringContainsString('reminders', $this->admin()->scheduledList());
    }

    public function testAnAiTextIsFoundByDateAndNameAndAsked(): void
    {
        $this->options = self::aiTextOptions(['reminder'], 42, ['reminder' => ['en' => 'See you!', 'fi' => 'Nähdään!']]);

        $asked = $this->admin()->prepare(self::me(), AssistantWebAdmin::AI_TEXT, '1.8. reminder fi ; Tervetuloa');

        self::assertTrue($asked['ok']);
        self::assertStringEndsWith("as you wrote it?\n\nTervetuloa", $asked['text']);
        self::assertSame(
            ['op' => AssistantWebAdmin::AI_TEXT, 'event' => 42, 'key' => 'reminder', 'lang' => 'fi', 'text' => 'Tervetuloa'],
            $asked['state']
        );

        $back = $this->admin()->prepare(self::me(), AssistantWebAdmin::AI_TEXT, '1.8. reminder ai');
        self::assertTrue($back['ok']);
        self::assertTrue($back['state']['ai']);

        self::assertFalse($this->admin()->prepare(self::me(), AssistantWebAdmin::AI_TEXT, '2.8. reminder ai')['ok']);
        self::assertFalse($this->admin()->prepare(self::me(), AssistantWebAdmin::AI_TEXT, '1.8. nothing ai')['ok']);
        self::assertFalse($this->admin()->prepare(self::me(), AssistantWebAdmin::AI_TEXT, '1.8. reminder xx ; Hi')['ok']);
    }
}
