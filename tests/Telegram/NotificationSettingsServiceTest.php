<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use EventCrew\Repositories\NewsletterSubscriberRepository;
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

        // The last row is Back to Settings.
        self::assertSame([['text' => '⬅️ Back', 'callback_data' => 'mn:set']], array_pop($rows));

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

        // The redrawn menu keeps Back to Settings as its last row.
        $rows = $this->lastCallTo('editMessageText')['reply_markup']['inline_keyboard'];
        self::assertSame('mn:set', end($rows)[0]['callback_data']);
    }

    /** @return list<string> the types with a row in the keyboard */
    private function keyboardTypes(): array
    {
        $types = [];

        // Skip the header row and the Back row.
        foreach (array_slice($this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'], 1, -1) as $row) {
            $types[] = explode(':', (string) $row[1]['callback_data'])[1];
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
        $row = array_pop($rows);
        self::assertSame('📰 Newsletter', $row[0]['text']);
        self::assertSame('np:noop', $row[1]['callback_data']);
        self::assertSame(['text' => '✅', 'callback_data' => 'np:newsletter'], $row[2]);
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
