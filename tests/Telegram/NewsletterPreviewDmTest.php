<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use EventCrew\Models\Person;
use EventCrew\Support\SiteLanguages;
use EventCrew\Telegram\NewsletterPreviewDm;

/** The announcement's preview on Telegram: heading and text, one card per event, then the stop button. */
final class NewsletterPreviewDmTest extends TelegramTestCase
{
    use FakeEvents;

    private const STOP = 'https://site.test/wp-json/eventcrew/v1/newsletter?do=stop&token=x';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeEvents();
        $this->options[SiteLanguages::OPTION] = ['fi', 'en'];
    }

    private static function organizer(?int $chat, ?string $lang = 'en'): Person
    {
        return new Person(
            id: 2,
            email: 'org@example.test',
            displayName: 'Org',
            telegramUserId: $chat,
            telegramChatId: $chat,
            isOrganizer: true,
            language: $lang
        );
    }

    public function testItSendsTheTextTheCardsAndTheStopButton(): void
    {
        $this->event(7, '2026-11-28', 'Night', ['_eventmesh_url' => 'https://shop.example.test/1']);
        $this->event(8, '2026-12-12', 'Later night');

        $sent = (new NewsletterPreviewDm($this->client()))->send(
            self::organizer(555),
            [7, 8],
            ['fi' => 'Tervetuloa', 'en' => 'Welcome <all>'],
            '2026-11-22 10:00:00',
            32,
            self::STOP
        );

        self::assertTrue($sent);
        self::assertSame(['sendMessage', 'sendMessage', 'sendMessage', 'sendMessage'], $this->calledMethods());

        $first = $this->telegramCalls[0]['body'];
        self::assertSame("<b>Preview of the next announcement</b>\n\nWelcome &lt;all&gt;", $first['text']);
        self::assertSame('HTML', $first['parse_mode']);
        self::assertStringContainsString('<b>Night</b>', $this->telegramCalls[1]['body']['text']);
        self::assertStringContainsString('<b>Later night</b>', $this->telegramCalls[2]['body']['text']);

        $last = $this->lastCallTo('sendMessage');
        self::assertSame('Goes out on 22.11.2026 at 10:00 to 32 subscribers.', $last['text']);
        self::assertSame(
            [[['text' => '🛑 Do Not Send This', 'url' => self::STOP]]],
            $last['reply_markup']['inline_keyboard']
        );
    }

    public function testWithoutATextInTheirLanguageTheMainLanguageShows(): void
    {
        (new NewsletterPreviewDm($this->client()))->send(
            self::organizer(555, null),
            [],
            ['fi' => 'Tervetuloa'],
            '2026-11-22 10:00:00',
            1,
            self::STOP
        );

        self::assertStringEndsWith("\n\nTervetuloa", $this->telegramCalls[0]['body']['text']);
    }

    public function testNothingGoesToAnOrganizerWithoutTelegram(): void
    {
        $sent = (new NewsletterPreviewDm($this->client()))->send(
            self::organizer(null),
            [7],
            null,
            '2026-11-22 10:00:00',
            1,
            self::STOP
        );

        self::assertFalse($sent);
        self::assertSame([], $this->calledMethods());
    }
}
