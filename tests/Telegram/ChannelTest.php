<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\FollowupRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\FollowupMail;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\ScheduledMessages;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\ChannelChat;
use EventCrew\Telegram\ChannelThanks;
use EventCrew\Telegram\GroupChat;
use EventCrew\Tests\Support\FakeEventNames;

/**
 * The crew's Telegram channel (Owner 2026-11-14, Q397-Q400): how the bot
 * learns and forgets it, and the thank-you card the day after each event
 * night. The next event's card in the channel is in BoardCardsTest.
 */
final class ChannelTest extends TelegramTestCase
{
    use FakeEvents;
    use FakeEventNames;

    private const CHANNEL = -200;
    private const IMAGE = 'https://holvi-cdn.example.test/image/past.jpg';
    private const SHOP = 'https://holvi.example.test/shop/x/product/2/';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeEvents();
        Functions\when('get_the_title')->justReturn('');
        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('UTC'));
        $this->telegramResults['sendPhoto'] = ['message_id' => 71];
        $this->telegramResults['sendMessage'] = ['message_id' => 72];
    }

    // --- Learning and forgetting the channel -------------------------------

    /** @param array<string, mixed> $extra */
    private function channelUpdate(string $status, int $fromId = 555, array $extra = []): array
    {
        return [
            'chat' => ['id' => self::CHANNEL, 'type' => 'channel', 'title' => 'Dance nights'],
            'from' => ['id' => $fromId],
            'new_chat_member' => ['status' => $status],
        ] + $extra;
    }

    public function testAnOrganizerMakingTheBotAnAdminConnectsTheChannel(): void
    {
        // A board in another group, locked: a channel is never left for it.
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => -100, 'message_id' => 3];
        $this->wpdb->nextRows[] = ['id' => 7, 'display_name' => 'Boss', 'is_organizer' => 1, 'telegram_user_id' => 555];

        $this->boardService()->onBotMembershipChange($this->channelUpdate('administrator'));

        self::assertSame(['id' => self::CHANNEL, 'title' => 'Dance nights'], $this->options[ChannelChat::OPTION]);
        self::assertSame(self::CHANNEL, ChannelChat::id());
        self::assertSame('Dance nights', ChannelChat::title());
        self::assertSame([], $this->telegramCalls);
        self::assertArrayNotHasKey(GroupChat::OPTION, $this->options);
        self::assertSame(-100, $this->options[BoardService::BOARD_OPTION]['chat_id']);
    }

    public function testSomeoneWhoIsNotAnOrganizerCannotConnectAChannel(): void
    {
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Sam', 'is_organizer' => 0, 'telegram_user_id' => 556];
        $this->boardService()->onBotMembershipChange($this->channelUpdate('administrator', 556));

        // Unknown to the site at all.
        $this->boardService()->onBotMembershipChange($this->channelUpdate('administrator', 557));

        self::assertSame(['leaveChat', 'leaveChat'], $this->calledMethods());
        self::assertSame(self::CHANNEL, $this->telegramCalls[0]['body']['chat_id']);
        self::assertArrayNotHasKey(ChannelChat::OPTION, $this->options);
    }

    public function testRemovingTheBotFromTheChannelForgetsIt(): void
    {
        $this->options[ChannelChat::OPTION] = ['id' => self::CHANNEL, 'title' => 'Dance nights'];

        // Another channel's removal changes nothing.
        $other = $this->channelUpdate('left');
        $other['chat']['id'] = -201;
        $this->boardService()->onBotMembershipChange($other);
        self::assertSame(self::CHANNEL, ChannelChat::id());

        $this->boardService()->onBotMembershipChange($this->channelUpdate('kicked'));

        self::assertSame(0, ChannelChat::id());
        self::assertSame('', ChannelChat::title());
        self::assertSame([], $this->telegramCalls);
    }

    public function testAPlainMemberUpdateOfAChannelDoesNothing(): void
    {
        $this->boardService()->onBotMembershipChange($this->channelUpdate('member'));

        self::assertArrayNotHasKey(ChannelChat::OPTION, $this->options);
        self::assertArrayNotHasKey(GroupChat::OPTION, $this->options);
        self::assertSame([], $this->telegramCalls);
    }

    // --- The thank-you card -------------------------------------------------

    private function thanks(): ChannelThanks
    {
        $logger = new Logger();

        return new ChannelThanks(
            $this->client(),
            new FollowupMail(
                new FollowupRepository(),
                new TaskRepository(),
                new PersonRepository(),
                new Mailer($logger, new EmailTemplate($logger)),
                $logger
            ),
            $logger
        );
    }

    /** Last night's event (19 July, with a picture) and the next one (1 August, with tickets). */
    private function nights(): void
    {
        $this->options[ChannelChat::OPTION] = ['id' => self::CHANNEL, 'title' => 'Dance nights'];
        $this->event(90, '2026-07-19', 'Summer Social', ['_eventmesh_image_url' => self::IMAGE]);
        $this->event(100, '2026-08-01', 'Party', [
            '_eventmesh_url' => self::SHOP,
            '_eventmesh_price' => '10.00 EUR',
        ]);
    }

    private static function at(string $when): \DateTimeImmutable
    {
        return new \DateTimeImmutable($when, new \DateTimeZone('UTC'));
    }

    public function testTheDayAfterAtTheEmailsHourTheThankYouIsPostedOnce(): void
    {
        $this->nights();

        self::assertSame(0, $this->thanks()->run(self::at('2026-07-20 11:00')));
        self::assertSame([], $this->telegramCalls);

        self::assertSame(1, $this->thanks()->run(self::at('2026-07-20 12:00')));

        self::assertSame(['sendPhoto'], $this->calledMethods());
        $body = $this->telegramCalls[0]['body'];
        self::assertSame(self::CHANNEL, $body['chat_id']);
        self::assertSame(self::IMAGE, $body['photo']);
        self::assertSame([[['text' => $body['reply_markup']['inline_keyboard'][0][0]['text'], 'url' => self::SHOP]]], $body['reply_markup']['inline_keyboard']);
        self::assertStringStartsWith('🎟 ', $body['reply_markup']['inline_keyboard'][0][0]['text']);
        self::assertSame(['2026-07-19' => true], $this->options[ChannelThanks::STATE_OPTION]);

        // Later runs post nothing more.
        $this->telegramCalls = [];
        self::assertSame(0, $this->thanks()->run(self::at('2026-07-20 13:00')));
        self::assertSame(0, $this->thanks()->run(self::at('2026-07-21 12:00')));
        self::assertSame([], $this->telegramCalls);
    }

    public function testTheThankYouHasNoNamesNoCountsAndBothLanguages(): void
    {
        $this->nights();

        $card = $this->thanks()->compose('2026-07-19', 90);

        self::assertStringStartsWith("<b>FI</b>\nKiitos, että olit mukana Summer Social", $card['caption']);
        self::assertStringContainsString("\n\n<b>EN</b>\nThank you for coming to Summer Social on 19.7.2026.", $card['caption']);
        self::assertSame(self::IMAGE, $card['photo']);
        self::assertStringNotContainsString('@', $card['caption']);
        self::assertDoesNotMatchRegularExpression('/\d+ (people|ihmist)/u', $card['caption']);
        self::assertStringNotContainsString('Hi,', $card['caption']);
    }

    public function testAThankYouIsGivenUpAfterTheEmailsLateDaysAndKeepsItsHours(): void
    {
        $this->nights();

        // Outside the card's hours, even when due.
        self::assertSame(0, $this->thanks()->run(self::at('2026-07-20 21:00')));
        self::assertSame(0, $this->thanks()->run(self::at('2026-07-21 08:00')));
        // FollowupMail::LATE_DAYS after the send time: given up.
        self::assertSame(0, $this->thanks()->run(self::at('2026-07-22 12:00')));

        self::assertSame([], $this->telegramCalls);
        self::assertArrayNotHasKey(ChannelThanks::STATE_OPTION, $this->options);

        // Within them, a late run still posts.
        self::assertSame(1, $this->thanks()->run(self::at('2026-07-22 11:00')));
    }

    public function testNoThankYouWithoutAChannelItsSwitchOrAnEventNight(): void
    {
        $this->event(100, '2026-08-01', 'Party', ['_eventmesh_url' => self::SHOP]);

        // No event last night.
        $this->options[ChannelChat::OPTION] = ['id' => self::CHANNEL, 'title' => 'Dance nights'];
        self::assertSame(0, $this->thanks()->run(self::at('2026-07-20 12:00')));
        self::assertFalse($this->thanks()->canSendNow(self::at('2026-07-20 12:00')));

        $this->nights();
        ScheduledMessages::setOn(ScheduledMessages::THANKS, false);
        self::assertSame(0, $this->thanks()->run(self::at('2026-07-20 12:00')));

        ScheduledMessages::setOn(ScheduledMessages::THANKS, true);
        ChannelChat::clear();
        self::assertSame(0, $this->thanks()->run(self::at('2026-07-20 12:00')));
        self::assertFalse($this->thanks()->canSendNow(self::at('2026-07-20 12:00')));
        self::assertFalse($this->thanks()->sendNow(self::at('2026-07-20 12:00')));

        self::assertSame([], $this->telegramCalls);
    }

    public function testSendNowPostsLastNightsThankYouAgainWhateverTheHour(): void
    {
        $this->nights();
        $this->options[ChannelThanks::STATE_OPTION] = ['2026-07-19' => true];

        self::assertTrue($this->thanks()->canSendNow(self::at('2026-07-20 23:00')));
        self::assertSame('2026-07-19', $this->thanks()->sendNowNight(self::at('2026-07-20 23:00')));
        self::assertTrue($this->thanks()->sendNow(self::at('2026-07-20 23:00')));

        self::assertSame(['sendPhoto'], $this->calledMethods());
        self::assertSame(self::CHANNEL, $this->telegramCalls[0]['body']['chat_id']);
    }

    public function testWithoutAPictureOrANextEventItIsATextWithoutButtons(): void
    {
        $this->options[ChannelChat::OPTION] = ['id' => self::CHANNEL, 'title' => 'Dance nights'];
        $this->event(90, '2026-07-19', 'Summer Social');

        self::assertSame(1, $this->thanks()->run(self::at('2026-07-20 12:00')));

        self::assertSame(['sendMessage'], $this->calledMethods());
        self::assertSame(self::CHANNEL, $this->telegramCalls[0]['body']['chat_id']);
        self::assertSame([], $this->telegramCalls[0]['body']['reply_markup']['inline_keyboard'] ?? []);
    }

    public function testTheSchedulePageListsBothChannelMessagesWithSendNow(): void
    {
        // ScheduledMessages asks about the real clock: last night is yesterday.
        $yesterday = (new \DateTimeImmutable('yesterday', new \DateTimeZone('UTC')))->format('Y-m-d');
        $this->event(90, $yesterday, 'Summer Social', ['_eventmesh_image_url' => self::IMAGE]);
        $scheduled = new ScheduledMessages(null, null, null, null, null, null, null, $this->thanks());

        self::assertTrue(ScheduledMessages::isOn(ScheduledMessages::THANKS));
        self::assertTrue(ScheduledMessages::isOn(ScheduledMessages::CHANNEL_CARD));
        // No channel yet: nothing to post.
        self::assertSame(0, $scheduled->count(ScheduledMessages::THANKS));
        self::assertSame('admin_sched_none', $scheduled->apply(ScheduledMessages::THANKS, 'send'));

        $this->options[ChannelChat::OPTION] = ['id' => self::CHANNEL, 'title' => 'Dance nights'];

        self::assertSame(1, $scheduled->count(ScheduledMessages::THANKS));
        self::assertSame('Post the thank-you card in the channel now?', $scheduled->question(ScheduledMessages::THANKS));
        self::assertSame([], $this->telegramCalls);

        $code = $scheduled->apply(ScheduledMessages::THANKS, 'send');

        self::assertSame('Thank-you card posted in the channel.', ScheduledMessages::noticeText($code));
        self::assertSame(['sendPhoto'], $this->calledMethods());
    }
}
