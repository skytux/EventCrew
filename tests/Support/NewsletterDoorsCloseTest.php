<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\EventTimes;
use EventCrew\Support\NewsletterLayout;
use EventCrew\Support\SiteLanguages;
use EventCrew\Tests\Telegram\FakeEvents;
use EventCrew\Tests\TestCase;

/**
 * Q332: the newsletter's "Doors close" line is the time the ticket shows
 * ({doors_close}) while the doors close setting is on, and the time the
 * language model read from the event text while it is off.
 */
final class NewsletterDoorsCloseTest extends TestCase
{
    use FakeEvents;

    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeEvents();
        // Starts at 21:00 (FakeEvents).
        $this->event(41, '2026-07-27', 'Summer Dance');
        $this->event(42, '2026-07-28', 'Picnic', ['_eventmesh_manual_starts_at' => '2026-07-28']);

        $this->options = [SiteLanguages::OPTION => ['fi', 'en']];
        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
    }

    /** @return array<string, mixed> */
    private static function card(int $id, string $doorsClose): array
    {
        return [
            'id' => $id,
            'date' => '2026-07-27',
            'time' => '21:00',
            'title' => 'Summer Dance',
            'title_fi' => '',
            'url' => 'https://site.test/event-' . $id . '/',
            'sold_out' => false,
            'practical' => [
                'venue' => 'Old Hall',
                'address' => '',
                'doors_open' => '20:30',
                'doors_close' => $doorsClose,
            ],
        ];
    }

    private function text(array $card): string
    {
        return NewsletterLayout::text([$card], [], null, 'en');
    }

    public function testWithTheSettingOffTheModelsTimeIsShown(): void
    {
        $text = $this->text(self::card(41, '19:00'));

        self::assertStringContainsString('Doors close 19:00', $text);
        self::assertStringContainsString('Doors open 20:30', $text);
    }

    public function testWithTheSettingOnTheTicketsTimeIsShown(): void
    {
        $this->options[EventTimes::DOORS_ON_OPTION] = '1';
        $this->options[EventTimes::DOORS_MINUTES_OPTION] = 30;

        $text = $this->text(self::card(41, '19:00'));

        self::assertStringContainsString('Doors close 21:30', $text);
        self::assertStringNotContainsString('19:00', $text);
        self::assertStringContainsString('Doors open 20:30', $text, 'the other lines stay');
    }

    public function testWithTheSettingOnTheLineIsShownEvenWhenTheModelFoundNoTime(): void
    {
        $this->options[EventTimes::DOORS_ON_OPTION] = '1';
        $card = self::card(41, '');
        unset($card['practical']);

        self::assertStringContainsString('Doors close 21:30', $this->text($card));
    }

    public function testTheEventsOwnDoorsTimeWinsAsOnTheTicket(): void
    {
        $this->options[EventTimes::DOORS_ON_OPTION] = '1';
        $this->events[41]['meta'][EventTimes::DOORS_META] = '22:15';

        self::assertStringContainsString('Doors close 22:15', $this->text(self::card(41, '19:00')));
    }

    public function testAnEventWithNoStartTimeKeepsTheModelsTime(): void
    {
        $this->options[EventTimes::DOORS_ON_OPTION] = '1';

        self::assertStringContainsString('Doors close 19:00', $this->text(self::card(42, '19:00')));
    }
}
