<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use EventCrew\Repositories\NewsletterSubscriberRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\ChannelChoice;
use EventCrew\Support\NotificationPreferences;
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

    public function testEachTypeHasANameRowAndARowOfTelegramEmailAndAppToggles(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'email_verified_at' => '2026-07-01 00:00:00', 'telegram_user_id' => 555, 'telegram_chat_id' => 999];

        $this->service()->start(555, 555);

        $rows = $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'];

        // The last row is Back to Settings.
        self::assertSame([['text' => '⬅️ Back', 'callback_data' => 'mn:set']], array_pop($rows));

        // Per type: a one-cell name row, then a three-cell toggle row.
        self::assertSame(0, count($rows) % 2);

        foreach (array_chunk($rows, 2) as [$name, $toggles]) {
            self::assertCount(1, $name);
            self::assertSame('np:noop', $name[0]['callback_data']);
            self::assertCount(3, $toggles);
            self::assertStringEndsWith(':dm', $toggles[0]['callback_data']);
            self::assertStringEndsWith(':email', $toggles[1]['callback_data']);
            self::assertStringEndsWith(':push', $toggles[2]['callback_data']);
        }

        // An account with nothing stored: every switch on, the app's too (it
        // sends only once a device turns app notifications on).
        self::assertSame(['📱 ✅', '✉ ✅', '🔔 ✅'], array_column($rows[1], 'text'));
    }

    /** @return list<string> the callback data of the "When both are on" row, or [] */
    private function choiceRowData(): array
    {
        foreach ($this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'] as $row) {
            if (str_starts_with((string) ($row[0]['callback_data'] ?? ''), NotificationSettingsService::CHOICE_PREFIX)) {
                return array_column($row, 'callback_data');
            }
        }

        return [];
    }

    public function testTheWhenBothAreOnRowShowsOnlyWithADevice(): void
    {
        $row = ['id' => 7, 'email_verified_at' => '2026-07-01 00:00:00', 'telegram_user_id' => 555, 'telegram_chat_id' => 999];
        $this->wpdb->nextRows[] = $row;
        $this->service()->start(555, 555);

        self::assertSame([], $this->choiceRowData(), 'No device with app notifications: no row.');

        NotificationPreferences::useDeviceLookup(static fn (int $id): bool => true);
        $this->wpdb->nextRows[] = $row + ['role_notices' => (string) json_encode([ChannelChoice::KEY => ChannelChoice::APP])];
        $this->service()->start(555, 555);

        self::assertSame(['np:cc:app', 'np:cc:telegram', 'np:cc:both'], $this->choiceRowData());
        $rows = $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'];
        $choice = array_values(array_filter($rows, static fn (array $r): bool => 'np:cc:app' === ($r[0]['callback_data'] ?? '')))[0];
        self::assertSame(['✅ App only', 'Telegram only', 'Both'], array_column($choice, 'text'));
    }

    public function testAnsweringTheQuestionStoresItAndReplacesTheButtons(): void
    {
        $row = ['id' => 7, 'email_verified_at' => '2026-07-01 00:00:00', 'telegram_user_id' => 555, 'telegram_chat_id' => 999];
        $this->wpdb->nextRows[] = $row; // findByTelegramUserId()
        $this->wpdb->nextRows[] = $row; // addRoleNotices()' read

        $this->service()->onChannelChoice([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'data' => 'np:cq:telegram',
            'message' => ['message_id' => 42, 'chat' => ['id' => 999]],
        ]);

        $saved = json_decode((string) end($this->wpdb->updates)['data']['role_notices'], true);
        self::assertSame(ChannelChoice::TELEGRAM, $saved[ChannelChoice::KEY]);
        self::assertSame('Saved. Notifications come to Telegram.', $this->lastCallTo('answerCallbackQuery')['text'] ?? null);
        $edit = $this->lastCallTo('editMessageText');
        self::assertSame('Saved. Notifications come to Telegram.', $edit['text']);
        self::assertArrayNotHasKey('reply_markup', $edit);
    }

    public function testTheRouterSendsTheAnswersHere(): void
    {
        $row = ['id' => 7, 'email_verified_at' => '2026-07-01 00:00:00', 'telegram_user_id' => 555, 'telegram_chat_id' => 999];

        for ($i = 0; $i < 6; $i++) {
            $this->wpdb->nextRows[] = $row;
        }

        $this->updateRouter()->dispatch([
            'callback_query' => [
                'id' => 'cbq',
                'from' => ['id' => 555],
                'data' => 'np:cq:both',
                'message' => ['message_id' => 42, 'chat' => ['id' => 999, 'type' => 'private']],
            ],
        ]);

        $notices = array_values(array_filter(
            array_map(static fn (array $u): ?string => $u['data']['role_notices'] ?? null, $this->wpdb->updates)
        ));
        self::assertNotSame([], $notices, 'The answer was stored.');
        self::assertSame(ChannelChoice::BOTH, json_decode((string) end($notices), true)[ChannelChoice::KEY]);
    }

    public function testAnUnknownAnswerStoresNothing(): void
    {
        $this->service()->onChannelChoice(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'np:cc:email']);

        self::assertSame([], $this->wpdb->updates);
        self::assertSame(['answerCallbackQuery'], $this->calledMethods());
    }

    public function testTogglingTheAppChannelSavesIt(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'email_verified_at' => '2026-07-01 00:00:00', 'telegram_user_id' => 555, 'telegram_chat_id' => 999];

        $this->service()->onToggle([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'data' => 'np:credit:push',
            'message' => ['message_id' => 42, 'chat' => ['id' => 999]],
        ]);

        $saved = json_decode((string) $this->wpdb->updates[0]['data']['notify_prefs'], true);
        self::assertFalse($saved['credit']['push']);
        self::assertTrue($saved['credit']['dm']);
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

        // The redrawn menu keeps Back to Settings as its last row.
        $rows = $this->lastCallTo('editMessageText')['reply_markup']['inline_keyboard'];
        self::assertSame('mn:set', end($rows)[0]['callback_data']);
    }

    /** @return list<string> the types with a row in the keyboard */
    private function keyboardTypes(): array
    {
        $types = [];

        // Each type's toggle row (every second row); not the Back row.
        $rows = array_slice($this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'], 0, -1);

        foreach (array_chunk($rows, 2) as $pair) {
            $types[] = explode(':', (string) $pair[1][0]['callback_data'])[1];
        }

        return $types;
    }

    public function testOnlyAnOrganizerSeesTheOrganizerRows(): void
    {
        $row = ['id' => 7, 'email_verified_at' => '2026-07-01 00:00:00', 'telegram_user_id' => 555];
        $row['telegram_chat_id'] = 999;

        $this->wpdb->nextRows[] = $row;
        $this->service()->start(555, 555);
        self::assertSame(['standing', 'credit', 'ticket', 'permission', 'open_task'], $this->keyboardTypes());

        $this->wpdb->nextRows[] = $row + ['is_organizer' => 1];
        $this->service()->start(555, 555);
        self::assertSame(
            ['standing', 'credit', 'ticket', 'permission', 'open_task', 'role_news', 'role_met', 'chat_forward', 'list_import', 'newcomer'],
            $this->keyboardTypes()
        );
    }

    public function testANonOrganizerCannotToggleAnOrganizerRow(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'email_verified_at' => '2026-07-01 00:00:00', 'telegram_user_id' => 555];

        $this->service()->onToggle([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'data' => 'np:role_met:dm',
            'message' => ['message_id' => 42, 'chat' => ['id' => 999]],
        ]);

        self::assertSame([], $this->wpdb->updates);
    }

    public function testAnOrganizerTogglesAnOrganizerRow(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => 555,
            'telegram_chat_id' => 999,
            'is_organizer' => 1,
        ];

        $this->service()->onToggle([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'data' => 'np:chat_forward:email',
            'message' => ['message_id' => 42, 'chat' => ['id' => 999]],
        ]);

        $saved = json_decode((string) $this->wpdb->updates[0]['data']['notify_prefs'], true);
        self::assertFalse($saved['chat_forward']['email']);
        self::assertTrue($saved['chat_forward']['dm']);
    }

    private function withNewsletter(): NotificationSettingsService
    {
        return new NotificationSettingsService(new PersonRepository(), $this->client(), new NewsletterSubscriberRepository());
    }

    /** @return array<string, mixed> */
    private static function verifiedPerson(): array
    {
        return [
            'id' => 7,
            'email' => 'm@example.test',
            'display_name' => 'Milla Member',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => 555,
            'telegram_chat_id' => 999,
            'language' => 'fi',
        ];
    }

    public function testTheNewsletterRowShowsItIsOnInTheEmailColumn(): void
    {
        $this->wpdb->nextRows[] = self::verifiedPerson();
        $this->wpdb->nextRows[] = ['id' => 3, 'email' => 'm@example.test', 'status' => 'confirmed'];

        $this->withNewsletter()->start(555, 555);

        $rows = $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'];
        array_pop($rows); // Back
        $toggle = array_pop($rows);
        $name = array_pop($rows);
        self::assertSame([['text' => '📰 Newsletter', 'callback_data' => 'np:noop']], $name);
        self::assertSame([['text' => '✉ ✅', 'callback_data' => 'np:newsletter']], $toggle);
    }

    public function testWithoutTheSubscriberStoreThereIsNoNewsletterRow(): void
    {
        $this->wpdb->nextRows[] = self::verifiedPerson();

        $this->service()->start(555, 555);

        $texts = array_map(
            static fn (array $row): string => $row[0]['text'],
            $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard']
        );
        self::assertNotContains('📰 Newsletter', $texts);
    }

    public function testTurningTheNewsletterOnAddsTheVerifiedAddressAsConfirmed(): void
    {
        $this->wpdb->nextRows[] = self::verifiedPerson();
        $this->wpdb->insert_id = 21;

        $this->withNewsletter()->onToggle([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'data' => 'np:newsletter',
            'message' => ['message_id' => 42, 'chat' => ['id' => 999]],
        ]);

        $rows = array_values(array_filter(
            array_column($this->wpdb->inserts, 'data'),
            static fn (array $data): bool => isset($data['email'])
        ));
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('m@example.test', $row['email']);
        self::assertSame('confirmed', $row['status']);
        self::assertSame('Milla', $row['name']);
        self::assertSame('fi', $row['language']);
        self::assertContains('editMessageText', $this->calledMethods());
    }

    public function testTurningTheNewsletterOffUnsubscribes(): void
    {
        $confirmed = ['id' => 3, 'email' => 'm@example.test', 'status' => 'confirmed', 'created_at' => '2026-07-01 10:00:00'];
        $this->wpdb->nextRows[] = self::verifiedPerson();
        $this->wpdb->nextRows[] = $confirmed; // subscribed()
        $this->wpdb->nextRows[] = $confirmed; // findByEmail()
        $this->wpdb->nextRows[] = $confirmed; // unsubscribe(): find()

        $this->withNewsletter()->onToggle([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'data' => 'np:newsletter',
            'message' => ['message_id' => 42, 'chat' => ['id' => 999]],
        ]);

        self::assertSame('unsubscribed', $this->wpdb->updates[0]['data']['status']);
    }
}
