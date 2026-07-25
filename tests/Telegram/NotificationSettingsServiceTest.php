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

    public function testEachRowHasThreeColumnsNameTelegramAndEmail(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'email_verified_at' => '2026-07-01 00:00:00', 'telegram_user_id' => 555, 'telegram_chat_id' => 999];

        $this->service()->start(555, 555);

        $rows = $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'];

        // A header row plus one row per type, every row three cells wide.
        foreach ($rows as $row) {
            self::assertCount(3, $row);
        }

        // In a type row the name cell is a no-op; the Telegram and email cells
        // carry the toggle callbacks.
        self::assertStringNotContainsString(':dm', $rows[1][0]['callback_data']);
        self::assertStringNotContainsString(':email', $rows[1][0]['callback_data']);
        self::assertStringEndsWith(':dm', $rows[1][1]['callback_data']);
        self::assertStringEndsWith(':email', $rows[1][2]['callback_data']);
    }

    public function testTappingTheNameCellChangesNothing(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'email_verified_at' => '2026-07-01 00:00:00', 'telegram_user_id' => 555, 'telegram_chat_id' => 999];

        $this->service()->onToggle([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'data' => 'np:noop',
            'message' => ['message_id' => 42, 'chat' => ['id' => 999]],
        ]);

        self::assertSame([], $this->wpdb->updates); // nothing saved
        self::assertNotContains('editMessageText', $this->calledMethods()); // menu untouched
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
