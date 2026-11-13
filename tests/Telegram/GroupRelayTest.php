<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use EventCrew\Models\Person;
use EventCrew\Repositories\GroupMessageRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Telegram\GroupChat;
use EventCrew\Telegram\GroupRelay;
use EventCrew\Web\GroupChatController;
use EventCrew\Web\SessionPerson;
use EventCrew\Support\WebSession;

/**
 * The crew group mirrored on the web: what is stored from the group, what
 * the web sends to it, an organizer's hide, and the 30 days.
 */
final class GroupRelayTest extends TelegramTestCase
{
    private const GROUP = -1001234;

    private InMemoryGroupMessages $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->options[GroupRelay::ENABLED_OPTION] = '1';
        $this->options[GroupChat::OPTION] = self::GROUP;
        $this->store = new InMemoryGroupMessages();
    }

    private function relay(): GroupRelay
    {
        return new GroupRelay($this->store, new PersonRepository(), $this->client());
    }

    /** @param array<string, mixed> $row */
    private function person(array $row = []): Person
    {
        return Person::fromRow($row + [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
        ]);
    }

    /** @param array<string, mixed> $message */
    private function update(array $message, bool $edited = false): array
    {
        return [
            'update_id' => 1,
            $edited ? 'edited_message' : 'message' => $message + [
                'message_id' => 50,
                'chat' => ['id' => self::GROUP, 'type' => 'supergroup'],
                'from' => ['id' => 900, 'first_name' => 'Kim', 'last_name' => 'Lee'],
            ],
        ];
    }

    public function testAGroupMessageIsStoredWithItsSenderAndLinkedPerson(): void
    {
        $this->wpdb->nextRows[] = ['id' => 12, 'email' => 'kim@example.com', 'telegram_user_id' => 900];

        self::assertGreaterThan(0, $this->relay()->record($this->update(['text' => 'Who brings the speakers?'])));

        $row = $this->store->rows[1];
        self::assertSame(self::GROUP, $row['chat_id']);
        self::assertSame(50, $row['message_id']);
        self::assertSame(12, $row['person_id']);
        self::assertSame('Kim Lee', $row['sender_name']);
        self::assertSame('Who brings the speakers?', $row['text']);
        self::assertSame('telegram', $row['source']);
        self::assertSame([], $this->telegramCalls);
    }

    public function testNothingIsStoredWhenOffFromAnotherChatOrACommand(): void
    {
        $this->options[GroupRelay::ENABLED_OPTION] = '0';
        self::assertSame(0, $this->relay()->record($this->update(['text' => 'hi'])));

        $this->options[GroupRelay::ENABLED_OPTION] = '1';
        self::assertSame(0, $this->relay()->record($this->update([
            'text' => 'hi',
            'chat' => ['id' => -999, 'type' => 'group'],
        ])));
        self::assertSame(0, $this->relay()->record($this->update(['text' => '/board'])));
        self::assertSame(0, $this->relay()->record($this->update(['new_chat_members' => [['id' => 5]]])));

        self::assertSame([], $this->store->rows);
    }

    public function testAPhotoIsNotedByItsKindWithItsCaption(): void
    {
        $this->wpdb->nextRows[] = null;
        $this->relay()->record($this->update(['photo' => [['file_id' => 'x']], 'caption' => 'Setup done']));

        self::assertSame('photo', $this->store->rows[1]['kind']);
        self::assertSame('Setup done', $this->store->rows[1]['text']);
        self::assertSame(0, $this->store->rows[1]['person_id']);
    }

    public function testAnEditChangesTheStoredText(): void
    {
        $this->store->rows[1] = ['id' => 1, 'chat_id' => self::GROUP, 'message_id' => 50, 'text' => 'old'];

        self::assertSame(0, $this->relay()->record($this->update(['text' => 'new'], true)));

        self::assertSame('new', $this->store->rows[1]['text']);
        self::assertNotEmpty($this->store->rows[1]['edited_at']);
    }

    public function testAReplyQuotesTheStoredMessageByItsWebWriter(): void
    {
        $this->store->rows[1] = [
            'id' => 1, 'chat_id' => self::GROUP, 'message_id' => 40, 'person_id' => 7, 'sender_name' => 'Sam',
            'text' => 'I can come at six', 'kind' => '', 'source' => 'web', 'hidden' => 0,
        ];
        $this->wpdb->nextRows[] = null;

        $this->relay()->record($this->update([
            'text' => 'Great',
            'reply_to_message' => ['message_id' => 40, 'from' => ['id' => 1, 'is_bot' => true], 'text' => 'Sam (web): I can come at six'],
        ]));

        self::assertSame(40, $this->store->rows[2]['reply_to']);
        self::assertSame(7, $this->store->rows[2]['reply_person_id']);
        self::assertSame('Sam', $this->store->rows[2]['reply_name']);
        self::assertSame('I can come at six', $this->store->rows[2]['reply_text']);
    }

    public function testPostingSendsTheEscapedTextWithTheNameAndStoresIt(): void
    {
        $this->telegramResults['sendMessage'] = ['message_id' => 77];
        $this->store->rows[3] = [
            'id' => 3, 'chat_id' => self::GROUP, 'message_id' => 60, 'person_id' => 12, 'sender_name' => 'Kim',
            'text' => 'Anyone?', 'kind' => '', 'source' => 'telegram', 'hidden' => 0,
        ];

        $result = $this->relay()->post($this->person(['display_name' => 'Sam <b>']), "I'll come <i>early</i>", 3);

        self::assertSame(['ok' => true, 'error' => ''], $result);
        $body = $this->lastCallTo('sendMessage');
        self::assertSame(self::GROUP, $body['chat_id']);
        self::assertSame('<b>Sam &lt;b&gt;</b> (web): I&#039;ll come &lt;i&gt;early&lt;/i&gt;', $body['text']);
        self::assertSame('HTML', $body['parse_mode']);
        self::assertSame(60, $body['reply_parameters']['message_id']);

        $row = $this->store->rows[4];
        self::assertSame(77, $row['message_id']);
        self::assertSame('web', $row['source']);
        self::assertSame(7, $row['person_id']);
        self::assertSame("I'll come <i>early</i>", $row['text']);
        self::assertSame(12, $row['reply_person_id']);
    }

    public function testPostingIsRefused(): void
    {
        $relay = $this->relay();

        self::assertSame('name', $relay->post($this->person(['display_name' => '']), 'hi')['error']);
        self::assertSame('empty', $relay->post($this->person(), "  \n ")['error']);
        self::assertSame('long', $relay->post($this->person(), str_repeat('a', GroupRelay::MAX_LENGTH + 1))['error']);
        self::assertSame('access', $relay->post($this->person(['email_verified_at' => null]), 'hi')['error']);
        self::assertSame('access', $relay->post($this->person(['banned_at' => '2026-07-01 00:00:00']), 'hi')['error']);

        $this->store->webCount = GroupRelay::RATE_COUNT;
        self::assertSame('rate', $relay->post($this->person(), 'hi')['error']);

        $this->options[GroupRelay::ENABLED_OPTION] = '0';
        self::assertSame('access', $relay->post($this->person(), 'hi')['error']);

        self::assertSame([], $this->telegramCalls);
        self::assertSame([], $this->store->rows);
    }

    public function testAFailedSendStoresNothing(): void
    {
        $this->telegramErrors['sendMessage'] = 'Bad Request: chat not found';

        self::assertSame('telegram', $this->relay()->post($this->person(), 'hi')['error']);
        self::assertSame([], $this->store->rows);
    }

    public function testAnOrganizerHidesAndAWebMessageIsDeletedInTheGroup(): void
    {
        $this->store->rows[1] = ['id' => 1, 'chat_id' => self::GROUP, 'message_id' => 40, 'source' => 'web', 'hidden' => 0];
        $this->store->rows[2] = ['id' => 2, 'chat_id' => self::GROUP, 'message_id' => 41, 'source' => 'telegram', 'hidden' => 0];
        $relay = $this->relay();

        self::assertFalse($relay->hide($this->person(), 1));
        self::assertSame(0, $this->store->rows[1]['hidden']);

        $organizer = $this->person(['is_organizer' => 1]);
        self::assertTrue($relay->hide($organizer, 1));
        self::assertTrue($relay->hide($organizer, 2));

        self::assertSame(1, $this->store->rows[1]['hidden']);
        self::assertSame(1, $this->store->rows[2]['hidden']);
        self::assertSame(['deleteMessage'], $this->calledMethods());
        self::assertSame(40, $this->lastCallTo('deleteMessage')['message_id']);
    }

    public function testTheViewLeavesHiddenOutAndMarksTheViewersOwn(): void
    {
        $this->store->rows[1] = [
            'id' => 1, 'chat_id' => self::GROUP, 'message_id' => 40, 'person_id' => 7, 'sender_name' => 'Sam',
            'text' => 'Mine', 'kind' => '', 'source' => 'web', 'hidden' => 0, 'created_at' => '2026-07-20 09:05:00',
            'edited_at' => null, 'reply_to' => null, 'reply_name' => '', 'reply_text' => '',
        ];
        $this->store->rows[2] = ['hidden' => 1, 'text' => 'Gone'] + $this->store->rows[1];
        $this->store->rows[2]['id'] = 2;
        $this->store->rows[3] = [
            'id' => 3, 'chat_id' => self::GROUP, 'message_id' => 42, 'person_id' => 12, 'sender_name' => '',
            'text' => '', 'kind' => 'sticker', 'source' => 'telegram', 'hidden' => 0,
            'created_at' => '2026-07-20 09:06:00', 'edited_at' => '2026-07-20 09:07:00',
            'reply_to' => 40, 'reply_name' => 'Sam', 'reply_text' => 'Mine',
        ];

        $view = $this->relay()->view($this->person());

        self::assertCount(2, $view);
        self::assertTrue($view[0]['mine']);
        self::assertTrue($view[0]['web']);
        self::assertSame('20.7. 09:05', $view[0]['time']);
        self::assertSame('2026-07-20', $view[0]['day']);
        self::assertSame('09:05', $view[0]['clock']);
        self::assertSame('', $view[0]['type']);
        self::assertNull($view[0]['reply']);
        self::assertFalse($view[1]['mine']);
        self::assertSame('Someone', $view[1]['name']);
        self::assertSame('Sticker', $view[1]['kind']);
        self::assertSame('sticker', $view[1]['type']);
        self::assertTrue($view[1]['edited']);
        self::assertSame(['name' => 'Sam', 'text' => 'Mine'], $view[1]['reply']);
    }

    public function testThePurgeRunsOnceADayAndKeepsThirtyDays(): void
    {
        $now = 1_800_000_000;

        self::assertSame(0, GroupRelay::purgeIfDue($this->store, $now));
        self::assertSame('2026-06-20 12:00:00', $this->store->purgedBefore);
        self::assertSame(-1, GroupRelay::purgeIfDue($this->store, $now + 3600));
        self::assertSame(0, GroupRelay::purgeIfDue($this->store, $now + DAY_IN_SECONDS));
    }

    public function testTheChatIsOpenOnlyToVerifiedCrewWhenOnAndTheGroupIsKnown(): void
    {
        self::assertTrue(GroupRelay::visibleTo($this->person()));
        self::assertFalse(GroupRelay::visibleTo(null));
        self::assertFalse(GroupRelay::visibleTo($this->person(['email_verified_at' => null])));
        self::assertFalse(GroupRelay::visibleTo($this->person(['approval' => Person::APPROVAL_WAITING])));

        unset($this->options[GroupChat::OPTION]);
        self::assertFalse(GroupRelay::visibleTo($this->person()));

        $this->options[GroupChat::OPTION] = self::GROUP;
        $this->options[GroupRelay::ENABLED_OPTION] = '0';
        self::assertFalse(GroupRelay::visibleTo($this->person()));
    }

    public function testTheControllerChecksTheSessionAndTheOrganizer(): void
    {
        $controller = new GroupChatController($this->relay(), new SessionPerson(new PersonRepository()));
        $csrf = WebSession::csrfToken(7);

        self::assertSame([401, ['ok' => false, 'error' => 'session']], $controller->handle(null, ['op' => 'list']));
        self::assertSame(403, $controller->handle($this->person(), ['op' => 'list', 'csrf' => 'x'])[0]);
        self::assertSame(
            [200, ['ok' => true, 'messages' => [], 'today' => '2026-07-20']],
            $controller->handle($this->person(), ['op' => 'list', 'csrf' => $csrf])
        );
        self::assertSame(
            [403, ['ok' => false, 'error' => 'access']],
            $controller->handle($this->person(), ['op' => 'hide', 'id' => '1', 'csrf' => $csrf])
        );
        self::assertSame(
            [400, ['ok' => false, 'error' => 'empty']],
            $controller->handle($this->person(), ['op' => 'post', 'text' => ' ', 'csrf' => $csrf])
        );
        self::assertSame(400, $controller->handle($this->person(), ['op' => 'nope', 'csrf' => $csrf])[0]);

        $this->options[GroupRelay::ENABLED_OPTION] = '0';
        self::assertSame(
            [403, ['ok' => false, 'error' => 'access']],
            $controller->handle($this->person(), ['op' => 'list', 'csrf' => $csrf])
        );
    }
}

/**
 * The group messages table as an array, so the relay's logic is tested
 * without its SQL.
 */
final class InMemoryGroupMessages extends GroupMessageRepository
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public int $webCount = 0;

    public string $purgedBefore = '';

    public function add(array $row): int
    {
        $id = [] === $this->rows ? 1 : max(array_keys($this->rows)) + 1;
        $this->rows[$id] = ['id' => $id, 'hidden' => 0] + $row
            + ['person_id' => 0, 'reply_to' => null, 'reply_person_id' => null];

        return $id;
    }

    public function edit(int $chatId, int $messageId, string $text, string $at): bool
    {
        foreach ($this->rows as $id => $row) {
            if ($row['chat_id'] === $chatId && $row['message_id'] === $messageId) {
                $this->rows[$id]['text'] = $text;
                $this->rows[$id]['edited_at'] = $at;
            }
        }

        return true;
    }

    public function recent(int $chatId, int $limit = 100): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (array $row): bool => $row['chat_id'] === $chatId && 0 === $row['hidden']
        ));
    }

    public function find(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function findByMessage(int $chatId, int $messageId): ?array
    {
        foreach ($this->rows as $row) {
            if ($row['chat_id'] === $chatId && $row['message_id'] === $messageId) {
                return $row;
            }
        }

        return null;
    }

    public function hide(int $id): bool
    {
        $this->rows[$id]['hidden'] = 1;

        return true;
    }

    public function countFromWebSince(int $personId, string $since): int
    {
        return $this->webCount;
    }

    public function deleteBefore(string $cutoff): int
    {
        $this->purgedBefore = $cutoff;

        return 0;
    }
}
