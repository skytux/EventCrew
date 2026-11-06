<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Support\Language;
use EventCrew\Support\SiteLanguages;
use EventCrew\Tests\FinnishCatalog;
use EventCrew\Tests\Support\FakeEventNames;
use EventCrew\Telegram\EntityHtml;
use EventCrew\Telegram\EventCard;

/**
 * One event as a Telegram photo message: the caption, the buttons, and the
 * text message sent instead when there is no picture or Telegram refuses it.
 */
final class EventCardTest extends TelegramTestCase
{
    use FakeEvents;
    use FakeEventNames;

    private const IMAGE = 'https://holvi-cdn.example.test/image/abc.jpg';
    private const SHOP = 'https://holvi.example.test/shop/x/product/1/';
    private const MUSIC = 'https://soundcloud.example.test/dj/set';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeEvents();
    }

    /** The caption as Telegram counts it: the text without tags and entities. */
    private static function visibleLength(string $caption): int
    {
        return EntityHtml::units(html_entity_decode(strip_tags($caption), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * An English event with a current Finnish translation of its text.
     *
     * @param array<string, string> $meta
     */
    private function bilingualEvent(int $id, string $title, string $en, string $fi, array $meta = []): void
    {
        $this->event($id, '2026-07-25', $title, $meta + [
            '_eventmesh_original_lang' => 'en',
            '_eventmesh_tr_fi_hash' => md5($title . "\0" . $en),
            '_eventmesh_tr_fi_content' => $fi,
            '_eventmesh_tr_fi_title' => 'Suomeksi: ' . $title,
        ], $en);
    }

    public function testTheCaptionHasTheTitleInBoldTheDateThePriceAndIsEscaped(): void
    {
        $this->event(7, '2026-07-25', 'Rock & <Roll>', ['_eventmesh_price' => '22.00 EUR'], '<p>a < b & c</p>');

        $caption = EventCard::forEvent(7)['caption'];

        self::assertStringStartsWith("<b>Rock &amp; &lt;Roll&gt;</b>\n📅 25.7.2026 · 21:00\n🎟 22 €\n\n", $caption);
        self::assertStringEndsWith('a &lt; b &amp; c', $caption);
    }

    public function testAPriceWithCentsIsWrittenTheFinnishWay(): void
    {
        $this->event(7, '2026-07-25', 'Night', ['_eventmesh_price' => '27.50 EUR']);

        self::assertStringContainsString('🎟 27,50 €', EventCard::forEvent(7)['caption']);
    }

    /** EventMesh's clean names and the DJ line in place of the title as written. */
    public function testTheCleanNameAndTheDjLineHeadTheCaption(): void
    {
        $this->fakeEventNames(
            [7 => ['en' => 'Ecstatic Dance', 'fi' => 'Ekstaattinen tanssi']],
            [7 => ['Aino Testinen', 'Bo Kokeilu', 'A & B']]
        );
        $this->bilingualEvent(7, 'ECSTATIC DANCE 25.7.2026 / Aino Testinen', 'Dance all night.', 'Tanssia koko yön.');

        $caption = EventCard::forEvent(7)['caption'];

        self::assertStringStartsWith(
            "<b>Ecstatic Dance</b>\n<i>Ekstaattinen tanssi</i>\nAino Testinen, Bo Kokeilu &amp; A &amp; B\n📅 25.7.2026",
            $caption
        );
        self::assertStringNotContainsString('ECSTATIC', $caption);
    }

    public function testTheFinnishTextComesFirstThenTheEnglish(): void
    {
        $this->bilingualEvent(7, 'Summer Night', 'Dance all night.', 'Tanssia koko yön.');

        $caption = EventCard::forEvent(7)['caption'];

        self::assertStringContainsString("<b>Summer Night</b>\n<i>Suomeksi: Summer Night</i>\n", $caption);
        self::assertStringEndsWith("<b>FI:</b> Tanssia koko yön.\n\n<b>EN:</b> Dance all night.", $caption);
    }

    public function testALongDescriptionIsCutAtASentenceEndAndTheCaptionFitsTheLimit(): void
    {
        $en = str_repeat('This is one sentence about the night. ', 100);
        $fi = str_repeat('Tämä on yksi lause illasta. ', 100);
        $this->bilingualEvent(7, str_repeat('Long title ', 40), $en, $fi, ['_eventmesh_price' => '22.00 EUR']);

        $caption = EventCard::forEvent(7)['caption'];

        self::assertLessThanOrEqual(EventCard::CAPTION_LIMIT, self::visibleLength($caption));
        self::assertMatchesRegularExpression('/<b>FI:<\/b> (Tämä on yksi lause illasta\. ?)+\n\n/u', $caption);
        self::assertMatchesRegularExpression('/<b>EN:<\/b> (This is one sentence about the night\. ?)+$/u', $caption);
    }

    public function testWithoutASentenceEndTheTextIsCutAtAWordWithAnEllipsis(): void
    {
        $this->event(7, '2026-07-25', 'Night', [], trim(str_repeat('alpha beta gamma ', 200)));

        $caption = EventCard::forEvent(7)['caption'];
        $about = substr($caption, (int) strrpos($caption, "\n\n") + 2);

        self::assertMatchesRegularExpression('/^(alpha|beta|gamma)( (alpha|beta|gamma))*…$/u', $about);
        self::assertLessThanOrEqual(300, EntityHtml::units($about));
    }

    public function testEmojiCountTwiceTowardsTheLimitLikeTelegramCountsThem(): void
    {
        $this->bilingualEvent(7, str_repeat('🎉', 300), str_repeat('🎶', 1000), str_repeat('🎵', 1000));

        self::assertLessThanOrEqual(EventCard::CAPTION_LIMIT, self::visibleLength(EventCard::forEvent(7)['caption']));
    }

    public function testASoldOutEventIsMarkedAndHasNoTicketButton(): void
    {
        $this->event(7, '2026-07-25', 'Night', [
            '_eventmesh_price' => '22.00 EUR',
            '_eventmesh_sold_out' => '1',
            '_eventmesh_url' => self::SHOP,
            '_eventmesh_embed_source_url' => self::MUSIC,
        ]);

        $card = EventCard::forEvent(7);

        self::assertStringContainsString("🚫 <b>Loppuunmyyty\nSold out</b>", $card['caption']);
        self::assertStringNotContainsString('22 €', $card['caption']);
        self::assertSame([[['text' => '▶ Kuuntele DJ:tä · Listen to the DJ', 'url' => self::MUSIC]]], $card['buttons']);
    }

    /**
     * A group post with three site languages: the text has a row for each
     * language, in the site order, and a button has the first two only, on
     * one row (Owner, 2026-11-06).
     */
    public function testAGroupPostHasARowPerLanguageAndTwoLanguageButtons(): void
    {
        $this->options[SiteLanguages::OPTION] = ['fi', 'en', 'sv'];
        Functions\when('__')->alias(
            static fn (string $text): string => 'sv' === Language::current()
                ? 'SV ' . $text
                : FinnishCatalog::translate($text)
        );
        $this->event(7, '2026-07-25', 'Night', [
            '_eventmesh_sold_out' => '1',
            '_eventmesh_embed_source_url' => self::MUSIC,
        ]);
        $this->event(8, '2026-07-26', 'Day', ['_eventmesh_url' => self::SHOP]);

        self::assertStringContainsString(
            "🚫 <b>Loppuunmyyty\nSold out\nSV Sold out</b>",
            EventCard::forEvent(7)['caption']
        );
        self::assertSame(
            [[['text' => '▶ Kuuntele DJ:tä · Listen to the DJ', 'url' => self::MUSIC]]],
            EventCard::forEvent(7)['buttons']
        );
        self::assertSame(
            [[['text' => '🎟 Osta lippu · Buy a ticket', 'url' => self::SHOP]]],
            EventCard::forEvent(8)['buttons']
        );
    }

    public function testTheButtonsBuyListenAndCrewApp(): void
    {
        $this->options['eventcrew_signup_page_id'] = 5;
        $this->event(7, '2026-07-25', 'Night', [
            '_eventmesh_price' => '27.50 EUR',
            '_eventmesh_url' => self::SHOP,
            '_eventmesh_embed_source_url' => self::MUSIC,
        ]);

        self::assertSame(
            [
                [['text' => '🎟 Osta lippu · Buy a ticket · 27,50 €', 'url' => self::SHOP]],
                [['text' => '▶ Kuuntele DJ:tä · Listen to the DJ', 'url' => self::MUSIC]],
                [['text' => '📱 Crew app', 'url' => 'https://site.test/page-5/']],
            ],
            EventCard::forEvent(7)['buttons']
        );
    }

    public function testWithoutAMusicPageThereIsNoListenButton(): void
    {
        $this->event(7, '2026-07-25', 'Night', ['_eventmesh_url' => self::SHOP]);

        self::assertSame(
            [[['text' => '🎟 Osta lippu · Buy a ticket', 'url' => self::SHOP]]],
            EventCard::forEvent(7)['buttons']
        );
    }

    public function testThePictureIsTheEventMeshImageAddress(): void
    {
        $this->event(7, '2026-07-25', 'Night', ['_eventmesh_image_url' => self::IMAGE]);

        $card = EventCard::forEvent(7);
        EventCard::send($this->client(), 555, $card);

        self::assertSame(self::IMAGE, $card['photo']);
        self::assertSame(['sendPhoto'], $this->calledMethods());
        self::assertSame(self::IMAGE, $this->lastCallTo('sendPhoto')['photo']);
        self::assertSame($card['caption'], $this->lastCallTo('sendPhoto')['caption']);
    }

    public function testWithoutAPictureTheCardIsATextMessageWithTheSameButtons(): void
    {
        $this->event(7, '2026-07-25', 'Night', ['_eventmesh_url' => self::SHOP]);

        $card = EventCard::forEvent(7);
        $sent = EventCard::send($this->client(), 555, $card);

        self::assertSame('', $card['photo']);
        self::assertNotNull($sent);
        self::assertSame(['sendMessage'], $this->calledMethods());
        $body = $this->lastCallTo('sendMessage');
        self::assertSame($card['caption'], $body['text']);
        self::assertSame('HTML', $body['parse_mode']);
        self::assertSame(['inline_keyboard' => $card['buttons']], $body['reply_markup']);
    }

    public function testAPictureTelegramRefusesFallsBackToATextMessage(): void
    {
        $this->telegramErrors['sendPhoto'] = 'Bad Request: failed to get HTTP URL content';
        $this->event(7, '2026-07-25', 'Night', ['_eventmesh_image_url' => self::IMAGE, '_eventmesh_url' => self::SHOP]);

        $card = EventCard::forEvent(7);
        $sent = EventCard::send($this->client(), 555, $card);

        self::assertNotNull($sent);
        self::assertSame(['sendPhoto', 'sendMessage'], $this->calledMethods());
        self::assertSame($card['caption'], $this->lastCallTo('sendMessage')['text']);
        self::assertSame(['inline_keyboard' => $card['buttons']], $this->lastCallTo('sendMessage')['reply_markup']);
    }

    public function testNoTextMessageWhenTelegramAsksToWait(): void
    {
        $this->telegramRateLimits['sendPhoto'] = 1;
        $this->event(7, '2026-07-25', 'Night', ['_eventmesh_image_url' => self::IMAGE]);

        self::assertNull(EventCard::send($this->client(), 555, EventCard::forEvent(7)));
        self::assertSame(['sendPhoto'], $this->calledMethods());
    }

    public function testAPostThatIsNotAnEventHasNoCard(): void
    {
        self::assertNull(EventCard::forEvent(99));
    }

    public function testTheComingEventsAreAfterTodayNotHiddenAndAtMostFive(): void
    {
        $this->event(1, '2026-07-20', 'Today');
        $this->event(2, '2026-07-10', 'Past');
        $this->event(3, '2026-07-22', 'Hidden', ['_eventmesh_manual_hidden' => '1']);
        $this->event(4, '2026-07-23', 'Draft', [], '', 'draft');
        $this->event(5, '2026-07-24', 'Sold out', ['_eventmesh_sold_out' => '1']);

        foreach (range(6, 11) as $id) {
            $this->event($id, '2026-08-' . sprintf('%02d', $id), 'Event ' . $id);
        }

        $titles = array_map(
            static fn (array $card): string => strtok($card['caption'], "\n"),
            EventCard::coming()
        );

        self::assertSame(
            ['<b>Sold out</b>', '<b>Event 6</b>', '<b>Event 7</b>', '<b>Event 8</b>', '<b>Event 9</b>'],
            $titles
        );
    }
}
