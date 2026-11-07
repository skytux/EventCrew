<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Admin\AiPromptsPage;
use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Support\EventDetailsText;
use EventCrew\Support\EventVenue;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\TicketText;
use EventCrew\Tests\TestCase;

/**
 * {venue}: the event's own venue, else the default venue, else EventMesh's;
 * its row in the messages and the ticket, and the one-time migration of the
 * stored texts.
 */
final class EventVenueTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, string> */
    private array $meta = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->meta = [];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
        Functions\when('post_type_exists')->justReturn(true);
        Functions\when('get_post')->alias(static fn (int $id): object => (object) [
            'ID' => $id,
            'post_type' => 'eventmesh_event',
            'post_title' => 'Ecstatic Dance',
            'post_content' => '',
            'post_excerpt' => '',
        ]);
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key): string => $this->meta[$key] ?? ''
        );
        Functions\when('update_post_meta')->alias(function (int $id, string $key, string $value): bool {
            $this->meta[$key] = $value;

            return true;
        });
        Functions\when('delete_post_meta')->alias(function (int $id, string $key): bool {
            unset($this->meta[$key]);

            return true;
        });
        Functions\when('get_the_title')->justReturn('Ecstatic Dance');
    }

    public function testTheOwnVenueWinsThenTheDefaultThenEventMesh(): void
    {
        self::assertSame('', EventVenue::forEvent(12));

        $this->meta['_eventmesh_venue_name'] = 'Hall A';
        self::assertSame('Hall A', EventVenue::forEvent(12));

        $this->meta['_eventmesh_manual_venue_name'] = 'Hall B';
        self::assertSame('Hall B', EventVenue::forEvent(12), 'EventMesh’s own manual venue');

        EventVenue::saveDefault("  Studio\n Kallio ");
        self::assertSame('Studio Kallio', EventVenue::forEvent(12));
        self::assertSame('Studio Kallio', EventVenue::forEvent(null), 'no event: the default');

        self::assertSame('Garden', EventVenue::saveOwn(12, '<b>Garden</b>'));
        self::assertSame('Garden', EventVenue::forEvent(12));
        self::assertSame('📍 Garden', EventVenue::line(12));

        EventVenue::saveOwn(12, ' ');
        self::assertArrayNotHasKey(EventVenue::META, $this->meta, 'blank removes the own venue');
        self::assertSame('Studio Kallio', EventVenue::forEvent(12));
    }

    public function testTheComingEventsCardSavesTheEventsOwnVenue(): void
    {
        $page = new AiPromptsPage();

        self::assertSame('The venue of this event is now Garden.', $page->applyVenue(['event_id' => '12', 'venue' => 'Garden']));
        self::assertSame('Garden', EventVenue::own(12));
        self::assertSame(
            'This event uses the default venue again, else the venue from EventMesh.',
            $page->applyVenue(['event_id' => '12', 'venue' => ''])
        );
        self::assertSame('', EventVenue::own(12));

        Functions\when('get_post')->justReturn(null);
        self::assertSame('Nothing was saved: that event was not found.', $page->applyVenue(['event_id' => '99', 'venue' => 'X']));
    }

    public function testTheBuiltInTextsHaveTheVenueRowUnderTheWhenLine(): void
    {
        foreach (EventDetailsText::VENUE_KINDS as $id) {
            self::assertStringContainsString('{venue}', MessageTemplates::find($id)->defaultBody(), $id);
            self::assertArrayHasKey('{venue}', MessageTemplates::find($id)->placeholderList(), $id);
        }

        self::assertStringStartsWith(
            "⏰ Reminder: you’re on {role} at {event}, {when}. See you there!\n{venue}\n\n",
            MessageTemplates::find('reminder_dm')->defaultBody()
        );
        self::assertStringContainsString(
            "Sign up on the board.\n{venue}\n\n{event_description}{task_details}",
            MessageTemplates::find('slot_freed_dm')->defaultBody()
        );
        self::assertStringContainsString("\n\n{venue}\n\n", TicketText::defaultText());
    }

    public function testTheRowIsFilledOrGoesWithoutAGap(): void
    {
        $context = [
            'person' => Person::fromRow(['id' => 8, 'display_name' => 'Anna', 'telegram_chat_id' => 1]),
            'task' => new Task(5, '2026-07-21', 'decorate', 2, 12, 'Dance', '2026-07-21 08:00:00'),
            'description' => '',
        ];

        $this->meta['_eventmesh_venue_name'] = 'Hall A';
        self::assertStringEndsWith("See you there!\n📍 Hall A", MessageTemplates::body('reminder_dm', $context));

        unset($this->meta['_eventmesh_venue_name']);
        self::assertStringEndsWith('See you there!', MessageTemplates::body('reminder_dm', $context));

        EventVenue::saveDefault('Studio');
        self::assertStringContainsString(
            "Tue 21 Jul\n📍 Studio\n",
            MessageTemplates::body('free_ticket_dm', ['date' => 'Tue 21 Jul', 'event_id' => 12])
        );

        self::assertSame(
            "Here is your ticket.\n\n📍 Studio",
            TicketText::fill("Here is your ticket.\n\n{venue}", [TicketText::VENUE_TAG => EventVenue::line(12)])
        );
        self::assertSame('Here is your ticket.', TicketText::fill("Here is your ticket.\n\n{venue}", [TicketText::VENUE_TAG => '']));
    }

    public function testTheStoredTextsGetTheRowOnce(): void
    {
        $key = MessageTemplates::OPTION_PREFIX;
        $this->options[EventDetailsText::OPTION] = ['description' => 1];
        $this->options[$key . 'reminder_dm'] = [MessageTemplates::BODY => "On {role} at {event}, {when}.\n\n{description}\n\n{event_description}"];
        $this->options[$key . 'free_ticket_dm'] = [MessageTemplates::BODY => 'Ticket for {date}.'];
        $this->options[$key . 'slot_freed_dm'] = [MessageTemplates::BODY => 'Open: {role}, {when}.{task_details}'];
        $this->options[TicketText::OPTION] = [
            'source' => "*Hi {first_name},*\n\nYour ticket to {event} on {date}.\n\nSee you.",
            'texts' => ['fi' => "*Hei {first_name},*\n\nLippusi: {event} {date}.", 'en' => ''],
        ];

        EventDetailsText::run();

        self::assertSame(
            "On {role} at {event}, {when}.\n{venue}\n\n{description}\n\n{event_description}",
            $this->options[$key . 'reminder_dm'][MessageTemplates::BODY]
        );
        self::assertSame("Ticket for {date}.\n{venue}", $this->options[$key . 'free_ticket_dm'][MessageTemplates::BODY]);
        self::assertSame("Open: {role}, {when}.\n{venue}{task_details}", $this->options[$key . 'slot_freed_dm'][MessageTemplates::BODY]);
        self::assertSame(
            [
                'source' => "*Hi {first_name},*\n\nYour ticket to {event} on {date}.\n\n{venue}\n\nSee you.",
                'texts' => ['fi' => "*Hei {first_name},*\n\nLippusi: {event} {date}.\n\n{venue}", 'en' => ''],
            ],
            $this->options[TicketText::OPTION]
        );
        self::assertSame(['description' => 1, 'venue' => 1, 'signup_email_no_description' => 1], $this->options[EventDetailsText::OPTION]);

        $this->options[$key . 'free_ticket_dm'] = [MessageTemplates::BODY => 'Ticket for {date}.'];
        EventDetailsText::run();
        self::assertSame('Ticket for {date}.', $this->options[$key . 'free_ticket_dm'][MessageTemplates::BODY], 'once');
    }
}
