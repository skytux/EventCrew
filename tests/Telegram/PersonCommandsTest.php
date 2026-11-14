<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\NewcomerApproval;
use EventCrew\Telegram\PersonCommands;
use EventCrew\Web\CrewAdminPeople;

/**
 * /mark, /standing, /newcomer, /ticket, /unticket and /unban: an organizer
 * acts on one person in a private chat. The organizer is person 1, Telegram
 * user 555; the clock reads 2026-07-20 12:00.
 */
final class PersonCommandsTest extends TelegramTestCase
{
    use CommandTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommandTest();
    }

    private function command(): PersonCommands
    {
        $mailer = new Mailer(new Logger(), new EmailTemplate(new Logger()));

        return new PersonCommands(
            new PersonRepository(),
            new TaskRepository(),
            new AssignmentRepository(),
            $this->standing(),
            new CreditGrantRepository(),
            new RedemptionRepository(),
            $this->roster(),
            new CrewAdminPeople(new PersonRepository(), new CreditGrantRepository(), new RedemptionRepository(), $this->standing()),
            new NewcomerApproval(new PersonRepository(), $mailer, $this->client()),
            $this->client()
        );
    }

    public function testMarkFindsTheRecentTaskAndWritesTheMarkOnlyAfterTheTap(): void
    {
        $milla = $this->person(7, 'Milla Virtanen');

        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextResults[] = [$this->person(2, 'Kim Lee'), $milla];
        $this->wpdb->nextRows[] = $milla;
        $this->wpdb->nextResults[] = [
            ['id' => 40, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up', 'task_date' => '2026-07-18'],
            ['id' => 30, 'task_id' => 3, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-06-01'],
        ];
        $this->wpdb->nextRows[] = ['id' => 40, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];
        $this->wpdb->nextRows[] = $this->task(5);

        $this->command()->onCommand(555, 555, '/mark mila late', true, PersonCommands::MARK);

        self::assertStringStartsWith('Milla Virtanen · bar', $this->lastText());
        self::assertStringEndsWith('Mark as “Arrived late”?', $this->lastText());
        $id = $this->requestId();
        self::assertSame(['pc:y:' . $id, 'pc:x:' . $id], $this->buttonData());
        self::assertSame([], $this->wpdb->updates);

        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextRows[] = $milla;
        $this->wpdb->nextRows[] = ['id' => 40, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];
        $this->wpdb->nextRows[] = $this->task(5);

        $this->command()->onCallback($this->tap('pc:y:' . $id));

        self::assertSame('arrived_late', $this->wpdb->updates[0]['data']['status']);
        self::assertSame(1, $this->wpdb->updates[0]['data']['changed_by']);
        self::assertSame(['id' => 40], $this->wpdb->updates[0]['where']);
        self::assertStringStartsWith('✅ Milla Virtanen · bar', $this->lastText());
        self::assertStringEndsWith('Marked arrived late.', $this->lastText());
        self::assertSame([], $this->transients);
    }

    public function testMarkWithoutAWordAsksForTheMark(): void
    {
        $milla = $this->person(7, 'Milla Virtanen');

        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextResults[] = [$milla];
        $this->wpdb->nextRows[] = $milla;
        $this->wpdb->nextResults[] = [['id' => 40, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up', 'task_date' => '2026-07-18']];
        $this->wpdb->nextRows[] = ['id' => 40, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];
        $this->wpdb->nextRows[] = $this->task(5);

        $this->command()->onCommand(555, 555, '/mark Milla', true, PersonCommands::MARK);

        self::assertStringContainsString('Now: Signed up', $this->lastText());
        $id = $this->requestId();
        self::assertSame(['pc:s:' . $id . ':1', 'pc:s:' . $id . ':2', 'pc:s:' . $id . ':3', 'pc:s:' . $id . ':4', 'pc:x:' . $id], $this->buttonData());
        self::assertSame([], $this->wpdb->updates);
    }

    public function testAFutureDateCannotBeMarked(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextResults[] = [$this->person(7, 'Milla Virtanen')];
        $this->wpdb->nextRows[] = $this->person(7, 'Milla Virtanen');

        $this->command()->onCommand(555, 555, '/mark 25.7. Milla ; done', true, PersonCommands::MARK);

        self::assertSame('A task can be marked on its day or later.', $this->lastText());
        self::assertSame([], $this->transients);
    }

    public function testSomeoneWhoIsNotAnOrganizerIsRefused(): void
    {
        $this->wpdb->nextRows[] = $this->person(7, 'Milla Virtanen', ['telegram_user_id' => 555]);

        $this->command()->onCommand(555, 555, '/unban Kim', true, PersonCommands::UNBAN);

        self::assertSame('Only organizers can use /unban.', $this->lastText());
        self::assertSame([], $this->transients);
    }

    /** A member can't cancel a free-entry ticket: /unticket tells them the rule. */
    public function testAMemberWhoTriesUnticketIsToldTicketsCantBeCancelled(): void
    {
        $this->wpdb->nextRows[] = $this->person(7, 'Milla Virtanen', ['telegram_user_id' => 555]);

        $this->command()->onCommand(555, 555, '/unticket Milla', true, PersonCommands::UNTICKET);

        self::assertSame('A free-entry ticket can’t be cancelled. You can give it to someone else.', $this->lastText());
        self::assertSame([], $this->transients);
        self::assertSame([], $this->wpdb->updates);
    }

    public function testATapFromSomeoneWhoIsNoLongerAnOrganizerWritesNothing(): void
    {
        $this->transients[PersonCommands::STATE_PREFIX . 'aaaaaaaaaaaaaaaa'] = [
            'user' => 555, 'op' => 'unban', 'date' => '', 'person' => 7, 'people' => [7],
        ];
        $this->wpdb->nextRows[] = $this->person(1, 'Owner', ['telegram_user_id' => 555]);

        $this->command()->onCallback($this->tap('pc:y:aaaaaaaaaaaaaaaa'));

        self::assertSame('Only organizers can answer this.', $this->lastCallTo('answerCallbackQuery')['text']);
        self::assertSame([], $this->wpdb->updates);
        self::assertSame([], $this->transients);
    }

    public function testATapFromAnotherUserIsRefused(): void
    {
        $this->transients[PersonCommands::STATE_PREFIX . 'aaaaaaaaaaaaaaaa'] = [
            'user' => 555, 'op' => 'unban', 'date' => '', 'person' => 7, 'people' => [7],
        ];

        $this->command()->onCallback($this->tap('pc:y:aaaaaaaaaaaaaaaa', 999));

        self::assertSame('This request has expired. Please send it again.', $this->lastCallTo('answerCallbackQuery')['text']);
        self::assertSame([], $this->wpdb->updates);
    }

    public function testTheCommandIsRefusedInAGroup(): void
    {
        $this->command()->onCommand(-100, 555, '/standing Kim', false, PersonCommands::STANDING);

        self::assertSame('Use /standing in a private chat with me.', $this->lastText());
        self::assertSame([], $this->wpdb->queries);
    }

    public function testAnAmbiguousNameAsksWithButtons(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextResults[] = [$this->person(7, 'Milla Virtanen'), $this->person(8, 'Milla Korhonen'), $this->person(2, 'Kim Lee')];

        $this->command()->onCommand(555, 555, '/standing Milla', true, PersonCommands::STANDING);

        self::assertSame('Which member?', $this->lastText());
        $id = $this->requestId();
        self::assertSame(['pc:p:' . $id . ':7', 'pc:p:' . $id . ':8', 'pc:x:' . $id], $this->buttonData());

        // A member who was not offered cannot be slipped in.
        $this->wpdb->nextRows[] = $this->organizer();
        $this->command()->onCallback($this->tap('pc:p:' . $id . ':2'));
        self::assertSame(0, $this->transients[PersonCommands::STATE_PREFIX . $id]['person']);
    }

    public function testStandingShowsTheSummaryAndTheLogs(): void
    {
        $milla = $this->person(7, 'Milla Virtanen', ['banned_at' => '2026-07-01 00:00:00']);

        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextResults[] = [$milla];
        $this->wpdb->nextRows[] = $milla;

        $this->command()->onCommand(555, 555, '/standing milla', true, PersonCommands::STANDING);

        $text = $this->lastText();
        self::assertStringStartsWith("Milla Virtanen\n", $text);
        self::assertStringContainsString('0 credits · 0 tasks done', $text);
        self::assertStringContainsString('⛔ Blocked from sign-ups.', $text);
        self::assertSame([], $this->transients);
        self::assertSame([], $this->wpdb->updates);
    }

    public function testUnbanOnlyLooksAtBlockedMembers(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextResults[] = [$this->person(7, 'Milla Virtanen')];

        $this->command()->onCommand(555, 555, '/unban Milla', true, PersonCommands::UNBAN);

        self::assertSame('No blocked member called “Milla” was found.', $this->lastText());
    }

    public function testUnbanAsksAndThenLiftsTheBlock(): void
    {
        $milla = $this->person(7, 'Milla Virtanen', ['banned_at' => '2026-07-01 00:00:00']);

        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextResults[] = [$milla];
        $this->wpdb->nextRows[] = $milla;

        $this->command()->onCommand(555, 555, '/unban Milla', true, PersonCommands::UNBAN);

        self::assertSame('Lift the block on Milla Virtanen? They can sign in and sign up again.', $this->lastText());
        $id = $this->requestId();
        self::assertSame([], $this->wpdb->updates);

        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextRows[] = $milla;
        $this->wpdb->nextRows[] = $milla;

        $this->command()->onCallback($this->tap('pc:y:' . $id));

        self::assertNull($this->wpdb->updates[0]['data']['banned_at']);
        self::assertStringStartsWith("✅ Milla Virtanen\n", $this->lastText());
    }

    public function testTicketWithoutCreditIsRefusedWithoutButtons(): void
    {
        $milla = $this->person(7, 'Milla Virtanen');

        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextResults[] = [$milla];
        $this->wpdb->nextRows[] = $milla;

        $this->command()->onCommand(555, 555, '/ticket 25.7. Milla', true, PersonCommands::TICKET);

        self::assertStringStartsWith('⚠️ Milla Virtanen · ', $this->lastText());
        self::assertSame([], $this->buttonData());
        self::assertSame([], $this->transients);
    }

    public function testUnticketWithoutATicketSaysSo(): void
    {
        $milla = $this->person(7, 'Milla Virtanen');

        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextResults[] = [$milla];
        $this->wpdb->nextRows[] = $milla;

        $this->command()->onCommand(555, 555, '/unticket 25.7. Milla', true, PersonCommands::UNTICKET);

        self::assertStringStartsWith('Milla Virtanen has no free-entry ticket for ', $this->lastText());
    }

    /**
     * ✅ on /unticket only when the ticket row was really cancelled: the
     * credit goes back and the bot says so.
     */
    public function testUnticketCancelsTheTicketAfterTheTap(): void
    {
        $this->unticketTapped(1);

        $cancels = array_values(array_filter(
            $this->wpdb->queries,
            static fn (string $sql): bool => str_contains($sql, 'SET cancelled_at')
        ));
        self::assertCount(1, $cancels);
        self::assertStringContainsString('WHERE id = 40', $cancels[0]);
        self::assertStringStartsWith('✅ Milla Virtanen · ', $this->lastText());
        self::assertStringEndsWith('Credit handed back.', $this->lastText());
    }

    /**
     * The bug (Owner 2026-11-13): the bot said the ticket was removed while
     * the database kept it. When the cancel changes no row, the bot says it
     * could not be done instead of ✅.
     */
    public function testUnticketSaysItFailedWhenTheTicketWasNotCancelled(): void
    {
        $this->unticketTapped(0);

        self::assertStringStartsWith('⚠️ Milla Virtanen · ', $this->lastText());
        self::assertStringEndsWith('That change could not be applied.', $this->lastText());
        self::assertStringNotContainsString('Credit handed back.', $this->lastText());
    }

    /** The Crew app's helper box asks the same question, without buttons in the chat. */
    public function testTheWebHelperGetsTheSameAnswerWithoutATicket(): void
    {
        $milla = $this->person(7, 'Milla Virtanen');
        $this->wpdb->nextResults[] = [$milla];
        $this->wpdb->nextRows[] = $milla;

        $asked = $this->command()->prepareWeb(PersonCommands::UNTICKET, '25.7. Milla');

        self::assertFalse($asked['ok']);
        self::assertStringStartsWith('Milla Virtanen has no free-entry ticket for ', $asked['text']);
        self::assertSame('', $this->lastText());
    }

    /** Owner 2026-11-14: several matches are choice buttons on the page, each carrying its person. */
    public function testTheWebHelperOffersSeveralMatchesAsChoices(): void
    {
        $this->wpdb->nextResults[] = [$this->person(7, 'Milla Virtanen'), $this->person(8, 'Milla Koski')];

        $asked = $this->command()->prepareWeb(PersonCommands::UNTICKET, '25.7. Milla');

        self::assertFalse($asked['ok']);
        self::assertSame('Which member?', $asked['text']);
        $people = array_map(static fn (array $c): int => (int) $c['state']['person'], $asked['choices'] ?? []);
        sort($people);
        self::assertSame([7, 8], $people);
        self::assertStringContainsString('Milla Koski', implode(' ', array_column($asked['choices'], 'label')));
        self::assertSame('', $this->lastText());
    }

    /** Confirm in the helper box: changed only when the ticket row was cancelled (Owner 2026-11-13). */
    public function testTheWebHelperSaysWhetherTheTicketWasRemoved(): void
    {
        foreach ([1 => true, 0 => false] as $rows => $changed) {
            $ticket = ['id' => 40, 'person_id' => 7, 'redeemed_for' => '2026-07-25', 'cancelled_at' => null];
            $this->wpdb->nextRows[] = $this->person(7, 'Milla Virtanen');
            $this->wpdb->nextRows[] = $ticket;
            $this->wpdb->nextRows[] = $ticket;
            $this->wpdb->nextQueryResults[] = $rows;

            $done = $this->command()->runWeb(
                Person::fromRow($this->organizer()),
                ['user' => 0, 'op' => 'unticket', 'date' => '2026-07-25', 'person' => 7, 'redemption' => 40]
            );

            self::assertSame($changed, $done['changed']);
            self::assertStringStartsWith($changed ? '✅ Milla Virtanen · ' : '⚠️ Milla Virtanen · ', $done['text']);
        }
    }

    /** Taps ✅ on a waiting /unticket for Milla's ticket 40; $rows is what the cancel UPDATE changes. */
    private function unticketTapped(int $rows): void
    {
        $this->transients[PersonCommands::STATE_PREFIX . 'aaaaaaaaaaaaaaaa'] = [
            'user' => 555, 'op' => 'unticket', 'date' => '2026-07-25', 'person' => 7, 'people' => [7], 'redemption' => 40,
        ];
        $ticket = ['id' => 40, 'person_id' => 7, 'redeemed_for' => '2026-07-25', 'cancelled_at' => null];
        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextRows[] = $this->person(7, 'Milla Virtanen');
        $this->wpdb->nextRows[] = $ticket;
        $this->wpdb->nextRows[] = $ticket;
        $this->wpdb->nextQueryResults[] = $rows;

        $this->command()->onCallback($this->tap('pc:y:aaaaaaaaaaaaaaaa'));
    }

    public function testNoNewcomerWaiting(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();

        $this->command()->onCommand(555, 555, '/newcomer', true, PersonCommands::NEWCOMER);

        self::assertSame('No newcomer is waiting for an OK.', $this->lastText());
    }

    public function testWithoutANameTheUsageIsShown(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();

        $this->command()->onCommand(555, 555, '/unban', true, PersonCommands::UNBAN);

        self::assertSame(PersonCommands::usage(PersonCommands::UNBAN), $this->lastText());
    }
}
