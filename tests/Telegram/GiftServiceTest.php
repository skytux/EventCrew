<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Telegram\GiftService;

/**
 * The organizer-only /gift grant. The pick (onSelect) is where the credit is
 * actually recorded, so that is what these guard; routing to it and the
 * name-resolution prompt are covered through UpdateRouterTest.
 */
final class GiftServiceTest extends TelegramTestCase
{
    private function service(): GiftService
    {
        return new GiftService(
            new PersonRepository(),
            new CreditGrantRepository(),
            $this->standing(),
            $this->client()
        );
    }

    public function testAnOrganizerGiftRecordsACreditAndNotifiesTheRecipient(): void
    {
        // findByTelegramUserId(organizer), then people->find(recipient).
        $this->wpdb->nextRows[] = ['id' => 7, 'display_name' => 'Boss', 'is_organizer' => 1, 'telegram_user_id' => 555];
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Sam', 'telegram_chat_id' => 999];

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'gift:8']);

        // One credit was recorded for the recipient...
        self::assertCount(1, $this->wpdb->inserts);
        self::assertSame(8, $this->wpdb->inserts[0]['data']['person_id']);
        self::assertSame(1, $this->wpdb->inserts[0]['data']['credits']);
        // ...and the recipient was told, in their DM.
        self::assertSame(999, $this->lastCallTo('sendMessage')['chat_id']);
    }

    public function testANonOrganizerCannotGift(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'display_name' => 'Nope', 'is_organizer' => 0, 'telegram_user_id' => 555];

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'gift:8']);

        self::assertSame([], $this->wpdb->inserts);
        self::assertStringContainsString('Only organizers', $this->lastCallTo('answerCallbackQuery')['text']);
    }
}
