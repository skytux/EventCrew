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

        // The shown night is left out; the last past night and the next one are offered.
        $buttons = array_merge(...$list['reply_markup']['inline_keyboard']);
        self::assertSame(['dl:2026-07-10', 'dl:2026-08-08'], array_column($buttons, 'callback_data'));
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
}
