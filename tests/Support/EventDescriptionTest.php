<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Support\EventDescription;
use EventCrew\Support\EventDetailsText;
use EventCrew\Support\MessageTemplates;
use EventCrew\Tests\TestCase;

/**
 * {event_description}: the short description of the event, in the reader's
 * language, in the messages about one event; the line goes when there is no
 * description; and the one-time migration of the stored texts.
 */
final class EventDescriptionTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, string> */
    private array $meta = [];

    private string $content = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->meta = [];
        $this->content = '';

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
        Functions\when('post_type_exists')->justReturn(true);
        Functions\when('get_post')->alias(fn (int $id): object => (object) [
            'ID' => $id,
            'post_type' => 'eventmesh_event',
            'post_title' => 'Ecstatic Dance',
            'post_content' => $this->content,
            'post_excerpt' => '',
        ]);
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key): string => $this->meta[$key] ?? ''
        );
        Functions\when('get_the_title')->justReturn('Ecstatic Dance');
    }

    private function task(): Task
    {
        return new Task(5, '2026-07-21', 'decorate', 2, 12, 'Dance', '2026-07-21 08:00:00');
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return [
            'person' => Person::fromRow(['id' => 8, 'display_name' => 'Anna', 'telegram_chat_id' => 1]),
            'task' => $this->task(),
        ];
    }

    public function testShortKeepsTheFirstTwoSentencesOnOneLine(): void
    {
        self::assertSame(
            'A night of free dance. DJ Aino plays.',
            EventDescription::shorten("A night of free dance.\n\nDJ Aino plays. Doors at 19. Bring water.")
        );
        self::assertSame('Tanssia 14.11. klo 19 alkaen. Tervetuloa!', EventDescription::shorten('Tanssia 14.11. klo 19 alkaen. Tervetuloa! Lisää alla.'));
        self::assertSame('', EventDescription::shorten("  \n "));
    }

    public function testALongDescriptionIsCutAtAWord(): void
    {
        $short = EventDescription::shorten(str_repeat('dance ', 100));

        self::assertStringEndsWith('dance…', $short);
        self::assertLessThanOrEqual(EventDescription::MAX_LENGTH, mb_strlen($short));
    }

    public function testTheReadersLanguageWinsWhenThereIsATranslation(): void
    {
        $this->content = '<p>Vapaata tanssia. Tervetuloa!</p>';
        $this->meta = [
            '_eventmesh_original_lang' => 'fi',
            '_eventmesh_tr_en_hash' => md5("Ecstatic Dance\0" . $this->content),
            '_eventmesh_tr_en_content' => 'Free dance. Welcome! Bring water.',
        ];

        self::assertSame('Free dance. Welcome!', EventDescription::short(12, 'en'));
        self::assertSame('Vapaata tanssia. Tervetuloa!', EventDescription::short(12, 'fi'));

        $this->meta['_eventmesh_tr_en_hash'] = 'stale';
        self::assertSame('Vapaata tanssia. Tervetuloa!', EventDescription::short(12, 'en'), 'an old translation is not used');
        self::assertSame('', EventDescription::short(null, 'en'));
    }

    public function testTheBuiltInTextsShowTheDescriptionUnderTheEventLine(): void
    {
        foreach (EventDetailsText::DESCRIPTION_KINDS as $id) {
            self::assertStringContainsString('{event_description}', MessageTemplates::find($id)->defaultBody(), $id);
            self::assertArrayHasKey('{event_description}', MessageTemplates::find($id)->placeholderList(), $id);
        }

        self::assertStringStartsWith(
            "✅ Signed up: {role} at {event}, {when}.\n{event_times}\n{venue}\n\n{description}\n\n{event_description}\n\n",
            MessageTemplates::find('signup_confirmed_dm')->defaultBody()
        );
    }

    public function testTheLineIsFilledOrGoesWithoutAGap(): void
    {
        $this->content = 'Free dance in the hall. Bring water. Doors at 19.';
        $body = MessageTemplates::body('reminder_dm', $this->context() + ['description' => '']);
        self::assertStringEndsWith("See you there!\n\nFree dance in the hall. Bring water.", $body);

        $this->content = '';
        $body = MessageTemplates::body('reminder_dm', $this->context() + ['description' => '']);
        self::assertStringEndsWith('See you there!', $body);

        self::assertSame(
            'Opened.Anna',
            MessageTemplates::find('followup_came')->fill(
                "Opened.\n\n{event_description}{first_name}",
                ['first_name' => 'Anna', 'event_id' => 12]
            ),
            'a placeholder written right after it moves up to the line before'
        );
    }

    public function testTheFollowupGetsTheNightsEvent(): void
    {
        $this->content = 'Free dance in the hall.';
        $body = MessageTemplates::find('followup_came')->fill(
            "Thanks for {event}.\n\n{event_description}",
            ['first_name' => 'Anna', 'event' => 'Ecstatic Dance', 'date' => '21.7.', 'event_id' => 12]
        );

        self::assertSame("Thanks for Ecstatic Dance.\n\nFree dance in the hall.", $body);
    }

    public function testTheStoredTextsGetTheLineOnce(): void
    {
        $key = MessageTemplates::OPTION_PREFIX;
        $this->options[EventDetailsText::OPTION] = ['venue' => 1];
        $this->options[$key . 'reminder_dm'] = [MessageTemplates::BODY => "On {role} at {event}, {when}.\n\n{description}"];
        $this->options[$key . 'slot_freed'] = [MessageTemplates::BODY => [
            'source' => "Hi {name},\n\nOpen: {role} at {event}, {when}.{task_details}",
            'texts' => ['fi' => "Hei {name},\n\nVapaa: {role}, {event}, {when}.{task_details}", 'en' => ''],
        ]];
        $this->options[$key . 'signup_confirmed_dm'] = [MessageTemplates::BODY => "Signed up.\n\n{event_description}\n\n{event}"];
        $this->options[$key . 'followup_came'] = [MessageTemplates::BODY => "Hi {first_name},\n\nThanks for {event}.\n{event_times}\n\nSee you."];

        EventDetailsText::run();

        self::assertSame(
            "On {role} at {event}, {when}.\n\n{description}\n\n{event_description}",
            $this->options[$key . 'reminder_dm'][MessageTemplates::BODY]
        );
        self::assertSame(
            [
                'source' => "Hi {name},\n\nOpen: {role} at {event}, {when}.\n\n{event_description}{task_details}",
                'texts' => ['fi' => "Hei {name},\n\nVapaa: {role}, {event}, {when}.\n\n{event_description}{task_details}", 'en' => ''],
            ],
            $this->options[$key . 'slot_freed'][MessageTemplates::BODY]
        );
        self::assertSame("Signed up.\n\n{event_description}\n\n{event}", $this->options[$key . 'signup_confirmed_dm'][MessageTemplates::BODY]);
        self::assertSame(
            "Hi {first_name},\n\nThanks for {event}.\n{event_times}\n\n{event_description}\n\nSee you.",
            $this->options[$key . 'followup_came'][MessageTemplates::BODY]
        );
        self::assertSame(['venue' => 1, 'description' => 1], $this->options[EventDetailsText::OPTION]);

        // Once: a text the organizer changes afterwards is left alone.
        $this->options[$key . 'reminder_dm'] = [MessageTemplates::BODY => 'Just {event}.'];
        EventDetailsText::run();
        self::assertSame('Just {event}.', $this->options[$key . 'reminder_dm'][MessageTemplates::BODY]);
    }
}
