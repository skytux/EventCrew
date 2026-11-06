<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;

final class DoorListServiceTest extends TelegramTestCase
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
    private function task(int $id, string $role): array
    {
        return ['id' => $id, 'task_date' => '2026-08-01', 'role_slug' => $role, 'capacity' => 3];
    }

    public function testRefusesAnUnlinkedSender(): void
    {
        $this->doorListService()->onTicketsCommand(555, 555, true);

        self::assertStringStartsWith('Only organizers', $this->lastCallTo('sendMessage')['text']);
    }

    public function testRefusesCrewWhoAreNotOnASalesDeskTaskThatNight(): void
    {
        $this->asker(false);
        $this->wpdb->nextCols[] = ['2026-08-01'];              // defaultDate
        $this->wpdb->nextResults[] = [$this->task(5, 'clean')]; // canView: tasks that night

        $this->doorListService()->onTicketsCommand(555, 555, true);

        self::assertStringStartsWith('Only organizers', $this->lastCallTo('sendMessage')['text']);
        self::assertSame(['sendMessage'], $this->calledMethods());
    }

    public function testWelcomeCrewSeeTheirNight(): void
    {
        $this->asker(false);
        $this->wpdb->nextCols[] = ['2026-08-01'];                // defaultDate
        $this->wpdb->nextResults[] = [$this->task(6, 'welcome')]; // canView: tasks that night
        $this->wpdb->nextRows[] = ['id' => 30, 'task_id' => 6, 'person_id' => 7, 'status' => 'signed_up']; // findFor
        $this->wpdb->nextResults[] = [];                          // render: tasks->forDate
        $this->wpdb->nextResults[] = [];                          // render: redemptions->forDate
        $this->wpdb->nextCols[] = ['2026-08-01'];                 // nearbyDates

        $this->doorListService()->onTicketsCommand(555, 555, true);

        $sent = $this->lastCallTo('sendMessage');
        self::assertSame(555, $sent['chat_id']);
        self::assertStringContainsString('Tickets — Sat 1 Aug', $sent['text']);
        self::assertStringContainsString('Nobody has a ticket yet.', $sent['text']);
    }

    public function testWithoutADateTheNextNightIsShownAndMarked(): void
    {
        $this->asker(true);
        $this->wpdb->nextCols[] = ['2026-08-08', '2026-08-01', '2026-07-10']; // defaultDate
        $this->wpdb->nextResults[] = [];                                      // render: tasks->forDate
        $this->wpdb->nextResults[] = [];                                      // render: redemptions->forDate
        $this->wpdb->nextResults[] = [];                                      // render: guests
        $this->wpdb->nextCols[] = ['2026-08-08', '2026-08-01', '2026-07-10']; // pickerDates

        $this->doorListService()->onTicketsCommand(555, 555, true);

        $sent = $this->lastCallTo('sendMessage');
        self::assertStringContainsString('Tickets — Sat 1 Aug', $sent['text']);
        $buttons = array_column(array_merge(...$sent['reply_markup']['inline_keyboard']), 'callback_data', 'text');
        self::assertSame(
            ['◀ Fri 10 Jul' => 'dl:2026-07-10', '📅 Sat 1 Aug ✓' => 'dl:2026-08-01', 'Sat 8 Aug ▶' => 'dl:2026-08-08'],
            $buttons
        );
    }

    public function testAnOrganizerGetsWorkersAndRedeemersWithNightButtons(): void
    {
        $this->asker(true);
        $this->wpdb->nextResults[] = [$this->task(5, 'decorate')];                                   // tasks->forDate
        // forTask
        $this->wpdb->nextResults[] = [['id' => 1, 'task_id' => 5, 'person_id' => 8, 'status' => 'signed_up']];
        $this->wpdb->nextRows[] = ['id' => 8, 'email' => 'a@example.com', 'display_name' => 'Alex'];  // find(8)
        $this->wpdb->nextResults[] = [];                                                             // standing(8)
        $this->wpdb->nextResults[] = [['id' => 2, 'person_id' => 9, 'redeemed_for' => '2026-08-01']]; // redemptions
        $this->wpdb->nextRows[] = ['id' => 9, 'email' => 'b@example.com', 'display_name' => 'Bo'];    // find(9)
        $this->wpdb->nextCols[] = ['2026-08-08', '2026-08-01', '2026-07-10'];                        // nearbyDates

        $this->doorListService()->onTicketsCommand(555, -100, false, '1.8.');

        $sent = $this->lastCallTo('sendMessage');
        self::assertSame('📬 Sent you a DM.', $sent['text']);

        $list = $this->telegramCalls[0]['body'];
        self::assertSame(555, $list['chat_id']);
        self::assertStringContainsString('2 people', $list['text']);
        self::assertStringContainsString('• Alex — Working: ', $list['text']);
        self::assertStringContainsString('• Bo — Free-entry credit', $list['text']);

        // One row: the night before, the shown night marked, the night after.
        $rows = $list['reply_markup']['inline_keyboard'];
        self::assertCount(1, $rows);
        self::assertSame(
            ['◀ Fri 10 Jul' => 'dl:2026-07-10', '📅 Sat 1 Aug ✓' => 'dl:2026-08-01', 'Sat 8 Aug ▶' => 'dl:2026-08-08'],
            array_column($rows[0], 'callback_data', 'text')
        );
    }

    public function testGuestsAndTheirPlusOnesCountTowardTheTotal(): void
    {
        $this->asker(true);
        $this->wpdb->nextResults[] = [];                                                             // tasks->forDate
        $this->wpdb->nextResults[] = [['id' => 2, 'person_id' => 9, 'redeemed_for' => '2026-08-01']]; // redemptions
        $this->wpdb->nextRows[] = ['id' => 9, 'email' => 'b@example.com', 'display_name' => 'Bo'];    // find(9)
        $this->wpdb->nextResults[] = [];                                                             // standing(9)
        $this->wpdb->nextResults[] = [
            ['id' => 1, 'event_date' => '2026-08-01', 'name' => 'Anna', 'plus_ones' => 2, 'note' => 'press'],
        ];                                                                                           // guests
        $this->wpdb->nextCols[] = [];                                                                // nearbyDates

        $this->doorListService()->onTicketsCommand(555, 555, true, '1.8.');

        $text = $this->lastCallTo('sendMessage')['text'];
        self::assertStringContainsString('4 people', $text);
        self::assertStringContainsString("🎫 Guests\n• Anna +2 (press)", $text);
    }

    public function testAnUnreadableDateIsExplained(): void
    {
        $this->asker(true);

        $this->doorListService()->onTicketsCommand(555, 555, true, 'friday');

        self::assertStringStartsWith('I could not read that date', $this->lastCallTo('sendMessage')['text']);
    }

    public function testANightButtonEditsTheListInPlace(): void
    {
        $this->asker(true);
        $this->wpdb->nextResults[] = []; // tasks->forDate
        $this->wpdb->nextResults[] = []; // redemptions->forDate
        $this->wpdb->nextCols[] = [];    // nearbyDates

        $this->doorListService()->onSelect([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'data' => 'dl:2026-07-10',
            'message' => ['chat' => ['id' => 555], 'message_id' => 42],
        ]);

        $edit = $this->lastCallTo('editMessageText');
        self::assertSame(42, $edit['message_id']);
        self::assertStringContainsString('Tickets — Fri 10 Jul', $edit['text']);
    }

    public function testANightButtonIsRefusedToSomeoneNotOnThatNight(): void
    {
        $this->asker(false);
        $this->wpdb->nextResults[] = []; // canView: no tasks that night

        $this->doorListService()->onSelect([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'data' => 'dl:2026-07-10',
            'message' => ['chat' => ['id' => 555], 'message_id' => 42],
        ]);

        self::assertNull($this->lastCallTo('editMessageText'));
        self::assertTrue($this->lastCallTo('answerCallbackQuery')['show_alert']);
    }

    public function testTheRouterSendsTicketsAndNightTapsHere(): void
    {
        $this->updateRouter()->dispatch([
            'update_id' => 1,
            'callback_query' => ['id' => 'cbq0', 'from' => ['id' => 555], 'data' => 'mn:dl:',
                'message' => ['chat' => ['id' => 555, 'type' => 'private'], 'message_id' => 41]],
        ]);

        self::assertStringStartsWith('Only organizers', $this->lastCallTo('sendMessage')['text']);

        $this->updateRouter()->dispatch([
            'update_id' => 2,
            'callback_query' => ['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'dl:2026-08-01',
                'message' => ['chat' => ['id' => 555], 'message_id' => 42]],
        ]);

        self::assertStringStartsWith('Only organizers', $this->lastCallTo('answerCallbackQuery')['text']);
    }

    public function testASmallNightIsTheSameTextAndButtonsAsBefore(): void
    {
        $this->asker(true);
        $this->wpdb->nextResults[] = [];                                                             // tasks->forDate
        $this->wpdb->nextResults[] = [['id' => 2, 'person_id' => 9, 'redeemed_for' => '2026-08-01']]; // redemptions
        $this->wpdb->nextRows[] = ['id' => 9, 'email' => 'b@example.com', 'display_name' => 'Bo'];    // find(9)
        $this->wpdb->nextResults[] = [];                                                             // standing(9)
        $this->wpdb->nextResults[] = [
            ['id' => 1, 'event_date' => '2026-08-01', 'name' => 'Anna', 'plus_ones' => 2, 'note' => 'press'],
        ];                                                                                           // guests
        $this->wpdb->nextCols[] = ['2026-08-08', '2026-08-01'];                                      // nearbyDates

        $this->doorListService()->onTicketsCommand(555, 555, true, '1.8.');

        $sent = $this->lastCallTo('sendMessage');
        self::assertSame(
            "🎟 Tickets — Sat 1 Aug\n4 people\n\n• Bo — Free-entry credit\n\n🎫 Guests\n• Anna +2 (press)",
            $sent['text']
        );
        self::assertSame(
            ['inline_keyboard' => [[
                ['text' => '📅 Sat 1 Aug ✓', 'callback_data' => 'dl:2026-08-01'],
                ['text' => 'Sat 8 Aug ▶', 'callback_data' => 'dl:2026-08-08'],
            ]]],
            $sent['reply_markup']
        );
    }

    // --- Telegram's text limit on a big night ---------------------------------

    /**
     * Queues a night of $redeemers people with free-entry credit and $guests
     * guests, all with long names, then the nearby nights.
     */
    private function bigNight(int $redeemers, int $guests): void
    {
        $this->wpdb->nextResults[] = []; // tasks->forDate
        $redemptions = [];

        for ($i = 1; $i <= $redeemers; $i++) {
            $redemptions[] = ['id' => $i, 'person_id' => 100 + $i, 'redeemed_for' => '2026-08-01'];
        }

        $this->wpdb->nextResults[] = $redemptions;

        for ($i = 1; $i <= $redeemers; $i++) {
            $this->wpdb->nextRows[] = [
                'id' => 100 + $i, 'email' => "p{$i}@example.com",
                'display_name' => sprintf('Someone With A Rather Long Name Äöå %03d', $i),
            ];
            $this->wpdb->nextResults[] = []; // standing
        }

        $list = [];

        for ($i = 1; $i <= $guests; $i++) {
            $list[] = [
                'id' => $i, 'event_date' => '2026-08-01', 'name' => sprintf('Guest Of Honour Øystein %03d', $i),
                'plus_ones' => 1, 'note' => 'partner of the DJ 🎧',
            ];
        }

        $this->wpdb->nextResults[] = $list;
        $this->wpdb->nextCols[] = ['2026-08-08', '2026-08-01', '2026-07-25']; // nearbyDates
    }

    /**
     * Every page of the door list for 1 Aug: /door 1.8., then a tap on each
     * next-page button in turn.
     *
     * @return array<int, array<string, mixed>> The message body of each page.
     */
    private function allPages(int $redeemers, int $guests): array
    {
        $this->asker(true);
        $this->bigNight($redeemers, $guests);
        $this->doorListService()->onTicketsCommand(555, 555, true, '1.8.');
        $pages = [$this->lastCallTo('sendMessage')];

        for ($guard = 0; $guard < 20; $guard++) {
            $next = $this->callbackOf($pages[count($pages) - 1], 'Next »');

            if (null === $next) {
                break;
            }

            $this->asker(true);
            $this->bigNight($redeemers, $guests);
            $this->doorListService()->onSelect([
                'id' => 'cbq',
                'from' => ['id' => 555],
                'data' => $next,
                'message' => ['chat' => ['id' => 555], 'message_id' => 42],
            ]);
            $pages[] = $this->lastCallTo('editMessageText');
        }

        return $pages;
    }

    public function testABigNightIsSplitIntoPagesThatEachFitOneMessage(): void
    {
        $pages = $this->allPages(150, 60);

        self::assertGreaterThan(1, count($pages));

        foreach ($pages as $number => $page) {
            $this->assertFitsOneTelegramMessage($page);
            self::assertStringStartsWith(
                '🎟 Tickets — Sat 1 Aug · page ' . ($number + 1) . ' of ' . count($pages) . "\n270 people\n",
                $page['text']
            );
            // Every page keeps the night buttons.
            self::assertNotNull($this->callbackOf($page, '◀ Sat 25 Jul'));
            self::assertNotNull($this->callbackOf($page, 'Sat 8 Aug ▶'));
        }

        // Everyone is listed once, on one page or another, and the guests
        // keep their heading on every page they are on.
        $text = implode("\n", array_column($pages, 'text'));
        for ($i = 1; $i <= 150; $i++) {
            self::assertSame(1, substr_count($text, sprintf('Äöå %03d — Free-entry credit', $i)), "Person {$i}");
        }
        for ($i = 1; $i <= 60; $i++) {
            self::assertSame(1, substr_count($text, sprintf('Øystein %03d +1', $i)), "Guest {$i}");
        }
        foreach ($pages as $page) {
            if (str_contains($page['text'], 'Øystein')) {
                self::assertStringContainsString("\n\n🎫 Guests\n• Guest Of Honour", $page['text']);
            }
        }

        // Page one is plain dl:<date>, the others carry their page.
        self::assertNull($this->callbackOf($pages[0], '« Previous'));
        self::assertSame('dl:2026-08-01:1', $this->callbackOf($pages[0], 'Next »'));
        self::assertSame('dl:2026-08-01', $this->callbackOf($pages[1], '« Previous'));
        self::assertNull($this->callbackOf($pages[count($pages) - 1], 'Next »'));
    }

    public function testAnOldNightButtonStillOpensPageOne(): void
    {
        $this->asker(true);
        $this->bigNight(150, 0);

        $this->doorListService()->onSelect([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'data' => 'dl:2026-08-01',
            'message' => ['chat' => ['id' => 555], 'message_id' => 42],
        ]);

        $edit = $this->lastCallTo('editMessageText');
        $this->assertFitsOneTelegramMessage($edit);
        self::assertStringStartsWith('🎟 Tickets — Sat 1 Aug · page 1 of ', $edit['text']);
    }

    public function testAPagePastTheEndShowsTheLastPage(): void
    {
        $pages = $this->allPages(150, 0);
        $this->asker(true);
        $this->bigNight(150, 0);

        $this->doorListService()->onSelect([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'data' => 'dl:2026-08-01:99',
            'message' => ['chat' => ['id' => 555], 'message_id' => 42],
        ]);

        self::assertSame($pages[count($pages) - 1]['text'], $this->lastCallTo('editMessageText')['text']);
    }
}
