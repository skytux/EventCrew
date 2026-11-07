<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use EventCrew\Repositories\FollowupRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\NewsletterSubscriberRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\FollowupMail;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\Newsletter;
use EventCrew\Support\NewsletterText;
use EventCrew\Support\TicketMail;
use EventCrew\Telegram\NightCommands;

/**
 * /freeentry, /stop, /newboard and /sendtickets: an organizer acts on a night
 * in a private chat. The clock reads 2026-07-20 12:00.
 */
final class NightCommandsTest extends TelegramTestCase
{
    use CommandTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommandTest();
    }

    private function command(): NightCommands
    {
        $logger = new Logger();
        $mailer = new Mailer($logger, new EmailTemplate($logger));
        $tasks = new TaskRepository();

        return new NightCommands(
            new PersonRepository(),
            $tasks,
            new FreeEntryGate(),
            new Newsletter(
                new NewsletterSubscriberRepository(),
                new PersonRepository(),
                new NewsletterText(new LlmClient($logger), $logger),
                $mailer,
                $logger
            ),
            new FollowupMail(new FollowupRepository(), $tasks, new PersonRepository(), $mailer, $logger),
            $this->boardService(),
            $this->roster(),
            new TicketMail(new TicketBuyerRepository(), new GuestRepository(), $tasks, $mailer),
            $this->client()
        );
    }

    public function testCloseFreeEntryAsksAndThenCloses(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();

        $this->command()->onCommand(555, 555, '/freeentry 25.7. close', true, NightCommands::FREE_ENTRY);

        self::assertSame('Close free entry for Sat 25 Jul? Nobody can use a credit for that night.', $this->lastText());
        $id = $this->requestId('ng:');
        self::assertSame(['ng:y:' . $id, 'ng:x:' . $id], $this->buttonData());
        self::assertArrayNotHasKey(FreeEntryGate::OPTION, $this->options);

        $this->wpdb->nextRows[] = $this->organizer();
        $this->command()->onCallback($this->tap('ng:y:' . $id));

        self::assertSame(['2026-07-25'], $this->options[FreeEntryGate::OPTION]);
        self::assertSame("✅ Sat 25 Jul\nFree entry closed for this date.", $this->lastText());
        self::assertSame([], $this->transients);
    }

    public function testTheDateMayComeAfterTheWord(): void
    {
        $this->options[FreeEntryGate::OPTION] = ['2026-07-25'];
        $this->wpdb->nextRows[] = $this->organizer();

        $this->command()->onCommand(555, 555, '/freeentry open 25.7.', true, NightCommands::FREE_ENTRY);

        self::assertSame('Reopen free entry for Sat 25 Jul?', $this->lastText());
    }

    public function testOpeningANightThatIsNotClosedSaysSo(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();

        $this->command()->onCommand(555, 555, '/freeentry 25.7. open', true, NightCommands::FREE_ENTRY);

        self::assertSame("Sat 25 Jul\nFree entry is open for this date.", $this->lastText());
        self::assertSame([], $this->transients);
    }

    public function testWithoutADateTheNextNightIsUsed(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextCols[] = ['2026-07-25'];

        $this->command()->onCommand(555, 555, '/freeentry', true, NightCommands::FREE_ENTRY);

        self::assertStringStartsWith('Close free entry for Sat 25 Jul?', $this->lastText());
    }

    public function testAnUnknownWordShowsTheUsage(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();

        $this->command()->onCommand(555, 555, '/freeentry shut it', true, NightCommands::FREE_ENTRY);

        self::assertSame(NightCommands::usage(NightCommands::FREE_ENTRY), $this->lastText());
    }

    public function testStopWithoutAPlannedAnnouncementSaysSo(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();

        $this->command()->onCommand(555, 555, '/stop announcement', true, NightCommands::STOP);

        self::assertSame('No announcement is planned.', $this->lastText());
    }

    public function testStopWithoutAWordShowsTheUsage(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();

        $this->command()->onCommand(555, 555, '/stop', true, NightCommands::STOP);

        self::assertSame(NightCommands::usage(NightCommands::STOP), $this->lastText());
    }

    public function testNewBoardWithoutAGroupSaysSo(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();

        $this->command()->onCommand(555, 555, '/newboard', true, NightCommands::NEW_BOARD);

        self::assertSame('No Telegram group is set up for the board yet.', $this->lastText());
        self::assertSame([], $this->transients);
    }

    public function testCancelWithNothingWaitingSaysSo(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();

        $this->command()->onCommand(555, 555, '/sendtickets cancel', true, NightCommands::SEND_TICKETS);

        self::assertSame('No send was waiting. It may have gone out already.', $this->lastText());
    }

    public function testSendTicketsAsksFirst(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();

        $this->command()->onCommand(555, 555, '/sendtickets 25.7.', true, NightCommands::SEND_TICKETS);

        self::assertStringStartsWith('Send the ticket emails for Sat 25 Jul that have not gone out?', $this->lastText());
        self::assertCount(2, $this->buttonData());
    }

    public function testSomeoneWhoIsNotAnOrganizerIsRefused(): void
    {
        $this->wpdb->nextRows[] = $this->person(7, 'Milla', ['telegram_user_id' => 555]);

        $this->command()->onCommand(555, 555, '/newboard', true, NightCommands::NEW_BOARD);

        self::assertSame('Only organizers can use /newboard.', $this->lastText());
    }

    public function testATapFromSomeoneWhoIsNoLongerAnOrganizerChangesNothing(): void
    {
        $this->transients[NightCommands::STATE_PREFIX . 'aaaaaaaaaaaaaaaa'] = [
            'user' => 555, 'op' => 'freeentry', 'date' => '2026-07-25', 'what' => 'close',
        ];
        $this->wpdb->nextRows[] = $this->person(1, 'Owner', ['telegram_user_id' => 555]);

        $this->command()->onCallback($this->tap('ng:y:aaaaaaaaaaaaaaaa'));

        self::assertSame('Only organizers can answer this.', $this->lastCallTo('answerCallbackQuery')['text']);
        self::assertArrayNotHasKey(FreeEntryGate::OPTION, $this->options);
        self::assertSame([], $this->transients);
    }

    public function testCancelChangesNothing(): void
    {
        $this->transients[NightCommands::STATE_PREFIX . 'aaaaaaaaaaaaaaaa'] = [
            'user' => 555, 'op' => 'freeentry', 'date' => '2026-07-25', 'what' => 'close',
        ];

        $this->command()->onCallback($this->tap('ng:x:aaaaaaaaaaaaaaaa'));

        self::assertSame('✖️ Cancelled. Nothing was changed.', $this->lastText());
        self::assertArrayNotHasKey(FreeEntryGate::OPTION, $this->options);
        self::assertSame([], $this->transients);
    }
}
