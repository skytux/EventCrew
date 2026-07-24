<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use EventCrew\Repositories\PersonRepository;
use EventCrew\Telegram\NotificationSettingsService;

final class NotificationSettingsServiceTest extends TelegramTestCase
{
    private function service(): NotificationSettingsService
    {
        return new NotificationSettingsService(new PersonRepository(), $this->client());
    }

    public function testStartSendsTheToggleKeyboard(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'email_verified_at' => '2026-07-01 00:00:00', 'telegram_user_id' => 555, 'telegram_chat_id' => 999];

        $this->service()->start(555, 555);

        $body = $this->lastCallTo('sendMessage');
        self::assertArrayHasKey('inline_keyboard', $body['reply_markup']);
        self::assertNotEmpty($body['reply_markup']['inline_keyboard']);
    }

    public function testTogglingFlipsAndSavesThePreference(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'email_verified_at' => '2026-07-01 00:00:00', 'telegram_user_id' => 555, 'telegram_chat_id' => 999];

        $this->service()->onToggle([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'data' => 'np:credit:dm',
            'message' => ['message_id' => 42, 'chat' => ['id' => 999]],
        ]);

        // The saved prefs turned credit-DM off (it defaulted on).
        $saved = json_decode((string) $this->wpdb->updates[0]['data']['notify_prefs'], true);
        self::assertFalse($saved['credit']['dm']);
        self::assertContains('editMessageText', $this->calledMethods());
    }
}
