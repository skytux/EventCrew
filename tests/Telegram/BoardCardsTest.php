<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssistantSettings;
use EventCrew\Telegram\BoardBanner;
use EventCrew\Telegram\BoardDjText;
use EventCrew\Telegram\BoardRenderer;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\DayPicker;
use EventCrew\Telegram\EntityHtml;
use EventCrew\Telegram\EventCard;
use EventCrew\Tests\Support\FakeEventNames;

/**
 * The group board in card format (BoardService::CARDS_OPTION): the text board
 * (the next event, who is signed up, the task buttons), and the next event's
 * picture (its photo, name, date, price, the short DJ or host text and its
 * links) posted to the group on its own schedule by announceNextCard(): once
 * when the event becomes the next one, and again on its morning (Q360).
 */
final class BoardCardsTest extends TelegramTestCase
{
    use FakeEvents;
    use FakeEventNames;

    private const IMAGE = 'https://holvi-cdn.example.test/image/abc.jpg';
    private const SHOP = 'https://holvi.example.test/shop/x/product/1/';
    private const CHAT = -100;
    private const INTRO = 'Open tasks — tap one to sign up. To cancel, tap 👤 My info.';

    /** @var list<array{0: int, 1: string}> Every wp_schedule_single_event call: [time, hook]. */
    private array $scheduled = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeEvents();
        Functions\when('get_the_title')->justReturn('');

        $this->scheduled = [];
        Functions\when('wp_schedule_single_event')->alias(function (int $time, string $hook): bool {
            $this->scheduled[] = [$time, $hook];

            return true;
        });

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

    /** @return list<array<string, mixed>> Task 1 (Party, 1 August) and task 2 (Cleanup, 2 August). */
    private function twoDays(): array
    {
        return [
            $this->taskRow(1, 100, 'Party'),
            $this->taskRow(2, null, 'Cleanup', '2026-08-02'),
        ];
    }

    /**
     * Queues what one refresh() with the card format reads: the tasks for the
     * text board, and who is signed up.
     *
     * @param list<array<string, mixed>> $tasks
     * @param list<array{task_id: int, display_name: string|null}> $occupants
     */
    private function queueRefresh(array $tasks, array $occupants = []): void
    {
        $this->wpdb->nextResults[] = $tasks;
        $this->wpdb->nextResults[] = $occupants;
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

    /** @return list<string> Every task button's callback data in a call's keyboard. */
    private static function taskTargets(array $body): array
    {
        return array_values(array_filter(
            self::targets($body),
            static fn (string $target): bool => str_starts_with($target, 't:')
        ));
    }

    /** @return list<int> The message ids deleted, in order. */
    private function deleted(): array
    {
        return array_map(
            static fn (array $call): int => (int) $call['body']['message_id'],
            array_values(array_filter(
                $this->telegramCalls,
                static fn (array $c): bool => 'deleteMessage' === $c['method']
            ))
        );
    }

    private static function visibleLength(string $caption): int
    {
        return EntityHtml::units(html_entity_decode(strip_tags($caption), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function storeDjText(int $postId, string $text, ?string $hash = null): void
    {
        $this->options[BoardDjText::OPTION] = [
            $postId => ['hash' => $hash ?? md5(BoardDjText::source($postId)), 'text' => $text],
        ];
    }

    /** Pins the site clock (current_time) to $hour o'clock on $day. */
    private function at(string $day, int $hour): void
    {
        $at = (int) strtotime(sprintf('%s %02d:00:00', $day, $hour));

        Functions\when('current_time')->alias(
            static fn (string $format): string|int => match ($format) {
                'timestamp' => $at,
                'mysql' => gmdate('Y-m-d H:i:s', $at),
                default => gmdate($format, $at),
            }
        );
    }

    /**
     * Queues what one announceNextCard() that posts reads: the tasks for the
     * next event, then the tasks for the card.
     *
     * @param list<array<string, mixed>> $tasks
     */
    private function queueAnnounce(array $tasks): void
    {
        $this->wpdb->nextResults[] = $tasks;
        $this->wpdb->nextResults[] = $tasks;
    }

    private function announce(): bool
    {
        return $this->board()->announceNextCard();
    }

    /** The board as posted: text message 62, with the party as the next event. */
    private function postBoard(): void
    {
        $this->partyEvent();
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => self::CHAT, 'message_id' => 62];
    }

    /** The board as stored before Q360: photo message 61 above text message 62. */
    private function postLegacyBoard(): void
    {
        $this->postBoard();
        $this->options[BoardService::BOARD_OPTION]['card'] = [
            'message_id' => 61,
            'event' => 100,
            'photo' => self::IMAGE,
            'text' => 'x',
            'keyboard' => 'y',
        ];
    }

    // --- The board is the text message only ---------------------------------

    public function testTheBoardIsTheTextMessageOnly(): void
    {
        $this->partyEvent();
        $this->queueRefresh($this->twoDays());

        self::assertTrue($this->board()->refresh());

        self::assertSame(['sendMessage'], $this->calledMethods());

        $text = $this->lastCallTo('sendMessage');
        // Owner 2026-11-07: the text is the next date only, without the tasks
        // nobody has signed up for; its buttons under its date, three to a
        // row, and no day buttons on the group's message.
        self::assertSame(
            self::INTRO . "\n\n📅 Sat 1 Aug\nParty",
            $text['text']
        );
        self::assertSame(['t:1'], self::taskTargets($text));
        self::assertSame(['📅 Sat 1 Aug', '🎈 Decorate', DayPicker::BLANK, DayPicker::BLANK], self::labels($text));

        self::assertSame(
            ['chat_id' => self::CHAT, 'message_id' => 62],
            $this->options[BoardService::BOARD_OPTION]
        );
        self::assertArrayNotHasKey(BoardService::NEXT_CARD_OPTION, $this->options);
    }

    public function testRefreshRepostAndAPrivateBoardNeverSendAPhoto(): void
    {
        $this->partyEvent();
        $this->storeDjText(100, 'DJ Anna plays swing.');

        $this->queueRefresh($this->twoDays());
        $this->board()->refresh();
        $this->queueRefresh($this->twoDays());
        $this->board()->refresh();
        $this->queueRefresh($this->twoDays());
        $this->board()->repost();
        $this->queueRefresh($this->twoDays());
        $this->board()->sendPrivate(555);

        self::assertSame(
            ['sendMessage', 'editMessageText', 'sendMessage', 'deleteMessage', 'sendMessage'],
            $this->calledMethods()
        );
        self::assertArrayNotHasKey('card', $this->options[BoardService::BOARD_OPTION]);
        self::assertArrayNotHasKey(BoardService::NEXT_CARD_OPTION, $this->options);
    }

    // --- The next event's picture, on its own schedule ----------------------

    public function testTheNextEventsPictureIsItsPhotoNameDatePriceAndShopLink(): void
    {
        $this->partyEvent();
        $this->at('2026-08-01', 10);
        $this->queueAnnounce($this->twoDays());

        self::assertTrue($this->announce());

        self::assertSame(['sendPhoto'], $this->calledMethods());
        $photo = $this->lastCallTo('sendPhoto');
        self::assertSame(self::CHAT, $photo['chat_id']);
        self::assertSame(self::IMAGE, $photo['photo']);
        self::assertSame('HTML', $photo['parse_mode']);
        // No DJ text written yet: the name, date and price lines only, and
        // never the event's long description or the board's lines.
        self::assertSame("<b>Party</b>\n📅 1.8.2026 · 21:00\n🎟 10 €", $photo['caption']);
        // The card's own links only: no task buttons on the photo.
        self::assertSame([self::SHOP], self::targets($photo));
        // The board itself is left alone.
        self::assertSame(['chat_id' => self::CHAT, 'message_id' => 0], $this->options[BoardService::BOARD_OPTION]);
    }

    /** Owner 2026-11-07: no 📱 Crew app button on the board's card. */
    public function testTheNextEventsPictureHasNoCrewAppButton(): void
    {
        $this->options['eventcrew_signup_page_id'] = 7;
        Functions\when('get_permalink')->justReturn('https://site.test/crew/');
        $this->partyEvent();
        $this->at('2026-08-01', 10);
        $this->queueAnnounce($this->twoDays());

        $this->announce();

        self::assertSame([self::SHOP], self::targets($this->lastCallTo('sendPhoto')));
    }

    public function testTheCaptionShowsTheStoredDjTextEscaped(): void
    {
        $this->partyEvent();
        $this->storeDjText(100, 'DJ Anna plays swing & blues. She has played <everywhere>.');
        $this->queueAnnounce($this->twoDays());

        self::assertTrue($this->announce());

        self::assertSame(
            "<b>Party</b>\n📅 1.8.2026 · 21:00\n🎟 10 €\n\n"
                . 'DJ Anna plays swing &amp; blues. She has played &lt;everywhere&gt;.',
            $this->lastCallTo('sendPhoto')['caption']
        );
        self::assertSame([], $this->scheduled);
    }

    public function testANewNextEventsPictureIsPostedOnceToTheGroup(): void
    {
        $this->partyEvent();
        $this->storeDjText(100, 'DJ Anna plays swing.');
        $this->queueAnnounce($this->twoDays());

        self::assertTrue($this->announce());

        self::assertSame(['sendPhoto'], $this->calledMethods());
        self::assertSame(
            ['event' => 100, 'day' => '', 'waited' => 0],
            $this->options[BoardService::NEXT_CARD_OPTION]
        );

        // The next runs, that day and the days after, post nothing.
        $this->telegramCalls = [];

        foreach ([['2026-07-20', 13], ['2026-07-21', 9], ['2026-07-31', 20]] as [$day, $hour]) {
            $this->at($day, $hour);
            $this->wpdb->nextResults[] = $this->twoDays();
            self::assertFalse($this->announce());
        }

        self::assertSame([], $this->telegramCalls);
    }

    public function testThePictureIsPostedAgainOnTheEventsMorningAndOnlyOnce(): void
    {
        $this->partyEvent();
        $this->storeDjText(100, 'DJ Anna plays swing.');
        $this->options[BoardService::NEXT_CARD_OPTION] = ['event' => 100, 'day' => '', 'waited' => 0];
        $this->at('2026-08-01', 9);
        $this->queueAnnounce($this->twoDays());

        self::assertTrue($this->announce());

        self::assertSame(['sendPhoto'], $this->calledMethods());
        self::assertSame(
            ['event' => 100, 'day' => '2026-08-01', 'waited' => 0],
            $this->options[BoardService::NEXT_CARD_OPTION]
        );

        $this->telegramCalls = [];
        $this->at('2026-08-01', 10);
        $this->wpdb->nextResults[] = $this->twoDays();

        self::assertFalse($this->announce());
        self::assertSame([], $this->telegramCalls);
    }

    public function testAnotherEventBecomingTheNextOneIsPostedToo(): void
    {
        $this->partyEvent();
        $this->storeDjText(100, 'DJ Anna plays swing.');
        $this->options[BoardService::NEXT_CARD_OPTION] = ['event' => 99, 'day' => '2026-07-19', 'waited' => 0];
        $this->queueAnnounce($this->twoDays());

        self::assertTrue($this->announce());

        self::assertSame(100, $this->options[BoardService::NEXT_CARD_OPTION]['event']);
    }

    public function testNothingIsPostedBeforeNineOrFromNineInTheEvening(): void
    {
        $this->partyEvent();
        $this->storeDjText(100, 'DJ Anna plays swing.');

        foreach ([0, 8, 21, 23] as $hour) {
            $this->at('2026-07-20', $hour);
            self::assertFalse($this->announce());
        }

        self::assertSame([], $this->telegramCalls);
        self::assertArrayNotHasKey(BoardService::NEXT_CARD_OPTION, $this->options);

        // The last hour of the day's window still posts.
        $this->at('2026-07-20', 20);
        $this->queueAnnounce($this->twoDays());

        self::assertTrue($this->announce());
    }

    public function testNothingIsPostedWithTheCardFormatOffOrWithoutABoardChat(): void
    {
        $this->partyEvent();
        $this->storeDjText(100, 'DJ Anna plays swing.');
        $this->queueAnnounce($this->twoDays());

        $this->options[BoardService::CARDS_OPTION] = false;
        self::assertFalse($this->announce());

        $this->options[BoardService::CARDS_OPTION] = true;
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 0, 'message_id' => 0];
        self::assertFalse($this->announce());

        unset($this->options[BoardService::BOARD_OPTION]);
        self::assertFalse($this->announce());

        self::assertSame([], $this->telegramCalls);
        self::assertArrayNotHasKey(BoardService::NEXT_CARD_OPTION, $this->options);
    }

    public function testAMissingDjTextWaitsOneRunThenThePictureIsPostedAnyway(): void
    {
        $this->options[AssistantSettings::URL_OPTION] = 'https://llm.example.test/v1';
        $this->options[AssistantSettings::MODEL_OPTION] = 'test-model';
        $this->partyEvent();
        // Written for an older description: not shown, and written again.
        $this->storeDjText(100, 'An old text.', md5('older source'));
        $this->wpdb->nextResults[] = $this->twoDays();

        self::assertFalse($this->announce());

        // One background run asked for, no model request and nothing posted.
        self::assertSame([BoardDjText::HOOK], array_column($this->scheduled, 1));
        self::assertSame([], $this->telegramCalls);
        self::assertSame(100, $this->options[BoardService::NEXT_CARD_OPTION]['waited']);

        $this->queueAnnounce($this->twoDays());

        self::assertTrue($this->announce());

        self::assertSame(['sendPhoto'], $this->calledMethods());
        self::assertSame("<b>Party</b>\n📅 1.8.2026 · 21:00\n🎟 10 €", $this->lastCallTo('sendPhoto')['caption']);
        self::assertSame(
            ['event' => 100, 'day' => '', 'waited' => 0],
            $this->options[BoardService::NEXT_CARD_OPTION]
        );
    }

    public function testOnTheEventsDayAMissingDjTextDoesNotWait(): void
    {
        $this->partyEvent();
        $this->at('2026-08-01', 9);
        $this->queueAnnounce($this->twoDays());

        self::assertTrue($this->announce());

        self::assertSame(['sendPhoto'], $this->calledMethods());
        self::assertSame(
            ['event' => 100, 'day' => '2026-08-01', 'waited' => 0],
            $this->options[BoardService::NEXT_CARD_OPTION]
        );
    }

    public function testAFailedPostIsTriedAgainOnTheNextRun(): void
    {
        $this->partyEvent();
        $this->storeDjText(100, 'DJ Anna plays swing.');
        $this->telegramErrors['sendPhoto'] = 'Bad Request: wrong file identifier/HTTP URL specified';
        $this->queueAnnounce($this->twoDays());

        self::assertFalse($this->announce());
        self::assertArrayNotHasKey(BoardService::NEXT_CARD_OPTION, $this->options);

        unset($this->telegramErrors['sendPhoto']);
        $this->queueAnnounce($this->twoDays());

        self::assertTrue($this->announce());
        self::assertSame(['sendPhoto', 'sendPhoto'], $this->calledMethods());
    }

    public function testNoPictureMeansNoPhotoMessage(): void
    {
        $this->partyEvent(['_eventmesh_image_url' => '']);
        $this->storeDjText(100, 'DJ Anna plays swing.');
        $this->queueRefresh($this->twoDays());

        self::assertTrue($this->board()->refresh());
        $this->wpdb->nextResults[] = $this->twoDays();
        self::assertFalse($this->announce());

        self::assertSame(['sendMessage'], $this->calledMethods());
        self::assertArrayNotHasKey(BoardService::NEXT_CARD_OPTION, $this->options);
    }

    public function testThePictureIsOnlyForAnEventOnTheFirstDay(): void
    {
        // The event with a picture is on the second day: no photo message.
        $this->event(100, '2026-08-02', 'Party', ['_eventmesh_image_url' => self::IMAGE]);
        $this->storeDjText(100, 'DJ Anna plays swing.');
        $this->wpdb->nextResults[] = [
            $this->taskRow(2, null, 'Cleanup', '2026-08-01'),
            $this->taskRow(1, 100, 'Party', '2026-08-02'),
        ];

        self::assertFalse($this->announce());

        self::assertSame([], $this->telegramCalls);
    }

    public function testTheCaptionFitsTelegramsLimitWithoutCuttingAnEntity(): void
    {
        $title = str_repeat('Big & Loud ', 30);
        $this->event(100, '2026-08-01', $title, [
            '_eventmesh_image_url' => self::IMAGE,
            '_eventmesh_price' => '10.00 EUR',
            '_eventmesh_original_lang' => 'en',
            '_eventmesh_tr_fi_title' => 'Suomeksi & ' . $title,
        ], 'Rock & roll.');
        $this->fakeEventNames([], [100 => array_fill(0, 20, 'DJ & Friend')]);
        $this->storeDjText(100, str_repeat('Swing & <blues>. ', 23));
        $this->queueAnnounce([$this->taskRow(1, 100, 'Party')]);

        self::assertTrue($this->announce());

        $caption = $this->lastCallTo('sendPhoto')['caption'];
        self::assertLessThanOrEqual(EventCard::CAPTION_LIMIT, self::visibleLength($caption));
        self::assertStringContainsString('Swing &amp; &lt;blues&gt;.', $caption);
        // Every & starts a whole entity.
        self::assertSame(0, preg_match('/&(?!amp;|lt;|gt;)/', $caption));
    }

    // --- A picture stored with the board before Q360 ------------------------

    public function testALegacyPictureIsForgottenNotDeletedAndItsEventIsNotPostedAgain(): void
    {
        $this->postLegacyBoard();
        $this->storeDjText(100, 'DJ Anna plays swing.');
        $this->queueRefresh($this->twoDays());

        self::assertTrue($this->board()->refresh());

        self::assertSame(['editMessageText'], $this->calledMethods());
        self::assertSame([], $this->deleted());
        self::assertSame(
            ['chat_id' => self::CHAT, 'message_id' => 62],
            $this->options[BoardService::BOARD_OPTION]
        );
        self::assertSame(
            ['event' => 100, 'day' => '', 'waited' => 0],
            $this->options[BoardService::NEXT_CARD_OPTION]
        );

        // It counts as that event's "became next" post.
        $this->telegramCalls = [];
        $this->wpdb->nextResults[] = $this->twoDays();

        self::assertFalse($this->announce());
        self::assertSame([], $this->telegramCalls);
    }

    public function testWithTheCardFormatOffALegacyPictureIsDeleted(): void
    {
        $this->postLegacyBoard();
        $this->options[BoardService::CARDS_OPTION] = false;
        $this->wpdb->nextResults[] = $this->twoDays();
        $this->wpdb->nextResults[] = [];

        self::assertTrue($this->board()->refresh());

        self::assertSame(['deleteMessage', 'editMessageText'], $this->calledMethods());
        self::assertSame([61], $this->deleted());
        self::assertSame(62, $this->lastCallTo('editMessageText')['message_id']);
        self::assertNotContains('bd:2026-08-02', self::targets($this->lastCallTo('editMessageText')));
        self::assertSame(
            ['chat_id' => self::CHAT, 'message_id' => 62],
            $this->options[BoardService::BOARD_OPTION]
        );
        self::assertArrayNotHasKey(BoardService::NEXT_CARD_OPTION, $this->options);
    }

    /** Stores the hash of the board as it renders now, so the text is "unchanged". */
    private function storeUnchangedHash(): void
    {
        $this->wpdb->nextResults[] = $this->twoDays();
        $this->wpdb->nextResults[] = [];
        $rendered = $this->board()->render();
        $this->options[BoardService::BOARD_HASH_OPTION] = md5(
            $rendered['text'] . '|' . (string) wp_json_encode($rendered['keyboard']) . '|[]'
        );
    }

    public function testTheHourlyCheckForgetsALegacyPictureEvenWithAnUnchangedText(): void
    {
        $this->postLegacyBoard();
        $this->storeUnchangedHash();
        for ($i = 0; $i < 2; $i++) { // the hash, then the refresh
            $this->wpdb->nextResults[] = $this->twoDays();
            $this->wpdb->nextResults[] = [];
        }

        $this->board()->refreshIfChanged();

        self::assertSame(['editMessageText'], $this->calledMethods());
        self::assertArrayNotHasKey('card', $this->options[BoardService::BOARD_OPTION]);
    }

    public function testTheHourlyCheckDeletesALegacyPictureAfterSwitchingOffEvenWithAnUnchangedText(): void
    {
        $this->postLegacyBoard();
        $this->options[BoardService::CARDS_OPTION] = false;
        $this->storeUnchangedHash();
        for ($i = 0; $i < 2; $i++) { // the hash, then the refresh
            $this->wpdb->nextResults[] = $this->twoDays();
            $this->wpdb->nextResults[] = [];
        }

        $this->board()->refreshIfChanged();

        self::assertSame(['deleteMessage', 'editMessageText'], $this->calledMethods());
    }

    public function testRepostPostsTheTextBoardThenDeletesTheOldMessages(): void
    {
        $this->options[BoardService::BOARD_OPTION] = [
            'chat_id' => self::CHAT,
            'message_id' => 5,
            'card' => ['message_id' => 4, 'event' => 100, 'photo' => self::IMAGE, 'text' => 'x', 'keyboard' => 'y'],
            'days' => ['2026-08-01' => ['message_id' => 3, 'kind' => 'text']],
        ];
        $this->partyEvent();
        $this->queueRefresh($this->twoDays());

        $this->board()->repost();

        self::assertSame(
            ['sendMessage', 'deleteMessage', 'deleteMessage', 'deleteMessage'],
            $this->calledMethods()
        );
        self::assertSame([5, 4, 3], $this->deleted());
        self::assertSame(
            ['chat_id' => self::CHAT, 'message_id' => 62],
            $this->options[BoardService::BOARD_OPTION]
        );
    }

    public function testAFailedRepostKeepsTheOldBoard(): void
    {
        $old = [
            'chat_id' => self::CHAT,
            'message_id' => 5,
            'card' => ['message_id' => 4, 'event' => 100, 'photo' => self::IMAGE, 'text' => 'x', 'keyboard' => 'y'],
        ];
        $this->options[BoardService::BOARD_OPTION] = $old;
        $this->telegramErrors['sendMessage'] = 'Forbidden: bot was kicked from the supergroup chat';
        $this->partyEvent();
        $this->queueRefresh($this->twoDays());

        self::assertFalse($this->board()->repost());

        self::assertSame(['sendMessage'], $this->calledMethods());
        self::assertSame($old, $this->options[BoardService::BOARD_OPTION]);
    }

    // --- Who is signed up, the next event, the day buttons -------------------

    public function testTheTextNamesWhoIsSignedUpForWhatAndWhenAndNeverAnEmail(): void
    {
        $this->partyEvent();
        $this->queueRefresh($this->twoDays(), [
            ['task_id' => 1, 'display_name' => 'Anna Virtanen'],
            ['task_id' => 1, 'display_name' => ''],
            ['task_id' => 2, 'display_name' => 'Ben'],
        ]);

        $this->board()->refresh();

        $text = $this->lastCallTo('sendMessage');
        self::assertSame(
            self::INTRO . "\n\n📅 Sat 1 Aug\nParty\n\n🎈 Decorate · 2/2\nAnna, someone",
            $text['text']
        );
        // A full task has no button, and the later day is not shown.
        self::assertSame([], self::taskTargets($text));

        // The names come from display_name; the query never reads an email.
        $query = array_values(array_filter(
            $this->wpdb->queries,
            static fn (string $sql): bool => str_contains($sql, 'display_name')
        ));
        self::assertCount(1, $query);
        self::assertStringNotContainsString('email', $query[0]);
        self::assertStringNotContainsString('@', $text['text']);
    }

    /**
     * Only the next date is in the text, and only it has buttons (Owner
     * 2026-11-07: the selected day only).
     */
    public function testOnlyTheNextEventIsListedAndHasButtons(): void
    {
        $rows = [];

        for ($day = 1; $day <= 5; $day++) {
            $rows[] = $this->taskRow($day, null, 'Night ' . $day, sprintf('2026-08-%02d', $day));
        }

        $this->queueRefresh($rows);

        $this->board()->refresh();

        $text = $this->lastCallTo('sendMessage');
        self::assertSame(1, substr_count($text['text'], '📅 '));
        self::assertStringContainsString('Night 1', $text['text']);
        self::assertStringNotContainsString('Night 2', $text['text']);
        self::assertSame(['t:1'], self::taskTargets($text));
        // No day buttons on the group's message (Owner 2026-11-07).
        $picker = array_values(array_filter(
            self::targets($text),
            static fn (string $target): bool => str_starts_with($target, BoardService::PICK_PREFIX)
        ));
        self::assertSame([], $picker);
    }

    public function testAPickedDayShowsThatDayWithItsNamesAndDayOnTheTaskButtons(): void
    {
        $this->wpdb->nextResults[] = $this->twoDays();
        $this->wpdb->nextResults[] = [['task_id' => 2, 'display_name' => 'Ben']];

        $board = (new BoardRenderer(new TaskRepository()))->crewBoard('2026-08-02', false);

        self::assertSame(self::INTRO . "\n\n📅 Sun 2 Aug\nCleanup\n\n🎈 Decorate · 1/2\nBen", $board['text']);
        $data = array_column(array_merge(...$board['keyboard']), 'callback_data', 'text');
        self::assertSame('t:2:2026-08-02', $data['🎈 Decorate'] ?? null);
        self::assertSame('bd:2026-08-01', $data['◀ Sat 1 Aug'] ?? null);
        self::assertSame('bd:2026-08-02', $data['📅 Sun 2 Aug ✓'] ?? null);
    }

    public function testOneDayHasNoDayButtons(): void
    {
        $this->queueRefresh([$this->taskRow(1, null, 'Party')]);

        $this->board()->refresh();

        foreach (self::targets($this->lastCallTo('sendMessage')) as $target) {
            self::assertStringStartsNotWith(BoardService::PICK_PREFIX, $target);
        }
    }

    public function testATooLongTextDropsWholeLinesFromTheEnd(): void
    {
        $rows = [];
        $occupants = [];
        $name = str_repeat('a', 60);

        for ($id = 1; $id <= 60; $id++) {
            $rows[] = $this->taskRow($id, null, 'Night', sprintf('2026-08-%02d', intdiv($id - 1, 20) + 1));

            for ($n = 0; $n < 10; $n++) {
                $occupants[] = ['task_id' => $id, 'display_name' => $name];
            }
        }

        $this->queueRefresh($rows, $occupants);

        $this->board()->refresh();

        $text = $this->lastCallTo('sendMessage')['text'];
        self::assertLessThanOrEqual(BoardRenderer::TEXT_LIMIT, EntityHtml::units($text));
        self::assertGreaterThan(BoardRenderer::TEXT_LIMIT - 700, EntityHtml::units($text));
        $names = implode(', ', array_fill(0, 10, $name));
        $lines = array_slice(explode("\n", $text), 2);

        foreach ($lines as $i => $line) {
            $task = '🎈 Decorate · 10/2' === $line;
            self::assertTrue('' === $line || str_starts_with($line, '📅 ') || 'Night' === $line || $task || $names === $line, $line);

            // A task line is never left without its names under it.
            if ($task) {
                self::assertSame($names, $lines[$i + 1] ?? null);
            }
        }
    }

    public function testBothFormatsHaveTheDayButtonsOnlyInThePrivateTextBoard(): void
    {
        $this->partyEvent();

        foreach ([true, false] as $cards) {
            $this->options[BoardService::CARDS_OPTION] = $cards;
            $this->options[BoardService::BOARD_OPTION] = ['chat_id' => self::CHAT, 'message_id' => 0];
            $this->telegramCalls = [];
            $this->queueRefresh($this->twoDays());
            $this->board()->refresh();
            $this->queueRefresh($this->twoDays());
            $this->board()->sendPrivate(555);

            self::assertSame(['sendMessage', 'sendMessage'], $this->calledMethods());
            self::assertNotContains('bd:2026-08-02', self::targets($this->telegramCalls[0]['body']));
            self::assertContains('bd:2026-08-02', self::targets($this->telegramCalls[1]['body']));
        }
    }

    public function testWithTheSettingOffTheBoardIsUnchanged(): void
    {
        $this->options[BoardService::CARDS_OPTION] = false;
        $this->partyEvent();
        $this->wpdb->nextResults[] = $this->twoDays();
        $this->wpdb->nextResults[] = [['task_id' => 1, 'taken' => 1]];

        self::assertTrue($this->board()->refresh());

        self::assertSame(['sendMessage'], $this->calledMethods());
        $sent = $this->lastCallTo('sendMessage');
        self::assertSame(
            self::INTRO . "\n\n📅 Sat 1 Aug\nParty\n\n🎈 Decorate · 1/2",
            $sent['text']
        );
        self::assertSame(['t:1'], self::taskTargets($sent));
        self::assertNotContains('bd:2026-08-02', self::targets($sent));
        self::assertContains('🎈 Decorate', self::labels($sent));
        self::assertSame([], array_filter(
            $this->wpdb->queries,
            static fn (string $sql): bool => str_contains($sql, 'display_name')
        ));
        self::assertSame(
            ['chat_id' => self::CHAT, 'message_id' => 62],
            $this->options[BoardService::BOARD_OPTION]
        );
    }

    // --- Refresh -------------------------------------------------------------

    public function testAnUnchangedBoardIsEditedInPlace(): void
    {
        $this->postBoard();
        $this->queueRefresh($this->twoDays());

        self::assertTrue($this->board()->refresh());

        // The text board is edited in place (Telegram answers "not modified").
        self::assertSame(['editMessageText'], $this->calledMethods());
        self::assertSame(62, $this->lastCallTo('editMessageText')['message_id']);
    }

    public function testANewCountEditsOnlyTheTextBoard(): void
    {
        $this->postBoard();
        $this->queueRefresh($this->twoDays(), [['task_id' => 1, 'display_name' => 'Anna']]);

        self::assertTrue($this->board()->refresh());

        self::assertSame(['editMessageText'], $this->calledMethods());
        $edit = $this->lastCallTo('editMessageText');
        self::assertStringContainsString("🎈 Decorate · 1/2\nAnna", $edit['text']);
    }

    public function testMessagesLeftFromOneMessagePerDayAreDeleted(): void
    {
        $this->options[BoardService::BOARD_OPTION]['days'] = [
            '2026-08-01' => ['message_id' => 33, 'kind' => 'card', 'photo' => self::IMAGE],
            '2026-08-02' => ['message_id' => 34, 'kind' => 'text', 'photo' => ''],
        ];
        $this->partyEvent();
        $this->queueRefresh($this->twoDays());

        $this->board()->refresh();

        self::assertSame(['deleteMessage', 'deleteMessage', 'sendMessage'], $this->calledMethods());
        self::assertSame([33, 34], $this->deleted());
        self::assertArrayNotHasKey('days', $this->options[BoardService::BOARD_OPTION]);
    }

    public function testTheHourlyCheckSendsNothingWhenNothingChanged(): void
    {
        $this->postBoard();

        for ($i = 0; $i < 3; $i++) { // the hash and the refresh, then the hash
            $this->wpdb->nextResults[] = $this->twoDays();
            $this->wpdb->nextResults[] = [];
        }

        $this->board()->refreshIfChanged();
        self::assertSame(['editMessageText'], $this->calledMethods());
        $this->telegramCalls = [];
        $this->board()->refreshIfChanged();

        self::assertSame([], $this->telegramCalls);
    }

    public function testAnEmptyBoardIsOneTextMessage(): void
    {
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];

        self::assertTrue($this->board()->refresh());

        self::assertSame(['sendMessage'], $this->calledMethods());
        self::assertSame('No open tasks right now. Check back soon!', $this->lastCallTo('sendMessage')['text']);
    }

    // --- The banner (Q361) ---------------------------------------------------

    /**
     * Chooses page 822 for the banner, with featured image 823 on file; the
     * strip is "made" by a stub image editor. Returns the banner's URL.
     */
    private function chooseBannerPage(): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'ecb');
        $this->options[BoardBanner::PAGE_OPTION] = 822;

        Functions\when('get_post')->alias(function (int $id): ?object {
            if (822 !== $id) {
                return $this->events[$id]['post'] ?? null;
            }

            $post = new \WP_Post();
            $post->ID = 822;
            $post->post_status = 'publish';

            return $post;
        });
        Functions\when('parse_blocks')->justReturn([]);
        Functions\when('get_post_thumbnail_id')->justReturn(823);
        Functions\when('get_attached_file')->justReturn($file);
        Functions\when('wp_upload_dir')->justReturn([
            'basedir' => sys_get_temp_dir() . '/ec-banner-' . getmypid(),
            'baseurl' => 'https://site.test/uploads',
            'error' => false,
        ]);
        Functions\when('trailingslashit')->alias(static fn (string $path): string => rtrim($path, '/') . '/');
        Functions\when('wp_mkdir_p')->justReturn(true);
        Functions\when('wp_delete_file')->justReturn(null);
        Functions\when('wp_get_image_editor')->justReturn(new class () {
            /** @return array{width: int, height: int} */
            public function get_size(): array // phpcs:ignore PSR1.Methods.CamelCapsMethodName
            {
                return ['width' => 1200, 'height' => 800];
            }

            public function crop(): bool
            {
                return true;
            }

            public function set_quality(): bool // phpcs:ignore PSR1.Methods.CamelCapsMethodName
            {
                return true;
            }

            public function save(): bool
            {
                return true;
            }
        });

        return BoardBanner::url();
    }

    public function testEveryBoardMessageAndEditCarriesTheBannerAsALargePreviewAboveTheText(): void
    {
        $url = $this->chooseBannerPage();
        self::assertStringStartsWith('https://site.test/uploads/eventcrew/board-banner-823-', $url);
        $this->partyEvent();

        $this->queueRefresh($this->twoDays());
        $this->board()->refresh();
        $this->queueRefresh($this->twoDays());
        $this->board()->refresh();
        $this->queueRefresh($this->twoDays());
        $this->board()->sendPrivate(555);
        $this->queueRefresh($this->twoDays());
        $this->board()->repost();

        self::assertSame(
            ['sendMessage', 'editMessageText', 'sendMessage', 'sendMessage', 'deleteMessage'],
            $this->calledMethods()
        );

        foreach ($this->telegramCalls as $call) {
            if ('deleteMessage' === $call['method']) {
                continue;
            }

            self::assertSame(
                ['url' => $url, 'prefer_large_media' => true, 'show_above_text' => true],
                $call['body']['link_preview_options'] ?? null,
                $call['method']
            );
        }
    }

    public function testWithoutABannerPageTheBoardHasNoLinkPreviewOptions(): void
    {
        $this->partyEvent();

        $this->queueRefresh($this->twoDays());
        $this->board()->refresh();
        $this->queueRefresh($this->twoDays());
        $this->board()->refresh();
        $this->queueRefresh($this->twoDays());
        $this->board()->sendPrivate(555);

        self::assertSame(['sendMessage', 'editMessageText', 'sendMessage'], $this->calledMethods());

        foreach ($this->telegramCalls as $call) {
            self::assertArrayNotHasKey('link_preview_options', $call['body']);
        }
    }

    public function testThePictureNeverCarriesTheBanner(): void
    {
        $this->chooseBannerPage();
        $this->partyEvent();
        $this->at('2026-08-01', 10);
        $this->queueAnnounce($this->twoDays());

        self::assertTrue($this->announce());

        self::assertArrayNotHasKey('link_preview_options', $this->lastCallTo('sendPhoto'));
    }

    // --- /board in a private chat -------------------------------------------

    public function testAPrivateBoardIsTheTextBoardOnlyAndLeavesTheGroupBoard(): void
    {
        $group = [
            'chat_id' => self::CHAT,
            'message_id' => 9,
            'card' => ['message_id' => 8, 'event' => 100, 'photo' => self::IMAGE, 'text' => 'x', 'keyboard' => 'y'],
        ];
        $this->options[BoardService::BOARD_OPTION] = $group;
        $this->partyEvent();
        $this->queueRefresh($this->twoDays());

        $this->board()->sendPrivate(555);

        self::assertSame(['sendMessage'], $this->calledMethods());
        self::assertSame(555, $this->lastCallTo('sendMessage')['chat_id']);
        self::assertSame(['t:1'], self::taskTargets($this->lastCallTo('sendMessage')));
        self::assertSame($group, $this->options[BoardService::BOARD_OPTION]);
    }

    public function testATapOnAPrivateBoardRedrawsItsTextBoard(): void
    {
        $this->partyEvent();
        $this->tapAsVerifiedPerson();
        $this->queueRefresh($this->twoDays()); // the group's refresh
        $this->wpdb->nextResults[] = $this->twoDays(); // the private redraw
        $this->wpdb->nextResults[] = [['task_id' => 2, 'display_name' => 'Sam']];

        $this->board()->onJoinLeave($this->privateTap('t:2:2026-08-02', 43));

        $private = $this->privateCalls();
        self::assertSame(['editMessageText'], array_column($private, 'method'));
        $edit = $private[0]['body'];
        self::assertSame(43, $edit['message_id']);
        // The copy stays on the day it showed.
        self::assertStringContainsString("📅 Sun 2 Aug\nCleanup\n\n🎈 Decorate · 1/2\nSam", $edit['text']);
        self::assertStringNotContainsString('Party', $edit['text']);
        self::assertSame(['t:2:2026-08-02'], self::taskTargets($edit));
        self::assertContains('bd:2026-08-01', self::targets($edit));
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
    private function privateTap(string $data, int $messageId): array
    {
        $message = ['message_id' => $messageId, 'chat' => ['id' => 555, 'type' => 'private']];

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
