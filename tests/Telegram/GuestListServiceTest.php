<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\DoorAccess;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\TicketMail;
use EventCrew\Telegram\BoardRenderer;
use EventCrew\Telegram\DayPicker;
use EventCrew\Telegram\GuestListService;

final class GuestListServiceTest extends TelegramTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_the_title')->justReturn('');
    }

    private function asker(bool $isOrganizer): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'door@example.com',
            'display_name' => 'Door',
            'is_organizer' => $isOrganizer ? 1 : 0,
            'telegram_user_id' => 555,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function guest(int $id, string $name, int $plusOnes = 0, string $note = ''): array
    {
        return [
            'id' => $id,
            'event_date' => '2026-08-01',
            'name' => $name,
            'plus_ones' => $plusOnes,
            'note' => $note,
            'added_by' => 7,
        ];
    }

    public function testCrewCannotChangeTheList(): void
    {
        $this->asker(false);

        $this->guestListService()->onGuestCommand(555, 555, true, 'add 1.8. Anna');

        self::assertSame('Only organizers can change the guest list.', $this->lastCallTo('sendMessage')['text']);
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testAnOrganizerAddsAGuestThroughTheRouter(): void
    {
        $this->asker(true);
        $this->wpdb->nextResults[] = [$this->guest(1, 'Anna Virtanen', 2, 'DJ’s partner')]; // render
        $this->wpdb->nextCols[] = [];                                                     // nearbyDates

        $this->updateRouter()->dispatch([
            'update_id' => 1,
            'message' => [
                'text' => '/door add 1.8. Anna Virtanen +2 ; DJ’s partner',
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 555],
            ],
        ]);

        $row = $this->wpdb->inserts[0]['data'];
        self::assertSame('2026-08-01', $row['event_date']);
        self::assertSame('Anna Virtanen', $row['name']);
        self::assertSame(2, $row['plus_ones']);
        self::assertSame('DJ’s partner', $row['note']);
        self::assertSame(7, $row['added_by']);

        $sent = $this->lastCallTo('sendMessage');
        self::assertSame(555, $sent['chat_id']);
        self::assertStringStartsWith('✅ Added Anna Virtanen to Sat 1 Aug.', $sent['text']);
        self::assertStringContainsString('1 guest, 3 people in all', $sent['text']);
        self::assertStringContainsString('1. Anna Virtanen +2 (DJ’s partner)', $sent['text']);
    }

    /** Decision 3: an email address in /door add is stored, and the ticket mail run is queued. */
    public function testAGuestAddedWithAnEmailIsStoredWithItAndTheMailRunIsQueued(): void
    {
        $scheduled = [];
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->alias(function (int $time, string $hook) use (&$scheduled): bool {
            $scheduled[] = $hook;

            return true;
        });
        $this->asker(true);
        $this->wpdb->nextResults[] = [$this->guest(1, 'Anna')]; // render
        $this->wpdb->nextCols[] = [];                           // nearbyDates

        $this->guestListService()->onGuestCommand(555, 555, true, 'add 1.8. Anna anna@example.com');

        self::assertSame('Anna', $this->wpdb->inserts[0]['data']['name']);
        self::assertSame('anna@example.com', $this->wpdb->inserts[0]['data']['email']);
        // The send waits TicketMail::DELAY first: its start event is queued.
        self::assertSame([TicketMail::START_HOOK], $scheduled);
    }

    public function testAGuestWithAnInvalidEmailIsNotAdded(): void
    {
        $this->asker(true);

        $this->guestListService()->onGuestCommand(555, 555, true, 'add 1.8. Anna anna@');

        self::assertSame([], $this->wpdb->inserts);
        self::assertStringContainsString('That email address is not valid.', $this->lastCallTo('sendMessage')['text']);
    }

    /** Decision 4: removing a guest who was sent a ticket emails "Your ticket is cancelled". */
    public function testRemovingAGuestWithASentTicketSendsTheCancelEmail(): void
    {
        $mails = [];
        Functions\when('get_option')->justReturn(false);
        Functions\when('is_email')->alias(static fn (string $email): bool => str_contains($email, '@'));
        Functions\when('wp_mail')->alias(function (string $to, string $subject) use (&$mails): bool {
            $mails[] = ['to' => $to, 'subject' => $subject];

            return true;
        });
        $this->asker(true);
        $sent = ['email' => 'bo@example.com', 'ticket_mail_status' => 'sent'] + $this->guest(5, 'Bo');
        $this->wpdb->nextResults[] = [$this->guest(4, 'Anna'), $sent]; // numbered
        $this->wpdb->nextResults[] = [$this->guest(4, 'Anna')];        // render
        $this->wpdb->nextCols[] = [];                                  // nearbyDates

        $service = new GuestListService(
            new GuestRepository(),
            new DoorAccess(new TaskRepository(), new AssignmentRepository()),
            new PersonRepository(),
            $this->client(),
            new TicketMail(
                new TicketBuyerRepository(),
                new GuestRepository(),
                new TaskRepository(),
                new Mailer(new Logger(), new EmailTemplate(new Logger()))
            )
        );
        $service->onGuestCommand(555, 555, true, 'remove 1.8. 2');

        self::assertSame(['id' => 5], $this->wpdb->deletes[0]['where']);
        self::assertCount(1, $mails);
        self::assertSame('bo@example.com', $mails[0]['to']);
        self::assertStringContainsString('Your ticket is cancelled', $mails[0]['subject']);
    }

    public function testEditChangesOnlyWhatIsGiven(): void
    {
        $this->asker(true);
        $this->wpdb->nextResults[] = [$this->guest(4, 'Anna', 0, 'press')]; // numbered
        $this->wpdb->nextResults[] = [$this->guest(4, 'Anna', 3, 'press')]; // render
        $this->wpdb->nextCols[] = [];                                       // nearbyDates

        $this->guestListService()->onGuestCommand(555, 555, true, 'edit 1.8. 1 +3');

        self::assertSame(
            ['name' => 'Anna', 'plus_ones' => 3, 'note' => 'press'],
            $this->wpdb->updates[0]['data']
        );
        self::assertSame(['id' => 4], $this->wpdb->updates[0]['where']);
        self::assertStringStartsWith('✏️ Changed Anna.', $this->lastCallTo('sendMessage')['text']);
    }

    public function testRemovingAMissingNumberChangesNothing(): void
    {
        $this->asker(true);
        $this->wpdb->nextResults[] = [$this->guest(4, 'Anna')]; // numbered

        $this->guestListService()->onGuestCommand(555, 555, true, 'remove 1.8. 3');

        self::assertSame([], $this->wpdb->deletes);
        self::assertStringStartsWith('There is no guest number 3', $this->lastCallTo('sendMessage')['text']);
    }

    public function testRemoveTakesTheNumberedGuestOff(): void
    {
        $this->asker(true);
        $this->wpdb->nextResults[] = [$this->guest(4, 'Anna'), $this->guest(5, 'Bo')]; // numbered
        $this->wpdb->nextResults[] = [$this->guest(4, 'Anna')];                        // render
        $this->wpdb->nextCols[] = [];                                                  // nearbyDates

        $this->guestListService()->onGuestCommand(555, -100, false, 'remove 1.8. 2');

        self::assertSame(['id' => 5], $this->wpdb->deletes[0]['where']);
        self::assertSame('📬 Sent you a DM.', $this->lastCallTo('sendMessage')['text']);
        self::assertStringStartsWith('🗑 Removed Bo.', $this->telegramCalls[0]['body']['text']);
    }

    public function testWelcomeCrewSeeTheirNightWithoutTheOrganizerHelp(): void
    {
        $this->asker(false);
        $this->wpdb->nextCols[] = ['2026-08-01']; // defaultDate
        $this->wpdb->nextResults[] = [['id' => 6, 'task_date' => '2026-08-01', 'role_slug' => 'welcome', 'capacity' => 3]];
        $this->wpdb->nextRows[] = ['id' => 30, 'task_id' => 6, 'person_id' => 7, 'status' => 'signed_up']; // findFor
        $this->wpdb->nextResults[] = [];          // render
        $this->wpdb->nextCols[] = ['2026-08-01']; // nearbyDates

        $this->guestListService()->onGuestCommand(555, 555, true, '');

        $text = $this->lastCallTo('sendMessage')['text'];
        self::assertStringContainsString('Guest list — Sat 1 Aug', $text);
        self::assertStringContainsString('No guests yet.', $text);
        self::assertStringNotContainsString('/guest add', $text);
    }

    public function testAParseErrorIsExplained(): void
    {
        $this->asker(true);

        $this->guestListService()->onGuestCommand(555, 555, true, 'edit 2');

        self::assertStringStartsWith('Say what to change', $this->lastCallTo('sendMessage')['text']);
    }

    public function testANightButtonEditsTheListInPlace(): void
    {
        $this->asker(true);
        $this->wpdb->nextResults[] = [$this->guest(1, 'Anna')]; // render
        $this->wpdb->nextCols[] = [];                           // nearbyDates

        $this->updateRouter()->dispatch([
            'update_id' => 1,
            'callback_query' => ['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'gl:2026-08-01',
                'message' => ['chat' => ['id' => 555], 'message_id' => 42]],
        ]);

        $edited = $this->lastCallTo('editMessageText');
        self::assertSame(42, $edited['message_id']);
        self::assertStringContainsString('1. Anna', $edited['text']);
    }

    /**
     * Presses Cancel sending under a message whose buttons are $keyboard.
     *
     * @param array<int, array<int, array<string, string>>>|null $keyboard
     */
    private function pressCancelSending(?array $keyboard = null): void
    {
        Functions\when('delete_option')->alias(function (string $name): bool {
            unset($this->options[$name]);

            return true;
        });
        Functions\when('wp_clear_scheduled_hook')->justReturn(1);
        $this->options[TicketMail::PENDING_OPTION] = ['at' => time() + 300, 'rest' => []];

        $this->updateRouter()->dispatch([
            'update_id' => 1,
            'callback_query' => ['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'gl:cancel',
                'message' => ['chat' => ['id' => 555], 'message_id' => 42]
                    + (null === $keyboard ? [] : ['reply_markup' => ['inline_keyboard' => $keyboard]])],
        ]);
    }

    public function testAfterCancellingTheCancelButtonIsTakenOffAndTheNightButtonsStay(): void
    {
        $this->asker(true);
        $night = ['text' => 'Sat 1.8.', 'callback_data' => 'gl:2026-08-01'];

        $this->pressCancelSending([
            [$night],
            [['text' => 'Cancel sending', 'callback_data' => 'gl:cancel']],
        ]);

        $edit = $this->lastCallTo('editMessageReplyMarkup');
        self::assertNotNull($edit);
        self::assertSame(555, $edit['chat_id']);
        self::assertSame(42, $edit['message_id']);
        self::assertSame(['inline_keyboard' => [[$night]]], $edit['reply_markup']);
    }

    public function testWhenCancelWasTheOnlyButtonTheKeyboardIsRemoved(): void
    {
        $this->asker(true);

        $this->pressCancelSending([[['text' => 'Cancel sending', 'callback_data' => 'gl:cancel']]]);

        $edit = $this->lastCallTo('editMessageReplyMarkup');
        self::assertNotNull($edit);
        self::assertArrayNotHasKey('reply_markup', $edit);
    }

    public function testCrewPressingCancelLeavesTheButtonInPlace(): void
    {
        $this->asker(false);

        $this->pressCancelSending([[['text' => 'Cancel sending', 'callback_data' => 'gl:cancel']]]);

        self::assertNull($this->lastCallTo('editMessageReplyMarkup'));
    }

    public function testAnOrganizersCancelButtonStopsTheWaitingSend(): void
    {
        $this->asker(true);

        $this->pressCancelSending();

        self::assertNull(TicketMail::pending());
        self::assertSame(
            'Sending cancelled. No ticket email was sent.',
            $this->lastCallTo('answerCallbackQuery')['text']
        );
    }

    public function testCrewCannotCancelTheWaitingSend(): void
    {
        $this->asker(false);

        $this->pressCancelSending();

        self::assertNotNull(TicketMail::pending());
        self::assertSame(
            'Only organizers can change the guest list.',
            $this->lastCallTo('answerCallbackQuery')['text']
        );
    }

    public function testASmallListIsTheSameTextAndButtonsAsBefore(): void
    {
        $this->asker(true);
        $this->wpdb->nextResults[] = [$this->guest(1, 'Anna', 2, 'press'), $this->guest(2, 'Bo')]; // render
        $this->wpdb->nextCols[] = ['2026-08-08', '2026-08-01'];                                    // nearbyDates

        $this->guestListService()->onGuestCommand(555, 555, true, '1.8.');

        $sent = $this->lastCallTo('sendMessage');
        self::assertSame(
            implode("\n", [
                '🎫 Guest list — Sat 1 Aug',
                '2 guests, 4 people in all',
                '',
                '1. Anna +2 (press)',
                '2. Bo',
                '',
                'Add: /door add Name ; note  (just the guest)',
                'Add with extra people: /door add Name +2 ; note  (the guest and 2 more)',
                'No +N, or +0, means no extra people.',
                'Email the ticket: /door add Name name@example.com',
                'Change: /door edit 2 +3  (number from the list; give only what changes)',
                'Remove: /door remove 2',
                'Another night: put the date first, e.g. /door add 1.8. Name',
            ]),
            $sent['text']
        );
        self::assertSame(
            ['inline_keyboard' => [[
                ['text' => DayPicker::BLANK, 'callback_data' => BoardRenderer::SPACER_DATA],
                ['text' => '📅 Sat 1 Aug ✓', 'callback_data' => 'gl:2026-08-01'],
                ['text' => 'Sat 8 Aug ▶', 'callback_data' => 'gl:2026-08-08'],
            ], [['text' => '⬅️ Back', 'callback_data' => 'mn:door']]]],
            $sent['reply_markup']
        );
    }

    // --- Telegram's text limit on a big night ---------------------------------

    /**
     * Queues $count guests with long names and notes for 1 Aug, then the
     * nearby nights.
     */
    private function bigList(int $count): void
    {
        $list = [];

        for ($i = 1; $i <= $count; $i++) {
            $name = sprintf('Guest Of Honour Øystein Äöå %03d', $i);
            $list[] = $this->guest($i, $name, 2, 'partner of the DJ 🎧, at the door');
        }

        $this->wpdb->nextResults[] = $list;
        $this->wpdb->nextCols[] = ['2026-08-08', '2026-08-01', '2026-07-25'];
    }

    /**
     * Every page of the guest list for 1 Aug: /door 1.8. (the 👥 list), then
     * a tap on each next-page button in turn.
     *
     * @return array<int, array<string, mixed>> The message body of each page.
     */
    private function allPages(int $count): array
    {
        $this->asker(true);
        $this->bigList($count);
        $this->guestListService()->onGuestCommand(555, 555, true, '1.8.');
        $pages = [$this->lastCallTo('sendMessage')];

        for ($guard = 0; $guard < 20; $guard++) {
            $next = $this->callbackOf($pages[count($pages) - 1], 'Next »');

            if (null === $next) {
                break;
            }

            $this->asker(true);
            $this->bigList($count);
            $this->guestListService()->onSelect([
                'id' => 'cbq',
                'from' => ['id' => 555],
                'data' => $next,
                'message' => ['chat' => ['id' => 555], 'message_id' => 42],
            ]);
            $pages[] = $this->lastCallTo('editMessageText');
        }

        return $pages;
    }

    public function testABigListIsSplitIntoPagesThatEachFitOneMessage(): void
    {
        $pages = $this->allPages(150);

        self::assertGreaterThan(1, count($pages));

        foreach ($pages as $number => $page) {
            $this->assertFitsOneTelegramMessage($page);
            self::assertStringStartsWith(
                '🎫 Guest list — Sat 1 Aug · page ' . ($number + 1) . ' of ' . count($pages)
                    . "\n150 guests, 450 people in all\n",
                $page['text']
            );
            // Every page keeps the organizer help and the night buttons.
            self::assertStringEndsWith('Another night: put the date first, e.g. /door add 1.8. Name', $page['text']);
            self::assertNotNull($this->callbackOf($page, '◀ Sat 25 Jul'));
        }

        // Each guest is listed once, with the number /door edit and remove take.
        $text = implode("\n", array_column($pages, 'text'));
        for ($i = 1; $i <= 150; $i++) {
            $line = sprintf("\n%d. Guest Of Honour Øystein Äöå %03d +2", $i, $i);
            self::assertSame(1, substr_count($text, $line), "Guest {$i}");
        }

        self::assertNull($this->callbackOf($pages[0], '« Previous'));
        self::assertSame('gl:2026-08-01:1', $this->callbackOf($pages[0], 'Next »'));
        self::assertSame('gl:2026-08-01', $this->callbackOf($pages[1], '« Previous'));
        self::assertNull($this->callbackOf($pages[count($pages) - 1], 'Next »'));
    }

    public function testAddingToABigListWithAnEmailStillFitsOneMessage(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->justReturn(true);
        $this->options[TicketMail::PENDING_OPTION] = ['at' => time() + 600, 'rest' => []];
        $this->asker(true);
        $this->bigList(150);

        $service = new GuestListService(
            new GuestRepository(),
            new DoorAccess(new TaskRepository(), new AssignmentRepository()),
            new PersonRepository(),
            $this->client(),
            new TicketMail(
                new TicketBuyerRepository(),
                new GuestRepository(),
                new TaskRepository(),
                new Mailer(new Logger(), new EmailTemplate(new Logger()))
            )
        );
        $service->onGuestCommand(555, 555, true, 'add 1.8. ' . str_repeat('Long Name ', 10) . 'anna@example.com');

        $sent = $this->lastCallTo('sendMessage');
        $this->assertFitsOneTelegramMessage($sent);
        self::assertStringStartsWith('✅ Added Long Name', $sent['text']);
        self::assertStringContainsString('Ticket emails go out at ', $sent['text']);
        self::assertStringContainsString('· page 1 of ', $sent['text']);
        // The page buttons come first, then Cancel sending, then Back to /door.
        self::assertSame('gl:2026-08-01:1', $this->callbackOf($sent, 'Next »'));
        $rows = $sent['reply_markup']['inline_keyboard'];
        self::assertSame('gl:cancel', $rows[count($rows) - 2][0]['callback_data']);
        self::assertSame('mn:door', $rows[count($rows) - 1][0]['callback_data']);
    }
}
