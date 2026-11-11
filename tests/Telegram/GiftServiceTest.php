<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\CreditGrantNotifier;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Telegram\GiftService;

/**
 * The organizer-only /gift grant. The pick (onSelect) is where the credit is
 * actually recorded, so that is what these guard; routing to it and the
 * name-resolution prompt are covered through UpdateRouterTest.
 */
final class GiftServiceTest extends TelegramTestCase
{
    /** @var array<int, array{to: string, body: string}> */
    private array $mails = [];

    /** @var array<string, mixed> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mails = [];
        $this->transients = [];
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = ['to' => $to, 'body' => $body];

            return true;
        });
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('get_transient')->alias(fn (string $key): mixed => $this->transients[$key] ?? false);
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);

            return true;
        });
    }

    private function service(): GiftService
    {
        return new GiftService(
            new PersonRepository(),
            new CreditGrantRepository(),
            $this->standing(),
            $this->client(),
            new CreditGrantNotifier(new Mailer(new Logger(), new EmailTemplate(new Logger())), $this->client())
        );
    }

    public function testAnOrganizerGiftRecordsACreditAndNotifiesTheRecipient(): void
    {
        // findByTelegramUserId(organizer), then people->find(recipient).
        $this->transients['eventcrew_tg_gift_amount_555'] = ['credits' => 1, 'note' => 'gift via bot'];
        $this->wpdb->nextRows[] = ['id' => 7, 'display_name' => 'Boss', 'is_organizer' => 1, 'telegram_user_id' => 555];
        $this->wpdb->nextRows[] = [
            'id' => 8, 'display_name' => 'Sam', 'email' => 'sam@example.com', 'telegram_chat_id' => 999,
        ];

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'gift:8']);

        // One credit was recorded for the recipient...
        self::assertCount(1, $this->wpdb->inserts);
        self::assertSame(8, $this->wpdb->inserts[0]['data']['person_id']);
        self::assertSame(1, $this->wpdb->inserts[0]['data']['credits']);
        // ...and the recipient was told, on both channels.
        self::assertSame(999, $this->lastCallTo('sendMessage')['chat_id']);
        self::assertSame('sam@example.com', $this->mails[0]['to']);
    }

    public function testTheTapTurnsThePickerIntoTheConfirmationAndASecondTapGivesNothing(): void
    {
        $this->transients['eventcrew_tg_gift_amount_555'] = ['credits' => 1, 'note' => 'gift via bot'];
        $this->wpdb->nextRows[] = ['id' => 7, 'display_name' => 'Boss', 'is_organizer' => 1, 'telegram_user_id' => 555];
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Sam', 'email' => 'sam@example.com'];
        $tap = [
            'id' => 'cbq', 'from' => ['id' => 555], 'data' => 'gift:8',
            'message' => ['chat' => ['id' => 555], 'message_id' => 42],
        ];

        $this->service()->onSelect($tap);

        $edit = $this->lastCallTo('editMessageText');
        self::assertSame(42, $edit['message_id']);
        self::assertStringStartsWith('✅ Gave Sam 1 credit', $edit['text']);
        self::assertArrayNotHasKey('reply_markup', $edit);
        self::assertCount(1, $this->wpdb->inserts);

        // The same button again: no second credit, and the buttons go.
        $this->wpdb->nextRows[] = ['id' => 7, 'display_name' => 'Boss', 'is_organizer' => 1, 'telegram_user_id' => 555];
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Sam', 'email' => 'sam@example.com'];

        $this->service()->onSelect($tap);

        self::assertCount(1, $this->wpdb->inserts);
        self::assertSame(
            'That credit was already given. Send /people to give another.',
            $this->lastCallTo('answerCallbackQuery')['text']
        );
        self::assertSame(42, $this->lastCallTo('editMessageReplyMarkup')['message_id']);
    }

    public function testGiftGrantsTheQuantityAndNoteTheOrganizerSet(): void
    {
        // The amount + note the organizer typed ("Sam 3 covered setup") rode a
        // transient to the tap.
        $this->transients['eventcrew_tg_gift_amount_555'] = ['credits' => 3, 'note' => 'covered setup'];
        $this->wpdb->nextRows[] = ['id' => 7, 'display_name' => 'Boss', 'is_organizer' => 1, 'telegram_user_id' => 555];
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Sam', 'email' => 'sam@example.com'];

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'gift:8']);

        self::assertSame(3, $this->wpdb->inserts[0]['data']['credits']);
        self::assertSame('covered setup', $this->wpdb->inserts[0]['data']['note']);
    }

    public function testTheNameButtonSaysOneCreditInTheSingular(): void
    {
        Functions\when('set_transient')->justReturn(true);
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Sam', 'telegram_user_id' => 888]; // the mention
        $this->wpdb->nextResults[] = [                                                             // historyFor
            ['id' => 1, 'task_id' => 1, 'person_id' => 8, 'status' => 'completed', 'task_date' => '2026-07-01'],
            ['id' => 2, 'task_id' => 2, 'person_id' => 8, 'status' => 'completed', 'task_date' => '2026-07-02'],
        ];
        $this->wpdb->nextVars[] = 0; // countFor redemptions
        $this->wpdb->nextVars[] = 0; // sumFor grants

        $this->service()->captureTarget(555, 555, 'Sam', [['type' => 'text_mention', 'user' => ['id' => 888]]]);

        $button = $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'][0][0];
        self::assertSame('Sam (1 credit now)', $button['text']);
    }

    public function testANonOrganizerCannotGift(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'display_name' => 'Nope', 'is_organizer' => 0, 'telegram_user_id' => 555];

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'gift:8']);

        self::assertSame([], $this->wpdb->inserts);
        self::assertStringContainsString('Only organizers', $this->lastCallTo('answerCallbackQuery')['text']);
    }
}
