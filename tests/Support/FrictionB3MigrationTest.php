<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\FrictionB3Migration;
use EventCrew\Support\MessageTemplates;
use EventCrew\Tests\TestCase;

/**
 * The one-time B3 changes to stored message texts: each step adds its
 * placeholders once and leaves an empty text and a missing one alone.
 */
final class FrictionB3MigrationTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var list<string> */
    private array $writes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->writes = [];
        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;
            $this->writes[] = $name;

            return true;
        });
    }

    public function testTheAskStepPutsTheRecordAndTheDescriptionUnderTheWhenLine(): void
    {
        $prefix = MessageTemplates::OPTION_PREFIX;
        $this->options[$prefix . 'takeover_ask_dm'] = [
            MessageTemplates::BODY => [
                'source' => "{cover} asks for {role} at {when}.\n\nYou decide.",
                'texts' => ['fi' => '{cover} pyytää: {role}, {when}.', 'en' => ''],
            ],
        ];
        $this->options[$prefix . 'takeover_ask'] = [MessageTemplates::BODY => "{cover} asks.\n{description}"];

        FrictionB3Migration::run();

        $dm = $this->options[$prefix . 'takeover_ask_dm'][MessageTemplates::BODY];
        self::assertSame(
            "{cover} asks for {role} at {when}.\n{cover_record}\n\n{description}\n\nYou decide.",
            $dm['source']
        );
        self::assertSame("{cover} pyytää: {role}, {when}.\n{cover_record}\n\n{description}", $dm['texts']['fi']);
        self::assertSame('', $dm['texts']['en'], 'an empty text stays empty');
        self::assertSame(
            "{cover} asks.\n{description}\n{cover_record}",
            $this->options[$prefix . 'takeover_ask'][MessageTemplates::BODY],
            'a text with {description} only gains the record'
        );

        $this->writes = [];
        FrictionB3Migration::run();
        self::assertSame([], $this->writes, 'a second run changes nothing');
    }

    public function testTheOutcomeStepAddsTheOtherTasksAtTheEnd(): void
    {
        $prefix = MessageTemplates::OPTION_PREFIX;
        $this->options[$prefix . 'takeover_refused_dm'] = [MessageTemplates::BODY => "{holder} keeps it.\n"];
        $this->options[$prefix . 'takeover_closed'] = [MessageTemplates::BODY => "Closed.\n{other_tasks}\nBye."];

        FrictionB3Migration::run();

        self::assertSame(
            '{holder} keeps it.{other_tasks}',
            $this->options[$prefix . 'takeover_refused_dm'][MessageTemplates::BODY]
        );
        self::assertSame("Closed.\n{other_tasks}\nBye.", $this->options[$prefix . 'takeover_closed'][MessageTemplates::BODY]);
        self::assertSame(1, $this->options[FrictionB3Migration::OPTION]['takeover_other_tasks']);
    }

    public function testTheCreditStepAddsTheBalanceAndTheNights(): void
    {
        $prefix = MessageTemplates::OPTION_PREFIX;
        $this->options[$prefix . 'credit_granted'] = [MessageTemplates::BODY => "Hi {name},\n\nA gift.\n"];
        $this->options[$prefix . 'credit_granted_dm'] = [MessageTemplates::BODY => 'A gift. {balance}'];

        FrictionB3Migration::run();

        self::assertSame(
            "Hi {name},\n\nA gift.\n{balance}\n{nights}",
            $this->options[$prefix . 'credit_granted'][MessageTemplates::BODY]
        );
        self::assertSame('A gift. {balance}', $this->options[$prefix . 'credit_granted_dm'][MessageTemplates::BODY]);
    }

    public function testTheTicketStepAddsTheEventAndItsTimes(): void
    {
        $prefix = MessageTemplates::OPTION_PREFIX;
        $this->options[$prefix . 'free_ticket_dm'] = [MessageTemplates::BODY => 'Ticket for {date}.'];

        FrictionB3Migration::run();

        // The event is added under the date, then moved above it (Q344).
        self::assertSame(
            "{event}\nTicket for {date}.\n{event_times}",
            $this->options[$prefix . 'free_ticket_dm'][MessageTemplates::BODY]
        );
    }

    /** Owner, Q344 2026-11-07: the event first, then the day, venue, times. */
    public function testEventFirstMovesTheEventLineAboveTheDate(): void
    {
        self::assertSame(
            "{event}\n🎟 Your free-entry ticket for {date} — show it at the door.\n{venue}\n{event_times}",
            FrictionB3Migration::eventFirst(
                "🎟 Your free-entry ticket for {date} — show it at the door.\n{venue}\n{event}\n{event_times}"
            )
        );
        self::assertSame("{event}\n{date}", FrictionB3Migration::eventFirst("{event}\n{date}"));
        self::assertSame('Ticket for {date}. {event}', FrictionB3Migration::eventFirst('Ticket for {date}. {event}'));
    }
}
