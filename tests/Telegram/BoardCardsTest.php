<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Support\AssistantSettings;
use EventCrew\Telegram\BoardDjText;
use EventCrew\Telegram\BoardRenderer;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\EntityHtml;
use EventCrew\Telegram\EventCard;
use EventCrew\Tests\Support\FakeEventNames;

/**
 * The group board in card format (BoardService::CARDS_OPTION): two messages,
 * first a photo message for the next event (its picture, name, date, price,
 * the short DJ or host text, and its links), then the text board with the
 * next three events, who is signed up and the task buttons, and no day
 * buttons.
 */
final class BoardCardsTest extends TelegramTestCase
{
    use FakeEvents;
    use FakeEventNames;

    private const IMAGE = 'https://holvi-cdn.example.test/image/abc.jpg';
    private const IMAGE_NEW = 'https://holvi-cdn.example.test/image/new.jpg';
    private const SHOP = 'https://holvi.example.test/shop/x/product/1/';
    private const SHOP_NEW = 'https://holvi.example.test/shop/x/product/2/';
    private const CHAT = -100;
    private const INTRO = "Open tasks — tap one to sign up, tap again to cancel.\n"
        . 'Send /me in a DM to get your summary.';

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
     * card, the tasks for the text board, and who is signed up.
     *
     * @param list<array<string, mixed>> $tasks
     * @param list<array{task_id: int, display_name: string|null}> $occupants
     */
    private function queueRefresh(array $tasks, array $occupants = []): void
    {
        $this->wpdb->nextResults[] = $tasks;
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

    // --- The two messages ----------------------------------------------------

    public function testTheBoardIsThePhotoOfTheNextEventThenTheTextBoard(): void
    {
        $this->partyEvent();
        $this->queueRefresh($this->twoDays());

        self::assertTrue($this->board()->refresh());

        self::assertSame(['sendPhoto', 'sendMessage'], $this->calledMethods());

        $photo = $this->lastCallTo('sendPhoto');
        self::assertSame(self::IMAGE, $photo['photo']);
        self::assertSame('HTML', $photo['parse_mode']);
        // No DJ text written yet: the name, date and price lines only, and
        // never the event's long description or the board's lines.
        self::assertSame("<b>Party</b>\n📅 1.8.2026 · 21:00\n🎟 10 €", $photo['caption']);
        // The card's own links only: no task buttons on the photo.
        self::assertSame([self::SHOP], self::targets($photo));

        $text = $this->lastCallTo('sendMessage');
        self::assertSame(
            self::INTRO . "\n\n📅 Sat 1 Aug · Party\n🎈 Decorate: –\n\n📅 Sun 2 Aug · Cleanup\n🎈 Decorate: –",
            $text['text']
        );
        self::assertSame(['t:1', 't:2:2026-08-02'], self::taskTargets($text));

        $board = $this->options[BoardService::BOARD_OPTION];
        self::assertSame(62, $board['message_id']);
        self::assertSame(61, $board['card']['message_id']);
        self::assertSame(100, $board['card']['event']);
        self::assertSame(self::IMAGE, $board['card']['photo']);
        self::assertArrayNotHasKey('days', $board);
    }

    public function testTheCaptionShowsTheStoredDjTextEscaped(): void
    {
        $this->partyEvent();
        $this->storeDjText(100, 'DJ Anna plays swing & blues. She has played <everywhere>.');
        $this->queueRefresh($this->twoDays());

        $this->board()->refresh();

        self::assertSame(
            "<b>Party</b>\n📅 1.8.2026 · 21:00\n🎟 10 €\n\n"
                . 'DJ Anna plays swing &amp; blues. She has played &lt;everywhere&gt;.',
            $this->lastCallTo('sendPhoto')['caption']
        );
        self::assertSame([], $this->scheduled);
    }

    public function testAMissingDjTextAsksForOneBackgroundRunAndNoModelRequest(): void
    {
        $this->options[AssistantSettings::URL_OPTION] = 'https://llm.example.test/v1';
        $this->options[AssistantSettings::MODEL_OPTION] = 'test-model';
        $this->partyEvent();
        // Written for an older description: not shown, and written again.
        $this->storeDjText(100, 'An old text.', md5('older source'));
        $this->queueRefresh($this->twoDays());

        $this->board()->refresh();

        self::assertStringNotContainsString('An old text.', $this->lastCallTo('sendPhoto')['caption']);
        self::assertSame([BoardDjText::HOOK], array_column($this->scheduled, 1));
        self::assertSame(['sendPhoto', 'sendMessage'], $this->calledMethods());
    }

    public function testNoPictureMeansNoPhotoMessage(): void
    {
        $this->partyEvent(['_eventmesh_image_url' => '']);
        $this->queueRefresh($this->twoDays());

        self::assertTrue($this->board()->refresh());

        self::assertSame(['sendMessage'], $this->calledMethods());
        self::assertArrayNotHasKey('card', $this->options[BoardService::BOARD_OPTION]);
    }

    public function testTheCardIsOnlyForAnEventOnTheFirstDay(): void
    {
        // The event with a picture is on the second day: no photo message.
        $this->event(100, '2026-08-02', 'Party', ['_eventmesh_image_url' => self::IMAGE]);
        $this->queueRefresh([
            $this->taskRow(2, null, 'Cleanup', '2026-08-01'),
            $this->taskRow(1, 100, 'Party', '2026-08-02'),
        ]);

        $this->board()->refresh();

        self::assertSame(['sendMessage'], $this->calledMethods());
    }

    // --- Who is signed up, the next 3 events, no day buttons ----------------

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
            self::INTRO . "\n\n📅 Sat 1 Aug · Party\n🎈 Decorate: Anna, someone"
                . "\n\n📅 Sun 2 Aug · Cleanup\n🎈 Decorate: Ben",
            $text['text']
        );
        self::assertStringContainsString('2/2', self::labels($text)[2]);

        // The names come from display_name; the query never reads an email.
        $query = array_values(array_filter(
            $this->wpdb->queries,
            static fn (string $sql): bool => str_contains($sql, 'display_name')
        ));
        self::assertCount(1, $query);
        self::assertStringNotContainsString('email', $query[0]);
        self::assertStringNotContainsString('@', $text['text']);
    }

    public function testOnlyTheNextThreeEventsHaveTheirInformationAndButtons(): void
    {
        $rows = [];

        for ($day = 1; $day <= 5; $day++) {
            $rows[] = $this->taskRow($day, null, 'Night ' . $day, sprintf('2026-08-%02d', $day));
        }

        $this->queueRefresh($rows);

        $this->board()->refresh();

        $text = $this->lastCallTo('sendMessage');
        self::assertSame(3, substr_count($text['text'], '📅 '));
        self::assertStringContainsString('Night 3', $text['text']);
        self::assertStringNotContainsString('Night 4', $text['text']);
        self::assertSame(['t:1', 't:2:2026-08-02', 't:3:2026-08-03'], self::taskTargets($text));
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
        $full = '🎈 Decorate: ' . implode(', ', array_fill(0, 10, $name));

        foreach (array_slice(explode("\n", $text), 2) as $line) {
            self::assertTrue('' === $line || str_starts_with($line, '📅 ') || $full === $line, $line);
        }
    }

    public function testTheDatePickerIsThereOnlyWithTheSettingOff(): void
    {
        $this->partyEvent();

        // On: neither the group board nor a private board has day buttons.
        $this->queueRefresh($this->twoDays());
        $this->board()->refresh();
        $this->wpdb->nextResults[] = $this->twoDays(); // the card
        $this->wpdb->nextResults[] = $this->twoDays(); // the text board
        $this->wpdb->nextResults[] = [];
        $this->board()->sendPrivate(555);

        self::assertCount(4, $this->telegramCalls);
        foreach ($this->telegramCalls as $call) {
            foreach (self::targets($call['body']) as $target) {
                self::assertStringStartsNotWith(BoardService::PICK_PREFIX, $target);
            }
        }

        // Off: both have them.
        $this->options[BoardService::CARDS_OPTION] = false;
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => self::CHAT, 'message_id' => 0];
        $this->telegramCalls = [];
        $this->wpdb->nextResults[] = $this->twoDays();
        $this->wpdb->nextResults[] = [];
        $this->board()->refresh();
        $this->wpdb->nextResults[] = $this->twoDays();
        $this->wpdb->nextResults[] = [];
        $this->board()->sendPrivate(555);

        self::assertSame(['sendMessage', 'sendMessage'], $this->calledMethods());
        foreach ($this->telegramCalls as $call) {
            self::assertContains('bd:2026-08-02', self::targets($call['body']));
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
        self::assertSame(self::INTRO, $sent['text']);
        self::assertSame(['t:1'], self::taskTargets($sent));
        self::assertContains('bd:2026-08-02', self::targets($sent));
        self::assertStringContainsString('1/2', implode("\n", self::labels($sent)));
        self::assertSame([], array_filter(
            $this->wpdb->queries,
            static fn (string $sql): bool => str_contains($sql, 'display_name')
        ));
        self::assertSame(
            ['chat_id' => self::CHAT, 'message_id' => 62],
            $this->options[BoardService::BOARD_OPTION]
        );
    }

    // --- Refresh: the smallest edit -----------------------------------------

    /** One refresh, then the calls cleared. */
    private function postBoard(): void
    {
        $this->partyEvent();
        $this->queueRefresh($this->twoDays());
        $this->board()->refresh();
        $this->telegramCalls = [];
    }

    public function testAnUnchangedCardSendsNothing(): void
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
        self::assertStringContainsString('🎈 Decorate: Anna', $edit['text']);
        self::assertStringContainsString('1/2', self::labels($edit)[2]);
    }

    public function testANewPriceEditsTheCaption(): void
    {
        $this->postBoard();
        $this->partyEvent(['_eventmesh_price' => '12.00 EUR']);
        $this->queueRefresh($this->twoDays());

        self::assertTrue($this->board()->refresh());

        self::assertSame(['editMessageCaption', 'editMessageText'], $this->calledMethods());
        $edit = $this->lastCallTo('editMessageCaption');
        self::assertSame(61, $edit['message_id']);
        self::assertSame('HTML', $edit['parse_mode']);
        self::assertStringContainsString('🎟 12 €', $edit['caption']);
        self::assertSame([self::SHOP], self::targets($edit));
    }

    public function testANewPictureEditsTheMedia(): void
    {
        $this->postBoard();
        $this->partyEvent(['_eventmesh_image_url' => self::IMAGE_NEW]);
        $this->queueRefresh($this->twoDays());

        self::assertTrue($this->board()->refresh());

        self::assertSame(['editMessageMedia', 'editMessageText'], $this->calledMethods());
        $edit = $this->lastCallTo('editMessageMedia');
        self::assertSame(61, $edit['message_id']);
        self::assertSame(self::IMAGE_NEW, $edit['media']['media']);
        self::assertStringStartsWith('<b>Party</b>', $edit['media']['caption']);
        self::assertSame(self::IMAGE_NEW, $this->options[BoardService::BOARD_OPTION]['card']['photo']);
    }

    public function testNewLinksEditOnlyTheButtons(): void
    {
        $this->postBoard();
        $this->partyEvent(['_eventmesh_url' => self::SHOP_NEW]);
        $this->queueRefresh($this->twoDays());

        self::assertTrue($this->board()->refresh());

        self::assertSame(['editMessageReplyMarkup', 'editMessageText'], $this->calledMethods());
        $edit = $this->lastCallTo('editMessageReplyMarkup');
        self::assertSame(61, $edit['message_id']);
        self::assertSame([self::SHOP_NEW], self::targets($edit));
    }

    public function testTheCardIsDeletedWhenTheNextEventHasNoPicture(): void
    {
        $this->postBoard();
        $this->partyEvent(['_eventmesh_image_url' => '']);
        $this->queueRefresh($this->twoDays());

        self::assertTrue($this->board()->refresh());

        self::assertSame(['deleteMessage', 'editMessageText'], $this->calledMethods());
        self::assertSame([61], $this->deleted());
        self::assertSame(
            ['chat_id' => self::CHAT, 'message_id' => 62],
            $this->options[BoardService::BOARD_OPTION]
        );
    }

    public function testAMissingCardIsPostedAboveANewTextBoard(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => self::CHAT, 'message_id' => 5];
        $this->partyEvent();
        $this->queueRefresh($this->twoDays());
        $this->wpdb->nextResults[] = $this->twoDays(); // the text board posted again
        $this->wpdb->nextResults[] = [];

        self::assertTrue($this->board()->refresh());

        self::assertSame(['sendPhoto', 'sendMessage', 'deleteMessage'], $this->calledMethods());
        self::assertSame([5], $this->deleted());
        $board = $this->options[BoardService::BOARD_OPTION];
        self::assertSame([62, 61], [$board['message_id'], $board['card']['message_id']]);
    }

    public function testAFailedCardPostStillShowsTheTextBoardAndTriesAgainNextTime(): void
    {
        $this->partyEvent();
        $this->telegramErrors['sendPhoto'] = 'Bad Request: wrong file identifier/HTTP URL specified';
        $this->queueRefresh($this->twoDays());

        self::assertFalse($this->board()->refresh());

        self::assertSame(['sendPhoto', 'sendMessage'], $this->calledMethods());
        self::assertArrayNotHasKey('card', $this->options[BoardService::BOARD_OPTION]);
        self::assertSame(62, $this->options[BoardService::BOARD_OPTION]['message_id']);
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

        self::assertSame(['deleteMessage', 'deleteMessage', 'sendPhoto', 'sendMessage'], $this->calledMethods());
        self::assertSame([33, 34], $this->deleted());
        self::assertArrayNotHasKey('days', $this->options[BoardService::BOARD_OPTION]);
    }

    public function testSwitchingOffDeletesTheCardAndKeepsTheTextBoard(): void
    {
        $this->postBoard();
        $this->options[BoardService::CARDS_OPTION] = false;
        $this->wpdb->nextResults[] = $this->twoDays();
        $this->wpdb->nextResults[] = [];

        self::assertTrue($this->board()->refresh());

        self::assertSame(['deleteMessage', 'editMessageText'], $this->calledMethods());
        self::assertSame([61], $this->deleted());
        self::assertSame(62, $this->lastCallTo('editMessageText')['message_id']);
        self::assertContains('bd:2026-08-02', self::targets($this->lastCallTo('editMessageText')));
        self::assertSame(
            ['chat_id' => self::CHAT, 'message_id' => 62],
            $this->options[BoardService::BOARD_OPTION]
        );
    }

    public function testTheHourlyCheckDeletesTheCardAfterSwitchingOffEvenWithAnUnchangedText(): void
    {
        $this->postBoard();
        $this->options[BoardService::CARDS_OPTION] = false;
        $this->wpdb->nextResults[] = $this->twoDays();
        $this->wpdb->nextResults[] = [];
        $rendered = $this->board()->render();
        $this->options[BoardService::BOARD_HASH_OPTION] = md5(
            $rendered['text'] . '|' . (string) wp_json_encode($rendered['keyboard']) . '|null'
        );
        for ($i = 0; $i < 2; $i++) { // the hash, then the refresh
            $this->wpdb->nextResults[] = $this->twoDays();
            $this->wpdb->nextResults[] = [];
        }

        $this->board()->refreshIfChanged();

        self::assertSame(['deleteMessage', 'editMessageText'], $this->calledMethods());
    }

    public function testTheHourlyCheckSendsNothingWhenNothingChanged(): void
    {
        $this->postBoard();

        for ($i = 0; $i < 2; $i++) {
            $this->wpdb->nextResults[] = $this->twoDays(); // the text board
            $this->wpdb->nextResults[] = [];
            $this->wpdb->nextResults[] = $this->twoDays(); // the card
            $this->wpdb->nextResults[] = $this->twoDays(); // refresh: the card
            $this->wpdb->nextResults[] = $this->twoDays(); // refresh: the text board
            $this->wpdb->nextResults[] = [];
        }

        $this->board()->refreshIfChanged();
        $this->telegramCalls = [];
        $this->board()->refreshIfChanged();

        self::assertSame([], $this->telegramCalls);
    }

    public function testRepostPostsTheCardThenTheTextBoardThenDeletesTheOldMessages(): void
    {
        $this->options[BoardService::BOARD_OPTION] = [
            'chat_id' => self::CHAT,
            'message_id' => 5,
            'card' => ['message_id' => 4, 'event' => 100, 'photo' => self::IMAGE, 'text' => 'x', 'keyboard' => 'y'],
            'days' => ['2026-08-01' => ['message_id' => 3, 'kind' => 'text']],
        ];
        $this->partyEvent();
        $this->wpdb->nextResults[] = $this->twoDays();
        $this->wpdb->nextResults[] = $this->twoDays();
        $this->wpdb->nextResults[] = [];

        $this->board()->repost();

        self::assertSame(
            ['sendPhoto', 'sendMessage', 'deleteMessage', 'deleteMessage', 'deleteMessage'],
            $this->calledMethods()
        );
        self::assertSame([5, 4, 3], $this->deleted());
        $board = $this->options[BoardService::BOARD_OPTION];
        self::assertSame([62, 61], [$board['message_id'], $board['card']['message_id']]);
        self::assertArrayNotHasKey('days', $board);
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
        $this->queueRefresh([$this->taskRow(1, 100, 'Party')]);

        $this->board()->refresh();

        $caption = $this->lastCallTo('sendPhoto')['caption'];
        self::assertLessThanOrEqual(EventCard::CAPTION_LIMIT, self::visibleLength($caption));
        self::assertStringContainsString('Swing &amp; &lt;blues&gt;.', $caption);
        // Every & starts a whole entity.
        self::assertSame(0, preg_match('/&(?!amp;|lt;|gt;)/', $caption));
    }

    public function testAnEmptyBoardIsOneTextMessage(): void
    {
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];

        self::assertTrue($this->board()->refresh());

        self::assertSame(['sendMessage'], $this->calledMethods());
        self::assertSame('No open tasks right now. Check back soon!', $this->lastCallTo('sendMessage')['text']);
    }

    // --- /board in a private chat -------------------------------------------

    public function testAPrivateBoardIsTheCardThenTheTextBoardAndLeavesTheGroupBoard(): void
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

        self::assertSame(['sendPhoto', 'sendMessage'], $this->calledMethods());
        foreach ($this->telegramCalls as $call) {
            self::assertSame(555, $call['body']['chat_id']);
        }
        self::assertSame([self::SHOP], self::targets($this->lastCallTo('sendPhoto')));
        self::assertSame(['t:1', 't:2:2026-08-02'], self::taskTargets($this->lastCallTo('sendMessage')));
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
        self::assertStringContainsString('🎈 Decorate: Sam', $edit['text']);
        self::assertSame(['t:1', 't:2:2026-08-02'], self::taskTargets($edit));
        foreach (self::targets($edit) as $target) {
            self::assertStringStartsNotWith(BoardService::PICK_PREFIX, $target);
        }
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
