<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;

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
}
