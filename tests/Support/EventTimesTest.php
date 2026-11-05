<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
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
    }

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
}
