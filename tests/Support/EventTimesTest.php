<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Admin\AiPromptsPage;
use EventCrew\Models\Task;
use EventCrew\Support\EventTimes;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\TicketNote;
use EventCrew\Tests\Telegram\FakeEvents;
use EventCrew\Tests\TestCase;

/**
 * An event's start time as members read it ({start_time}): on the ticket,
 * in the ticket note and in the message texts that name the event.
 */
final class EventTimesTest extends TestCase
{
    use FakeEvents;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeEvents();
        $this->event(41, '2026-11-14', 'Ecstatic Dance');
        $this->event(42, '2026-11-21', 'Picnic', ['_eventmesh_manual_starts_at' => '2026-11-21']);

        $this->options = [];
        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
        Functions\when('update_post_meta')->alias(function (int $id, string $key, string $value): bool {
            $this->events[$id]['meta'][$key] = $value;

            return true;
        });
        Functions\when('delete_post_meta')->alias(function (int $id, string $key): bool {
            unset($this->events[$id]['meta'][$key]);

            return true;
        });
    }

    /** @var array<string, mixed> */
    private array $options = [];

    public function testTheStartTimeOfAnEvent(): void
    {
        self::assertSame('21:00', EventTimes::start(41));
        self::assertSame('', EventTimes::start(42), 'a date with no time');
        self::assertSame('', EventTimes::start(99), 'no such event');
        self::assertSame('', EventTimes::start(null));
    }

    public function testWithoutEventMeshThereIsNoStartTime(): void
    {
        Functions\when('post_type_exists')->justReturn(false);

        self::assertSame('', EventTimes::start(41));
    }

    public function testTheTicketNoteAndTheMessagesFillTheStartTime(): void
    {
        self::assertSame(
            "Doors open at {start_time}.\nDoor code 1234",
            TicketNote::fill("Doors open at {start_time}.\nDoor code 1234", ['{organization}' => 'X']),
        );
        self::assertSame(
            "Doors open at 21:00.\nDoor code 1234",
            TicketNote::fill("Doors open at {start_time}.\nDoor code 1234", TicketNote::values(41))
        );
        self::assertSame('Door code 1234', TicketNote::fill("Doors open at {start_time}.\nDoor code 1234", TicketNote::values()));

        $reminder = MessageTemplates::find('reminder');
        self::assertArrayHasKey('{start_time}', $reminder->placeholderList());
        self::assertSame(
            'Starts 21:00',
            $reminder->fill('Starts {start_time}', ['task' => new Task(1, '2026-11-14', 'door', 2, 41)])
        );
        self::assertArrayNotHasKey(
            '{start_time}',
            MessageTemplates::find('sign_in_link')->placeholderList(),
            'only a kind that names the event'
        );
    }

    public function testDoorsCloseIsOffByDefault(): void
    {
        self::assertFalse(EventTimes::doorsOn());
        self::assertSame(30, EventTimes::doorsMinutes());
        self::assertSame('', EventTimes::doorsClose(41));
    }

    public function testWhenOnTheDoorsCloseMinutesAfterTheStart(): void
    {
        EventTimes::saveDoorsSetting(true, '30');

        self::assertSame('21:30', EventTimes::doorsClose(41));
        self::assertSame('', EventTimes::doorsClose(42), 'no start time, no doors time');
        self::assertSame('', EventTimes::doorsClose(99));
        self::assertSame('', EventTimes::doorsClose(null));

        EventTimes::saveDoorsSetting(true, '180');
        self::assertSame('00:00', EventTimes::doorsClose(41), 'past midnight');

        EventTimes::saveDoorsSetting(false, '');
        self::assertSame(180, EventTimes::doorsMinutes(), 'a blank box keeps the minutes');
        self::assertSame('', EventTimes::doorsClose(41));
    }

    public function testAnEventsOwnTimeWins(): void
    {
        EventTimes::saveDoorsSetting(true, '30');

        self::assertSame('19:05', EventTimes::saveOwnDoorsClose(41, '19.05'));
        self::assertSame('19:05', EventTimes::doorsClose(41));
        self::assertSame('08:00', EventTimes::saveOwnDoorsClose(42, '8:00'));
        self::assertSame('08:00', EventTimes::doorsClose(42), 'even without a start time');

        EventTimes::saveDoorsSetting(false, '30');
        self::assertSame('19:05', EventTimes::doorsClose(41), 'set by hand, so shown with the setting off');

        self::assertSame('', EventTimes::saveOwnDoorsClose(41, 'soon'));
        self::assertSame('', EventTimes::ownDoorsClose(41), 'an unreadable time removes it');
        self::assertSame('', EventTimes::doorsClose(41));
    }

    public function testTheComingEventsCardSavesTheEventsOwnTime(): void
    {
        $page = new AiPromptsPage();

        self::assertSame(
            'The doors of this event close at 19:00.',
            $page->applyDoorsClose(['event_id' => '41', 'doors_close' => '19:00'])
        );
        self::assertSame('19:00', EventTimes::ownDoorsClose(41));
        self::assertSame(
            'This event follows the doors setting again.',
            $page->applyDoorsClose(['event_id' => '41', 'doors_close' => ''])
        );
        self::assertSame(
            'Nothing was saved: that event was not found.',
            $page->applyDoorsClose(['event_id' => '99', 'doors_close' => '19:00'])
        );
    }

    public function testTheBuiltInNoteIsTheDoorsLine(): void
    {
        self::assertSame('', TicketNote::fill(TicketNote::orBuiltIn(''), TicketNote::values(41)), 'off: no note');

        EventTimes::saveDoorsSetting(true, '30');

        self::assertSame(
            'Doors close at 21:30.',
            TicketNote::fill(TicketNote::orBuiltIn(" \n"), TicketNote::values(41))
        );
        self::assertSame(
            "Door code 1234\nDoors close at 21:30.",
            TicketNote::fill(TicketNote::orBuiltIn("Door code 1234\nDoors close at {doors_close}."), TicketNote::values(41)),
            'a stored note adds the line itself'
        );
        self::assertSame('Door code 1234', TicketNote::orBuiltIn('Door code 1234'));

        $reminder = MessageTemplates::find('reminder');
        self::assertArrayHasKey('{doors_close}', $reminder->placeholderList());
        self::assertSame(
            'In by 21:30',
            $reminder->fill('In by {doors_close}', ['task' => new Task(1, '2026-11-14', 'door', 2, 41)])
        );
    }

    /**
     * The sign-up confirmation says when the event starts and its doors
     * close, on the line under the when line; the line goes when neither is
     * known.
     */
    public function testTheSignupConfirmationShowsTheEventTimesUnderTheWhenLine(): void
    {
        $dm = MessageTemplates::find('signup_confirmed_dm');
        self::assertNotNull($dm);
        $text = "Signed up.\n{event_times}\n\nShow your ticket.";
        $event = new Task(1, '2026-11-14', 'door', 2, 41);

        self::assertSame("Signed up.\nEvent starts 21:00\n\nShow your ticket.", $dm->fill($text, ['task' => $event]));

        EventTimes::saveDoorsSetting(true, '30');

        self::assertSame(
            "Signed up.\nEvent starts 21:00 · doors close 21:30\n\nShow your ticket.",
            $dm->fill($text, ['task' => $event])
        );
        self::assertSame(
            "Signed up.\n\nShow your ticket.",
            $dm->fill($text, ['task' => new Task(1, '2026-11-21', 'door', 2, 42)]),
            'no time: the line goes'
        );
        self::assertStringContainsString(
            "{when}.\n{event_times}",
            MessageTemplates::raw('signup_confirmed_dm', MessageTemplates::BODY)
        );
    }
}
