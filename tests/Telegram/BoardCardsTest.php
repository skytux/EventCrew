<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Telegram\BoardRenderer;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\EntityHtml;
use EventCrew\Telegram\EventCard;
use EventCrew\Tests\Support\FakeEventNames;

/**
 * The group board in card format (BoardService::CARDS_OPTION): one message per
 * day, a photo message for a day with an EventMesh event, edited in place.
 */
final class BoardCardsTest extends TelegramTestCase
{
    use FakeEvents;
    use FakeEventNames;

    private const IMAGE = 'https://holvi-cdn.example.test/image/abc.jpg';
    private const IMAGE_NEW = 'https://holvi-cdn.example.test/image/new.jpg';
    private const SHOP = 'https://holvi.example.test/shop/x/product/1/';
    private const CHAT = -100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeEvents();
        Functions\when('get_the_title')->justReturn('');

        $this->options[BoardService::CARDS_OPTION] = true;
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => self::CHAT, 'message_id' => 0];
        $this->telegramResults['sendPhoto'] = ['message_id' => 61];
        $this->telegramResults['sendMessage'] = ['message_id' => 62];
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(int $id, ?int $eventPostId, string $label, string $date = '2026-08-01'): array
    {
        return [
            'id' => $id,
            'task_date' => $date,
            'role_slug' => 'decorate',
            'capacity' => 2,
            'event_post_id' => $eventPostId,
            'event_label' => $label,
            'starts_at' => null,
            'ends_at' => null,
            'notes' => '',
            'created_at' => '2026-07-01 00:00:00',
        ];
    }

    /** The event of task 1: 1 August, with a picture and a ticket shop. */
    private function partyEvent(array $meta = [], string $content = 'A night of dancing.'): void
    {
        $this->event(100, '2026-08-01', 'Party', $meta + [
            '_eventmesh_image_url' => self::IMAGE,
            '_eventmesh_url' => self::SHOP,
            '_eventmesh_price' => '10.00 EUR',
        ], $content);
    }

    /**
     * One event day (task 1, event 100) and one day without an event (task 2).
     * Queues the upcoming tasks and one occupancy result per day.
     *
     * @param array<int, int> $taken task id => people signed up
     */
    private function queueTwoDays(array $taken = []): void
    {
        $this->wpdb->nextResults[] = [
            $this->taskRow(1, 100, 'Party'),
            $this->taskRow(2, null, 'Cleanup', '2026-08-02'),
        ];
        $this->wpdb->nextResults[] = isset($taken[1]) ? [['task_id' => 1, 'taken' => $taken[1]]] : [];
        $this->wpdb->nextResults[] = isset($taken[2]) ? [['task_id' => 2, 'taken' => $taken[2]]] : [];
    }

    /** The event day alone, with one occupancy result. */
    private function queueEventDay(int $taken = 0): void
    {
        $this->wpdb->nextResults[] = [$this->taskRow(1, 100, 'Party')];
        $this->wpdb->nextResults[] = $taken > 0 ? [['task_id' => 1, 'taken' => $taken]] : [];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<int, string> Every callback_data and url in a call's keyboard.
     */
    private static function targets(array $body): array
    {
        $targets = [];

        foreach ($body['reply_markup']['inline_keyboard'] ?? [] as $row) {
            foreach ($row as $button) {
                $targets[] = (string) ($button['callback_data'] ?? $button['url'] ?? '');
            }
        }

        return $targets;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<int, string> Every button label in a call's keyboard.
     */
    private static function labels(array $body): array
    {
        $labels = [];

        foreach ($body['reply_markup']['inline_keyboard'] ?? [] as $row) {
            foreach ($row as $button) {
                $labels[] = (string) $button['text'];
            }
        }

        return $labels;
    }

    /**
     * A day as BOARD_OPTION stores it, with hashes that match nothing.
     *
     * @return array<string, int|string>
     */
    private static function stored(int $messageId, string $kind, string $photo): array
    {
        return ['message_id' => $messageId, 'kind' => $kind, 'photo' => $photo, 'text' => 'x', 'keyboard' => 'y'];
    }

    private static function visibleLength(string $caption): int
    {
        return EntityHtml::units(html_entity_decode(strip_tags($caption), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public function testWithTheSettingOffTheBoardIsOneTextMessageWithDayButtons(): void
    {
        $this->options[BoardService::CARDS_OPTION] = false;
        $this->partyEvent();
        $this->wpdb->nextResults[] = [
            $this->taskRow(1, 100, 'Party'),
            $this->taskRow(2, null, 'Cleanup', '2026-08-02'),
        ];
        $this->wpdb->nextResults[] = [];

        self::assertTrue($this->board()->refresh());

        self::assertSame(['sendMessage'], $this->calledMethods());
        self::assertContains('bd:2026-08-02', self::targets($this->lastCallTo('sendMessage')));
        self::assertSame(
            ['chat_id' => self::CHAT, 'message_id' => 62],
            $this->options[BoardService::BOARD_OPTION]
        );
    }

    public function testAnEventDayIsOnePhotoMessageWithItsTaskButtonsFirst(): void
    {
        $this->partyEvent();
        $this->queueEventDay();

        self::assertTrue($this->board()->refresh());

        self::assertSame(['sendPhoto'], $this->calledMethods());
        $photo = $this->lastCallTo('sendPhoto');
        self::assertSame(self::IMAGE, $photo['photo']);
        self::assertSame('HTML', $photo['parse_mode']);
        self::assertStringStartsWith(
            "<b>Party</b>\n📅 1.8.2026 · 21:00\n🎟 10 €\n\nA night of dancing.",
            $photo['caption']
        );
        self::assertStringEndsWith(
            "\n\nOpen tasks — tap one to sign up, tap again to cancel.\nSend /me in a DM to get your summary.",
            $photo['caption']
        );

        // The task button first; no heading rows, since the caption names the
        // event and its day; then the ticket button from the card.
        $targets = self::targets($photo);
        self::assertSame('t:1', $targets[0]);
        self::assertSame([BoardRenderer::SPACER_DATA, self::SHOP], array_slice($targets, 1));
        self::assertSame(BoardRenderer::DIVIDER, self::labels($photo)[1]);
        foreach (self::labels($photo) as $label) {
            self::assertStringNotContainsString('📅', $label);
        }

        $days = $this->options[BoardService::BOARD_OPTION]['days'];
        self::assertSame(['2026-08-01'], array_keys($days));
        self::assertSame(61, $days['2026-08-01']['message_id']);
        self::assertSame('card', $days['2026-08-01']['kind']);
        self::assertSame(self::IMAGE, $days['2026-08-01']['photo']);
        self::assertSame(0, $this->options[BoardService::BOARD_OPTION]['message_id']);
    }

    public function testADayWithoutAnEventStaysATextMessageAndNoDayHasDayButtons(): void
    {
        $this->partyEvent();
        $this->queueTwoDays();

        self::assertTrue($this->board()->refresh());

        self::assertSame(['sendPhoto', 'sendMessage'], $this->calledMethods());

        $text = $this->lastCallTo('sendMessage');
        self::assertSame(
            "Open tasks — tap one to sign up, tap again to cancel.\nSend /me in a DM to get your summary.",
            $text['text']
        );
        self::assertArrayNotHasKey('parse_mode', $text);
        self::assertSame('📅 Cleanup', self::labels($text)[0]);
        self::assertContains('t:2:2026-08-02', self::targets($text));

        foreach ($this->telegramCalls as $call) {
            foreach (self::targets($call['body']) as $target) {
                self::assertStringStartsNotWith(BoardService::PICK_PREFIX, $target);
            }

            self::assertNotContains('🗓 Dates', self::labels($call['body']));
        }

        $days = $this->options[BoardService::BOARD_OPTION]['days'];
        self::assertSame('card', $days['2026-08-01']['kind']);
        self::assertSame('text', $days['2026-08-02']['kind']);
        self::assertSame(62, $days['2026-08-02']['message_id']);
    }

    public function testAnEventWithoutAPictureIsATextDay(): void
    {
        $this->partyEvent(['_eventmesh_image_url' => '']);
        $this->queueEventDay();

        $this->board()->refresh();

        self::assertSame(['sendMessage'], $this->calledMethods());
        self::assertSame('📅 Party', self::labels($this->lastCallTo('sendMessage'))[0]);
    }

    public function testAnUnchangedBoardSendsNothing(): void
    {
        $this->partyEvent();
        $this->queueTwoDays();
        $this->board()->refresh();
        $this->telegramCalls = [];

        $this->queueTwoDays();

        self::assertTrue($this->board()->refresh());
        self::assertSame([], $this->telegramCalls);
    }

    public function testANewCountEditsOnlyTheButtonsOfThatDay(): void
    {
        $this->partyEvent();
        $this->queueTwoDays();
        $this->board()->refresh();
        $this->telegramCalls = [];

        $this->queueTwoDays([1 => 1]);

        self::assertTrue($this->board()->refresh());
        self::assertSame(['editMessageReplyMarkup'], $this->calledMethods());
        $edit = $this->lastCallTo('editMessageReplyMarkup');
        self::assertSame(61, $edit['message_id']);
        self::assertStringContainsString('1/2', self::labels($edit)[0]);
    }

    public function testANewCountOnATextDayEditsItsText(): void
    {
        $this->partyEvent();
        $this->queueTwoDays();
        $this->board()->refresh();
        $this->telegramCalls = [];

        $this->queueTwoDays([2 => 2]);

        $this->board()->refresh();
        self::assertSame(['editMessageText'], $this->calledMethods());
        self::assertSame(62, $this->lastCallTo('editMessageText')['message_id']);
    }

    public function testANewPriceEditsTheCaption(): void
    {
        $this->partyEvent();
        $this->queueEventDay();
        $this->board()->refresh();
        $this->telegramCalls = [];

        $this->partyEvent(['_eventmesh_price' => '12.00 EUR']);
        $this->queueEventDay();

        self::assertTrue($this->board()->refresh());
        self::assertSame(['editMessageCaption'], $this->calledMethods());
        $edit = $this->lastCallTo('editMessageCaption');
        self::assertSame(61, $edit['message_id']);
        self::assertSame('HTML', $edit['parse_mode']);
        self::assertStringContainsString('🎟 12 €', $edit['caption']);
        self::assertSame('t:1', self::targets($edit)[0]);
    }

    public function testANewPictureEditsTheMedia(): void
    {
        $this->partyEvent();
        $this->queueEventDay();
        $this->board()->refresh();
        $this->telegramCalls = [];

        $this->partyEvent(['_eventmesh_image_url' => self::IMAGE_NEW]);
        $this->queueEventDay();

        self::assertTrue($this->board()->refresh());
        self::assertSame(['editMessageMedia'], $this->calledMethods());
        $edit = $this->lastCallTo('editMessageMedia');
        self::assertSame(61, $edit['message_id']);
        self::assertSame('photo', $edit['media']['type']);
        self::assertSame(self::IMAGE_NEW, $edit['media']['media']);
        self::assertSame('HTML', $edit['media']['parse_mode']);
        self::assertStringStartsWith('<b>Party</b>', $edit['media']['caption']);
        self::assertSame('t:1', self::targets($edit)[0]);
        self::assertSame(self::IMAGE_NEW, $this->options[BoardService::BOARD_OPTION]['days']['2026-08-01']['photo']);
    }

    public function testADayThatGetsAnEventIsPostedAgainAndTheOldTextMessageDeleted(): void
    {
        $this->partyEvent(['_eventmesh_image_url' => '']);
        $this->queueEventDay();
        $this->board()->refresh();
        self::assertSame('text', $this->options[BoardService::BOARD_OPTION]['days']['2026-08-01']['kind']);
        $this->telegramCalls = [];

        $this->partyEvent();
        $this->queueEventDay();

        self::assertTrue($this->board()->refresh());
        self::assertSame(['sendPhoto', 'deleteMessage'], $this->calledMethods());
        self::assertSame(62, $this->lastCallTo('deleteMessage')['message_id']);
        $day = $this->options[BoardService::BOARD_OPTION]['days']['2026-08-01'];
        self::assertSame(['card', 61], [$day['kind'], $day['message_id']]);
    }

    public function testADayThatLosesItsPictureIsPostedAgainAsTextAndTheCardDeleted(): void
    {
        $this->partyEvent();
        $this->queueEventDay();
        $this->board()->refresh();
        $this->telegramCalls = [];

        $this->partyEvent(['_eventmesh_image_url' => '']);
        $this->queueEventDay();

        self::assertTrue($this->board()->refresh());
        self::assertSame(['sendMessage', 'deleteMessage'], $this->calledMethods());
        self::assertSame(61, $this->lastCallTo('deleteMessage')['message_id']);
        $day = $this->options[BoardService::BOARD_OPTION]['days']['2026-08-01'];
        self::assertSame(['text', 62], [$day['kind'], $day['message_id']]);
    }

    public function testAFailedPostKeepsTheOldMessageForTheNextTry(): void
    {
        $this->partyEvent(['_eventmesh_image_url' => '']);
        $this->queueEventDay();
        $this->board()->refresh();
        $this->telegramCalls = [];

        $this->partyEvent();
        $this->telegramErrors['sendPhoto'] = 'Bad Request: wrong file identifier/HTTP URL specified';
        $this->queueEventDay();

        self::assertFalse($this->board()->refresh());
        self::assertNotContains('deleteMessage', $this->calledMethods());
        $day = $this->options[BoardService::BOARD_OPTION]['days']['2026-08-01'];
        self::assertSame(['text', 62], [$day['kind'], $day['message_id']]);
    }

    public function testADayNoLongerOnTheBoardIsDeleted(): void
    {
        $this->options[BoardService::BOARD_OPTION]['days'] = [
            '2026-07-19' => self::stored(33, 'card', self::IMAGE),
        ];
        $this->partyEvent();
        $this->queueEventDay();

        $this->board()->refresh();

        self::assertSame(['sendPhoto', 'deleteMessage'], $this->calledMethods());
        self::assertSame(33, $this->lastCallTo('deleteMessage')['message_id']);
        self::assertSame(['2026-08-01'], array_keys($this->options[BoardService::BOARD_OPTION]['days']));
    }

    public function testSwitchingOnDeletesTheTextBoard(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => self::CHAT, 'message_id' => 5];
        $this->partyEvent();
        $this->queueEventDay();

        $this->board()->refresh();

        self::assertSame(['deleteMessage', 'sendPhoto'], $this->calledMethods());
        self::assertSame(5, $this->lastCallTo('deleteMessage')['message_id']);
        self::assertSame(0, $this->options[BoardService::BOARD_OPTION]['message_id']);
    }

    public function testSwitchingOffDeletesTheCardsAndPostsTheTextBoard(): void
    {
        $this->partyEvent();
        $this->queueEventDay();
        $this->board()->refresh();
        $this->telegramCalls = [];

        $this->options[BoardService::CARDS_OPTION] = false;
        $this->queueEventDay();

        self::assertTrue($this->board()->refresh());
        self::assertSame(['deleteMessage', 'sendMessage'], $this->calledMethods());
        self::assertSame(61, $this->lastCallTo('deleteMessage')['message_id']);
        self::assertSame(
            ['chat_id' => self::CHAT, 'message_id' => 62],
            $this->options[BoardService::BOARD_OPTION]
        );
    }

    public function testTheHourlyCheckReplacesTheCardsAfterSwitchingOffEvenWithAnUnchangedText(): void
    {
        $this->partyEvent();
        $this->queueEventDay();
        $this->board()->refresh();
        $this->telegramCalls = [];

        $this->options[BoardService::CARDS_OPTION] = false;
        $this->queueEventDay();
        $rendered = $this->board()->render();
        $this->options[BoardService::BOARD_HASH_OPTION] = md5(
            $rendered['text'] . '|' . (string) wp_json_encode($rendered['keyboard'])
        );
        $this->queueEventDay(); // refreshIfChanged: render for the hash
        $this->queueEventDay(); // refresh

        $this->board()->refreshIfChanged();

        self::assertSame(['deleteMessage', 'sendMessage'], $this->calledMethods());
    }

    public function testRepostPostsEveryDayAgainAndDeletesTheOldMessages(): void
    {
        $this->options[BoardService::BOARD_OPTION]['days'] = [
            '2026-08-01' => self::stored(33, 'card', self::IMAGE),
            '2026-08-02' => self::stored(34, 'text', ''),
        ];
        $this->partyEvent();
        $this->queueTwoDays();

        $this->board()->repost();

        self::assertSame(['sendPhoto', 'sendMessage', 'deleteMessage', 'deleteMessage'], $this->calledMethods());
        $deleted = array_map(
            static fn (array $call): int => (int) $call['body']['message_id'],
            array_values(array_filter(
                $this->telegramCalls,
                static fn (array $c): bool => 'deleteMessage' === $c['method']
            ))
        );
        self::assertSame([33, 34], $deleted);
        $days = $this->options[BoardService::BOARD_OPTION]['days'];
        self::assertSame([61, 62], [$days['2026-08-01']['message_id'], $days['2026-08-02']['message_id']]);
    }

    public function testTheCaptionFitsTelegramsLimitWithoutCuttingAnEntity(): void
    {
        // The longest title, Finnish title and DJ line, and two long
        // descriptions: without the board's lines the card alone is close to
        // the limit, so the descriptions must get shorter to leave room.
        $long = str_repeat('Rock & roll <all> night, "loud" & late. ', 80);
        $title = str_repeat('Big & Loud ', 30);
        $this->event(100, '2026-08-01', $title, [
            '_eventmesh_image_url' => self::IMAGE,
            '_eventmesh_price' => '10.00 EUR',
            '_eventmesh_original_lang' => 'en',
            '_eventmesh_tr_fi_hash' => md5($title . "\0" . $long),
            '_eventmesh_tr_fi_content' => str_replace('Rock', 'Rokki', $long),
            '_eventmesh_tr_fi_title' => 'Suomeksi & ' . $title,
        ], $long);
        $this->fakeEventNames([], [100 => array_fill(0, 20, 'DJ & Friend')]);
        $this->queueEventDay();

        $this->board()->refresh();

        $caption = $this->lastCallTo('sendPhoto')['caption'];
        self::assertLessThanOrEqual(EventCard::CAPTION_LIMIT, self::visibleLength($caption));
        self::assertStringEndsWith('Send /me in a DM to get your summary.', $caption);
        self::assertStringContainsString('&amp;', $caption);
        // Every & starts a whole entity.
        self::assertSame(0, preg_match('/&(?!amp;|lt;|gt;)/', $caption));
    }

    public function testTheMostDaysShownIsSix(): void
    {
        $rows = [];

        for ($day = 1; $day <= 8; $day++) {
            $rows[] = $this->taskRow($day, null, 'Night ' . $day, sprintf('2026-08-%02d', $day));
        }

        $this->wpdb->nextResults[] = $rows;

        $this->board()->refresh();

        self::assertCount(6, $this->options[BoardService::BOARD_OPTION]['days']);
        self::assertSame('2026-08-06', array_key_last($this->options[BoardService::BOARD_OPTION]['days']));
    }

    public function testAnEmptyBoardIsOneTextMessage(): void
    {
        $this->wpdb->nextResults[] = [];

        self::assertTrue($this->board()->refresh());

        self::assertSame(['sendMessage'], $this->calledMethods());
        self::assertSame('No open tasks right now. Check back soon!', $this->lastCallTo('sendMessage')['text']);
        self::assertSame([''], array_keys($this->options[BoardService::BOARD_OPTION]['days']));
    }

    // --- /board in a private chat -------------------------------------------

    public function testAPrivateBoardWithCardsOnSendsOneMessagePerDayAndLeavesTheGroupBoard(): void
    {
        $group = [
            'chat_id' => self::CHAT,
            'message_id' => 0,
            'days' => ['2026-08-01' => self::stored(33, 'card', self::IMAGE)],
        ];
        $this->options[BoardService::BOARD_OPTION] = $group;
        $this->partyEvent();
        $this->queueTwoDays();

        $this->board()->sendPrivate(555);

        self::assertSame(['sendPhoto', 'sendMessage'], $this->calledMethods());
        foreach ($this->telegramCalls as $call) {
            self::assertSame(555, $call['body']['chat_id']);
        }
        self::assertSame(self::IMAGE, $this->lastCallTo('sendPhoto')['photo']);
        self::assertSame('t:1', self::targets($this->lastCallTo('sendPhoto'))[0]);
        self::assertContains('t:2:2026-08-02', self::targets($this->lastCallTo('sendMessage')));
        self::assertSame($group, $this->options[BoardService::BOARD_OPTION]);
    }

    public function testAPrivateBoardWithCardsOffIsOneTextMessageWithDayButtons(): void
    {
        $this->options[BoardService::CARDS_OPTION] = false;
        $this->partyEvent();
        $this->wpdb->nextResults[] = [
            $this->taskRow(1, 100, 'Party'),
            $this->taskRow(2, null, 'Cleanup', '2026-08-02'),
        ];
        $this->wpdb->nextResults[] = [];

        $this->board()->sendPrivate(555);

        self::assertSame(['sendMessage'], $this->calledMethods());
        $sent = $this->lastCallTo('sendMessage');
        self::assertSame(555, $sent['chat_id']);
        self::assertContains('t:1', self::targets($sent));
        self::assertContains('bd:2026-08-02', self::targets($sent));
        self::assertSame(
            ['chat_id' => self::CHAT, 'message_id' => 0],
            $this->options[BoardService::BOARD_OPTION]
        );
    }

    public function testATapOnAPrivateCardEditsOnlyThatCardsCaption(): void
    {
        $this->partyEvent();
        $this->tapAsVerifiedPerson();
        $this->queueTwoDays(); // the group's refresh
        $this->queueTwoDays([1 => 1]); // the private redraw

        $this->board()->onJoinLeave($this->privateTap('t:1', 42, true));

        $private = $this->privateCalls();
        self::assertSame(['editMessageCaption'], array_column($private, 'method'));
        $edit = $private[0]['body'];
        self::assertSame(42, $edit['message_id']);
        self::assertStringStartsWith('<b>Party</b>', $edit['caption']);
        self::assertSame('t:1', self::targets($edit)[0]);
        self::assertStringContainsString('1/2', self::labels($edit)[0]);
    }

    public function testATapOnAPrivateTextDayEditsOnlyThatDaysText(): void
    {
        $this->partyEvent();
        $this->tapAsVerifiedPerson();
        $this->queueTwoDays(); // the group's refresh
        $this->queueTwoDays([2 => 1]); // the private redraw

        $this->board()->onJoinLeave($this->privateTap('t:2:2026-08-02', 43, false));

        $private = $this->privateCalls();
        self::assertSame(['editMessageText'], array_column($private, 'method'));
        $edit = $private[0]['body'];
        self::assertSame(43, $edit['message_id']);
        $targets = self::targets($edit);
        self::assertContains('t:2:2026-08-02', $targets);
        self::assertNotContains('t:1', $targets);
        foreach ($targets as $target) {
            self::assertStringStartsNotWith(BoardService::PICK_PREFIX, $target);
        }
        self::assertStringContainsString('1/2', implode("\n", self::labels($edit)));
    }

    public function testATapOnAPrivateCopyOfADayNoLongerOnTheBoardChangesNothing(): void
    {
        $this->partyEvent();
        $this->tapAsVerifiedPerson();
        $this->queueEventDay(); // the group's refresh
        $this->queueEventDay(); // the private redraw

        $this->board()->onJoinLeave($this->privateTap('t:2:2026-08-02', 43, false));

        self::assertSame([], $this->privateCalls());
    }

    /**
     * The sender is a verified person, and the toggle is a join that succeeds.
     */
    private function tapAsVerifiedPerson(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => 555,
        ];
        $this->wpdb->nextVars[] = 2; // taskCapacity
        $this->wpdb->nextQueryResults[] = 1; // conditional insert
    }

    /**
     * A task button tapped on a private copy: message $messageId in chat 555.
     *
     * @return array<string, mixed>
     */
    private function privateTap(string $data, int $messageId, bool $photo): array
    {
        $message = ['message_id' => $messageId, 'chat' => ['id' => 555, 'type' => 'private']];

        if ($photo) {
            $message['photo'] = [['file_id' => 'f', 'width' => 320, 'height' => 320]];
        }

        return ['id' => 'cbq1', 'from' => ['id' => 555], 'data' => $data, 'message' => $message];
    }

    /**
     * Every call to the private chat 555, except answering the tap.
     *
     * @return array<int, array<string, mixed>>
     */
    private function privateCalls(): array
    {
        return array_values(array_filter(
            $this->telegramCalls,
            static fn (array $call): bool => 555 === ($call['body']['chat_id'] ?? null)
        ));
    }

    private function board(): BoardService
    {
        return $this->boardService();
    }
}
