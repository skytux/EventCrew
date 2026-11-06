<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\FrictionB1Migration;
use EventCrew\Support\MessageTemplates;
use EventCrew\Tests\TestCase;

/**
 * The one-time changes to stored message texts: each step adds its
 * placeholder to the source and every language text, once, and leaves a text
 * that has it already, an empty one and a kind with no stored text alone.
 */
final class FrictionB1MigrationTest extends TestCase
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

    public function testTheStandingStepAddsTheLineToEveryStoredTextOnce(): void
    {
        $prefix = MessageTemplates::OPTION_PREFIX;
        $this->options[$prefix . 'no_show'] = [
            MessageTemplates::BODY => [
                'source' => "Hi {name},\n\nYou missed {task}.",
                'texts' => ['fi' => "Hei {name},\n\nEt tullut: {task}.\n", 'en' => '', 'sv' => 'Du missade {task}. {standing}'],
            ],
            'settings' => ['ai' => false],
        ];
        $this->options[$prefix . 'late_cancel_dm'] = [MessageTemplates::BODY => 'Late for {task}.'];
        $this->options[$prefix . 'late_cancel'] = [MessageTemplates::SUBJECT => ['source' => 'Late']];

        FrictionB1Migration::run();

        $noShow = $this->options[$prefix . 'no_show'];
        self::assertSame("Hi {name},\n\nYou missed {task}.\n\n{standing}", $noShow[MessageTemplates::BODY]['source']);
        self::assertSame("Hei {name},\n\nEt tullut: {task}.\n\n{standing}", $noShow[MessageTemplates::BODY]['texts']['fi']);
        self::assertSame('', $noShow[MessageTemplates::BODY]['texts']['en'], 'an empty text stays empty');
        self::assertSame('Du missade {task}. {standing}', $noShow[MessageTemplates::BODY]['texts']['sv']);
        self::assertSame(['ai' => false], $noShow['settings']);
        self::assertSame("Late for {task}.\n\n{standing}", $this->options[$prefix . 'late_cancel_dm'][MessageTemplates::BODY]);
        self::assertArrayNotHasKey(MessageTemplates::BODY, $this->options[$prefix . 'late_cancel']);
        self::assertArrayNotHasKey($prefix . 'no_show_dm', $this->options);
        self::assertSame(1, $this->options[FrictionB1Migration::OPTION]['standing']);

        // A second run changes nothing: the step is done.
        $this->writes = [];
        $this->options[$prefix . 'late_cancel_dm'] = [MessageTemplates::BODY => 'Changed again.'];
        FrictionB1Migration::run();

        self::assertSame([], $this->writes);
        self::assertSame('Changed again.', $this->options[$prefix . 'late_cancel_dm'][MessageTemplates::BODY]);
    }

    /**
     * The sign-up step puts the task's description and the event's times
     * under the when line, and drops {about}: the description says the same.
     */
    public function testTheSignupStepAddsTheDescriptionAndTheEventTimesUnderTheWhenLine(): void
    {
        $prefix = MessageTemplates::OPTION_PREFIX;
        $this->options[$prefix . 'signup_confirmed_dm'] = [
            MessageTemplates::BODY => "Signed up: {task}, {when}.\n\nTicket at the door.\n\n{about}",
        ];
        $this->options[$prefix . 'signup_confirmed'] = [
            MessageTemplates::BODY => [
                'source' => 'No when line here.',
                'texts' => ['fi' => "Ilmoittauduttu: {when}.\n{event_times}\n\n{description}"],
            ],
        ];

        FrictionB1Migration::run();

        self::assertSame(
            "Signed up: {task}, {when}.\n{event_times}\n\n{description}\n\nTicket at the door.",
            $this->options[$prefix . 'signup_confirmed_dm'][MessageTemplates::BODY]
        );
        $email = $this->options[$prefix . 'signup_confirmed'][MessageTemplates::BODY];
        self::assertSame('No when line here.', $email['source'], 'no when line: left alone');
        self::assertSame("Ilmoittauduttu: {when}.\n{event_times}\n\n{description}", $email['texts']['fi']);
        self::assertSame(1, $this->options[FrictionB1Migration::OPTION]['signup']);
    }
}
