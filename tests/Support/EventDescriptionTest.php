<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Support\EventDescription;
use EventCrew\Support\EventDetailsText;
use EventCrew\Support\Language;
use EventCrew\Support\MessageTemplates;
use EventCrew\Telegram\BoardDjText;
use EventCrew\Tests\TestCase;

/**
 * {event_description}: the short description of the event, in the reader's
 * language, in the messages about one event; the line goes when there is no
 * description; and the one-time migration of the stored texts.
 */
final class EventDescriptionTest extends TestCase
{
    /** An event's own text as EventMesh keeps it: a block of details, no prose. */
    private const RAW = "<p>ECSTATIC DANCE HELSINKI<br>14.11.2026<br>Esimerkki<br>Example Hall Helsinki<br>"
        . "Sörnäisten rantatie 33C, 00500 Helsinki<br>Door code 7302 (lower keypad)</p>"
        . "<p>Event schedule:<br>18:30-19:00 Doors open<br>19:00 Opening circle &amp; warm-up<br>"
        . "19:30 Ecstatic Dance<br>21:30 Closing circle</p><p>Note! Doors close at 19:00.</p>";

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

    /**
     * The group board's current AI text about event 12 (BoardDjText), with
     * $translations [lang => text] made from it in the board's background run.
     *
     * @param array<string, string> $translations
     */
    private function boardText(string $text, array $translations = []): void
    {
        $this->options[BoardDjText::OPTION] = [12 => [
            'hash' => md5(BoardDjText::source(12)),
            'text' => $text,
            'texts' => $translations,
            'text_hash' => md5($text),
        ]];
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

    /**
     * The event's own text never goes into a message (Owner, 2026-11-07): not
     * a structured block, not prose, not a translation. Only the board's AI
     * text does, and only for an English reader (the model writes it in
     * English); a text whose source changed since is not used.
     */
    public function testOnlyTheCuratedTextIsUsedNeverTheEventsOwnText(): void
    {
        foreach ([self::RAW, '<p>Free dance in the hall. Bring water.</p>', "Details\n14.11.\n\nA night of free dance. Bring water."] as $own) {
            $this->content = $own;
            $this->meta = [
                '_eventmesh_original_lang' => 'fi',
                '_eventmesh_tr_en_hash' => md5("Ecstatic Dance\0" . $own),
                '_eventmesh_tr_en_content' => 'Free dance. Welcome!',
            ];

            self::assertSame('', EventDescription::short(12, 'en'));
            self::assertSame('', EventDescription::short(12, 'fi'));
        }

        $this->meta = [];
        $this->content = self::RAW;
        $this->boardText('DJ Aino opens the night with warm grooves. Expect a slow build into deep dancing. A third one.');

        self::assertSame('DJ Aino opens the night with warm grooves. Expect a slow build into deep dancing.', EventDescription::short(12, 'en'));
        self::assertSame('', EventDescription::short(12, 'fi'), 'no Finnish translation: empty, not English');
        self::assertSame('', EventDescription::short(null, 'en'));

        $this->content = '<p>Other text.</p>';
        self::assertSame('', EventDescription::short(12, 'en'), 'an old text is not used');
    }

    /**
     * A Finnish reader gets the board text's Finnish translation, made in the
     * board's background run; sending only reads it and never asks the model.
     */
    public function testAFinnishReaderGetsTheFinnishTextAndSendingNeverAsksTheModel(): void
    {
        Functions\expect('wp_remote_post')->never();
        $this->content = self::RAW;
        $this->boardText(
            'DJ Aino opens the night with warm grooves. Expect a slow build into deep dancing.',
            ['fi' => 'DJ Aino avaa illan lämpimillä grooveilla. Luvassa hidas nousu syvään tanssiin.']
        );

        self::assertSame(
            'DJ Aino avaa illan lämpimillä grooveilla. Luvassa hidas nousu syvään tanssiin.',
            EventDescription::short(12, 'fi')
        );
        self::assertSame('', EventDescription::short(12, 'sv'), 'no Swedish translation: empty, not English');

        $body = Language::run(
            Language::FINNISH,
            fn (): string => MessageTemplates::body('reminder_dm', $this->context() + ['description' => ''])
        );
        // The cancel hint comes after it (cancelHelpDm()).
        self::assertStringContainsString(
            "\n\nDJ Aino avaa illan lämpimillä grooveilla. Luvassa hidas nousu syvään tanssiin.\n\n",
            $body
        );
        self::assertStringNotContainsString('warm grooves', $body);
    }

    /** A new English board text drops the translations made from the old one. */
    public function testAChangedBoardTextDropsItsTranslations(): void
    {
        $this->content = self::RAW;
        $this->boardText('DJ Aino plays warm grooves.', ['fi' => 'DJ Aino soittaa lämpimiä grooveja.']);
        self::assertSame('DJ Aino soittaa lämpimiä grooveja.', EventDescription::short(12, 'fi'));

        // A new English text: the translations kept beside it were made from the old one.
        $this->options[BoardDjText::OPTION][12]['text'] = 'DJ Ben plays lindy hop.';
        self::assertSame('', EventDescription::short(12, 'fi'), 'not the old translation');
        self::assertSame('DJ Ben plays lindy hop.', EventDescription::short(12, 'en'));

        // The event changed (a new description): the English text and its translations go.
        $this->boardText('DJ Aino plays warm grooves.', ['fi' => 'DJ Aino soittaa lämpimiä grooveja.']);
        $this->content = '<p>Other text.</p>';
        self::assertSame('', EventDescription::short(12, 'fi'));
        self::assertSame('', EventDescription::short(12, 'en'));
    }

    /** A raw-only event: no raw text, door code or schedule in the reminder or "A spot opened". */
    public function testARawOnlyEventPutsNothingIntoTheMessages(): void
    {
        $this->content = self::RAW;

        foreach (['reminder', 'reminder_dm', 'slot_freed', 'slot_freed_dm'] as $id) {
            $body = MessageTemplates::body($id, $this->context() + ['description' => '', 'name' => 'Anna']);

            foreach (['ECSTATIC', '7302', 'Doors open', 'Note!', 'Sörnäisten', 'Esimerkki'] as $raw) {
                self::assertStringNotContainsString($raw, $body, $id);
            }

            self::assertStringNotContainsString("\n\n\n", $body, $id);
        }
    }

    public function testTheBuiltInTextsShowTheDescriptionUnderTheEventLine(): void
    {
        foreach (EventDetailsText::DESCRIPTION_KINDS as $id) {
            self::assertStringContainsString('{event_description}', MessageTemplates::find($id)->defaultBody(), $id);
            self::assertArrayHasKey('{event_description}', MessageTemplates::find($id)->placeholderList(), $id);
        }

        self::assertStringNotContainsString('{event_description}', MessageTemplates::find('signup_confirmed_dm')->defaultBody());
        self::assertStringStartsWith(
            "✅ Signed up: {role} at {event}, {when}.\n{event_times}\n{venue}\n\n{description}\n\n",
            MessageTemplates::find('signup_confirmed_dm')->defaultBody()
        );
    }

    public function testTheLineIsFilledOrGoesWithoutAGap(): void
    {
        $this->content = self::RAW;
        $this->boardText('Free dance in the hall. Bring water. Doors at 19.');
        $body = MessageTemplates::body('reminder_dm', $this->context() + ['description' => '']);
        self::assertStringEndsWith("See you there!\n\nFree dance in the hall. Bring water.\n\nCan’t make it? Tap Cancel below: there you can also hand the task over to someone else.", $body);

        $this->options = [];
        $body = MessageTemplates::body('reminder_dm', $this->context() + ['description' => '']);
        self::assertStringEndsWith("See you there!\n\nCan’t make it? Tap Cancel below: there you can also hand the task over to someone else.", $body);

        self::assertSame(
            'Opened.Anna',
            MessageTemplates::find('reminder')->fill(
                "Opened.\n\n{event_description}{name}",
                $this->context()
            ),
            'a placeholder written right after it moves up to the line before'
        );
    }

    /**
     * The post-event emails have no {event_description}: they show the text
     * under the next event's picture (FollowupMail, Owner, 2026-11-07).
     */
    public function testTheFollowupsHaveNoEventDescription(): void
    {
        foreach (['followup_came', 'followup_missed'] as $id) {
            self::assertNotContains($id, EventDetailsText::DESCRIPTION_KINDS);
            self::assertStringNotContainsString('{event_description}', MessageTemplates::find($id)->defaultBody(), $id);
            self::assertArrayNotHasKey('{event_description}', MessageTemplates::find($id)->placeholderList(), $id);
        }
    }

    /**
     * A stored post-event email that the "description" step gave the line
     * loses it again, in the source and every language text. Once.
     */
    public function testAStoredFollowupLosesTheEventDescriptionOnce(): void
    {
        $key = MessageTemplates::OPTION_PREFIX;
        $this->options[EventDetailsText::OPTION] = ['venue' => 1, 'description' => 1, 'signup_email_no_description' => 1];
        $this->options[$key . 'followup_came'] = [MessageTemplates::BODY => [
            'source' => "Hi {first_name},\n\nThanks for {event}.\n\n{event_description}\n\nSee you.",
            'texts' => ['fi' => "Hei {first_name},\n\nKiitos.\n\n{event_description}\n\nNähdään.", 'en' => ''],
        ]];
        $this->options[$key . 'followup_missed'] = [MessageTemplates::BODY => "We missed you.\n\n{event_description}"];
        $this->options[$key . 'reminder_dm'] = [MessageTemplates::BODY => "Soon.\n\n{event_description}"];

        EventDetailsText::run();

        self::assertSame(
            [
                'source' => "Hi {first_name},\n\nThanks for {event}.\n\nSee you.",
                'texts' => ['fi' => "Hei {first_name},\n\nKiitos.\n\nNähdään.", 'en' => ''],
            ],
            $this->options[$key . 'followup_came'][MessageTemplates::BODY]
        );
        self::assertSame('We missed you.', $this->options[$key . 'followup_missed'][MessageTemplates::BODY]);
        self::assertSame("Soon.\n\n{event_description}", $this->options[$key . 'reminder_dm'][MessageTemplates::BODY]);
        self::assertSame(1, $this->options[EventDetailsText::OPTION]['followup_no_description']);
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
        self::assertSame("Signed up.\n\n{event}", $this->options[$key . 'signup_confirmed_dm'][MessageTemplates::BODY]);
        self::assertSame(
            "Hi {first_name},\n\nThanks for {event}.\n{event_times}\n\nSee you.",
            $this->options[$key . 'followup_came'][MessageTemplates::BODY]
        );
        self::assertSame(
            ['venue' => 1, 'description' => 1, 'signup_email_no_description' => 1, 'followup_no_description' => 1, 'signup_dm_no_description' => 1],
            $this->options[EventDetailsText::OPTION]
        );

        // Once: a text the organizer changes afterwards is left alone.
        $this->options[$key . 'reminder_dm'] = [MessageTemplates::BODY => 'Just {event}.'];
        EventDetailsText::run();
        self::assertSame('Just {event}.', $this->options[$key . 'reminder_dm'][MessageTemplates::BODY]);
    }

    /**
     * The sign-up email is a thank-you about the task (Owner, 2026-11-07): the
     * task's description, never the event's; the DM too.
     */
    public function testTheSignupEmailShowsTheTasksDescriptionNotTheEvents(): void
    {
        $this->content = self::RAW;
        $this->boardText('Free dance in the hall. Bring water.');
        $email = MessageTemplates::find('signup_confirmed');

        self::assertNotContains('signup_confirmed', EventDetailsText::DESCRIPTION_KINDS);
        self::assertStringNotContainsString('{event_description}', $email->defaultBody());
        self::assertStringContainsString('{description}', $email->defaultBody());

        $body = MessageTemplates::body('signup_confirmed', $this->context() + ['description' => 'Bring a ladder.']);
        self::assertStringContainsString("\n\nBring a ladder.\n\nShow your ticket at the door.", $body);
        self::assertStringNotContainsString('Free dance', $body);

        $dm = MessageTemplates::body('signup_confirmed_dm', $this->context() + ['description' => 'Bring a ladder.']);
        self::assertStringNotContainsString('Free dance', $dm);
        self::assertStringContainsString('Bring a ladder.', $dm);
    }

    /** No task description: no line, no label, no gap. */
    public function testASignupEmailWithoutATaskDescriptionLeavesNoGap(): void
    {
        $this->content = self::RAW;
        $this->boardText('Free dance in the hall.');
        $body = MessageTemplates::body(
            'signup_confirmed',
            $this->context() + ['description' => '', 'standing' => 'Your standing: Beginner.']
        );

        self::assertStringStartsWith("Hi Anna,\n\nThank you for joining the crew! You’re on Decorate at Ecstatic Dance,", $body);
        self::assertMatchesRegularExpression('/[^\n]\n\nShow your ticket at the door\. We can’t wait to see you there ✨\n\n/u', $body);
        self::assertStringNotContainsString("\n\n\n", $body);
        self::assertStringNotContainsString('Free dance', $body);
    }

    /**
     * A stored sign-up email that the "description" step gave the event's
     * description loses it again, in the source and every language text,
     * and so does the stored DM. Once.
     */
    public function testAStoredSignupEmailLosesTheEventDescriptionOnce(): void
    {
        $key = MessageTemplates::OPTION_PREFIX;
        $this->options[EventDetailsText::OPTION] = ['venue' => 1, 'description' => 1];
        $this->options[$key . 'signup_confirmed'] = [
            MessageTemplates::SUBJECT => ['source' => 'In: {role}', 'texts' => []],
            MessageTemplates::BODY => [
                'source' => "Hi {name},\n\nOn {role} at {event}, {when}.\n{venue}\n\n{description}\n\n{event_description}\n\n{cancel_help}{free_cancel}",
                'texts' => [
                    'fi' => "Hei {name},\n\n{role}, {event}, {when}.\n\n{event_description}{free_cancel}",
                    'en' => '',
                ],
            ],
        ];
        $this->options[$key . 'signup_confirmed_dm'] = [MessageTemplates::BODY => "Signed up.\n\n{event_description}\n\n{event}"];

        EventDetailsText::run();

        self::assertSame(
            [
                'source' => "Hi {name},\n\nOn {role} at {event}, {when}.\n{venue}\n\n{description}\n\n{cancel_help}{free_cancel}",
                'texts' => ['fi' => "Hei {name},\n\n{role}, {event}, {when}.{free_cancel}", 'en' => ''],
            ],
            $this->options[$key . 'signup_confirmed'][MessageTemplates::BODY]
        );
        self::assertSame(['source' => 'In: {role}', 'texts' => []], $this->options[$key . 'signup_confirmed'][MessageTemplates::SUBJECT]);
        self::assertSame("Signed up.\n\n{event}", $this->options[$key . 'signup_confirmed_dm'][MessageTemplates::BODY]);
        self::assertSame(1, $this->options[EventDetailsText::OPTION]['signup_email_no_description']);

        // Once: an organizer who puts it back keeps it.
        $this->options[$key . 'signup_confirmed'] = [MessageTemplates::BODY => "On {event}.\n\n{event_description}"];
        EventDetailsText::run();
        self::assertSame("On {event}.\n\n{event_description}", $this->options[$key . 'signup_confirmed'][MessageTemplates::BODY]);

        self::assertSame('Text.', EventDetailsText::withoutEventDescription("{event_description}\n\nText."));
        self::assertSame('Just text.', EventDetailsText::withoutEventDescription('Just text.'));
    }

    /** A site that never ran the "description" step: the stored sign-up email does not get it. */
    public function testAFreshSiteDoesNotGiveTheSignupEmailTheEventDescription(): void
    {
        $key = MessageTemplates::OPTION_PREFIX;
        $this->options[$key . 'signup_confirmed'] = [MessageTemplates::BODY => "On {role} at {event}, {when}.\n\n{description}"];

        EventDetailsText::run();

        self::assertStringNotContainsString('{event_description}', $this->options[$key . 'signup_confirmed'][MessageTemplates::BODY]);
    }
}
