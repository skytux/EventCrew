<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Support\DmBody;
use EventCrew\Support\Signature;
use EventCrew\Tests\TestCase;

/**
 * The wrapper round the Telegram messages nobody asked for: who is writing, who
 * to, and whether the crew signs off.
 */
final class DmBodyTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('get_bloginfo')->justReturn('Ecstatic Dance Helsinki');
    }

    private function person(): Person
    {
        return Person::fromRow(['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam']);
    }

    public function testAnUnpromptedMessageSaysWhoIsWritingAndGreetsByName(): void
    {
        // The point of the frame: weeks may have passed since this person last
        // touched the bot, and the chat is one of dozens.
        $text = DmBody::frame($this->person(), 'Some tasks still need people.');

        self::assertStringContainsString('Ecstatic Dance Helsinki', $text);
        self::assertStringContainsString('Hi Sam,', $text);
        self::assertStringContainsString('Some tasks still need people.', $text);
    }

    public function testTheSignOffIsAddedOnlyWhenAskedFor(): void
    {
        $this->options[Signature::OPTION] = 'Barefoot and with ♥ — the Ecstatic Dance Helsinki crew';

        self::assertStringContainsString(
            'Barefoot and with ♥',
            DmBody::frame($this->person(), 'A spot just opened.', true)
        );

        // A standing notice passes false: a warm sign-off under "you were marked
        // as a no-show" would read as sarcasm.
        self::assertStringNotContainsString(
            'Barefoot and with ♥',
            DmBody::frame($this->person(), 'You were marked as a no-show.')
        );
    }

    public function testAnUnsetSignatureLeavesNoStrayBlankLine(): void
    {
        $text = DmBody::frame($this->person(), 'A spot just opened.', true);

        self::assertStringEndsWith('A spot just opened.', $text);
    }
}
